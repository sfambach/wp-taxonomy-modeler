<?php declare(strict_types=1);
/**
 * Der Wächter für den **Träger** eines Einstellungsdatensatzes — heute eine Kante.
 *
 *     php scripts/dev/settings-record-carrier-check.php [path/to/wordpress]
 *
 * ⚠️ **Er hiess `settings-record-column-check` und bewachte eine Spalte. Die Spalte ist weg**
 * (TASK-057, [D-642](../../docs/NewConcept/90-decision-log.md)): *der Eigentümer hat berichtigt, was
 * ich aus seinem Satz gemacht hatte — «ich meinte einfach eine Multiplizität von 1», **am Knoten**,
 * an einer gewöhnlichen Einstellungskante. **Die Zusagen sind dieselben geblieben und mitgezogen**;
 * dazu kam eine neue: die drei Spalten dürfen nicht zurückkommen.*
 *
 * ⚠️ **Sie entstand aus einem Schaden, den nichts gemeldet hat.** *Der Eigentümer löschte den
 * Hüllknoten `DisplayOption` ([D-604](../../docs/NewConcept/90-decision-log.md)). Danach hingen
 * **29 Renderer-Wahlen an einer Kante, die es nicht mehr gab** — und alle Randprüfungen, die
 * überhaupt etwas dazu sagten, meldeten «kein Renderer», nicht «der Halter ist weg». **Der
 * Unterschied zwischen «nichts eingestellt» und «die Einstellung ist unerreichbar geworden» war
 * nirgends zu sehen.***
 *
 * ⚠️ **Zwei Zusagen, und beide sind Richtungen derselben Sache:**
 * *keine Wertzeile hängt an einer Kante, die es nicht gibt — und kein Träger an der
 * Einstellungskante `renderer` zeigt auf einen Datensatz, den es nicht gibt.*
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

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\SettingKey;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;

$framework = new SeededFrameworkNodes(
    new WpdbNodeRepository(),
    new WpdbRelationRepository(),
    new WpdbChangelog(new SystemClock())
);
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

echo "\n== Ein gefuellter Traeger zeigt auf einen Datensatz, den es gibt ==\n";

// ⚠️ **Diese drei Zusagen sind mit TASK-057 umgezogen, nicht entschaerft**
// ([D-642](../../docs/NewConcept/90-decision-log.md)). *Sie fragten `nodes.settings_record_id`; die
// Spalte gibt es seit Fassung 32 nicht mehr, weil der Eigentuemer berichtigt hat, was ich aus
// seinem Satz gemacht hatte: «ich meinte einfach eine Multiplizitaet von 1» — **am Knoten, an einer
// gewoehnlichen Einstellungskante**. Dieselben drei Fragen stehen hier weiter, an der Kante.*
//
// ⚠️ **An der Kante ist der Renderer ersatzlos gefallen** ([D-643](../../docs/NewConcept/90-decision-log.md)),
// *also sind die beiden Kantenzeiger hier ohne Nachfolger: `relations.settings_record_id` und
// `relations.target_settings_record_id` hatten **je 0 Zeilen** — nie belegt, seit es sie gab.*
$kante = $framework->settingRelationId(SettingKey::Renderer);

check('die Einstellungskante `renderer` ist aufgeschrieben', $kante !== 0, (string) $kante);

$traeger = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . " WHERE relation_id = %d AND value_ref_kind = 'record'",
    $kante
));

// ⚠️ *Der Gegenfall, und ohne ihn waere alles hierunter auch bei leerer Kante gruen. **Gemessen am
// 2026-09-05 sind es 29** — die Wahlen, die TASK-057 aus der Spalte zurueckgeholt hat. Die Zahl
// darf steigen und nie unter eins fallen.*
check('die Einstellungskante ist ueberhaupt in Gebrauch', $traeger > 0, (string) $traeger);
printf("  --   %d Knoten tragen ihre Renderer-Wahl an dieser Kante\n", $traeger);

$insLeere = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' v
       LEFT JOIN ' . Schema::table('node_records') . ' s ON s.id = v.value_ref
      WHERE v.relation_id = %d AND v.value_ref_kind = \'record\' AND s.id IS NULL',
    $kante
));

check('kein Traeger zeigt ins Leere', $insLeere === 0, (string) $insLeere);

// ⚠️ **Und die Spalten duerfen nicht zurueckkommen** (Fassung 32). *`dbDelta` legt eine fehlende
// Spalte wieder an; kaeme eine der drei zurueck, haette der Renderer wieder zwei Orte — und das
// war der Zustand, aus dem TASK-052 entstand.*
foreach ([['nodes', 'settings_record_id'], ['relations', 'settings_record_id'], ['relations', 'target_settings_record_id']] as [$tabelle, $spalte]) {
    $name = Schema::table($tabelle);

    check(
        "die Spalte «{$tabelle}.{$spalte}» ist weg und bleibt weg",
        $wpdb->get_var("SHOW COLUMNS FROM {$name} LIKE '{$spalte}'") === null
    );
}

echo "\n== Und der Traeger nennt einen Knoten, den es gibt ==\n";

// ⚠️ *Ein Satz ohne Knoten sagt nicht, welcher Renderer er ist — der Traeger fuehrt dann formal
// irgendwohin und inhaltlich nirgends. **Das ist genau der Zustand, in dem die 29 Wahlen nach dem
// Loeschen des Huellknotens waren** ([D-604](../../docs/NewConcept/90-decision-log.md)).*
$ohneKnoten = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' v
       JOIN ' . Schema::table('node_records') . ' s ON s.id = v.value_ref
       LEFT JOIN ' . Schema::table('nodes') . ' z ON z.id = s.node_id
      WHERE v.relation_id = %d AND v.value_ref_kind = \'record\' AND z.id IS NULL',
    $kante
));

check('jeder Einstellungssatz gehoert einem Knoten', $ohneKnoten === 0, (string) $ohneKnoten);

echo "\n{$bad} fehlgeschlagen, {$ok} in Ordnung\n";

exit($bad === 0 ? 0 : 1);
