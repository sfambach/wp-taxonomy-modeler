<?php declare(strict_types=1);

/**
 * Schnittstellen als Modell mit Datensätzen: ein Vater mit vergleichbaren Feldern, darunter die Gruppen (2026-09-18).
 *
 *     php scripts/dev/schnittstellen.php            # nur zeigen, was geschähe
 *     php scripts/dev/schnittstellen.php --write    # umbauen
 *
 * ⚠️ *Seine Worte: «stecklatz arten würd ich gerne als model mit daten mit zusätlichen informationen anreichern im grunde sind es busse,
 * und die haben geschwindigkeiten von bis», «busse gefällt mir, ja klar gib dem vater felder das macht es vergleichbar», «was haben wir
 * denn noch für schnittstellen netzerk ? serielle / i2c one wire ...» und «parallell».*
 *
 * ⚠️ **Was aus einer Quelle stammt, steht als Quelle daneben; nichts wird dazuerfunden.** *Die Buswerte stehen nach Wikipedia (Stand
 * 2026-09-18) und tragen den Link. Die übrigen Gruppen bekommen nur Namen und die Übertragungsart — die ist Teil der Definition.*
 *
 * Wiederholbar: jeder Schritt sucht erst, ob es das schon gibt.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\NodeClass\Choice;
use Taxmod\Core\Model\NodeClass\Constant;
use Taxmod\Core\Model\NodeClass\Unit;
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

// Gemessen am 2026-09-18
const PC_CONSTANTS = 149000102259, SCHNITTSTELLEN = 149000103870, STORAGE = 32801, STORAGE_SCHNITTSTELLE = 149000103834;
const STECKPLATZ = 149000107689, STECKPLATZARTEN = 149000107677, MEDIUM = 149000107775, MEDIUM_ADRESSE = 149000107928, MEDIUM_BESCHRIFTUNG = 149000107929;
const TEXT = 1175, INTEGER = 1171, EINHEITENWERT = 4232, MIT_VORSATZ = 4032;
const EW_WERT = 4234, EW_PREFIX = 4235, EW_EINHEIT = 4236;
const HERTZ = 4054, VOLT = 4050, BIT = 149000103889, MEGA = 4002;

$sagen = static function (string $satz): void {
    echo $satz, "\n";
};

$knoten = static function (string $name, ?int $vater, string $klasse) use ($editor, $wpdb, $p, $schreiben, $sagen): ?int {
    if ($vater === null) {
        return null;
    }

    $steht = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", $name, $vater));

    if ($steht !== null) {
        return (int) $steht;
    }

    $sagen("Knoten «{$name}» unter {$vater} (" . basename(str_replace('\\', '/', $klasse)) . ')');

    return $schreiben ? $editor->createNode($name, $vater, $klasse)->id : null;
};

$feld = static function (?int $eigner, ?int $ziel, string $name, RelationKind $art, Multiplicity $wieOft) use ($editor, $wpdb, $p, $schreiben, $sagen): ?int {
    if ($eigner === null || $ziel === null) {
        return null;
    }

    $steht = $wpdb->get_var($wpdb->prepare(
        "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
        $eigner,
        $name
    ));

    if ($steht !== null) {
        return (int) $steht;
    }

    $sagen("Feld «{$name}» an {$eigner} → {$ziel} ({$wieOft->value}, {$art->value})");

    if (! $schreiben) {
        return null;
    }

    $kante = $editor->addField($eigner, $ziel, $name, $art);

    if ($kante->multiplicity !== $wieOft) {
        $kante = $editor->setMultiplicity($eigner, $kante->id, $wieOft);
    }

    return $kante->id;
};

$satz = static function (?int $nodeId, ?int $erkennungsfeld, string $erkennung) use ($data, $wpdb, $p, $schreiben, $sagen): ?int {
    if ($nodeId === null || $erkennungsfeld === null) {
        return null;
    }

    $steht = $wpdb->get_var($wpdb->prepare(
        "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
         WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s",
        $nodeId,
        $erkennungsfeld,
        $erkennung
    ));

    if ($steht !== null) {
        return (int) $steht;
    }

    $sagen("Satz «{$erkennung}» am Knoten {$nodeId}");

    if (! $schreiben) {
        return null;
    }

    $neu = $data->create($nodeId, RecordType::User);
    $data->put($neu->id, $erkennungsfeld, TypedValue::ofText($erkennung));

    return $neu->id;
};

$steht = static function (?int $satzId, ?int $feldId) use ($wpdb, $p): bool {
    if ($satzId === null || $feldId === null) {
        return true;
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d",
        $satzId,
        $feldId
    )) > 0;
};

$wert = static function (?int $satzId, ?int $feldId, TypedValue $inhalt, string $wofuer) use ($data, $schreiben, $sagen, $steht): void {
    if ($satzId === null || $feldId === null || $steht($satzId, $feldId)) {
        return;
    }

    $sagen("  {$wofuer} = " . $inhalt->describe());

    if ($schreiben) {
        $data->put($satzId, $feldId, $inhalt);
    }
};

/** Einheitenwerte als Teile; mehrere Zahlen bei einem mehrfachen Feld (Spannungen). */
$menge = static function (?int $satzId, ?int $feldId, array $zahlen, ?int $vorsatz, ?int $einheit, string $wofuer) use ($data, $schreiben, $sagen, $steht): void {
    if ($satzId === null || $feldId === null || $einheit === null || $steht($satzId, $feldId)) {
        return;
    }

    $sagen("  {$wofuer} = " . implode(', ', $zahlen));

    if (! $schreiben) {
        return;
    }

    foreach ($zahlen as $zahl) {
        $teil = $data->createPart($satzId, $feldId);
        $data->put($teil->id, EW_WERT, TypedValue::ofDecimal($zahl));
        $data->put($teil->id, EW_EINHEIT, TypedValue::ofReference($einheit));

        if ($vorsatz !== null) {
            $data->put($teil->id, EW_PREFIX, TypedValue::ofReference($vorsatz));
        }
    }
};

