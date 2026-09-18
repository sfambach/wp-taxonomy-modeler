<?php declare(strict_types=1);
define('WP_USE_THEMES', false);
require 'C:/Devel/Wordpress/wp-load.php';
require 'C:/Devel/Wordpress/source/wp-taxonomy-tree/vendor/autoload.php';
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\RelationKind;
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
const PROJEKTE = 149000104440, LINKS = 149000107930, MEDIUM = 149000107775, ADRESSE = 149000107928, BESCHRIFTUNG = 149000107929;

// Feld «Quellen» an Projekte, gleich hinter «Links»
$quellen = $wpdb->get_var("SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = " . PROJEKTE . " AND n.name = 'Quellen'");
if ($quellen === null) {
    echo "Feld «Quellen» an Projekte\n";
    if ($schreiben) {
        $kante = $editor->addField(PROJEKTE, MEDIUM, 'Quellen', RelationKind::Composition);
        if ($kante->multiplicity !== Multiplicity::ZeroToMany) $kante = $editor->setMultiplicity(PROJEKTE, $kante->id, Multiplicity::ZeroToMany);
        $quellen = $kante->id;
    }
}
$quellen = (int) $quellen;

// Aus der Vorlage «Retro Projekt - Template» (heute und in einer älteren Fassung) mitgeschleppt
$vorlage = [
    'https://github.com/hkzlab/ES1868_ISA8', 'https://github.com/hkzlab/ES1868_ISA8/tree/master/gerbers',
    'http://devel.test/wp-content/uploads/2026/01/PS2_Schnittstellen.pdf',
    'https://github.com/necroware/voltage-blaster/releases/tag/v1.1', 'http://devel.test/wp-content/uploads/2025/09/voltage-blaster-gerber.zip',
    'http://devel.test/wp-content/uploads/2026/07/ibom.html', 'https://github.com/LimeProgramming/USB-serial-mouse-adapter',
    'https://github.com/skiselev/isa8_eth', 'https://github.com/skiselev/isa8_eth/tree/main/gerber',
];
// Satz => [Beitrag, Projektlink (null = behalten wie er ist), was aus der Vorlage hier trotzdem hingehört]
$zuordnung = [
    28722 => [14508, null, ['https://github.com/necroware/voltage-blaster/releases/tag/v1.1', 'http://devel.test/wp-content/uploads/2025/09/voltage-blaster-gerber.zip']],
    28723 => [12601, null, []],
    28726 => [14916, null, []],
    30763 => [15680, null, ['http://devel.test/wp-content/uploads/2026/01/PS2_Schnittstellen.pdf']],
    30779 => [13131, null, []],
    30781 => [14449, null, []],
    30783 => [15877, null, []],
    30785 => [16409, ['https://www.pcbway.com/project/shareproject/RASPBERRY_Pi1541_HAT_with_ROTARY_ENCODER_COMMODORE_64_DISK_DRIVE_EMULATOR_0c4b9675.html', 'PCBWay: Pi1541 HAT mit Drehgeber'], []],
    30788 => [13577, null, []],
];
$db = new mysqli('127.0.0.1', 'root', '', 'wordpress'); $db->set_charset('utf8mb4');
$norm = fn (string $u) => rtrim(str_replace('&amp;', '&', $u), '/');
foreach ($zuordnung as $satz => [$post, $neuerLink, $gehoert]) {
    $c = $db->query('SELECT post_title, post_content FROM wp_posts WHERE ID = ' . $post)->fetch_assoc();
    echo "\n== {$c['post_title']} → Satz $satz\n";
    // Die Adressen, die der Satz schon als Link trägt
    $haben = $wpdb->get_col($wpdb->prepare(
        "SELECT a.value_text FROM {$p}relation_records v JOIN {$p}relation_records a ON a.node_record_id = v.value_ref AND a.relation_id = %d
         WHERE v.node_record_id = %d AND v.relation_id IN (%d, %d)", ADRESSE, $satz, LINKS, $quellen));
    if ($neuerLink !== null) {
        echo "  Link neu: {$neuerLink[1]}\n";
        if ($schreiben) {
            $data->clear($satz, LINKS);
            $teil = $data->createPart($satz, LINKS);
            $data->put($teil->id, ADRESSE, TypedValue::ofText($neuerLink[0]));
            $data->put($teil->id, BESCHRIFTUNG, TypedValue::ofText($neuerLink[1]));
        }
        $haben = [$neuerLink[0]];
    }
    $haben = array_map($norm, $haben);
    preg_match_all('#<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>#is', $c['post_content'], $m, PREG_SET_ORDER);
    $gesehen = [];
    foreach ($m as [$_, $href, $text]) {
        $url = $norm(html_entity_decode($href));
        if (isset($gesehen[$url]) || in_array($url, $haben, true)) continue;
        $gesehen[$url] = true;
        $wort = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text))));
        if (in_array($url, array_map($norm, $vorlage), true) && ! in_array($url, array_map($norm, $gehoert), true)) { echo "  aus der Vorlage, weggelassen: $url\n"; continue; }
        if (str_contains($wort, 'Link zum Projekt')) { echo "  Projektlink im Entwurf, weggelassen: $url\n"; continue; }
        if ($wort === '' || $wort === $url || in_array(strtolower($wort), ['link', 'github link'], true)) {
            $teile = parse_url($url);
            $wort = ($teile['host'] ?? '') . ': ' . basename($teile['path'] ?? '');
        }
        echo "  Quelle: $wort — $url\n";
        if ($schreiben) {
            $teil = $data->createPart($satz, $quellen);
            $data->put($teil->id, ADRESSE, TypedValue::ofText($url));
            $data->put($teil->id, BESCHRIFTUNG, TypedValue::ofText($wort));
        }
    }
}
echo $schreiben ? "\nFertig.\n" : "\nNur gezeigt.\n";
