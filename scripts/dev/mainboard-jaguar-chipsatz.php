<?php declare(strict_types=1);

/**
 * Sein Befund an den ICs: «headland htk 321 B 322 B» — der Chipsatz HTK320 **besteht** aus HT321 und HT322
 * ([The Retro Web](https://theretroweb.com/chipsets/348)). Damit ist sein Board die IV-Q-Variante, nicht die mit OPTi.
 *
 *     php scripts/dev/mainboard-jaguar-chipsatz.php --write
 *
 * *Die Revision 1.2, die CPU 386DX-33, der Takt 33 MHz und der Hinweis «seins» wandern vom Satz «Jaguar IV 386» zum Satz
 * «Jaguar IV-Q 386DX». Am Chipsatz steht, aus welchen Bausteinen er besteht.*
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
/** @var \Taxmod\Core\Service\ModelEditor $editor */
$editor = (new ReflectionProperty($screen, 'editor'))->getValue($screen);
/** @var \Taxmod\Core\Service\DataEntry $data */
$data    = (new ReflectionProperty($screen, 'data'))->getValue($screen);
$records = new WpdbRecordRepository();

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

const MAINBOARDS = 149000107691, CHIPSAETZE = 149000107688, BEZEICHNUNG = 149000103839, TEXT = 1175;
const EW_WERT = 4234, EW_PREFIX = 4235, EW_EINHEIT = 4236, MEGA = 4002, HERTZ = 4054, CPU_386DX33 = 22915;

$satzMit = static fn (int $knoten, string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
     WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s",
    $knoten,
    BEZEICHNUNG,
    $name
))) === null ? null : (int) $id;

$feldId = static fn (int $knoten, string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
    $knoten,
    $name
))) === null ? null : (int) $id;

$vier = $satzMit(MAINBOARDS, 'Jaguar IV 386');
$q    = $satzMit(MAINBOARDS, 'Jaguar IV-Q 386DX');
$htk  = $satzMit(CHIPSAETZE, 'Headland HTK320');

// 1. Am Chipsatz: aus welchen Bausteinen er besteht.
$bausteine = $feldId(CHIPSAETZE, 'Bausteine');

if ($bausteine === null) {
    echo "Feld «Bausteine» an Chipsätze anlegen\n";

    if ($schreiben) {
        $kante     = $editor->addField(CHIPSAETZE, TEXT, 'Bausteine', RelationKind::Composition);
        $bausteine = $editor->setMultiplicity(CHIPSAETZE, $kante->id, Multiplicity::ZeroToOne)->id;
    }
}

if ($htk !== null && $bausteine !== null && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $htk, $bausteine)) === 0) {
    echo "  Headland HTK320: Bausteine = HT321, HT322\n";

    if ($schreiben) {
        $data->put($htk, $bausteine, TypedValue::ofText('HT321, HT322 (auf seinem Board als HT321 B und HT322 B)'));
    }
}

// 2. Seine Angaben wandern zur IV-Q.
$revision = $feldId(MAINBOARDS, 'Revision');
$hinweis  = $feldId(MAINBOARDS, 'Hinweis');
$cpu      = $feldId(MAINBOARDS, 'CPU');
$takt     = $feldId(MAINBOARDS, 'Takt');

$seins = 'Seins: Revision 1.2, ICs HT321 B und HT322 B — also der Chipsatz HTK320 (HT321 + HT322), mit 386DX-33. '
    . 'Die Quellen führen keine Revision 1.2; die Jumper stehen beim Satz «Jaguar IV 386» derselben Reihe und sind hier ungeprüft.';
$dort  = 'Schwesterboard mit OPTi-Chipsatz, 33 oder 40 MHz. Die Jumper hier stammen von Stason (Ocean Information Systems JAGUAR IV 386) '
    . 'und von MicroHouse (JAGUAR IV 486DLC).';

$setze = static function (?int $satzId, ?int $feldId, string $wert, string $wofuer) use ($data, $wpdb, $p, $schreiben, $records): void {
    if ($satzId === null || $feldId === null) {
        return;
    }

    $steht = $wpdb->get_row($wpdb->prepare("SELECT id, value_text FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $satzId, $feldId));

    if ($steht !== null && (string) $steht->value_text === $wert) {
        return;
    }

    echo "  {$wofuer} → " . mb_substr($wert, 0, 60) . "…\n";

    if ($schreiben) {
        $data->put($satzId, $feldId, TypedValue::ofText($wert));
    }
};

echo "Jaguar IV-Q 386DX (sein Board)\n";
$setze($q, $revision, '1.2', 'Revision');
$setze($q, $hinweis, $seins, 'Hinweis');

if ($q !== null && $cpu !== null && ! in_array(CPU_386DX33, array_map(intval(...), $wpdb->get_col($wpdb->prepare("SELECT value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $q, $cpu))), true)) {
    echo "  CPU 80386DX-33 anhängen\n";

    if ($schreiben) {
        $data->appendValue($q, $cpu, TypedValue::ofRecordReference(CPU_386DX33));
    }
}

$taktZahlen = [];

foreach ($wpdb->get_col($wpdb->prepare("SELECT value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $q, $takt)) as $teilId) {
    $taktZahlen[] = rtrim(rtrim((string) $wpdb->get_var($wpdb->prepare("SELECT value_decimal FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", (int) $teilId, EW_WERT)), '0'), '.');
}

if (! in_array('33', $taktZahlen, true)) {
    echo "  Takt 33 MHz anhängen\n";

    if ($schreiben) {
        $teil = $data->createPart($q, $takt);
        $data->put($teil->id, EW_WERT, TypedValue::ofDecimal('33'));
        $data->put($teil->id, EW_EINHEIT, TypedValue::ofReference(HERTZ));
        $data->put($teil->id, EW_PREFIX, TypedValue::ofReference(MEGA));
    }
}

echo "Jaguar IV 386 (Schwesterboard)\n";
$setze($vier, $hinweis, $dort, 'Hinweis');

if ($vier !== null && $revision !== null) {
    $zeile = $wpdb->get_row($wpdb->prepare("SELECT id, value_text FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $vier, $revision));

    if ($zeile !== null && (string) $zeile->value_text === '1.2') {
        echo "  Revision 1.2 entfernen — sie gehört zu seinem Board\n";

        if ($schreiben) {
            $records->forgetValueById((int) $zeile->id);
        }
    }
}

echo $schreiben ? "geschrieben\n" : "trocken — nichts geschrieben\n";
