<?php declare(strict_types=1);
// Plan 249: die Bauteile der zwei BT2PS2-Platinen im Teilekatalog und die Stücklisten («Positionen») beider
// Revisionen. Wiederverwendet: Jumper 24583, Keramik 100 nF RM 5 mm 24586. Ohne --write wird nur gezeigt.
define('WP_USE_THEMES', false);
require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Taxmod\Core\Model\NodeClass\Constant;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;
wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);
$rc = new ReflectionClass(Plugin::class); $plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
$editor = (new ReflectionProperty($screen, 'editor'))->getValue($screen);
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);
global $wpdb; $p = $wpdb->prefix . 'taxmod_';

// Knoten
const RESISTOR = 3640, CONDENSATOR = 3642, LED = 149000104409, TRANSISTOR = 149000104412, REGLER = 149000104416;
const MODULE = 149000104428, STIFTLEISTE = 149000104419, BUCHSENLEISTE = 149000104420, STECKVERBINDER = 149000104423, TASTER = 149000104425;
const SMD = 149000104322, THT = 149000104330, STECKERTYPEN = 149000104367;
// Felder
const BAUFORM = 149000104307, BESCHREIBUNG = 149000104313, HERSTELLER = 149000108565, TEILENUMMER = 149000108567, TYP = 149000105278, MODUL_TYP = 149000105279;
const TOLERANZ = 149000104310, T_VON = 149000104898, T_BIS = 149000104899, T_EINHEIT = 149000104901;
const R_WERT = 149000104311, R_LEISTUNG = 149000104312, C_KAPAZITAET = 149000104314, C_SPANNUNG = 149000104315, C_DIELEKTRIKUM = 149000104316;
const LED_FARBE = 149000104324, TR_TYP = 149000104326, REG_U = 149000104331, REG_I = 149000104332;
const POLZAHL = 149000104333, REIHEN = 149000104334, RASTER = 149000104335, STECKERTYP = 149000104337, GESCHLECHT = 149000104338;
const HERKUNFT = 149000108187, HERKUNFTSHINWEIS = 149000108188;
const EW_WERT = 4234, EW_PREFIX = 4235, EW_EINHEIT = 4236;
const POSITIONEN = 149000107280, PL_REFERENZ = 3633, PL_PART = 8264, PL_ANZAHL = 8265, PL_SEITE = 149000104343, PL_BESTUECKUNG = 149000104344, PL_HINWEIS = 149000104345;
// Konstanten
const OHM = 4044, WATT = 4048, FARAD = 4046, VOLT = 4050, AMPERE = 4042, STUECK = 4062, METER = 4036, PROZENT = 149000104316;
const KILO = 4004, MILLI = 4014, MICRO = 4016, NANO = 4018;
const B0805 = 149000104326, B0207 = 149000104332, SOT23 = 149000104379, TO92 = 149000104399, TO220 = 149000104400, LED3 = 149000104402;
const SMD_ALLG = 149000104492, THT_ALLG = 149000104493, RADIAL5 = 149000104495;
const KERAMIK = 149000104337, ELEKTROLYT = 149000104338, BLAU = 149000104344, NMOS = 149000104351, MAENNLICH = 149000104362;
const OBEN = 149000104434, UNTEN = 149000104435, BESTUECKEN = 149000104437, OPTIONAL = 149000104438, ABGELEITET = 149000108194;
const REV_SMD = 33072, REV_THT = 33074, JUMPER = 24583, C100N_DISC = 24586;

$knoten = static function (string $name, int $vater) use ($editor, $wpdb, $p, $schreiben): ?int {
    $steht = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", $name, $vater));
    if ($steht !== null) return (int) $steht;
    echo "Auswahlwert «{$name}» unter {$vater}\n";
    return $schreiben ? $editor->createNode($name, $vater, Constant::class)->id : null;
};
$sot235 = $knoten('SOT-23-5', SMD);
$radial2 = $knoten('radial RM 2 mm', THT);
$jstxh = $knoten('JST XH (2,5 mm)', STECKERTYPEN);

// (Früher als Herkunftshinweis geschrieben, siehe unten.)
$hinweis = 'Aus der Stückliste der BT2PS2-Platinen (KiCad, 2026-09-19). Toleranz, Leistung und Spannungsfestigkeit '
         . 'als übliche Werte angenommen und nicht im Datenblatt geprüft.';

