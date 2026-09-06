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
 * ⚠️ **Die Bedingungen auf `nodes.id` stehen seit Fassung 26 wieder in der Datenbank** (TASK-010,
 * der Eigentümer: *«das ist eine Knoten-Id, da ist ein Constraint»*). *Von Fassung 21 bis dahin hielt
 * **dieser Lauf** dieselbe Zusage lesend, und er tut es weiter — für die fünf Verweise, die bewusst
 * keine Bedingung tragen. **Für die beiden Kantenspalten prüft er jetzt zusätzlich, dass die
 * Bedingung wirklich dasteht**: eine, die MySQL still nicht angelegt hat, sähe sonst aus wie eine,
 * die hält.*
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

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
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
$eigene = ['nodes', 'relations', 'node_records', 'relation_records', 'labels', 'changelog'];

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
//
// ⚠️ **`labels.owner_id` steht hier nicht mehr, und das ist keine Entschärfung** (Fassung 31,
// `INF-035`, [D-597](../../docs/NewConcept/90-decision-log.md)). *Sie stand hier als «zeigt auf einen
// Knoten» — eine Zusage, die **schlicht falsch** war: eine Kante darf eigene Beschriftungen tragen
// ([D-410](../../docs/NewConcept/90-decision-log.md)), und gemessen zeigte die Spalte nur deshalb auf
// lauter Knoten, weil noch niemand einer Kante eine gegeben hatte. **Die Spalte zeigt jetzt in den
// Raum, den `owner_kind` nennt**, und Abschnitt 6 prüft genau das — je Raum, gegen die richtige
// Tabelle.*
$verweise = [
    ['relations', 'from_node_id', 'nodes'],
    ['relations', 'to_node_id', 'nodes'],
    ['labels', 'role_id', 'nodes'],
    ['node_records', 'node_id', 'nodes'],
    ['relation_records', 'node_record_id', 'records'],
    ['relation_records', 'relation_id', 'relations'],
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

// ⚠️ **Umgeschrieben am 2026-09-05, weil TASK-010 gebaut ist** (`PR-9`). *Der Kopf dieses Laufs sagte
// «bis dahin hält dieser Lauf dieselbe Zusage **lesend**» — jetzt hält sie die Datenbank, und dieser
// Lauf prüft, **dass sie es tut**. Eine Bedingung, die MySQL still nicht angelegt hat, sähe sonst
// genauso aus wie eine, die steht.*
//
// ⚠️ *Nur die beiden Kantenspalten. Die anderen fünf Verweise oben haben bewusst keine Bedingung:
// `relation_records.relation_id` zeigt auf eine Kante, die geparkt werden kann (TASK-013), und die
// Schattentabellen führen ihre Verweise als **Datum** und nicht als Zwang.*
foreach ([['from_node_id', 'nodes'], ['to_node_id', 'nodes']] as [$spalte, $ziel]) {
    $steht = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
           AND REFERENCED_TABLE_NAME = %s',
        Schema::table('relations'),
        $spalte,
        Schema::table($ziel)
    ));

    check("und relations.{$spalte} ist an nodes.id gebunden", $steht === 1, 'die Bedingung fehlt');
}

echo "\n4 · Keine neue Zeile bekommt eine Nummer, die schon vergeben war\n";

// ⚠️ **Der Schatten und das Änderungsbuch gehören dazu, und das ist der Punkt des Abschnitts.**
// *Sie überleben, was sie beschreiben; eine Nummer, die dort steht, ist verbraucht, auch wenn keine
// lebende Zeile sie mehr trägt ([D-340], [D-065]).*
$verbraucht = [
    'nodes'     => [['nodes', 'id'], ['nodes_history', 'id'], ['relations', 'from_node_id'], ['relations', 'to_node_id']],
    'relations' => [['relations', 'id'], ['relations_history', 'id']],
    // ⚠️ *Der Schlüssel ist der **Tabellenname** und seit TASK-014 `node_records` — er geht durch
    // {@see Schema::table()}. Als `records` fand er nichts und der Zähler las sich als `0`.*
    'node_records' => [['node_records', 'id'], ['node_records_history', 'id'], ['relation_records', 'node_record_id']],
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
// mehrdeutigen Besitzerspalte. changelog nennt owner_kind, relation_records nennt value_ref_kind --
// und labels nennt seit Fassung 31 ebenfalls owner_kind (INF-035, D-597). Bis dahin stand hier "zeigt
// gemessen nur auf Knoten", was keine Zusage war, sondern eine Beobachtung an Daten, die noch keine
// beschriftete Kante enthielten.
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

echo "
6 · Jede Beschriftung nennt ihren Raum, und der Raum stimmt
";

// ⚠️ **Fassung 31, INF-035, D-597.** *Bis dahin stand `labels.owner_id` in Abschnitt 3 als "zeigt auf
// einen Knoten". Das war eine Beobachtung und keine Zusage: eine Kante darf eigene Beschriftungen
// tragen (D-410), und seit D-581 tragen Knoten und Kante ohne Weiteres dieselbe Nummer. Hier steht
// jetzt die Zusage, die wirklich gilt -- jede Zeile nennt ihren Raum, und in diesem Raum gibt es sie.*
$labelTabelle = Schema::table('labels');

$ohneRaum = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$labelTabelle} WHERE owner_kind NOT IN ('node','relation')"
);

check('jede Beschriftung nennt einen der beiden Raeume', $ohneRaum === 0, "$ohneRaum ohne Raum");

// ⚠️ **Seit TASK-019 zeigt der Verweis in die Gegenrichtung** ([D-580](../../docs/NewConcept/90-decision-log.md)):
// *`nodes.label_id` und `relations.label_id` statt `labels.owner_id`. Die Frage lautet darum umgekehrt
// — **auf jede Beschriftung zeigt jemand**, und der Raum, den sie nennt, ist der, aus dem der Verweis
// kommt.*
foreach ([['node', 'nodes'], ['relation', 'relations']] as [$raum, $ziel]) {
    $zielTabelle = Schema::table($ziel);

    $waisen = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$labelTabelle} l
         WHERE l.owner_kind = %s
           AND NOT EXISTS (SELECT 1 FROM {$zielTabelle} z WHERE z.label_id = l.id)",
        $raum
    ));

    check("labels mit owner_kind = {$raum} finden ihren Eintrag in {$ziel}", $waisen === 0, "$waisen Waisen");
}

// ⚠️ *Die Version ist seit Fassung 31 Pflicht (D-634) -- eine Zeile mit Version 0 waere eine, die
// unter dem alten Schema angelegt und beim Aufstieg uebersehen wurde.*
$ohneVersion = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$labelTabelle} WHERE version < 1");

check('jede Beschriftung hat eine Version', $ohneVersion === 0, "$ohneVersion ohne Version");

// ⚠️ **Hier stand der eindeutige Schluessel `one_text` und die Zusage, dass er den Raum nennt.**
// *Er ist mit TASK-019 gefallen: die Beschriftung traegt keine Adresse mehr, sie **ist** die Adresse
// ([D-580](../../docs/NewConcept/90-decision-log.md)). Was an seine Stelle tritt, ist der Schluessel
// von `label_texts` — eine Zeile je Beschriftung, Sprache und Numerus.*
$schluessel = $wpdb->get_col($wpdb->prepare(
    "SELECT COLUMN_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'one_row'
     ORDER BY SEQ_IN_INDEX",
    Schema::table('label_texts')
));

check(
    'der eindeutige Schluessel nennt Beschriftung, Sprache und Numerus',
    $schluessel === ['label_id', 'locale', 'number'],
    implode(', ', $schluessel)
);

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
