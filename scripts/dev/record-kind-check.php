<?php declare(strict_types=1);

/**
 * Vier Satzarten, und die Einstellungen wohnen in der vierten — und die Grenzen in den Grenzknoten.
 *
 *     php scripts/dev/record-kind-check.php [path/to/wordpress]
 *
 * ⚠️ **Zwei Beschlüsse des Eigentümers vom 2026-09-09:** [D-704](../../docs/NewConcept/90-decision-log.md)
 * *«ich finde es auch das die vier arten es genauer machen sollten wir so festlegen»* — und
 * [D-707](../../docs/NewConcept/90-decision-log.md) *«ja wenn nichts in der kante gesetzt ist gilt der
 * Wert des Zielknoten (wenn einer da ist)»*.
 *
 * ```mermaid
 * flowchart LR
 *   B["Bestand"] -->|"jede Einstellungszeile"| S["liegt in einem settings-Satz"]
 *   B -->|"kein default/user/example"| N["trägt eine Einstellungszeile"]
 *   W["schreiben an einem frischen Knoten"] --> S2["legt einen settings-Satz an, keinen default"]
 *   V["Verwendungsstelle schreiben"] --> S3["ihr Satz ist settings"]
 *   G["Integer ohne Kantenwert"] -->|"Kette"| M["min aus integer_min, max aus integer_max"]
 *   K["Kind setzt min enger"] --> K2["näher schlägt ferner"]
 * ```
 *
 * ⚠️ *Gemessen am Bestand **und** an einer eigenen Wiese (Präfix `__rk`): der Bestand sagt, dass die
 * Fassung 42 gelaufen ist; die Wiese sagt, dass der Rand seither richtig schreibt.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende alles zurück.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Model\Type\DecimalType;
use Taxmod\Core\Model\Type\IntType;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

register_shutdown_function(static fn (): int => Schema::forgetOrphanLabels());

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  ok   {$what}\n";

        return;
    }

    $failed++;
    echo "  FAIL {$what}" . ($detail === '' ? '' : " — {$detail}") . "\n";
}

global $wpdb;

$saetze    = Schema::table('node_records');
$werte     = Schema::table('relation_records');
$kanten    = Schema::table('relations');
$benannt   = Schema::table('nodes_named');

$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$rows      = new WpdbRecordRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $framework, $log, new WpdbLabelRepository(), $rows);
$data      = new DataEntry($rows, $relations, $nodes, $framework, new SystemClock(), $log);

echo "== 1. der Bestand nach Fassung 42 (D-704) ==\n";

$falsch = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT s.id) FROM {$saetze} s
       JOIN {$werte} v ON v.node_record_id = s.id
       JOIN {$kanten} r ON r.id = v.relation_id AND r.kind = 'setting'
      WHERE s.record_type <> 'settings'"
);
check('jede Einstellungszeile liegt in einem settings-Satz', $falsch === 0, "{$falsch} Sätze anderer Art tragen eine");

$fremd = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$werte} v JOIN {$saetze} s ON s.id = v.node_record_id
       LEFT JOIN {$kanten} r ON r.id = v.relation_id
      WHERE s.record_type = 'settings' AND v.relation_id <> 0 AND (r.kind IS NULL OR r.kind <> 'setting')"
);
check('und ein settings-Satz trägt nichts anderes', $fremd === 0, "{$fremd} fremde Zeilen");

$stellen = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$saetze} WHERE relation_id <> 0 AND record_type <> 'settings'");
check('der Satz einer Verwendungsstelle ist settings (D-702)', $stellen === 0, "{$stellen} andere");

$doppelt = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM (SELECT node_id, relation_id FROM {$saetze} WHERE record_type = 'settings' GROUP BY node_id, relation_id HAVING COUNT(*) > 1) d"
);
check('einer je Adresse, nie zwei (D-538)', $doppelt === 0, "{$doppelt} Adressen mit mehreren");

echo "\n== 2. die Grenzen kommen aus dem Vertrag des Typs (D-712) ==\n";

// ⚠️ **Hier stand «die Grenzen wohnen in den Grenzknoten (D-707)»** — *`integer_min`, `integer_max`, `decimal_min`,
// `decimal_max` als Knoten unter den Typen, je mit einem default-Satz. Seit Schritt 7 des Bauplans (2026-09-11)
// sind sie gefallen (sein Wort: «K3a ja, fallen», [D-719](../../docs/NewConcept/90-decision-log.md)); min, max und
// step erklärt der Vertrag der Typklasse, und ohne Zeile in `settings_value` gilt seine Vorgabe.*
$integer = $editor->nodeImplementing(IntType::class);
$vertragInt = \Taxmod\Core\Model\NodeClass\Contracts::of(IntType::class);
check('der Vertrag von Integer erklärt min, max und step', $vertragInt->attribute('min') !== null && $vertragInt->attribute('max') !== null && $vertragInt->attribute('step') !== null);
check('kein Knoten `integer_min` oder `decimal_max` steht mehr unter den Typen', (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::table('nodes_named') . " WHERE name IN ('integer_min', 'integer_max', 'integer_step', 'decimal_min', 'decimal_max', 'decimal_step')") === 0);
check('und `Integer` trägt keine Einstellungssätze mehr', $integer !== null && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$saetze} WHERE node_id = %d AND record_type = 'settings'", $integer->id)) === 0);

echo "\n== 3. die Wiese: der Rand schreibt seit Fassung 42 richtig ==\n";

$modell = $editor->createNode('__rk Modell', $framework->rootOf(Branch::Model)->id);
$zahl   = $editor->createNode('__rk Zahl', $integer->id);
// ⚠️ *Ein direktes Kind von `Integer` ist ein Geschwister von `integer_min` und erbt die Kante `min` nicht
// ([D-686](../../docs/NewConcept/90-decision-log.md)) — der Enkel erbt sie wieder.*
$enkel  = $editor->createNode('__rk Enkel', $zahl->id);

check('ein frischer Knoten hat keinen Satz', $data->recordsOf($modell->id) === []);

// ⚠️ *Bis Schritt 2 des Bauplans (2026-09-11) stand hier `read_only` — die Kante ist mit Fassung 48
// gewandert ([D-714](../../docs/NewConcept/90-decision-log.md)); `validator` ist eine
// Einstellungskante an der Wurzel, die geblieben ist und einen Namen als Wert nimmt.*

echo "\n{$passed} ok, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
