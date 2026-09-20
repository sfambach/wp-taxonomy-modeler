<?php declare(strict_types=1);

/**
 * Kurze Namen für die Steckertypen, die Feinheiten in die Serie (2026-09-20).
 *
 *     php scripts/dev/serien-kurznamen.php            # nur zeigen
 *     php scripts/dev/serien-kurznamen.php --write    # umbenennen, Rastermaß setzen, nachrechnen
 *
 * ⚠️ *Zu [D-890](../../docs/NewConcept/90-decision-log.md), sein Grund: «Serien scheinen eine Gruppierung zu sein … und einen
 * kurzen Titel, den wir wiederum verwenden könnten?» Die Steckertypen hiessen «Molex 90331 (AT-Netzteil, 3,96 mm)» — der Name
 * trug die Feinheiten mit. Kurz heisst der Typ «Molex 90331»; das Rastermaß steht an der Serie, der Einsatz stand dort schon.*
 *
 * *Damit steht in der Bezeichnung eines Steckers der Steckertyp und nicht die Serie: beide sagten dasselbe, und doppelt stand es
 * unlesbar da («Molex 90331 · Molex 90331 (AT-Netzteil, 3,96 mm) · 6 St · männlich»).*
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

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
/** @var \Taxmod\Core\Service\Rendering $rendering */
$rendering = (new ReflectionProperty($screen, 'rendering'))->getValue($screen);
/** @var \Taxmod\Core\Service\SettingsEditor $attributes */
$attributes = (new ReflectionProperty($screen, 'attributes'))->getValue($screen);

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Gemessen am 2026-09-20
const STECKVERBINDER = 149000104423, BEZEICHNUNG = 149000103839, STECKERTYP = 149000104337, SERIE = 149000108566;
const MILLI = 4014, METER = 4036, EW_WERT = 4234, EW_PREFIX = 4235, EW_EINHEIT = 4236;

/** Steckertyp-Knoten ⇒ kurzer Name und, wo es eine Serie gibt, ihr Rastermaß in Millimeter. */
$kurz = [
    149000108576 => ['Molex 90331', 'Molex 90331', '3.96'],
    149000108577 => ['Molex 8981', 'Molex 8981', '5.08'],
    149000108578 => ['AMP EI 171822', 'AMP EI', '2.5'],
    149000108579 => ['Molex Mini-Fit Jr.', 'Molex Mini-Fit Jr.', '4.2'],
    149000108994 => ['JST XH', null, null],
];

foreach ($kurz as $knotenId => [$name, $serie, $raster]) {
    $knoten = $editor->find($knotenId);

    if ($knoten === null || $knoten->name === $name) {
        continue;
    }

    echo "Steckertyp «{$knoten->name}» → «{$name}»\n";

    if ($schreiben) {
        $editor->rename($knotenId, $name);
    }
}

// ── Rastermaß an die Serie ─────────────────────────────────────────────────────────────────────────
$serienKnoten = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s", 'Bauteil-Serien'));
$rasterFeld   = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
    $serienKnoten,
    'Rastermaß'
));

foreach ($kurz as [$name, $serie, $raster]) {
    if ($serie === null || $raster === null || $serienKnoten === 0 || $rasterFeld === 0) {
        continue;
    }

    $satz = $wpdb->get_var($wpdb->prepare(
        "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
         WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s LIMIT 1",
        $serienKnoten,
        BEZEICHNUNG,
        $serie
    ));

    if ($satz === null) {
        echo "Serie «{$serie}» gibt es nicht\n";
        continue;
    }

    $steht = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d",
        (int) $satz,
        $rasterFeld
    ));

    echo "Serie «{$serie}»: Rastermaß {$raster} mm" . ($steht > 0 ? ' (steht)' : '') . "\n";

    if ($schreiben && $steht === 0) {
        $teil = $data->createPart((int) $satz, $rasterFeld);
        $data->put($teil->id, EW_WERT, TypedValue::ofDecimal($raster));
        $data->put($teil->id, EW_PREFIX, TypedValue::ofReference(MILLI));
        $data->put($teil->id, EW_EINHEIT, TypedValue::ofReference(METER));
    }
}

// ── In der Bezeichnung des Steckers: der Typ, nicht die Serie ──────────────────────────────────────
$knoten = $editor->find(STECKVERBINDER);
$kante  = $editor->relationById(BEZEICHNUNG);
/** @var \Taxmod\Core\Service\SettingsResolver $resolver */
$resolver = (new ReflectionProperty($attributes, 'resolver'))->getValue($attributes);

foreach ($resolver->listOf($knoten, 'summary_fields', $kante) as $glied) {
    $sollAn = $glied->reference !== SERIE;

    if ($glied->aktiv === $sollAn) {
        continue;
    }

    echo "Bezeichnung des Steckverbinders: «{$glied->word}» " . ($sollAn ? 'an' : 'aus') . "\n";

    if ($schreiben) {
        $attributes->setListEntry($knoten, 'summary_fields', $glied->rowId, $sollAn, null, $kante);
    }
}

if (! $schreiben) {
    exit("— nur gezeigt. Mit --write umbauen.\n");
}

$schreiber = new \Taxmod\Core\Service\SummaryWriter(
    new \Taxmod\WordPress\Persistence\WpdbRecordRepository(),
    new \Taxmod\WordPress\Persistence\WpdbRelationRepository(),
    new \Taxmod\WordPress\Persistence\WpdbNodeRepository(),
    (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin),
    (new ReflectionMethod($plugin, 'typeNodes'))->invoke($plugin),
    $rendering,
    $data
);

$saetze = array_map('intval', $wpdb->get_col(
    "SELECT id FROM {$p}node_records WHERE node_id = " . STECKVERBINDER . " AND record_type = 'user'"
));

echo 'geändert: ' . $schreiber->refresh($saetze) . "\n";

foreach ($wpdb->get_results($wpdb->prepare(
    "SELECT node_record_id, value_text FROM {$p}relation_records WHERE relation_id = %d AND node_record_id IN ("
    . implode(',', $saetze) . ')',
    BEZEICHNUNG
)) as $zeile) {
    echo "  #{$zeile->node_record_id}: {$zeile->value_text}\n";
}
