<?php declare(strict_types=1);
/**
 * Kein Verweis ohne Raumangabe — und die Angabe stimmt mit dem überein, worauf die Id zeigt.
 *
 *     php scripts/dev/value-ref-space-check.php [path/to/wordpress]
 *
 * ⚠️ **Die Zusage von TASK-005.** *`relation_records.value_ref` zeigt auf **Knoten und Datensätze**
 * (gemessen am 2026-09-04: 50 gegen 93), und bis Schema 20 sagte nichts, auf welches von beiden.
 * Solange jede Tabelle ihre Ids aus `identities` zieht, ist das schadlos — eine Nummer gehört genau
 * einem Ding. **Nach TASK-004 hat jede Tabelle ihren eigenen Id-Raum**, dann gibt es Knoten 5 und
 * Datensatz 5, und ohne `value_ref_kind` ist die Spalte nicht mehr lesbar
 * ([D-164](../../docs/NewConcept/90-decision-log.md),
 * [`package.md` §6](../../docs/pakete/modelltabellen/package.md)).*
 *
 * ⚠️ **Der Lauf prüft dreierlei:** *(1) jede lebende Verweiszeile nennt ihren Raum, (2) der genannte
 * Raum ist einer der beiden bekannten, (3) die Id ist in der genannten Tabelle wirklich vorhanden.
 * **Der dritte Punkt ist der, der nach TASK-004 die Arbeit tut** — heute fiele er auch ohne die
 * Spalte auf, danach nur noch mit ihr.*
 *
 * ⚠️ **Die Schattentabelle wird ausdrücklich nur gezählt und nicht verlangt.** *Gemessen tragen 810
 * ihrer Zeilen einen Verweis; 393 lösen sich eindeutig auf einen Knoten auf, 58 auf einen Datensatz,
 * **und 359 zeigen auf nichts Lebendes mehr oder sind mehrdeutig**. Für alte Fassungen gelöschter
 * Zeilen lässt sich der Raum nicht mehr ermitteln, und **geraten wird nicht** (`PR-4`). Der Wächter
 * hält deshalb die Zusage für die lebenden Daten und macht die Lücke im Schatten sichtbar, statt
 * sie zu verstecken.*
 *
 * ⚠️ *Dieser Lauf schreibt nichts und legt nichts an — er liest nur, also gibt es auch nichts
 * wegzuräumen.*
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

use Taxmod\Core\Model\ReferenceSpace;
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

$werte   = Schema::table('relation_records');
$schatten = Schema::table('relation_records_history');
$knoten  = Schema::table('nodes');
$saetze  = Schema::table('node_records');

echo "Die Spalte ist da\n";

$spalte = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
    $werte,
    'value_ref_kind'
));

check('relation_records nennt den Raum', $spalte === 1);

if ($spalte !== 1) {
    echo "\n$bad fehlgeschlagen, $ok in Ordnung\n";
    exit(1);
}

echo "\nJeder lebende Verweis nennt seinen Raum\n";

// ⚠️ **Eine Abfrage über alle Zeilen und keine je Zeile** (`CD-7`). *Die drei Fragen sind drei
// Spalten derselben Zeile, nicht drei Läufe.*
$offen = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$werte} WHERE value_ref IS NOT NULL AND value_ref_kind IS NULL"
);

check('kein value_ref ohne Raumangabe', $offen === 0, "$offen Zeilen ohne Angabe");

$erlaubt = array_map(static fn (ReferenceSpace $r): string => $r->value, ReferenceSpace::cases());
$platz   = implode(',', array_fill(0, count($erlaubt), '%s'));

$fremd = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$werte}
     WHERE value_ref_kind IS NOT NULL AND value_ref_kind NOT IN ({$platz})",
    ...$erlaubt
));

check('und kein Raum, den der Kern nicht kennt', $fremd === 0, "$fremd unbekannte Angaben");

$leer = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$werte} WHERE value_ref IS NULL AND value_ref_kind IS NOT NULL"
);

check('eine Raumangabe ohne Verweis gibt es nicht', $leer === 0, "$leer Zeilen");

echo "\nUnd der Raum stimmt mit dem überein, worauf die Id zeigt\n";

$falschKnoten = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$werte} v
     WHERE v.value_ref_kind = 'node'
       AND NOT EXISTS (SELECT 1 FROM {$knoten} n WHERE n.id = v.value_ref)"
);

check('«node» findet seinen Knoten', $falschKnoten === 0, "$falschKnoten ins Leere");

$falschSatz = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$werte} v
     WHERE v.value_ref_kind = 'record'
       AND NOT EXISTS (SELECT 1 FROM {$saetze} r WHERE r.id = v.value_ref)"
);

check('«record» findet seinen Datensatz', $falschSatz === 0, "$falschSatz ins Leere");

$verteilung = $wpdb->get_row(
    "SELECT SUM(value_ref_kind = 'node') AS k, SUM(value_ref_kind = 'record') AS d
     FROM {$werte} WHERE value_ref IS NOT NULL",
    ARRAY_A
) ?: ['k' => '0', 'd' => '0'];

printf("       gemessen: %d Knotenverweise, %d Datensatzverweise\n", (int) $verteilung['k'], (int) $verteilung['d']);

echo "\nDer Schatten trägt die Spalte, gefüllt so weit es ging\n";

$schattenSpalte = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
    $schatten,
    'value_ref_kind'
));

check('relation_records_history hat sie auch', $schattenSpalte === 1);

$schattenOffen = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$schatten} WHERE value_ref IS NOT NULL AND value_ref_kind IS NULL"
);

// ⚠️ *Gezählt und nicht verlangt — siehe der Kopf dieser Datei.*
printf("       %d alte Fassungen ohne Raumangabe (nicht mehr ermittelbar)\n", $schattenOffen);

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