$link = static function (?int $satzId, ?int $feldId, string $adresse, string $beschriftung) use ($data, $schreiben, $sagen, $steht): void {
    if ($satzId === null || $feldId === null || $steht($satzId, $feldId)) {
        return;
    }

    $sagen("  Link: {$beschriftung}");

    if ($schreiben) {
        $teil = $data->createPart($satzId, $feldId);
        $data->put($teil->id, MEDIUM_ADRESSE, TypedValue::ofText($adresse));
        $data->put($teil->id, MEDIUM_BESCHRIFTUNG, TypedValue::ofText($beschriftung));
    }
};

// ── Zwei Einheiten für Datenraten, mit Zeichen ─────────────────────────────────────────────────────
$einheit = static function (string $name, string $zeichen) use ($knoten, $wpdb, $p, $schreiben): ?int {
    $id = $knoten($name, MIT_VORSATZ, Unit::class);

    if ($id !== null && $schreiben) {
        $labelId = (int) $wpdb->get_var($wpdb->prepare("SELECT label_id FROM {$p}nodes_named WHERE id = %d", $id));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$p}label_texts SET text_symbol = %s, text_select = %s WHERE label_id = %d AND text_symbol IS NULL",
            $zeichen,
            $zeichen . ' - ' . $name,
            $labelId
        ));
    }

    return $id;
};
$byteJeSekunde = $einheit('Byte pro Sekunde', 'B/s');
$bitJeSekunde  = $einheit('Bit pro Sekunde', 'bit/s');

// ── Übertragungsart: seriell oder parallel ─────────────────────────────────────────────────────────
$uebertragung = $knoten('Übertragungsarten', PC_CONSTANTS, Choice::class);
$seriell      = $knoten('seriell', $uebertragung, Constant::class);
$parallel     = $knoten('parallel', $uebertragung, Constant::class);