// key => [Knoten, Beschreibung, Bauform, [Feld => Wert, …]]; Wert: string = Text, ['ref', id], ['ew', zahl, vorsatz, einheit], ['tol', ±]
$teile = [
    'R10k0805' => [RESISTOR, 'Widerstand 10 kΩ 0805', B0805, [R_WERT => ['ew', 10, KILO, OHM], R_LEISTUNG => ['ew', 125, MILLI, WATT], TOLERANZ => ['tol', 1]]],
    'R1k0805'  => [RESISTOR, 'Widerstand 1 kΩ 0805', B0805, [R_WERT => ['ew', 1, KILO, OHM], R_LEISTUNG => ['ew', 125, MILLI, WATT], TOLERANZ => ['tol', 1]]],
    'R10k0207' => [RESISTOR, 'Widerstand 10 kΩ 0207 (¼ W, bedrahtet)', B0207, [R_WERT => ['ew', 10, KILO, OHM], R_LEISTUNG => ['ew', 250, MILLI, WATT], TOLERANZ => ['tol', 1]]],
    'R1k0207'  => [RESISTOR, 'Widerstand 1 kΩ 0207 (¼ W, bedrahtet)', B0207, [R_WERT => ['ew', 1, KILO, OHM], R_LEISTUNG => ['ew', 250, MILLI, WATT], TOLERANZ => ['tol', 1]]],
    'C10u0805' => [CONDENSATOR, 'Kondensator Keramik 10 µF 0805', B0805, [C_KAPAZITAET => ['ew', 10, MICRO, FARAD], C_SPANNUNG => ['ew', 16, null, VOLT], C_DIELEKTRIKUM => ['ref', KERAMIK], TOLERANZ => ['tol', 10]]],
    'C1u0805'  => [CONDENSATOR, 'Kondensator Keramik 1 µF 0805', B0805, [C_KAPAZITAET => ['ew', 1, MICRO, FARAD], C_SPANNUNG => ['ew', 16, null, VOLT], C_DIELEKTRIKUM => ['ref', KERAMIK], TOLERANZ => ['tol', 10]]],
    'C100n0805'=> [CONDENSATOR, 'Kondensator Keramik 100 nF 0805', B0805, [C_KAPAZITAET => ['ew', 100, NANO, FARAD], C_SPANNUNG => ['ew', 16, null, VOLT], C_DIELEKTRIKUM => ['ref', KERAMIK], TOLERANZ => ['tol', 10]]],
    'C10uElko' => [CONDENSATOR, 'Elektrolytkondensator 10 µF, Ø 5 mm, RM 2 mm', $radial2, [C_KAPAZITAET => ['ew', 10, MICRO, FARAD], C_SPANNUNG => ['ew', 16, null, VOLT], C_DIELEKTRIKUM => ['ref', ELEKTROLYT], TOLERANZ => ['tol', 20]]],
    'C1uDisc'  => [CONDENSATOR, 'Kondensator Keramik 1 µF, radial RM 5 mm', RADIAL5, [C_KAPAZITAET => ['ew', 1, MICRO, FARAD], C_SPANNUNG => ['ew', 16, null, VOLT], C_DIELEKTRIKUM => ['ref', KERAMIK], TOLERANZ => ['tol', 10]]],
    'LED0805'  => [LED, 'LED blau 0805', B0805, [LED_FARBE => ['ref', BLAU]]],
    'LED3mm'   => [LED, 'LED blau 3 mm', LED3, [LED_FARBE => ['ref', BLAU]]],
    'BSS138'   => [TRANSISTOR, 'N-Kanal-MOSFET BSS138 (Pegelwandler 5 V ↔ 3,3 V)', SOT23, [TR_TYP => ['ref', NMOS], TYP => 'BSS138', TEILENUMMER => 'BSS138']],
    '2N7000'   => [TRANSISTOR, 'N-Kanal-MOSFET 2N7000 (Pegelwandler 5 V ↔ 3,3 V; Vgs(th) bis 3 V)', TO92, [TR_TYP => ['ref', NMOS], TYP => '2N7000', TEILENUMMER => '2N7000']],
    'AP2112K'  => [REGLER, 'Spannungsregler 3,3 V 600 mA, LDO', $sot235, [REG_U => ['ew', 3.3, null, VOLT], REG_I => ['ew', 600, MILLI, AMPERE], TYP => 'AP2112K-3.3', HERSTELLER => 'Diodes Incorporated', TEILENUMMER => 'AP2112K-3.3TRG1']],
    'LD1117V33'=> [REGLER, 'Spannungsregler 3,3 V 800 mA, LDO (Ausgang mind. 10 µF)', TO220, [REG_U => ['ew', 3.3, null, VOLT], REG_I => ['ew', 800, MILLI, AMPERE], TYP => 'LD1117V33', HERSTELLER => 'STMicroelectronics', TEILENUMMER => 'LD1117V33']],
    'WROOM'    => [MODULE, 'ESP32-Modul mit WLAN, Bluetooth Classic und BLE, 4 MB Flash, Leiterplattenantenne', SMD_ALLG, [MODUL_TYP => 'ESP32-WROOM-32E-N4', HERSTELLER => 'Espressif', TEILENUMMER => 'ESP32-WROOM-32E-N4']],
    'D1mini'   => [MODULE, 'D1 mini ESP32 (MH-ET LIVE MiniKit): ESP32-WROOM-32, CH9102, 4 Stiftreihen, 39 × 32 mm', THT_ALLG, [MODUL_TYP => 'D1 mini ESP32']],
    'JSTXH4'   => [STECKVERBINDER, 'JST-XH-Stiftleiste 4-polig, stehend, 2,5 mm', THT_ALLG, [STECKERTYP => ['ref', $jstxh], GESCHLECHT => ['ref', MAENNLICH], POLZAHL => ['ew', 4, null, STUECK], REIHEN => ['ew', 1, null, STUECK], RASTER => ['ew', 2.5, MILLI, METER], HERSTELLER => 'JST', TEILENUMMER => 'B4B-XH-A']],
    'Stift1x4' => [STIFTLEISTE, 'Stiftleiste 1 × 4, 2,54 mm', THT_ALLG, [POLZAHL => ['ew', 4, null, STUECK], REIHEN => ['ew', 1, null, STUECK], RASTER => ['ew', 2.54, MILLI, METER]]],
    'Stift1x2' => [STIFTLEISTE, 'Stiftleiste 1 × 2, 2,54 mm', THT_ALLG, [POLZAHL => ['ew', 2, null, STUECK], REIHEN => ['ew', 1, null, STUECK], RASTER => ['ew', 2.54, MILLI, METER]]],
    'Buchse1x10'=>[BUCHSENLEISTE, 'Buchsenleiste 1 × 10, 2,54 mm', THT_ALLG, [POLZAHL => ['ew', 10, null, STUECK], REIHEN => ['ew', 1, null, STUECK], RASTER => ['ew', 2.54, MILLI, METER]]],
    'Taster6'  => [TASTER, 'Taster 6 × 6 mm, bedrahtet', THT_ALLG, []],
];

