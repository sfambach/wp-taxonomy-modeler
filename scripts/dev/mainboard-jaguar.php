<?php declare(strict_types=1);

/**
 * Mainboards ins Modell, und das Octek Jaguar IV 386 als Beispiel mit seinen Jumpern (Exkurs vom 2026-09-17).
 *
 *     php scripts/dev/mainboard-jaguar.php            # nur zeigen, was geschähe
 *     php scripts/dev/mainboard-jaguar.php --write    # anlegen
 *
 * ⚠️ *Seine Worte: «ja, chipsatz und cpu sind aggregationen, das ist ein cooles beispiel weil es das board auch noch in anderen variationen
 * gibt», «vergiss nicht stromversorgung at unterschied zwischen 8bit und 16bit isa slots, slots könnten modell sein mit eigenschaften»,
 * «formfaktor at, baby at ...», «auch model mit abmessungen von bis».*
 *
 * ⚠️ **Was aus einer Quelle stammt, steht als Quelle daneben; nichts wird dazuerfunden.** *Zu «IV-Q rev 1.2» gibt es keine Unterlage; die
 * Jumper stammen von der Jaguar IV 386 (Stason, MicroHouse) und sind am Board zu prüfen.*
 *
 * Wiederholbar: jeder Schritt sucht erst, ob es das schon gibt. Bezeichnung und Hersteller erbt ein Mainboard von `Hardware` bzw. `Models`.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\NodeClass\Choice;
use Taxmod\Core\Model\NodeClass\Constant;
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

// Gemessen am 2026-09-17
const INTERNAL = 32483, COMPOSITIONS = 404, PC_CONSTANTS = 149000102259, MANUFACTURER = 149000102677, CPUS = 149000103845;
const TEXT = 1175, INTEGER = 1171, MEDIA = 149000105224, EINHEITENWERT = 4232;
const METER = 4036, HERTZ = 4054, BYTE = 149000103868, MILLI = 4014, MEGA = 4002;
// Geerbte Felder: Bezeichnung hängt an `Hardware`, Hersteller an `Models`; der Name einer Organisation an `Manufacturer`.
const BEZEICHNUNG = 149000103839, HERSTELLER = 149000102544, ORGA_NAME = 149000102666;
const EW_WERT = 4234, EW_PREFIX = 4235, EW_EINHEIT = 4236;
const CPU_386DX33 = 22915;

$sagen = static function (string $satz): void {
    echo $satz, "\n";
};

/** Ein Knoten unter einem Vater, am Namen gefunden oder angelegt. */
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

/** Ein Feld an einem Knoten, am Namen gefunden oder angelegt. */
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

/** Ein Satz eines Knotens, am Wert eines Feldes wiedererkannt. */
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

/** Ob an einem Satz für dieses Feld schon etwas steht. */
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

/** Einen Wert schreiben, wo noch keiner steht. */
$wert = static function (?int $satzId, ?int $feldId, TypedValue $inhalt, string $wofuer) use ($data, $schreiben, $sagen, $steht): void {
    if ($satzId === null || $feldId === null || $steht($satzId, $feldId)) {
        return;
    }

    $sagen("  {$wofuer} = " . $inhalt->describe());

    if ($schreiben) {
        $data->put($satzId, $feldId, $inhalt);
    }
};

/** Ein Einheitenwert als Teil: Zahl, Vorsatz, Einheit. */
$menge = static function (?int $satzId, ?int $feldId, string $zahl, ?int $vorsatz, int $einheit, string $wofuer) use ($data, $schreiben, $sagen, $steht): void {
    if ($satzId === null || $feldId === null || $steht($satzId, $feldId)) {
        return;
    }

    $sagen("  {$wofuer} = {$zahl}");

    if (! $schreiben) {
        return;
    }

    $teil = $data->createPart($satzId, $feldId);
    $data->put($teil->id, EW_WERT, TypedValue::ofDecimal($zahl));
    $data->put($teil->id, EW_EINHEIT, TypedValue::ofReference($einheit));

    if ($vorsatz !== null) {
        $data->put($teil->id, EW_PREFIX, TypedValue::ofReference($vorsatz));
    }
};