// ── Der Vater: aus der Auswahl der Laufwerksschnittstellen wird ein Knoten mit Datensätzen ──────────
// Die acht Konstanten darunter gehen in den Papierkorb; gemessen: kein Satz verweist auf sie. Sie kommen als Sätze wieder.
$alteKonstanten = $wpdb->get_results($wpdb->prepare(
    "SELECT n.id, n.name FROM {$p}nodes_named n JOIN {$p}nodes k ON k.id = n.id WHERE n.parent_node_id = %d AND k.klasse = %s ORDER BY n.sort_order",
    SCHNITTSTELLEN,
    Constant::class
));
$laufwerkNamen = array_map(static fn (object $zeile): string => (string) $zeile->name, $alteKonstanten);

if ($laufwerkNamen === []) {
    $laufwerkNamen = ['MFM', 'RLL', 'ESDI', 'IDE/ATA', 'ATAPI', 'SCSI', 'SATA', 'Floppy-Controller'];
}

foreach ($alteKonstanten as $zeile) {
    $sagen("Konstante «{$zeile->name}» in den Papierkorb");

    if ($schreiben) {
        $editor->moveToTrash((int) $zeile->id);
    }
}

$klasse = (string) $wpdb->get_var($wpdb->prepare("SELECT klasse FROM {$p}nodes WHERE id = %d", SCHNITTSTELLEN));

if ($klasse !== Category::class) {
    $sagen('«Schnittstellen» wird ein Knoten mit Datensätzen (Category)');

    if ($schreiben) {
        $editor->changeClass(SCHNITTSTELLEN, Category::class);
    }
}

$bezeichnung = $feld(SCHNITTSTELLEN, TEXT, 'Bezeichnung', RelationKind::Composition, Multiplicity::ExactlyOne);
$art         = $feld(SCHNITTSTELLEN, $uebertragung, 'Übertragung', RelationKind::Aggregation, Multiplicity::ZeroToOne);
$breite      = $feld(SCHNITTSTELLEN, EINHEITENWERT, 'Datenbreite', RelationKind::Composition, Multiplicity::ZeroToOne);
$taktVon     = $feld(SCHNITTSTELLEN, EINHEITENWERT, 'Takt von', RelationKind::Composition, Multiplicity::ZeroToOne);
$taktBis     = $feld(SCHNITTSTELLEN, EINHEITENWERT, 'Takt bis', RelationKind::Composition, Multiplicity::ZeroToOne);
$rateVon     = $feld(SCHNITTSTELLEN, EINHEITENWERT, 'Datenrate von', RelationKind::Composition, Multiplicity::ZeroToOne);
$rateBis     = $feld(SCHNITTSTELLEN, EINHEITENWERT, 'Datenrate bis', RelationKind::Composition, Multiplicity::ZeroToOne);
$spannung    = $feld(SCHNITTSTELLEN, EINHEITENWERT, 'Spannung', RelationKind::Composition, Multiplicity::ZeroToMany);
$eingefuehrt = $feld(SCHNITTSTELLEN, INTEGER, 'Eingeführt', RelationKind::Composition, Multiplicity::ZeroToOne);
$links       = $feld(SCHNITTSTELLEN, MEDIUM, 'Links', RelationKind::Composition, Multiplicity::ZeroToMany);
$hinweis     = $feld(SCHNITTSTELLEN, TEXT, 'Hinweis', RelationKind::Composition, Multiplicity::ZeroToOne);

// ── Die Gruppen ────────────────────────────────────────────────────────────────────────────────────
$busse      = $knoten('Erweiterungsbusse', SCHNITTSTELLEN, Category::class);
$adresse    = $feld($busse, EINHEITENWERT, 'Adressbreite', RelationKind::Composition, Multiplicity::ZeroToOne);
$laufwerke  = $knoten('Laufwerksschnittstellen', SCHNITTSTELLEN, Category::class);
$peripherie = $knoten('Peripherieschnittstellen', SCHNITTSTELLEN, Category::class);
$platine    = $knoten('Platinenbusse', SCHNITTSTELLEN, Category::class);
$netzwerk   = $knoten('Netzwerk', SCHNITTSTELLEN, Category::class);

