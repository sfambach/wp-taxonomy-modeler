<?php declare(strict_types=1);
// Plan 243: die THT-Platine zu «ESP32 BT2PS2» trägt jetzt auch das WROOM-32E (Variante B). Die Dateien in der
// Mediathek werden an Ort und Stelle ersetzt — die Ids bleiben, also bleiben alle Verweise gültig. Dazu die neue
// Beschreibung. Ohne --write wird nur gezeigt.
define('WP_USE_THEMES', false);
require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;
wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);
$rc = new ReflectionClass(Plugin::class); $plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);

const PLATINE = 33075, P_BESCHREIBUNG = 149000105895;
define('ORDNER', (getenv('PLATINEN_ROOT') ?: 'C:/Devel/Platinen/projekte') . '/esp32-bt2ps2-tht/');
$ersetzen = [
    16884 => 'bt2ps2-tht-3d.png', 16885 => 'bt2ps2-tht-oben.png', 16886 => 'bt2ps2-tht-unten.png',
    16887 => 'bt2ps2-tht-schaltplan.pdf', 16888 => 'fertigung/bt2ps2-tht-jlcpcb-gerber.zip',
    16892 => 'fertigung/bestueckung-oben.pdf', 16893 => 'fertigung/bt2ps2-tht-kicad-projekt.zip',
];
foreach ($ersetzen as $id => $quelle) {
    $ziel = get_attached_file($id);
    if (! $ziel || ! is_file(ORDNER . $quelle)) throw new RuntimeException("Id $id oder $quelle fehlt");
    $gleich = is_file($ziel) && md5_file($ziel) === md5_file(ORDNER . $quelle);
    echo "  $id " . basename($ziel) . ($gleich ? ' — unverändert' : ' — wird ersetzt') . "\n";
    if ($gleich || ! $schreiben) continue;
    copy(ORDNER . $quelle, $ziel);
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $ziel));
}
$text = 'Bedrahtete Schwester der SMD-Platine. Variante A: D1 mini ESP32 gesteckt. Variante B: ESP32-WROOM-32E aufgelötet — '
      . 'das einzige SMD-Bauteil —, dazu LD1117V33 (TO-220), BOOT- und RESET-Taster, LED 3 mm und Programmierstecker J3. '
      . 'Pegelwandler 2 × 2N7000 (TO-92) mit 10 kΩ (0207), PS/2-Anschluss J1 (JST-XH, 4-polig) und Jumper JP1 wie bei der SMD-Platine. '
      . 'Nur Tastatur (Version 1). 2 Lagen, 83,0 × 34,1 mm. ⚠️ 2N7000 an 3,3 V Gate ungeprüft (Vgs(th) bis 3 V). '
      . 'KiCad 10, Projekt unter C:/Devel/Platinen/projekte/esp32-bt2ps2-tht.';
echo "  Beschreibung der Platine " . PLATINE . " neu\n";
if ($schreiben) $data->put(PLATINE, P_BESCHREIBUNG, TypedValue::ofText($text));
echo $schreiben ? "Fertig.\n" : "Nur gezeigt.\n";
