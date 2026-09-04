<?php declare(strict_types=1);
/**
 * Jede Tabelle vergibt ihre Ids selbst — und keine Nummer wird ein zweites Mal vergeben.
 *
 *     php scripts/dev/id-space-check.php [path/to/wordpress]
 *
 * ⚠️ **Die Zusage von TASK-004** ([`package.md` §6](../../docs/pakete/modelltabellen/package.md),
 * der Eigentümer: *«jede Tabelle bekommt ihren eigenen Id-Raum … Records hatten dann einen zweiten
 * Nummernraum, das eliminieren wir jetzt»*). *`identities` ist gestrichen, und dieser Lauf hält
 * dreierlei fest:*
 *
 * 1. **Niemand zieht mehr aus `identities`** — die Tabelle ist weg, und keine Bedingung zeigt darauf.
 * 2. **Jede Id ist in ihrer eigenen Tabelle eindeutig** und jede Fremdschlüsselspalte findet ihr Ziel.
 * 3. **Keine neue Zeile bekommt eine Nummer, die schon einmal vergeben war** — der Zähler jeder
 *    Tabelle steht über allem, was sie je enthielt, den Schatten und das Änderungsbuch eingerechnet.
 *
 * ⚠️ **Punkt 3 ist der, der ohne Wächter still bräche.** *Der gemeinsame Raum ist bis 79 755 gelaufen,
 * und die Schattentabellen wie das Änderungsbuch tragen Nummern von längst Gelöschtem. Begänne ein
 * Raum beim höchsten **lebenden** Wert, hinge die Geschichte einer alten Sache an einer neuen —
 * [D-340](../../docs/NewConcept/90-decision-log.md).*
 *
 * ⚠️ **Die Fremdschlüssel selbst sind seit Fassung 21 nicht mehr in der Datenbank**, sondern werden
 * hier gelesen — *die Bedingungen auf `nodes.id` setzt TASK-010; bis dahin ist dieser Lauf die
 * Stelle, die es merkt. Gemessen vor dem Umbau: **null Waisen in allen sieben Spalten.***
 *
 * ⚠️ **Der eine offene Punkt war `settings.owner_id` — sie nannte ihren Raum nicht und zeigte
 * gemessen auf Knoten (3) und Kanten (10).** *Die Tabelle ist mit
 * [D-579](../../docs/NewConcept/90-decision-log.md) gestrichen; mit ihr faellt der Abstand, der die
 * beiden Raeume auseinanderhielt, und `INF-009` ist damit erledigt.*
 *
 * ⚠️ *Dieser Lauf schreibt nichts und legt nichts an — er liest nur, also gibt es nichts wegzuräumen.*
 *
 * @see docs/pakete/modelltabellen/package.md
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php. Pass the WordPress folder as the first argument.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\WordPress\Persistence\Schema;

global $wpdb;
$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;

    if ($passed) {
        $ok++;
        echo "  OK   $what\n";

        return;
    }

    $bad++;
    echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
}

/**
 * Der Zähler einer Tabelle.
 *
 * ⚠️ **Aus `SHOW CREATE TABLE` und nicht aus `information_schema`.** *MySQL 8 hält die Statistik dort
 * zwischengespeichert — im Versuch am 2026-09-04 kam sie als `NULL` zurück, während die Tabelle
 * längst `AUTO_INCREMENT=79756` trug. **Ein Wächter, der eine veraltete Zahl liest, ist schlimmer als
 * keiner.***
 */
function zaehlerVon(string $tabelle): int
{
    global $wpdb;

    $sql     = ($wpdb->get_row("SHOW CREATE TABLE {$tabelle}", ARRAY_N) ?: [1 => ''])[1];
    $treffer = [];

    if (preg_match('/AUTO_INCREMENT=(\d+)/', (string) $sql, $treffer) === 1) {
        return (int) $treffer[1];
    }

    // Ohne eine einzige Zeile nennt MySQL den Zähler nicht; dann ist er die nächste freie Nummer.
    return (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) + 1 FROM {$tabelle}");
}

echo "1 · Niemand zieht mehr aus identities\n";

$identities = $wpdb->prefix . 'taxmod_identities';

check(
    'die Tabelle ist gestrichen',
    $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $identities)) === null
);

$zeiger = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = %s',
    $identities
));

check('und keine Bedingung zeigt noch darauf', $zeiger === 0, "$zeiger Bedingungen");

check(
    'auch der alte Zaehler aus Fassung 1 ist weg',
    get_option('taxmod_model_last_id', null) === null
);

echo "\n2 · Jede Tabelle vergibt ihre Ids selbst\n";

// ⚠️ *Eine Abfrage über `information_schema` und keine je Tabelle (`CD-7`).*
// WICHTIG: settings stand hier und ist mit D-579 gestrichen.
$eigene = ['nodes', 'relations', 'records', 'record_values', 'labels', 'changelog'];

