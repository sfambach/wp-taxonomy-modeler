<?php declare(strict_types=1);
// Plan 264: Positionsdaten (Pick-and-place) der zwei BT2PS2-Platinen. Neue Dateikategorie «Positionsdaten»,
// je eine Datei an der Revision. Dazu die geänderten Gerber- und Projekt-ZIPs an Ort und Stelle erneuern —
// die Mediathek-Ids bleiben. Ohne --write wird nur gezeigt.
define('WP_USE_THEMES', false);
require 'C:/Devel/Wordpress/wp-load.php';
require 'C:/Devel/Wordpress/source/wp-taxonomy-tree/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
use Taxmod\Core\Model\NodeClass\Constant;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;
wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);
$rc = new ReflectionClass(Plugin::class); $plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, 'C:/Devel/Wordpress/source/wp-taxonomy-tree/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
$editor = (new ReflectionProperty($screen, 'editor'))->getValue($screen);
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);
global $wpdb; $p = $wpdb->prefix . 'taxmod_';

const REV_SMD = 33072, REV_THT = 33074;
$smd = 'C:/Devel/Platinen/projekte/esp32-bt2ps2/';
$tht = 'C:/Devel/Platinen/projekte/esp32-bt2ps2-tht/';

// ── 1. Geänderte Dateien erneuern (gleiche Id, neuer Inhalt) ──────────────────────────────────
$ersetzen = [
    16883 => $smd . 'fertigung/bt2ps2-jlcpcb-gerber.zip',
    16891 => $smd . 'fertigung/bt2ps2-kicad-projekt.zip',
    16888 => $tht . 'fertigung/bt2ps2-tht-jlcpcb-gerber.zip',
    16893 => $tht . 'fertigung/bt2ps2-tht-kicad-projekt.zip',
];
foreach ($ersetzen as $id => $quelle) {
    $ziel = get_attached_file($id);
    if (! $ziel || ! is_file($quelle)) throw new RuntimeException("Id $id oder $quelle fehlt");
    if (md5_file($ziel) === md5_file($quelle)) continue;
    echo '  ' . basename($ziel) . " wird erneuert\n";
    if (! $schreiben) continue;
    copy($quelle, $ziel);
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $ziel));
}

// ── 2. Kategorie «Positionsdaten» und die zwei Dateien ────────────────────────────────────────
$kategorien = (int) $wpdb->get_var("SELECT id FROM {$p}nodes_named WHERE name = 'Dateikategorien'");
$kat = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", 'Positionsdaten', $kategorien));
if ($kat === null) {
    echo "Auswahlwert «Positionsdaten» unter den Dateikategorien\n";
    $kat = $schreiben ? $editor->createNode('Positionsdaten', $kategorien, Constant::class)->id : null;
}
$fDateien = (int) $wpdb->get_var("SELECT id FROM {$p}relations_named WHERE name = 'Dateien' AND from_node_id = 149000105262");
$fDatei = (int) $wpdb->get_var($wpdb->prepare("SELECT r.id FROM {$p}relations_named r WHERE r.name = 'Datei' AND r.from_node_id = (SELECT to_node_id FROM {$p}relations WHERE id = %d)", $fDateien));
$fKategorie = (int) $wpdb->get_var($wpdb->prepare("SELECT r.id FROM {$p}relations_named r WHERE r.name = 'Kategorie' AND r.from_node_id = (SELECT to_node_id FROM {$p}relations WHERE id = %d)", $fDateien));

foreach ([REV_SMD => $smd . 'fertigung/bt2ps2-jlcpcb-positionen.csv', REV_THT => $tht . 'fertigung/bt2ps2-tht-jlcpcb-positionen.csv'] as $rev => $pfad) {
    $name = basename($pfad);
    $id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT 1", '%/' . $wpdb->esc_like($name)));
    if ($id !== null) {
        $ziel = get_attached_file((int) $id);
        if (md5_file($ziel) !== md5_file($pfad)) { echo "  $name wird erneuert\n"; if ($schreiben) copy($pfad, $ziel); }
        $media = 'media:' . $id;
    } else {
        echo "  Mediathek neu: $name\n";
        if (! $schreiben) continue;
        $tmp = wp_tempnam($name); copy($pfad, $tmp);
        $neu = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0, pathinfo($name, PATHINFO_FILENAME));
        if (is_wp_error($neu)) throw new RuntimeException("$name: " . $neu->get_error_message());
        $media = 'media:' . $neu;
    }
    $steht = $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$p}relation_records d JOIN {$p}relation_records f ON f.node_record_id = d.value_ref AND f.relation_id = %d
         WHERE d.node_record_id = %d AND d.relation_id = %d AND f.value_text = %s", $fDatei, $rev, $fDateien, $media));
    if ($steht) continue;
    echo "  Revision $rev: Positionsdaten ← $media\n";
    if (! $schreiben) continue;
    $t = $data->createPart($rev, $fDateien);
    $data->put($t->id, $fDatei, TypedValue::ofText($media));
    $data->put($t->id, $fKategorie, TypedValue::ofReference((int) $kat));
}
echo $schreiben ? "Fertig.\n" : "Nur gezeigt.\n";
