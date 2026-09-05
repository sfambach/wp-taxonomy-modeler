<?php declare(strict_types=1);
/**
 * Der Wächter für `settings_record_id` — die Spalte aus TASK-020.
 *
 *     php scripts/dev/settings-record-column-check.php [path/to/wordpress]
 *
 * ⚠️ **Sie entstand aus einem Schaden, den nichts gemeldet hat.** *Der Eigentümer löschte den
 * Hüllknoten `DisplayOption` ([D-604](../../docs/NewConcept/90-decision-log.md)). Danach hingen
 * **29 Renderer-Wahlen an einer Kante, die es nicht mehr gab** — und alle Randprüfungen, die
 * überhaupt etwas dazu sagten, meldeten «kein Renderer», nicht «der Halter ist weg». **Der
 * Unterschied zwischen «nichts eingestellt» und «die Einstellung ist unerreichbar geworden» war
 * nirgends zu sehen.***
 *
 * ⚠️ **Zwei Zusagen, und beide sind Richtungen derselben Sache:**
 * *keine Wertzeile hängt an einer Kante, die es nicht gibt — und kein gefülltes
 * `settings_record_id` zeigt auf einen Datensatz, den es nicht gibt.*
 *
 * @see docs/NewConcept/90-decision-log.md
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

echo "\n== Keine Wertzeile haengt an einer Kante, die es nicht gibt ==\n";

// ⚠️ **Die drei bekannten Reste sind benannt und gedeckelt, nicht weggeschaut.** *Sie stehen im
// Satz 2233 an den gefallenen Kanten `render` (44091) und `converter` (44092) und sind die
// **Neuform**-Zeilen, die `displayoption-migrate.php` bei TASK-024 ausdrücklich hat stehenlassen —
// ihre Beseitigung ist eine Entscheidung über Teile und steht in
// `docs/pakete/modelltabellen/inbox.md`. **Die Zahl darf fallen und nie steigen:** steigt sie, hat
// wieder etwas seinen Halter verloren, und genau davor schützt diese Prüfung.*
// ⚠️ *Die vierte ist der Halter der **Wurzel** — `INF-014`. Er zeigt auf einen Satz von `checkbox`,
// und in der Spalte träfe er über die Vorfahrenkette jeden Knoten des Modells. **Gemessen: zwei
// grüne Prüfungen wurden davon rot.** Er bleibt darum an seiner toten Kante stehen, bis jemand über
// ihn entscheidet.*
$bekannteReste = 4;

$tote = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' v
       LEFT JOIN ' . Schema::table('relations') . ' r ON r.id = v.relation_id
      WHERE r.id IS NULL'
);

check(
    "hoechstens die {$bekannteReste} benannten Reste stehen an toten Kanten",
    $tote <= $bekannteReste,
    $tote . ' statt hoechstens ' . $bekannteReste
);

$listeTot = $wpdb->get_results(
    'SELECT v.relation_id, COUNT(*) AS wieviele FROM ' . Schema::table('relation_records') . ' v
       LEFT JOIN ' . Schema::table('relations') . ' r ON r.id = v.relation_id
      WHERE r.id IS NULL GROUP BY v.relation_id'
);

foreach ($listeTot as $zeile) {
    printf("  --   Kante %d traegt noch %d Zeile(n)\n", (int) $zeile->relation_id, (int) $zeile->wieviele);
}

echo "\n== Ein gefuellter Zeiger zeigt auf einen Datensatz, den es gibt ==\n";

$gefuelltAmKnoten = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' WHERE settings_record_id IS NOT NULL'
);

// ⚠️ *Der Gegenfall, und ohne ihn wäre alles hierunter auch bei leerer Spalte grün. **Gemessen am
// 2026-09-04 sind es 29** — die Wahlen, die TASK-020 in die Spalte gebracht hat. Die Zahl darf
// steigen und nie unter eins fallen.*
check('die Spalte am Knoten ist ueberhaupt in Gebrauch', $gefuelltAmKnoten > 0, (string) $gefuelltAmKnoten);
printf("  --   %d Knoten tragen einen Einstellungszeiger\n", $gefuelltAmKnoten);

$insLeereAmKnoten = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' n
       LEFT JOIN ' . Schema::table('node_records') . ' s ON s.id = n.settings_record_id
      WHERE n.settings_record_id IS NOT NULL AND s.id IS NULL'
);

check('kein Knotenzeiger ins Leere', $insLeereAmKnoten === 0, (string) $insLeereAmKnoten);

foreach (['settings_record_id', 'target_settings_record_id'] as $spalte) {
    $insLeere = (int) $wpdb->get_var(
        'SELECT COUNT(*) FROM ' . Schema::table('relations') . ' k
           LEFT JOIN ' . Schema::table('node_records') . ' s ON s.id = k.' . $spalte . '
          WHERE k.' . $spalte . ' IS NOT NULL AND s.id IS NULL'
    );

    check("kein Kantenzeiger «{$spalte}» ins Leere", $insLeere === 0, (string) $insLeere);
}

echo "\n== Und der Zeiger nennt einen Knoten, den es gibt ==\n";

// ⚠️ *Ein Satz ohne Knoten sagt nicht, welcher Renderer er ist — der Zeiger führt dann formal
// irgendwohin und inhaltlich nirgends. **Das ist genau der Zustand, in dem die 29 Wahlen vor
// TASK-020 waren**, nur eine Stufe später.*
$ohneKnoten = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' n
       JOIN ' . Schema::table('node_records') . ' s ON s.id = n.settings_record_id
       LEFT JOIN ' . Schema::table('nodes') . ' z ON z.id = s.node_id
      WHERE n.settings_record_id IS NOT NULL AND z.id IS NULL'
);

check('jeder Einstellungssatz gehoert einem Knoten', $ohneKnoten === 0, (string) $ohneKnoten);

echo "\n{$bad} fehlgeschlagen, {$ok} in Ordnung\n";

exit($bad === 0 ? 0 : 1);
