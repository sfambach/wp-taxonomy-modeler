<?php declare(strict_types=1);
// Plan 244: die Dateien beider BT2PS2-Platinen neu (fambach.net im Bestückungsdruck) — an Ort und Stelle, die
// Mediathek-Ids bleiben. Dazu der Fork mit den Platinen als Link am Projekt. Ohne --write wird nur gezeigt.
define('WP_USE_THEMES', false);
require 'C:/Devel/Wordpress/wp-load.php';
require 'C:/Devel/Wordpress/source/wp-taxonomy-tree/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;
wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);
$rc = new ReflectionClass(Plugin::class); $plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, 'C:/Devel/Wordpress/source/wp-taxonomy-tree/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);
global $wpdb; $p = $wpdb->prefix . 'taxmod_';

const PROJEKT = 30753, LINKS = 149000107930, ADRESSE = 149000107928, BESCHRIFTUNG = 149000107929;
const FORK = 'https://github.com/sfambach/esp32-bt2ps2';
$smd = 'C:/Devel/Platinen/projekte/esp32-bt2ps2/';
$tht = 'C:/Devel/Platinen/projekte/esp32-bt2ps2-tht/';
$ersetzen = [
    16879 => $smd . 'bt2ps2-3d.png', 16880 => $smd . 'bt2ps2-oben.png', 16881 => $smd . 'bt2ps2-unten.png',
    16882 => $smd . 'bt2ps2-schaltplan.pdf', 16883 => $smd . 'fertigung/bt2ps2-jlcpcb-gerber.zip',
    16889 => $smd . 'fertigung/bestueckung-oben.pdf', 16890 => $smd . 'fertigung/bestueckung-unten.pdf',
    16891 => $smd . 'fertigung/bt2ps2-kicad-projekt.zip',
    16884 => $tht . 'bt2ps2-tht-3d.png', 16885 => $tht . 'bt2ps2-tht-oben.png', 16886 => $tht . 'bt2ps2-tht-unten.png',
    16887 => $tht . 'bt2ps2-tht-schaltplan.pdf', 16888 => $tht . 'fertigung/bt2ps2-tht-jlcpcb-gerber.zip',
    16892 => $tht . 'fertigung/bestueckung-oben.pdf', 16893 => $tht . 'fertigung/bt2ps2-tht-kicad-projekt.zip',
];
foreach ($ersetzen as $id => $quelle) {
    $ziel = get_attached_file($id);
    if (! $ziel || ! is_file($quelle)) throw new RuntimeException("Id $id oder $quelle fehlt");
    $gleich = is_file($ziel) && md5_file($ziel) === md5_file($quelle);
    echo "  $id " . basename($ziel) . ($gleich ? ' — unverändert' : ' — wird ersetzt') . "\n";
    if ($gleich || ! $schreiben) continue;
    copy($quelle, $ziel);
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $ziel));
}
$hat = $wpdb->get_var($wpdb->prepare(
    "SELECT 1 FROM {$p}relation_records v JOIN {$p}relation_records a ON a.node_record_id = v.value_ref AND a.relation_id = %d
     WHERE v.node_record_id = %d AND v.relation_id = %d AND a.value_text = %s", ADRESSE, PROJEKT, LINKS, FORK));
if (! $hat) {
    echo "  Link am Projekt: " . FORK . "\n";
    if ($schreiben) {
        $teil = $data->createPart(PROJEKT, LINKS);
        $data->put($teil->id, ADRESSE, TypedValue::ofText(FORK));
        $data->put($teil->id, BESCHRIFTUNG, TypedValue::ofText('Fork mit den Platinen (hardware/, Release v0.1)'));
    }
}
echo $schreiben ? "Fertig.\n" : "Nur gezeigt.\n";