// ── Erweiterungsbusse, nach Wikipedia ──────────────────────────────────────────────────────────────
$wiki = [
    'ISA'  => 'https://en.wikipedia.org/wiki/Industry_Standard_Architecture',
    'EISA' => 'https://en.wikipedia.org/wiki/Extended_Industry_Standard_Architecture',
    'VLB'  => 'https://en.wikipedia.org/wiki/VESA_Local_Bus',
    'PCI'  => 'https://en.wikipedia.org/wiki/Peripheral_Component_Interconnect',
];
$busDaten = [
    'ISA 8 Bit' => ['breite' => '8', 'adresse' => '20', 'takt' => ['4.77', null], 'rate' => null, 'volt' => ['5', '-5', '12', '-12'], 'jahr' => 1981, 'wiki' => 'ISA',
        'hinweis' => 'Nach Wikipedia: 8 Datenleitungen des 8088, Takt des 8088 mit 4,77 MHz. Die Datenrate nennt die Quelle nur für ISA insgesamt («8 MB/s oder 16 MB/s»), darum hier leer.'],
    'ISA 16 Bit' => ['breite' => '16', 'adresse' => '24', 'takt' => ['6', '8'], 'rate' => null, 'volt' => ['5', '-5', '12', '-12'], 'jahr' => 1984, 'wiki' => 'ISA',
        'hinweis' => 'Nach Wikipedia: mit dem IBM PC/AT, 6 MHz in den ersten, 8 MHz in späteren Modellen. Datenrate laut Quelle für ISA insgesamt «8 MB/s oder 16 MB/s».'],
    'EISA' => ['breite' => '32', 'adresse' => '32', 'takt' => ['8.33', null], 'rate' => [null, '33'], 'volt' => ['5', '-5', '12', '-12'], 'jahr' => 1988, 'wiki' => 'EISA',
        'hinweis' => 'Nach Wikipedia: 33 MB/s theoretisch, etwa 20 MB/s nutzbar. Adressbreite aus «4 GB Speicher» abgeleitet.'],
    'VESA Local Bus' => ['breite' => '32', 'adresse' => null, 'takt' => ['25', '40'], 'rate' => ['100', '160'], 'volt' => ['5'], 'jahr' => 1992, 'wiki' => 'VLB',
        'hinweis' => 'Nach Wikipedia: 100 MB/s bei 25 MHz, 133 bei 33, 160 bei 40; 50 MHz (200 MB/s) ausserhalb der Norm. Adressbreite nennt die Quelle nicht.'],
    'PCI' => ['breite' => '32', 'adresse' => '32', 'takt' => ['33.33', '66'], 'rate' => ['133', '533'], 'volt' => ['5', '3.3'], 'jahr' => 1992, 'wiki' => 'PCI',
        'hinweis' => 'Nach Wikipedia: 133 MB/s bei 32 Bit und 33 MHz, 533 MB/s bei 64 Bit und 66 MHz (64 Bit seit PCI 1.0 vorgesehen). 5 V, seit 2.0 auch 3,3 V Signalpegel.'],
];
$busIds = [];