// ── Konstanten ─────────────────────────────────────────────────────────────────────────────────
$steckplatzarten = $knoten('Steckplatzarten', PC_CONSTANTS, Choice::class);
$slotIds         = [];

foreach (['ISA 8 Bit', 'ISA 16 Bit', 'EISA', 'VESA Local Bus', 'PCI'] as $art) {
    $slotIds[$art] = $knoten($art, $steckplatzarten, Constant::class);
}

$strom    = $knoten('Stromanschlüsse', PC_CONSTANTS, Choice::class);
$stromIds = [];

foreach (['AT (P8 + P9)', 'ATX 20-polig', 'ATX 24-polig'] as $art) {
    $stromIds[$art] = $knoten($art, $strom, Constant::class);
}

// ── Formfaktoren als eigenes Modell mit Abmessungen von/bis ────────────────────────────────────
$formfaktoren = $knoten('Formfaktoren', PC_CONSTANTS, Category::class);
$ffName       = $feld($formfaktoren, TEXT, 'Bezeichnung', RelationKind::Composition, Multiplicity::ExactlyOne);
$ffBreiteVon  = $feld($formfaktoren, EINHEITENWERT, 'Breite von', RelationKind::Composition, Multiplicity::ZeroToOne);
$ffBreiteBis  = $feld($formfaktoren, EINHEITENWERT, 'Breite bis', RelationKind::Composition, Multiplicity::ZeroToOne);
$ffLaengeVon  = $feld($formfaktoren, EINHEITENWERT, 'Länge von', RelationKind::Composition, Multiplicity::ZeroToOne);
$ffLaengeBis  = $feld($formfaktoren, EINHEITENWERT, 'Länge bis', RelationKind::Composition, Multiplicity::ZeroToOne);

$babyAt = $satz($formfaktoren, $ffName, 'Baby AT');
$menge($babyAt, $ffBreiteBis, '220', MILLI, METER, 'Breite bis');
$menge($babyAt, $ffLaengeBis, '330', MILLI, METER, 'Länge bis');
$at = $satz($formfaktoren, $ffName, 'AT');
$sagen('  AT bleibt ohne Abmessungen — dazu habe ich keine Quelle');

// ── Chipsätze (erbt Bezeichnung und Hersteller) ────────────────────────────────────────────────
$chipsaetze = $knoten('Chipsätze', INTERNAL, Category::class);
$headland   = $satz($chipsaetze, BEZEICHNUNG, 'Headland HTK320');
$opti       = $satz($chipsaetze, BEZEICHNUNG, 'OPTi (386, Typ unbestimmt)');

// ── Teile: Steckplatz und Jumper-Einstellung ───────────────────────────────────────────────────
$steckplatz = $knoten('Steckplatz', COMPOSITIONS, Category::class);
$spArt      = $feld($steckplatz, $steckplatzarten, 'Art', RelationKind::Aggregation, Multiplicity::ExactlyOne);
$spAnzahl   = $feld($steckplatz, INTEGER, 'Anzahl', RelationKind::Composition, Multiplicity::ExactlyOne);

$jumperTeil = $knoten('Jumper-Einstellung', COMPOSITIONS, Category::class);
$juName     = $feld($jumperTeil, TEXT, 'Bezeichnung', RelationKind::Composition, Multiplicity::ExactlyOne);
$juFunktion = $feld($jumperTeil, TEXT, 'Funktion', RelationKind::Composition, Multiplicity::ZeroToOne);
$juStellung = $feld($jumperTeil, TEXT, 'Stellung', RelationKind::Composition, Multiplicity::ZeroToOne);
$juQuelle   = $feld($jumperTeil, TEXT, 'Quelle', RelationKind::Composition, Multiplicity::ZeroToOne);