$beschreibungsKante = (int) $wpdb->get_var("SELECT id FROM {$p}relations_named WHERE name = 'Beschreibung' AND from_node_id = 402");
$zahl = static fn ($z) => rtrim(rtrim(number_format((float) $z, 3, '.', ''), '0'), '.');
// Ein Pflichtfeld (1..1) aus Teilen bringt beim Anlegen des Satzes schon einen leeren Teil mit — den füllen, nicht
// einen zweiten daneben legen (gemessen beim ersten Lauf: 28 leere Doppel).
$teilFuer = static function (int $satz, int $feld) use ($data, $wpdb, $p) {
    $da = $wpdb->get_var($wpdb->prepare("SELECT value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d AND value_ref IS NOT NULL ORDER BY position LIMIT 1", $satz, $feld));
    return $da !== null ? (int) $da : $data->createPart($satz, $feld)->id;
};
$id = ['Jumper' => JUMPER, 'C100nDisc' => C100N_DISC];
foreach ($teile as $key => [$node, $text, $bauform, $felder]) {
    // ⚠️ «Beschreibung» ist an den Vater «Model» gewandert — die Kante aus den Konstanten gibt es nicht mehr.
    // Darum wird über den Text im Satz gesucht, nicht über die Kantennummer (sonst legt ein zweiter Lauf alles doppelt an).
    $steht = $wpdb->get_var($wpdb->prepare(
        "SELECT r.node_record_id FROM {$p}relation_records r JOIN {$p}node_records n ON n.id = r.node_record_id
         WHERE n.node_id = %d AND r.value_text = %s", $node, $text));
    if ($steht !== null) { $id[$key] = (int) $steht; continue; }
    echo "Teil neu: $text\n";
    if (! $schreiben) { $id[$key] = 0; continue; }
    $satz = $data->create($node, RecordType::User);
    $data->put($satz->id, $beschreibungsKante, TypedValue::ofText($text));
    $data->put($satz->id, BAUFORM, TypedValue::ofReference((int) $bauform));
    foreach ($felder as $feld => $wert) {
        if (is_string($wert)) { $data->put($satz->id, $feld, TypedValue::ofText($wert)); continue; }
        switch ($wert[0]) {
            case 'ref': $data->put($satz->id, $feld, TypedValue::ofReference((int) $wert[1])); break;
            case 'ew':
                $t = (object) ['id' => $teilFuer($satz->id, $feld)];
                $data->put($t->id, EW_WERT, TypedValue::ofDecimal($zahl($wert[1])));
                $data->put($t->id, EW_EINHEIT, TypedValue::ofReference($wert[3]));
                if ($wert[2] !== null) $data->put($t->id, EW_PREFIX, TypedValue::ofReference($wert[2]));
                break;
            case 'tol':
                $t = (object) ['id' => $teilFuer($satz->id, $feld)];
                $data->put($t->id, T_VON, TypedValue::ofDecimal($zahl(-$wert[1])));
                $data->put($t->id, T_BIS, TypedValue::ofDecimal($zahl($wert[1])));
                $data->put($t->id, T_EINHEIT, TypedValue::ofReference(PROZENT));
                break;
        }
    }
    $data->appendValue($satz->id, HERKUNFT, TypedValue::ofReference(ABGELEITET));
    $id[$key] = $satz->id;
}

// Der Herkunftshinweis wird als Name des Teils angezeigt, wo kein Wertfeld den Namen liefert (Transistor, Modul,
// Stecker, Leisten, Taster — gesehen im Browser am 2026-09-19). Darum steht er nicht am Teil; die Annahmen stehen in
// der Planzeile, die Herkunft «abgeleitet» bleibt.
$unsereIds = array_values(array_filter(array_map('intval', $id), fn ($i) => $i >= 34600));
if ($unsereIds) {
    $mitHinweis = $wpdb->get_col("SELECT node_record_id FROM {$p}relation_records WHERE relation_id = " . HERKUNFTSHINWEIS . " AND node_record_id IN (" . implode(',', $unsereIds) . ")");
    if ($mitHinweis) echo count($mitHinweis) . " Herkunftshinweise entfernen
";
    if ($schreiben) foreach ($mitHinweis as $s) $data->clear((int) $s, HERKUNFTSHINWEIS);
}

// Leere Doppel aus dem ersten Lauf: ein leerer Teil neben einem gefüllten im selben Feld eines unserer Teile
$unsere = array_values(array_filter(array_map('intval', $id)));
if ($unsere) {
    $leer = $wpdb->get_results("SELECT v.value_ref AS teil, v.node_record_id AS satz, v.relation_id AS feld FROM {$p}relation_records v
        WHERE v.node_record_id IN (" . implode(',', $unsere) . ") AND v.value_ref_kind = 'record'
          AND NOT EXISTS (SELECT 1 FROM {$p}relation_records x WHERE x.node_record_id = v.value_ref)
          AND EXISTS (SELECT 1 FROM {$p}relation_records w JOIN {$p}relation_records y ON y.node_record_id = w.value_ref
                      WHERE w.node_record_id = v.node_record_id AND w.relation_id = v.relation_id AND w.value_ref <> v.value_ref)");
    if ($leer) echo count($leer) . " leere Doppel entfernen
";
    if ($schreiben) foreach ($leer as $l) $data->removeRecord((int) $l->teil);
}

// Stücklisten: [Referenzen, Teil, Seite, Bestückung, Hinweis]
$A = 'Variante A (D1 mini)'; $B = 'nur Variante B (WROOM)';
$listen = [
    REV_SMD => [
        [['Q1', 'Q2'], 'BSS138', OBEN, BESTUECKEN, 'Pegelwandler'],
        [['R1', 'R2', 'R3', 'R4'], 'R10k0805', OBEN, BESTUECKEN, 'Pull-ups der Pegelwandler'],
        [['J1'], 'JSTXH4', OBEN, BESTUECKEN, 'PS/2 zum PC: 5V, GND, DATA, CLK'],
        [['JP1'], 'Stift1x2', OBEN, BESTUECKEN, '5 V vom PC'],
        [['JP1'], 'Jumper', OBEN, BESTUECKEN, 'ziehen, solange über USB versorgt'],
        [['U1'], 'Buchse1x10', OBEN, BESTUECKEN, "$A: 4 Stück als Sockel", 4],
        [['U1'], 'D1mini', OBEN, BESTUECKEN, "$A, gesteckt"],
        [['U2'], 'WROOM', OBEN, OPTIONAL, $B],
        [['U3'], 'AP2112K', OBEN, OPTIONAL, $B],
        [['C1', 'C2'], 'C10u0805', OBEN, OPTIONAL, $B],
        [['C3'], 'C100n0805', OBEN, OPTIONAL, $B],
        [['C5'], 'C100n0805', UNTEN, OPTIONAL, $B],
        [['C4'], 'C1u0805', UNTEN, OPTIONAL, $B],
        [['R5', 'R6'], 'R10k0805', UNTEN, OPTIONAL, $B],
        [['R7'], 'R1k0805', OBEN, OPTIONAL, $B],
        [['D1'], 'LED0805', OBEN, OPTIONAL, "$B; Status an IO2"],
        [['SW1', 'SW2'], 'Taster6', OBEN, OPTIONAL, "$B; BOOT und RESET"],
        [['J3'], 'Stift1x4', OBEN, OPTIONAL, "$B; USB-Seriell-Adapter"],
    ],
    REV_THT => [
        [['Q1', 'Q2'], '2N7000', OBEN, BESTUECKEN, 'Pegelwandler'],
        [['R1', 'R2', 'R3', 'R4'], 'R10k0207', OBEN, BESTUECKEN, 'Pull-ups der Pegelwandler'],
        [['J1'], 'JSTXH4', OBEN, BESTUECKEN, 'PS/2 zum PC: 5V, GND, DATA, CLK'],
        [['JP1'], 'Stift1x2', OBEN, BESTUECKEN, '5 V vom PC'],
        [['JP1'], 'Jumper', OBEN, BESTUECKEN, 'ziehen, solange über USB versorgt'],
        [['U1'], 'Buchse1x10', OBEN, BESTUECKEN, "$A: 4 Stück als Sockel", 4],
        [['U1'], 'D1mini', OBEN, BESTUECKEN, "$A, gesteckt"],
        [['U2'], 'WROOM', OBEN, OPTIONAL, "$B; einziges SMD-Teil"],
        [['U3'], 'LD1117V33', OBEN, OPTIONAL, $B],
        [['C1', 'C2'], 'C10uElko', OBEN, OPTIONAL, $B],
        [['C3', 'C5'], 'C100nDisc', OBEN, OPTIONAL, $B],
        [['C4'], 'C1uDisc', OBEN, OPTIONAL, $B],
        [['R5', 'R6'], 'R10k0207', OBEN, OPTIONAL, $B],
        [['R7'], 'R1k0207', OBEN, OPTIONAL, $B],
        [['D1'], 'LED3mm', OBEN, OPTIONAL, "$B; Status an IO2"],
        [['SW1', 'SW2'], 'Taster6', OBEN, OPTIONAL, "$B; BOOT und RESET"],
        [['J3'], 'Stift1x4', OBEN, OPTIONAL, "$B; USB-Seriell-Adapter"],
    ],
];
foreach ($listen as $rev => $zeilen) {
    $hat = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $rev, POSITIONEN));
    if ($hat > 0) { echo "Revision $rev hat schon $hat Positionen — übersprungen\n"; continue; }
    echo "Revision $rev: " . count($zeilen) . " Positionen\n";
    if (! $schreiben) continue;
    foreach ($zeilen as $z) {
        [$refs, $key, $seite, $best, $text] = $z;
        $t = $data->createPart($rev, POSITIONEN);
        foreach ($refs as $r) $data->appendValue($t->id, PL_REFERENZ, TypedValue::ofText($r));
        $data->put($t->id, PL_PART, TypedValue::ofRecordReference($id[$key]));
        $data->put($t->id, PL_ANZAHL, TypedValue::ofInt($z[5] ?? count($refs)));
        $data->put($t->id, PL_SEITE, TypedValue::ofReference($seite));
        $data->put($t->id, PL_BESTUECKUNG, TypedValue::ofReference($best));
        $data->put($t->id, PL_HINWEIS, TypedValue::ofText($text));
    }
}
echo $schreiben ? "Fertig.\n" : "Nur gezeigt.\n";
