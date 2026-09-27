<?php declare(strict_types=1);
// Plan 237: die zwei Platinen zu «ESP32 BT2PS2» (SMD und THT) mit Revision, Gerber, Bildern und Schaltplan.
// Ohne --write wird nur gezeigt. Die Dateien kommen aus C:/Devel/Platinen/projekte (KiCad-Projekte).
define('WP_USE_THEMES', false);
require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;
wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);
$rc = new ReflectionClass(Plugin::class); $plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);
global $wpdb; $p = $wpdb->prefix . 'taxmod_';

const PROJEKT = 30753;                                   // Satz «ESP32 BT2PS2»
const PLATINE = 149000105261, REVISION = 149000105262;
const P_NAME = 149000105893, P_ART = 149000105894, P_BESCHREIBUNG = 149000105895, P_REVISIONEN = 149000107282;
const R_VERSION = 149000105897, R_BESTUECKUNG = 149000105898, R_GERBER = 149000105899, R_AUFWAND = 149000105900, R_FERTIGER = 149000105902;
const TITELBILD = 149000108056, BILDER = 149000108057, PROJ_PLATINEN = 149000107283;
const EIGENE = 149000105254, SMD = 149000104322, THT = 149000104330, MITTEL = 149000105259, EINFACH = 149000105258;
// ⚠️ *Sein KiCad-Ordner; unter Linux über die Umgebungsvariable `PLATINEN_ROOT`.*
define('QUELLE', getenv('PLATINEN_ROOT') ?: 'C:/Devel/Platinen/projekte');

$platinen = [
    [
        'name' => 'ESP32 BT2PS2 (SMD)',
        'beschreibung' => 'Trägerplatine für einen gesteckten D1 mini ESP32 (Variante A) oder ein aufgelötetes ESP32-WROOM-32E (Variante B), dazu Pegelwandler 5 V ↔ 3,3 V (2 × BSS138), PS/2-Anschluss J1 (JST-XH, 4-polig: 5V, GND, DATA, CLK) und Jumper JP1 für die 5 V vom PC. Nur Tastatur (Version 1). 2 Lagen, 61,0 × 34,1 mm, SMD 0805/SOT-23. KiCad 10, Projekt unter C:/Devel/Platinen/projekte/esp32-bt2ps2.',
        'bestueckung' => SMD, 'aufwand' => MITTEL,
        'ordner' => 'esp32-bt2ps2',
        'titelbild' => 'bt2ps2-3d.png',
        'bilder' => ['bt2ps2-oben.png', 'bt2ps2-unten.png', 'bt2ps2-schaltplan.pdf'],
        'gerber' => 'fertigung/bt2ps2-jlcpcb-gerber.zip',
    ],
    [
        'name' => 'ESP32 BT2PS2 (THT)',
        'beschreibung' => 'Bedrahtete Schwester der SMD-Platine: nur D1 mini ESP32 (gesteckt), Pegelwandler 2 × 2N7000 (TO-92) mit 4 × 10 kΩ (0207), PS/2-Anschluss J1 und Jumper JP1 wie bei der SMD-Platine. Nur Tastatur (Version 1). 2 Lagen, 53,0 × 34,1 mm, 9 Bauteile. ⚠️ 2N7000 an 3,3 V Gate ungeprüft (Vgs(th) bis 3 V). KiCad 10, Projekt unter C:/Devel/Platinen/projekte/esp32-bt2ps2-tht.',
        'bestueckung' => THT, 'aufwand' => EINFACH,
        'ordner' => 'esp32-bt2ps2-tht',
        'titelbild' => 'bt2ps2-tht-3d.png',
        'bilder' => ['bt2ps2-tht-oben.png', 'bt2ps2-tht-unten.png', 'bt2ps2-tht-schaltplan.pdf'],
        'gerber' => 'fertigung/bt2ps2-tht-jlcpcb-gerber.zip',
    ],
];

/** Lädt eine Datei in die Mediathek, einmal: eine schon geladene gleichen Namens wird wiederverwendet. */
function mediathek(string $pfad, bool $schreiben): string
{
    global $wpdb;
    if (! is_file($pfad)) throw new RuntimeException("Datei fehlt: $pfad");
    $name = basename($pfad);
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT 1",
        '%/' . $wpdb->esc_like($name)));
    if ($id !== null) { echo "    Mediathek hat schon $name (Id $id)\n"; return 'media:' . $id; }
    echo "    Mediathek neu: $name\n";
    if (! $schreiben) return 'media:?';
    $tmp = wp_tempnam($name);
    copy($pfad, $tmp);
    $id = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0, pathinfo($name, PATHINFO_FILENAME));
    if (is_wp_error($id)) { @unlink($tmp); throw new RuntimeException("$name: " . $id->get_error_message()); }
    return 'media:' . $id;
}

foreach ($platinen as $pl) {
    echo "\n== {$pl['name']}\n";
    $vorhanden = $wpdb->get_var($wpdb->prepare(
        "SELECT r.node_record_id FROM {$p}relation_records r JOIN {$p}node_records n ON n.id = r.node_record_id
         WHERE n.node_id = %d AND r.relation_id = %d AND r.value_text = %s", PLATINE, P_NAME, $pl['name']));
    if ($wpdb->last_error) throw new RuntimeException($wpdb->last_error);
    if ($vorhanden !== null) { echo "  steht schon als Satz $vorhanden — übersprungen\n"; continue; }

    $dir = QUELLE . '/' . $pl['ordner'] . '/';
    $titel = mediathek($dir . $pl['titelbild'], $schreiben);
    $bilder = array_map(fn ($b) => mediathek($dir . $b, $schreiben), $pl['bilder']);
    $gerber = mediathek($dir . $pl['gerber'], $schreiben);
    echo "  Platine: Art Eigene, Titelbild $titel, Bilder " . implode(' ', $bilder) . "\n";
    echo "  Revision 1: Bestückung " . ($pl['bestueckung'] === SMD ? 'SMD' : 'THT') . ", Gerber $gerber, Fertiger JLCPCB\n";
    if (! $schreiben) continue;

    $rev = $data->create(REVISION, RecordType::User);
    $data->put($rev->id, R_VERSION, TypedValue::ofText('1'));
    $data->put($rev->id, R_BESTUECKUNG, TypedValue::ofReference($pl['bestueckung']));
    $data->put($rev->id, R_GERBER, TypedValue::ofText($gerber));
    $data->put($rev->id, R_AUFWAND, TypedValue::ofReference($pl['aufwand']));
    $data->put($rev->id, R_FERTIGER, TypedValue::ofText('JLCPCB'));

    $satz = $data->create(PLATINE, RecordType::User);
    $data->put($satz->id, P_NAME, TypedValue::ofText($pl['name']));
    $data->put($satz->id, P_ART, TypedValue::ofReference(EIGENE));
    $data->put($satz->id, P_BESCHREIBUNG, TypedValue::ofText($pl['beschreibung']));
    $data->appendValue($satz->id, P_REVISIONEN, TypedValue::ofRecordReference($rev->id));
    $data->put($satz->id, TITELBILD, TypedValue::ofText($titel));
    foreach ($bilder as $b) $data->appendValue($satz->id, BILDER, TypedValue::ofText($b));

    $data->appendValue(PROJEKT, PROJ_PLATINEN, TypedValue::ofRecordReference($satz->id));
    echo "  geschrieben: Platine {$satz->id}, Revision {$rev->id}, am Projekt " . PROJEKT . "\n";
}
echo $schreiben ? "\nFertig.\n" : "\nNur gezeigt.\n";