// ── Mainboards ─────────────────────────────────────────────────────────────────────────────────
$mainboards = $knoten('Mainboards', INTERNAL, Category::class);
$mbRevision = $feld($mainboards, TEXT, 'Revision', RelationKind::Composition, Multiplicity::ZeroToOne);
$mbChipsatz = $feld($mainboards, $chipsaetze, 'Chipsatz', RelationKind::Aggregation, Multiplicity::ZeroToOne);
$mbCpu      = $feld($mainboards, CPUS, 'CPU', RelationKind::Aggregation, Multiplicity::ZeroToMany);
$mbTakt     = $feld($mainboards, EINHEITENWERT, 'Takt', RelationKind::Composition, Multiplicity::ZeroToMany);
$mbRam      = $feld($mainboards, EINHEITENWERT, 'RAM höchstens', RelationKind::Composition, Multiplicity::ZeroToOne);
$mbCache    = $feld($mainboards, TEXT, 'Cache', RelationKind::Composition, Multiplicity::ZeroToOne);
$mbForm     = $feld($mainboards, $formfaktoren, 'Formfaktor', RelationKind::Aggregation, Multiplicity::ZeroToOne);
$mbStrom    = $feld($mainboards, $strom, 'Stromversorgung', RelationKind::Aggregation, Multiplicity::ZeroToOne);
$mbSlots    = $feld($mainboards, $steckplatz, 'Steckplätze', RelationKind::Composition, Multiplicity::ZeroToMany);
$mbJumper   = $feld($mainboards, $jumperTeil, 'Jumper', RelationKind::Composition, Multiplicity::ZeroToMany);
$mbLinks    = $feld($mainboards, MEDIA, 'Links', RelationKind::Composition, Multiplicity::ZeroToMany);
$mbHinweis  = $feld($mainboards, TEXT, 'Hinweis', RelationKind::Composition, Multiplicity::ZeroToOne);

$octek = $satz(MANUFACTURER, ORGA_NAME, 'Octek');

/** Ein Teil mit Werten anhängen, wenn es noch keines gibt. */
$teil = static function (?int $satzId, ?int $feldId, array $werte, string $wofuer) use ($data, $wpdb, $p, $schreiben, $sagen): void {
    if ($satzId === null || $feldId === null) {
        return;
    }

    $wie = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d",
        $satzId,
        $feldId
    ));

    if ($wie > 0 && $wofuer !== 'Jumper' && $wofuer !== 'Steckplatz') {
        return;
    }

    $sagen("  {$wofuer}: " . implode(', ', array_map(static fn (TypedValue $w): string => $w->describe(), $werte)));

    if (! $schreiben) {
        return;
    }

    $neu = $data->createPart($satzId, $feldId);

    foreach ($werte as $kante => $inhalt) {
        $data->put($neu->id, (int) $kante, $inhalt);
    }
};

// Die zwei Bauarten desselben Boards — sein Wort: «das board gibt es auch noch in anderen variationen».
$boards = [
    'Jaguar IV 386' => [
        'revision' => '1.2',
        'chipsatz' => $opti,
        'takt'     => ['33', '40'],
        'slots'    => ['ISA 16 Bit' => 6, 'ISA 8 Bit' => 1],
        'links'    => ['https://theretroweb.com/motherboards/4017', 'https://stason.org/TULARC/pc/motherboards/O/OCEAN-INFORMATION-SYSTEMS-INC-386-JAGUAR-IV-386.html'],
        'hinweis'  => 'Seins: Revision 1.2 mit 386DX-33. Die Quellen führen keine Revision 1.2; Steckplätze, Takt und Chipsatz nach The Retro Web (Jaguar IV 386), Jumper nach Stason (Ocean Information Systems).',
    ],
    'Jaguar IV-Q 386DX' => [
        'revision' => '1.3 / 1.4',
        'chipsatz' => $headland,
        'takt'     => ['40'],
        'slots'    => ['ISA 16 Bit' => 5, 'ISA 8 Bit' => 2],
        'links'    => ['https://theretroweb.com/motherboards/10441'],
        'hinweis'  => 'Nach The Retro Web: Headland HTK320, AMI-BIOS Kern 121291. Bekanntes Übel: auslaufende Ni-Cd-Batterie früh entfernen.',
    ],
];

