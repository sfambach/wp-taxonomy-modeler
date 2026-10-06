<?php declare(strict_types=1);

/**
 * Die Logitech-Mäuse aus den Projekten nach PC › Hardware (2026-09-19).
 *
 *     php scripts/dev/logitech-maeuse.php            # nur zeigen
 *     php scripts/dev/logitech-maeuse.php --write    # umbauen
 *
 * ⚠️ *Seine Worte: «logitechmouse ist doch kein hardware propjekt», «gehört eher zu pc» (D-880). Sein Entwurf «Retro Projekt -
 * Logitechmouse» sagt nur «mit viel Glück zum kleinen Preis 4 Logitech Mäuse ersteigert» — kein Modell, der Rest ist die leere Vorlage.
 * Darum: Knoten «Eingabegeräte › Mäuse» unter External, ein Modell mit offener Bezeichnung und vier Exemplare. Der Satz unter
 * «Projekte» geht in den Schatten.*
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\RecordType;
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

// Gemessen am 2026-09-19
const EXTERNAL = 149000103002, PROJEKTE = 149000104440, PROJEKT_NAME = 149000104347, EXEMPLARE = 149000108212;
const BEZEICHNUNG = 149000103839, HERSTELLER = 149000102544, HERSTELLERLISTE = 149000102677, HERSTELLER_NAME = 149000102666;
const HERKUNFT = 149000108187, HERKUNFTSHINWEIS = 149000108188, EX_MODELL = 149000108189, EX_HINWEIS = 149000108198;

$knoten = static function (string $name, int $vater) use ($editor, $wpdb, $p, $schreiben): ?int {
    $steht = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", $name, $vater));

    if ($steht !== null) {
        return (int) $steht;
    }

    echo "Knoten «{$name}» unter {$vater}\n";

    return $schreiben ? $editor->createNode($name, $vater, Category::class)->id : null;
};

$satzMit = static fn (int $knotenId, int $feldId, string $text): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
     WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s LIMIT 1",
    $knotenId,
    $feldId,
    $text
))) === null ? null : (int) $id;

$eingabe = $knoten('Eingabegeräte', EXTERNAL);
$maeuse  = $eingabe === null ? null : $knoten('Mäuse', $eingabe);
$name    = 'Logitech-Maus (Modell offen)';
$modell  = $maeuse === null ? null : $satzMit($maeuse, BEZEICHNUNG, $name);

if ($modell === null) {
    echo "Modell «{$name}»\n";

    if ($schreiben && $maeuse !== null) {
        $modell    = $data->create($maeuse, RecordType::User)->id;
        $logitech  = $satzMit(HERSTELLERLISTE, HERSTELLER_NAME, 'Logitech');

        if ($logitech === null) {
            $logitech = $data->create(HERSTELLERLISTE, RecordType::User)->id;
            $data->put($logitech, HERSTELLER_NAME, TypedValue::ofText('Logitech'));
        }

        $data->put($modell, BEZEICHNUNG, TypedValue::ofText($name));
        $data->put($modell, HERSTELLER, TypedValue::ofRecordReference($logitech));
        $data->put($modell, HERKUNFTSHINWEIS, TypedValue::ofText(
            'Aus seinem Entwurf «Retro Projekt - Logitechmouse» (2025-04-26): «mit viel Glück zum kleinen Preis 4 Logitech Mäuse ersteigert». '
            . 'Welches Modell, sagt der Entwurf nicht — die Bezeichnung ist offen (D-880).'
        ));
    }
}

$vorhanden = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
     WHERE r.node_id = %d AND v.relation_id = %d AND v.value_ref = %d",
    EXEMPLARE,
    EX_MODELL,
    (int) $modell
));

for ($stueck = $vorhanden + 1; $stueck <= 4; $stueck++) {
    echo "Exemplar {$stueck} von 4\n";

    if ($schreiben && $modell !== null) {
        $ex = $data->create(EXEMPLARE, RecordType::User)->id;
        $data->put($ex, EX_MODELL, TypedValue::ofRecordReference($modell));
        $data->put($ex, EX_HINWEIS, TypedValue::ofText("Maus {$stueck} von 4, zusammen ersteigert (Entwurf «Retro Projekt - Logitechmouse»)."));
    }
}

$projekt = $satzMit(PROJEKTE, PROJEKT_NAME, 'Logitechmouse');

if ($projekt !== null) {
    echo "Projektsatz #{$projekt} geht in den Schatten\n";

    if ($schreiben) {
        $data->removeRecord($projekt);
    }
}
