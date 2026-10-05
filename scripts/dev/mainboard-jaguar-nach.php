<?php declare(strict_types=1);

/**
 * Nachtrag zum Exkurs Mainboards: der zweite Takt der Jaguar IV 386 und die 40-MHz-CPU der IV-Q.
 *
 *     php scripts/dev/mainboard-jaguar-nach.php --write
 *
 * *Der erste Lauf schrieb je Satz nur einen Takt (die Prüfung «steht schon etwas» traf auch das zweite Glied), und beiden Boards stand
 * dieselbe CPU. Die IV-Q läuft mit 40 MHz; dafür fehlte ein Satz `Am386DX-40` unter CPUs.*
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
/** @var \Taxmod\Core\Service\DataEntry $data */
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

const CPUS = 149000103845, MAINBOARDS = 149000107691;
const BEZEICHNUNG = 149000103839, HERSTELLER = 149000102544, CPU_TAKT = 149000103842, CPU_FAMILIE = 149000103843;
const AMD = 22660, FAMILIE_386 = 24196;
const EW_WERT = 4234, EW_PREFIX = 4235, EW_EINHEIT = 4236, MEGA = 4002, HERTZ = 4054;

$board = static fn (string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
     WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s",
    MAINBOARDS,
    BEZEICHNUNG,
    $name
))) === null ? null : (int) $id;

$feldId = static fn (string $name): int => (int) $wpdb->get_var($wpdb->prepare(
    "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
    MAINBOARDS,
    $name
));

$takt = $feldId('Takt');
$cpu  = $feldId('CPU');
$vier = $board('Jaguar IV 386');
$q    = $board('Jaguar IV-Q 386DX');

// 1. Der zweite Takt der Jaguar IV 386: 40 MHz neben 33 MHz.
$taktZahlen = [];

foreach ($wpdb->get_col($wpdb->prepare("SELECT value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $vier, $takt)) as $teilId) {
    $taktZahlen[] = rtrim(rtrim((string) $wpdb->get_var($wpdb->prepare("SELECT value_decimal FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", (int) $teilId, EW_WERT)), '0'), '.');
}

echo 'Jaguar IV 386, Takt bisher: ', implode(', ', $taktZahlen), "\n";

if (! in_array('40', $taktZahlen, true)) {
    echo "  40 MHz anhängen\n";

    if ($schreiben) {
        $teil = $data->createPart($vier, $takt);
        $data->put($teil->id, EW_WERT, TypedValue::ofDecimal('40'));
        $data->put($teil->id, EW_EINHEIT, TypedValue::ofReference(HERTZ));
        $data->put($teil->id, EW_PREFIX, TypedValue::ofReference(MEGA));
    }
}

// 2. Eine CPU für 40 MHz: Am386DX-40, und sie an beide Boards.
$am386 = $wpdb->get_var($wpdb->prepare(
    "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
     WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s",
    CPUS,
    BEZEICHNUNG,
    'Am386DX-40'
));

if ($am386 === null) {
    echo "CPU-Satz «Am386DX-40» anlegen (AMD, Familie wie 80386DX-33)\n";

    if ($schreiben) {
        $neu = $data->create(CPUS, RecordType::User);
        $data->put($neu->id, BEZEICHNUNG, TypedValue::ofText('Am386DX-40'));
        $data->put($neu->id, HERSTELLER, TypedValue::ofRecordReference(AMD));
        $data->put($neu->id, CPU_FAMILIE, TypedValue::ofRecordReference(FAMILIE_386));
        $teil = $data->createPart($neu->id, CPU_TAKT);
        $data->put($teil->id, EW_WERT, TypedValue::ofDecimal('40'));
        $data->put($teil->id, EW_EINHEIT, TypedValue::ofReference(HERTZ));
        $data->put($teil->id, EW_PREFIX, TypedValue::ofReference(MEGA));
        $am386 = $neu->id;
    }
}

foreach ([[$vier, 'Jaguar IV 386', true], [$q, 'Jaguar IV-Q 386DX', false]] as [$satzId, $name, $behaeltDreiDrei]) {
    $steht = array_map(intval(...), $wpdb->get_col($wpdb->prepare(
        "SELECT value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d",
        $satzId,
        $cpu
    )));

    echo "{$name}: CPU bisher ", implode(', ', $steht), "\n";

    if ($am386 !== null && ! in_array((int) $am386, $steht, true)) {
        echo "  Am386DX-40 anhängen\n";

        if ($schreiben) {
            $data->appendValue($satzId, $cpu, TypedValue::ofRecordReference((int) $am386));
        }
    }

    // ⚠️ *Die IV-Q läuft mit 40 MHz; der 33er stand dort nur, weil der erste Lauf beiden dieselbe CPU gab.*
    if (! $behaeltDreiDrei && in_array(22915, $steht, true)) {
        echo "  80386DX-33 entfernen\n";

        if ($schreiben) {
            $zeile = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d AND value_ref = 22915",
                $satzId,
                $cpu
            ));
            (new Taxmod\WordPress\Persistence\WpdbRecordRepository())->forgetValueById($zeile);
        }
    }
}

echo $schreiben ? "geschrieben\n" : "trocken — nichts geschrieben\n";
