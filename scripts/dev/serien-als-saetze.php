<?php declare(strict_types=1);

/**
 * Serien werden eigene Sätze: «Bauteil-Serien» mit kurzem Namen, Hersteller und Beschreibung (2026-09-20).
 *
 *     php scripts/dev/serien-als-saetze.php            # nur zeigen
 *     php scripts/dev/serien-als-saetze.php --write    # umbauen
 *
 * ⚠️ *Seine Worte ([D-890](../../docs/NewConcept/90-decision-log.md)): «Serien scheinen eine Gruppierung zu sein, damit würde
 * die Serie als eine Entität entstehen, mit zusätzlichen Infos. Und einen kurzen Titel, den wir wiederum verwenden könnten?»,
 * dann «ja fang mit den serien an, gruppe und serie ist dasselbe muster».*
 *
 * *Der Grund steht in den Daten: «Serie» war ein Text und trug halbe Steckbriefe — «90331 (Industry Standard Power Supply
 * Housing); Gegenstück KK 396». Die gerechnete Bezeichnung eines Steckers wurde damit unlesbar lang. Der kurze Name gehört
 * in die Bezeichnung der Serie, der Rest in ihre Beschreibung.*
 *
 * Wiederholbar: Knoten, Felder und Sätze werden am Namen erkannt.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\TypedValue;
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
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Gemessen am 2026-09-20
const HARDWARE_PROJECT = 18507, ELECTRONIC_PARTS = 3634, SERIE_ALT = 149000108566;
const BEZEICHNUNG = 149000103839, BESCHREIBUNG = 149000107372, HERSTELLERLISTE = 149000102677, HERSTELLER_NAME = 149000102666;
const MEDIUM = 149000107775, EINHEITENWERT = 4232;

/** Satz ⇒ kurzer Name und Beschreibung — von Hand gelesen, weil ein Text kein Feld ist. */
$serienJeSatz = [
    32670 => ['Molex 90331', 'Industry Standard Power Supply Housing; Gegenstück KK 396.', 'Molex'],
    32700 => ['Molex 8981', 'Disk Drive Power, Kontakte der Serie 8980; gleichwertig TE Commercial MATE-N-LOK.', 'Molex'],
    32720 => ['AMP EI', 'EI / EIS — Economy Interconnection System.', 'TE Connectivity (AMP)'],
    32736 => ['Molex Mini-Fit Jr.', 'Gehäuse 5557, Stiftleiste 5566.', 'Molex'],
];

$knoten = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", 'Bauteil-Serien', HARDWARE_PROJECT));

if ($knoten === null) {
    echo "Knoten «Bauteil-Serien» unter Hardware Project\n";
    $knoten = $schreiben ? $editor->createNode('Bauteil-Serien', HARDWARE_PROJECT, Category::class)->id : null;
}

$feldAn = static fn (int $eigner, string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
    $eigner,
    $name
))) === null ? null : (int) $id;

if ($knoten !== null) {
    foreach ([['Hersteller', HERSTELLERLISTE, RelationKind::Aggregation], ['Rastermaß', EINHEITENWERT, RelationKind::Composition], ['Links', MEDIUM, RelationKind::Composition]] as [$name, $ziel, $art]) {
        if ($feldAn((int) $knoten, $name) !== null) {
            continue;
        }

        echo "Feld «{$name}» an Bauteil-Serien\n";

        if ($schreiben) {
            $kante = $editor->addField((int) $knoten, $ziel, $name, $art);
            $editor->setMultiplicity((int) $knoten, $kante->id, $name === 'Links' ? Multiplicity::ZeroToMany : Multiplicity::ZeroToOne);
        }
    }
}

$herstellerFeld = $knoten === null ? null : $feldAn((int) $knoten, 'Hersteller');
$serieFuer      = [];

foreach ($serienJeSatz as $teil => [$kurz, $beschreibung, $hersteller]) {
    $steht = $knoten === null ? null : $wpdb->get_var($wpdb->prepare(
        "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
         WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s LIMIT 1",
        $knoten,
        BEZEICHNUNG,
        $kurz
    ));

    echo "Serie «{$kurz}»" . ($steht === null ? ' (neu)' : ' (steht)') . " für Teil #{$teil}\n";

    if (! $schreiben || $knoten === null) {
        continue;
    }

    if ($steht === null) {
        $steht = $data->create((int) $knoten, RecordType::User)->id;
        $data->put((int) $steht, BEZEICHNUNG, TypedValue::ofText($kurz));
        $data->put((int) $steht, BESCHREIBUNG, TypedValue::ofText($beschreibung));

        $wer = $wpdb->get_var($wpdb->prepare(
            "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
             WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s LIMIT 1",
            HERSTELLERLISTE,
            HERSTELLER_NAME,
            $hersteller
        ));

        if ($wer === null) {
            $wer = $data->create(HERSTELLERLISTE, RecordType::User)->id;
            $data->put((int) $wer, HERSTELLER_NAME, TypedValue::ofText($hersteller));
        }

        if ($herstellerFeld !== null) {
            $data->put((int) $steht, $herstellerFeld, TypedValue::ofRecordReference((int) $wer));
        }
    }

    $serieFuer[$teil] = (int) $steht;
}

// ── Das Feld «Serie» am Teil zeigt künftig auf die Serie ───────────────────────────────────────────
$kante = $editor->relationById(SERIE_ALT);

if ($kante !== null && $knoten !== null && $kante->toNodeId !== (int) $knoten) {
    echo "Feld «Serie» an Electronic Parts zeigt künftig auf «Bauteil-Serien» (Verweis statt Text)\n";

    if ($schreiben) {
        foreach (array_keys($serienJeSatz) as $teil) {
            $data->clear($teil, SERIE_ALT);
        }

        $editor->retargetField(ELECTRONIC_PARTS, SERIE_ALT, (int) $knoten);
        $editor->setKind(ELECTRONIC_PARTS, SERIE_ALT, RelationKind::Aggregation);
        $editor->setMultiplicity(ELECTRONIC_PARTS, SERIE_ALT, Multiplicity::ZeroToOne);
    }
}

if ($schreiben) {
    foreach ($serieFuer as $teil => $serie) {
        $data->put($teil, SERIE_ALT, TypedValue::ofRecordReference($serie));
        echo "  Teil #{$teil} → Serie #{$serie}\n";
    }

    foreach ($serieFuer as $teil => $serie) {
        echo '  Bezeichnung: ' . $wpdb->get_var($wpdb->prepare(
            "SELECT value_text FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d",
            $teil,
            BEZEICHNUNG
        )) . "\n";
    }
} else {
    echo "— nur gezeigt. Mit --write umbauen.\n";
}
