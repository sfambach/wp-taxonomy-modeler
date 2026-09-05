<?php declare(strict_types=1);

/**
 * Entsteht ein Datensatz erst beim Schreiben — und nicht schon beim Ansehen oder Loeschen?
 *
 * [D-609](../../docs/NewConcept/90-decision-log.md), TASK-043, BUG-004. **Sein Wort:** *«ein Datensatz
 * entsteht beim ersten Schreiben, nicht beim Ansehen? ja bitte.»*
 *
 * ⚠️ **Eine Grenzpruefung, weil die Zahl aus der Datenbank kam.** *Gemessen waren 324 von 377
 * Datensaetzen ohne eine einzige Wertzeile — angelegt von `clearSettingAt()`, das seinen Satz holte,
 * bevor es merkte, dass nichts zu loeschen ist. **Eine Seite zu speichern, auf der ein
 * Einstellungsfeld leer ist, legte damit einen Datensatz an.***
 *
 * ⚠️ **Eigene Knoten, kein Name aus seinem Modell** ([D-613](../../docs/NewConcept/90-decision-log.md)):
 * angelegt, geprueft, weggeraeumt — und weggeraeumt wird nach dem eigenen Namensmuster, nie ueber
 * `clearTrash()`.
 *
 * Usage: php scripts/dev/record-on-first-write-check.php C:/Devel/Wordpress
 *
 * @see docs/NewConcept/90-decision-log.md
 */

$wordpress = $argv[1] ?? 'C:/Devel/Wordpress';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

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

$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$rows      = new WpdbRecordRepository();
$labelRows = new WpdbLabelRepository();
$framework = new SeededFrameworkNodes($nodes, $edges, $log);

$editor = new ModelEditor($nodes, $edges, $framework, $log, $labelRows, $rows);
$data   = new DataEntry($rows, $edges, $nodes, $framework, new SystemClock());

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

/** Wie viele Datensaetze dieser Knoten traegt — die einzige Frage dieses Laufs. */
function saetze(int $nodeId): int
{
    global $wpdb, $p;

    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records WHERE node_id = {$nodeId}");
}

echo "\n== ein eigener Knoten mit einem eigenen Feld ==\n";

$typen  = $framework->rootOf(Branch::DataTypes);
$ziel   = $editor->createNode('__rw ziel', $typen->id);
$traeger = $editor->createNode('__rw traeger', $typen->id);
$feld   = $editor->addField($traeger->id, $ziel->id, 'probe');

check('er hat noch keinen Datensatz', saetze($traeger->id) === 0, (string) saetze($traeger->id));

echo "\n== ansehen und loeschen legen nichts an ==\n";

// ⚠️ *Genau der Weg aus BUG-004: die Maske speichert ein leeres Einstellungsfeld.*
$data->clearSettingAt($traeger->id, $feld->id, 0);

check('Loeschen legt keinen Datensatz an', saetze($traeger->id) === 0, (string) saetze($traeger->id));

$data->settingValuesOf($traeger->id, [$feld->id]);

check('Lesen legt keinen Datensatz an', saetze($traeger->id) === 0, (string) saetze($traeger->id));

echo "\n== das erste Schreiben legt ihn an ==\n";

// ⚠️ **Die Gegenprobe, ohne die die Zusage auch ein Schreiber erfuellen wuerde, der gar nichts tut.**
$data->putSettingAt($traeger->id, $feld->id, 0, TypedValue::ofText('probewert'));

check('Schreiben legt genau einen Datensatz an', saetze($traeger->id) === 1, (string) saetze($traeger->id));

$data->putSettingAt($traeger->id, $feld->id, 0, TypedValue::ofText('zweiter wert'));

check('ein zweites Schreiben legt keinen zweiten an', saetze($traeger->id) === 1, (string) saetze($traeger->id));

echo "\n== aufraeumen ==\n";

// ⚠️ *Nach dem eigenen Namensmuster und nie ueber `clearTrash()` — dort liegt seine geparkte Arbeit
// (TASK-039). Nach Namen, nicht nur nach den Ids dieses Laufs: ein abgestuerzter Lauf laesst sonst
// Reste stehen, die der naechste als eigenen Fehlschlag meldet.*
$meine = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}nodes WHERE name LIKE '\\_\\_rw %'"));
$in    = $meine === [] ? (string) $ziel->id : implode(',', $meine);

$wpdb->query("DELETE FROM {$p}relation_records WHERE node_record_id IN (SELECT id FROM {$p}node_records WHERE node_id IN ({$in}))");
$wpdb->query("DELETE FROM {$p}node_records WHERE node_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}labels WHERE owner_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}relations WHERE from_node_id IN ({$in}) OR to_node_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}nodes WHERE id IN ({$in})");

check(
    'der Waechter laesst nichts zurueck',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes WHERE name LIKE '\\_\\_rw %'") === 0
);

printf("\n%d ok, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