foreach ($boards as $name => $angabe) {
    $sagen("\n── {$name}");
    $board = $satz($mainboards, BEZEICHNUNG, $name);

    $wert($board, HERSTELLER, TypedValue::ofRecordReference((int) ($octek ?? 0)), 'Hersteller');
    $wert($board, $mbRevision, TypedValue::ofText($angabe['revision']), 'Revision');
    $wert($board, $mbChipsatz, TypedValue::ofRecordReference((int) ($angabe['chipsatz'] ?? 0)), 'Chipsatz');
    $wert($board, $mbCpu, TypedValue::ofRecordReference(CPU_386DX33), 'CPU');
    $wert($board, $mbCache, TypedValue::ofText('32, 64 oder 128 KB'), 'Cache');
    $wert($board, $mbForm, TypedValue::ofRecordReference((int) ($babyAt ?? 0)), 'Formfaktor');
    $wert($board, $mbStrom, TypedValue::ofReference((int) ($stromIds['AT (P8 + P9)'] ?? 0)), 'Stromversorgung');
    $wert($board, $mbHinweis, TypedValue::ofText($angabe['hinweis']), 'Hinweis');
    $menge($board, $mbRam, '32', MEGA, BYTE, 'RAM höchstens');

    foreach ($angabe['takt'] as $takt) {
        $menge($board, $mbTakt, $takt, MEGA, HERTZ, 'Takt');
    }

    foreach ($angabe['slots'] as $art => $anzahl) {
        $teil($board, $mbSlots, [(int) $spArt => TypedValue::ofReference((int) ($slotIds[$art] ?? 0)), (int) $spAnzahl => TypedValue::ofInt($anzahl)], 'Steckplatz');
    }

    foreach ($angabe['links'] as $link) {
        if ($board !== null && $mbLinks !== null && $schreiben) {
            $data->appendValue($board, $mbLinks, TypedValue::ofText($link));
        }

        $sagen('  Link: ' . $link);
    }
}

// ── Die Jumper der Jaguar IV 386 ───────────────────────────────────────────────────────────────
$jaguar = $satz($mainboards, BEZEICHNUNG, 'Jaguar IV 386');
$stason = 'Stason, Ocean Information Systems JAGUAR IV 386 — an diesem Board ungeprüft';
$mh     = 'MicroHouse, JAGUAR IV 486DLC — anderes Board derselben Reihe, ungeprüft';

foreach ([
    ['JP1', 'Passwort an', 'Stifte 1 & 2 geschlossen', $stason],
    ['JP1', 'Passwort aus', 'Stifte 2 & 3 geschlossen', $stason],
    ['JP3', 'Cache 32 KB oder 64 KB', 'Stifte 2 & 3 geschlossen', $stason],
    ['JP3', 'Cache 128 KB', 'Stifte 1 & 2 geschlossen', $stason],
    ['JP5', 'IDE schnell', 'Stifte 2 & 3 geschlossen', $stason],
    ['JP5', 'IDE normal', 'Stifte 1 & 2 geschlossen', $stason],
    ['JP4', 'CMOS löschen', 'Stifte 1 & 2 geschlossen', $mh],
    ['JP4', 'CMOS normal', 'Stifte 2 & 3 geschlossen', $mh],
] as [$name, $funktion, $stellung, $quelle]) {
    $teil($jaguar, $mbJumper, [
        (int) $juName     => TypedValue::ofText($name),
        (int) $juFunktion => TypedValue::ofText($funktion),
        (int) $juStellung => TypedValue::ofText($stellung),
        (int) $juQuelle   => TypedValue::ofText($quelle),
    ], 'Jumper');
}

echo "\n", $schreiben ? "geschrieben\n" : "trocken — nichts geschrieben\n";