foreach ($eigene as $name) {
    $tabelle = Schema::table($name);

    $selbst = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'id'
           AND EXTRA LIKE %s",
        $tabelle,
        '%auto_increment%'
    ));

    check("{$name} hat einen eigenen Zaehler", $selbst === 1);
}

echo "\n3 · Kein Fremdschluessel zeigt ins Leere\n";

// ⚠️ *Die sieben Spalten, die bis Fassung 20 auf `identities.id` zeigten, plus die drei, die schon
// immer ihre Zieltabelle im Namen trugen. **Was hier steht, ist der Soll-Zustand von `package.md` §6**,
// und `settings.owner_id` stand hier bewusst nicht — die Tabelle ist seit D-579 fort.*
$verweise = [
    ['relations', 'from_id', 'nodes'],
    ['relations', 'to_id', 'nodes'],
    ['labels', 'owner_id', 'nodes'],
    ['labels', 'role_id', 'nodes'],
    ['records', 'node_id', 'nodes'],
    ['record_values', 'record_id', 'records'],
    ['record_values', 'edge_id', 'relations'],
];

foreach ($verweise as [$name, $spalte, $ziel]) {
    $quelle  = Schema::table($name);
    $tabelle = Schema::table($ziel);

    $waisen = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$quelle} s
         WHERE s.{$spalte} IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM {$tabelle} z WHERE z.id = s.{$spalte})"
    );

    check("{$name}.{$spalte} findet seinen Eintrag in {$ziel}", $waisen === 0, "$waisen Waisen");
}

echo "\n4 · Keine neue Zeile bekommt eine Nummer, die schon vergeben war\n";

// ⚠️ **Der Schatten und das Änderungsbuch gehören dazu, und das ist der Punkt des Abschnitts.**
// *Sie überleben, was sie beschreiben; eine Nummer, die dort steht, ist verbraucht, auch wenn keine
// lebende Zeile sie mehr trägt ([D-340], [D-065]).*
$verbraucht = [
    'nodes'     => [['nodes', 'id'], ['nodes_history', 'id'], ['relations', 'from_id'], ['relations', 'to_id']],
    'relations' => [['relations', 'id'], ['relations_history', 'id']],
    'records'   => [['records', 'id'], ['records_history', 'id'], ['record_values', 'record_id']],
];

foreach ($verbraucht as $name => $quellen) {
    $tabelle = Schema::table($name);

    $zaehler  = zaehlerVon($tabelle);
    $hoechste = 0;

    foreach ($quellen as [$quelle, $spalte]) {
        $hoechste = max($hoechste, (int) $wpdb->get_var(
            'SELECT MAX(' . $spalte . ') FROM ' . Schema::table($quelle)
        ));
    }

    check(
        "der Zaehler von {$name} steht ueber allem, was je dastand",
        $zaehler > $hoechste,
        "$zaehler gegen $hoechste"
    );
}

// ⚠️ *Und das Änderungsbuch, das für Knoten wie Kanten Nummern führt — es nennt den Raum selbst.*
foreach (['node' => 'nodes', 'relation' => 'relations'] as $art => $name) {
    $tabelle = Schema::table($name);

    $zaehler = zaehlerVon($tabelle);

    $imBuch = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT MAX(owner_id) FROM ' . Schema::table('changelog') . ' WHERE owner_kind = %s',
        $art
    ));

    check(
        "keine Nummer aus dem Aenderungsbuch ({$art}) wird noch einmal vergeben",
        $zaehler > $imBuch,
        "$zaehler gegen $imBuch"
    );
}

echo "
5 · Der Behelf ist weg, und das ist die Zusage
";

// WICHTIG: Hier standen drei Zeilen ueber settings.owner_id (INF-009): sie nannte ihren Raum nicht
// und zeigte gemessen auf 3 Knoten und 10 Kanten, weshalb der Kantenraum eine Milliarde ueber dem
// Knotenraum beginnen musste. **Die Tabelle ist mit D-579 gestrichen, und der Abstand mit ihr** —
// die Zusage «keine Nummer ist zugleich Knoten und Kante» ist damit nicht mehr noetig und
// ausdruecklich nicht mehr gewollt: package.md §6 sagt, mit eigenen Raeumen gebe es «Knoten 5,
// Kante 5 und Datensatz 5».
//
// Was bleibt, ist die Gegenprobe, dass niemand mehr ohne Raum fragt: keine Tabelle mit einer
// mehrdeutigen Besitzerspalte. changelog nennt owner_kind, record_values nennt value_ref_kind, und
// labels.owner_id zeigt gemessen nur auf Knoten — das steht in Abschnitt 3.
$settingsTabelle = Schema::table('settings');

check(
    'die settings-Tabelle ist fort',
    $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $settingsTabelle)) === null,
    'sie steht noch'
);

$doppelt = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' n
     INNER JOIN ' . Schema::table('relations') . ' r ON r.id = n.id'
);

printf("       gemessen: %d Nummern sind zugleich Knoten und Kante — erlaubt, seit jede Tabelle ihren eigenen Raum hat
", $doppelt);

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