foreach ($busDaten as $name => $d) {
    $sagen("\n── {$name}");
    $bus = $satz($busse, $bezeichnung, $name);
    $busIds[$name] = $bus;

    $wert($bus, $art, TypedValue::ofReference((int) ($parallel ?? 0)), 'Übertragung');
    $menge($bus, $breite, [$d['breite']], null, BIT, 'Datenbreite');

    if ($d['adresse'] !== null) {
        $menge($bus, $adresse, [$d['adresse']], null, BIT, 'Adressbreite');
    }

    $menge($bus, $taktVon, [$d['takt'][0]], MEGA, HERTZ, 'Takt von');

    if ($d['takt'][1] !== null) {
        $menge($bus, $taktBis, [$d['takt'][1]], MEGA, HERTZ, 'Takt bis');
    }

    if ($d['rate'] !== null) {
        if ($d['rate'][0] !== null) {
            $menge($bus, $rateVon, [$d['rate'][0]], MEGA, $byteJeSekunde, 'Datenrate von');
        }

        $menge($bus, $rateBis, [$d['rate'][1]], MEGA, $byteJeSekunde, 'Datenrate bis');
    }

    $menge($bus, $spannung, $d['volt'], null, VOLT, 'Spannung');
    $wert($bus, $eingefuehrt, TypedValue::ofInt($d['jahr']), 'Eingeführt');
    $wert($bus, $hinweis, TypedValue::ofText($d['hinweis']), 'Hinweis');
    $link($bus, $links, $wiki[$d['wiki']], 'Wikipedia');
}

// ── Die übrigen Gruppen: Namen und Übertragungsart ────────────────────────────────────────────────
$gruppen = [
    [$laufwerke, array_fill_keys($laufwerkNamen, null)],
    [$peripherie, ['RS-232' => $seriell, 'Centronics' => $parallel]],
    [$platine, ['I²C' => $seriell, 'SPI' => $seriell, '1-Wire' => $seriell]],
    [$netzwerk, ['Ethernet' => $seriell]],
];

// Bei den Laufwerken nur, wo die Übertragungsart eindeutig ist.
$gruppen[0][1]['IDE/ATA'] = $parallel;
$gruppen[0][1]['SATA']    = $seriell;

foreach ($gruppen as [$gruppe, $eintraege]) {
    foreach ($eintraege as $name => $uebertragungsart) {
        $eintrag = $satz($gruppe, $bezeichnung, $name);

        if ($uebertragungsart !== null) {
            $wert($eintrag, $art, TypedValue::ofReference($uebertragungsart), 'Übertragung');
        }
    }
}

// ── Das Laufwerksfeld zeigt auf die neue Gruppe ─────────────────────────────────────────────────────
$ziel = (int) $wpdb->get_var($wpdb->prepare("SELECT to_node_id FROM {$p}relations WHERE id = %d", STORAGE_SCHNITTSTELLE));

if ($laufwerke !== null && $ziel !== $laufwerke) {
    $sagen("\nFeld «Schnittstelle» an Storage zeigt auf Laufwerksschnittstellen");

    if ($schreiben) {
        $editor->retargetField(STORAGE, STORAGE_SCHNITTSTELLE, $laufwerke);
    }
}

// ── Steckplatz: «Bus» statt «Art», die Werte übertragen ────────────────────────────────────────────
$spArt = $wpdb->get_var($wpdb->prepare(
    "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = 'Art'",
    STECKPLATZ
));
$spBus = $feld(STECKPLATZ, $busse, 'Bus', RelationKind::Aggregation, Multiplicity::ExactlyOne);

if ($spArt !== null) {
    $alt = $wpdb->get_results($wpdb->prepare(
        "SELECT v.node_record_id AS satz, n.name FROM {$p}relation_records v JOIN {$p}nodes_named n ON n.id = v.value_ref WHERE v.relation_id = %d",
        (int) $spArt
    ));

    foreach ($alt as $zeile) {
        $wert((int) $zeile->satz, $spBus, TypedValue::ofRecordReference((int) ($busIds[$zeile->name] ?? 0)), "Steckplatz {$zeile->satz}: Bus {$zeile->name}");
    }

    $sagen('Feld «Art» am Steckplatz in den Schatten, «Steckplatzarten» in den Papierkorb');

    if ($schreiben) {
        $editor->removeField(STECKPLATZ, (int) $spArt);
        $editor->moveField(STECKPLATZ, (int) $spBus, -1);
        $editor->moveToTrash(STECKPLATZARTEN, true);
    }
}

$sagen($schreiben ? "\nFertig." : "\nNur gezeigt. Mit --write umbauen.");
