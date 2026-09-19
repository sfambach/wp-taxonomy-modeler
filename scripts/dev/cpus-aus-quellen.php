<?php declare(strict_types=1);

/**
 * CPUs frisch aus Quellen übernehmen, je Variante ein Satz, jeder Wert mit Quelle und Herkunft (2026-09-19).
 *
 *     php scripts/dev/cpus-aus-quellen.php <cpus-quellen.json> <cpus-quellenverzeichnis.json>            # nur zeigen
 *     php scripts/dev/cpus-aus-quellen.php <cpus-quellen.json> <cpus-quellenverzeichnis.json> --write    # übernehmen
 *
 * ⚠️ *Seine Worte: «beim übernehmen berichtigen», «eine eigene CPU für eine Variante anlegen», «Komma und Punkt einheitlich … die
 * Datumsformate einheitlich … Newtonmeter und Mikrometer harmonisieren» (D-868). Die alte Tabelle (TablePress #12) war nach Prüfung nur
 * Rohmaterial (INF-058); die Werte hier stammen aus Wikipedia und Intel-Datenblättern, gesammelt am 2026-09-19.*
 *
 * Felder: was für jeden Prozessor vergleichbar ist, steht am Vater «Prozessoren» (Codename, Sockel, Busbreiten, Spannungen, Leistung,
 * Fertigung, Transistoren); was nur eine CPU hat, an «CPUs» (Bustakt, Multiplikator, Caches, Befehlssatz, Erweiterungen). Geerbt und
 * benutzt: Bezeichnung, Teilenummer, Hersteller, Erscheinungs Monat/Jahr, Gebaut bis, Familie, Takt, Quellen, Herkunft.
 *
 * Wiederholbar: vorhandene Sätze werden am Namen erkannt und ergänzt; ein Einheitenwert, der schon steht, bleibt.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\NodeClass\Choice;
use Taxmod\Core\Model\NodeClass\Constant;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);
$daten     = json_decode((string) file_get_contents($argv[1] ?? ''), true) ?: exit("keine CPU-Daten\n");
$quellen   = json_decode((string) file_get_contents($argv[2] ?? ''), true) ?: exit("kein Quellenverzeichnis\n");

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
const PROZESSOREN = 149000103929, CPUS = 149000103845, PC_KONSTANTEN = 149000102259, MODEL = 402;
const TEXT = 1175, INTEGER = 1171, DECIMAL = 1173, EINHEITENWERT = 4232;
const EW_WERT = 4234, EW_PREFIX = 4235, EW_EINHEIT = 4236;
const BIT = 149000103889, HERTZ = 4054, VOLT = 4050, WATT = 4048, METER = 4036, BYTE = 149000103868;
const MEGA = 4002, KILO = 4004, NANO = 4018;
const BEZEICHNUNG = 149000103839, TEILENUMMER = 149000103844, HERSTELLER = 149000102544, INTEL = 22656;
const FAMILIE_PENTIUM = 24298, FAMILIE_DX = 24196, FAMILIE_SX = 24212;

$sagen = static function (string $satz): void {
    echo $satz, "\n";
};

$knoten = static function (string $name, int $vater, string $klasse) use ($editor, $wpdb, $p, $schreiben, $sagen): ?int {
    $steht = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", $name, $vater));

    if ($steht !== null) {
        return (int) $steht;
    }

    $sagen("Knoten «{$name}» unter {$vater}");

    return $schreiben ? $editor->createNode($name, $vater, $klasse)->id : null;
};

$feldAn = static function (int $eigner, string $name) use ($wpdb, $p): ?int {
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
        $eigner,
        $name
    ));

    return $id === null ? null : (int) $id;
};

$feld = static function (int $eigner, ?int $ziel, string $name, RelationKind $art, Multiplicity $wieOft) use ($editor, $feldAn, $schreiben, $sagen): ?int {
    $steht = $feldAn($eigner, $name);

    if ($steht !== null || $ziel === null) {
        return $steht;
    }

    $sagen("Feld «{$name}» an {$eigner} ({$wieOft->value})");

    if (! $schreiben) {
        return null;
    }

    $kante = $editor->addField($eigner, $ziel, $name, $art);

    if ($kante->multiplicity !== $wieOft) {
        $kante = $editor->setMultiplicity($eigner, $kante->id, $wieOft);
    }

    return $kante->id;
};

// ── Auswahlen ──────────────────────────────────────────────────────────────────────────────────────
$sockelWahl = $knoten('Sockel und Gehäuse', PC_KONSTANTEN, Choice::class);
$befehlWahl = $knoten('Befehlssätze', PC_KONSTANTEN, Choice::class);
$erweitWahl = $knoten('Prozessor-Erweiterungen', PC_KONSTANTEN, Choice::class);
$konstante  = static function (?int $wahl, string $name) use ($knoten): ?int {
    return $wahl === null ? null : $knoten($name, $wahl, Constant::class);
};

// ── Felder ─────────────────────────────────────────────────────────────────────────────────────────
$ew  = static fn (int $an, string $name) => $feld($an, EINHEITENWERT, $name, RelationKind::Composition, Multiplicity::ZeroToOne);
$f   = [
    'codename'     => $feld(PROZESSOREN, TEXT, 'Codename', RelationKind::Composition, Multiplicity::ZeroToOne),
    'sockel'       => $feld(PROZESSOREN, $sockelWahl, 'Sockel', RelationKind::Aggregation, Multiplicity::ZeroToMany),
    'datenbus'     => $ew(PROZESSOREN, 'Datenbus'),
    'adressbus'    => $ew(PROZESSOREN, 'Adressbus'),
    'kern_von'     => $ew(PROZESSOREN, 'Kernspannung von'),
    'kern_bis'     => $ew(PROZESSOREN, 'Kernspannung bis'),
    'io_von'       => $ew(PROZESSOREN, 'I/O-Spannung von'),
    'io_bis'       => $ew(PROZESSOREN, 'I/O-Spannung bis'),
    'leistung'     => $ew(PROZESSOREN, 'Leistung höchstens'),
    'fertigung'    => $ew(PROZESSOREN, 'Fertigung'),
    'transistoren' => $feld(PROZESSOREN, INTEGER, 'Transistoren', RelationKind::Composition, Multiplicity::ZeroToOne),
    'bustakt'      => $ew(CPUS, 'Bustakt'),
    'multi'        => $feld(CPUS, DECIMAL, 'Multiplikator', RelationKind::Composition, Multiplicity::ZeroToOne),
    'l1d'          => $ew(CPUS, 'L1-Cache Daten'),
    'l1b'          => $ew(CPUS, 'L1-Cache Befehle'),
    'l2'           => $ew(CPUS, 'L2-Cache'),
    'befehlssatz'  => $feld(CPUS, $befehlWahl, 'Befehlssatz', RelationKind::Aggregation, Multiplicity::ZeroToOne),
    'erweiterung'  => $feld(CPUS, $erweitWahl, 'Erweiterungen', RelationKind::Aggregation, Multiplicity::ZeroToMany),
];
$geerbt = [
    'takt'      => $feldAn(PROZESSOREN, 'Takt'),
    'familie'   => $feldAn(PROZESSOREN, 'Familie'),
    'erschien'  => $feldAn(149000103431, 'Erscheinungs Monat/Jahr'),
    'gebaut'    => $feldAn(149000103001, 'Gebaut bis'),
    'quellen'   => $feldAn(MODEL, 'Quellen'),
    'herkunft'  => $feldAn(MODEL, 'Herkunft'),
    'hinweis'   => $feldAn(MODEL, 'Herkunftshinweis'),
];

// ── Quellenverzeichnis ─────────────────────────────────────────────────────────────────────────────
$verzeichnis = (int) $wpdb->get_var("SELECT id FROM {$p}nodes_named WHERE name = 'Quellenverzeichnis' AND parent_node_id = " . MODEL);
$qTitel      = $feldAn($verzeichnis, 'Titel');
$qAdresse    = $feldAn($verzeichnis, 'Adresse');
$qArt        = $feldAn($verzeichnis, 'Art');
$qAbgerufen  = $feldAn($verzeichnis, 'Abgerufen');
$quellenart  = static fn (string $art): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = (SELECT id FROM {$p}nodes_named WHERE name = 'Quellenarten' LIMIT 1)",
    $art
))) === null ? null : (int) $id;
$quelleFuer  = [];

foreach ($quellen as $q) {
    $steht = $wpdb->get_var($wpdb->prepare("SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d AND value_text = %s LIMIT 1", $qAdresse, $q['url']));

    if ($steht !== null) {
        $quelleFuer[$q['url']] = (int) $steht;
        continue;
    }

    $sagen("Quelle «{$q['titel']}»");

    if ($schreiben) {
        $neu = $data->create($verzeichnis, RecordType::User);
        $data->put($neu->id, $qTitel, TypedValue::ofText($q['titel']));
        $data->put($neu->id, $qAdresse, TypedValue::ofText($q['url']));

        if (($art = $quellenart($q['art'] === 'Datenblatt' ? 'Datenblatt' : 'Webseite')) !== null) {
            $data->put($neu->id, $qArt, TypedValue::ofReference($art));
        }

        $data->put($neu->id, $qAbgerufen, TypedValue::ofDate($q['abgerufen'] . ' 00:00:00'));
        $quelleFuer[$q['url']] = $neu->id;
    }
}

// ── Hilfen ─────────────────────────────────────────────────────────────────────────────────────────
$steht = static fn (int $satz, ?int $feld): bool => $feld === null || (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d",
    $satz,
    $feld
)) > 0;

$menge = static function (?int $satz, ?int $feld, $zahl, ?int $vorsatz, int $einheit) use ($data, $schreiben, $steht): void {
    if ($satz === null || $feld === null || $zahl === null || ! $schreiben || $steht($satz, $feld)) {
        return;
    }

    $teil = $data->createPart($satz, $feld);
    // *Eine Schreibweise: Punkt als Dezimalzeichen, keine Nachkommanullen (D-868).*
    $data->put($teil->id, EW_WERT, TypedValue::ofDecimal(rtrim(rtrim(number_format((float) $zahl, 6, '.', ''), '0'), '.')));
    $data->put($teil->id, EW_EINHEIT, TypedValue::ofReference($einheit));

    if ($vorsatz !== null) {
        $data->put($teil->id, EW_PREFIX, TypedValue::ofReference($vorsatz));
    }
};

// *Ein Datum in einer Form: JJJJ-MM-TT; nur das Jahr oder nur der Monat wird zum ersten Tag, und der Hinweis sagt es.*
$datum = static function (?string $wert): array {
    if ($wert === null || $wert === '') {
        return [null, ''];
    }

    return match (strlen($wert)) {
        4       => [$wert . '-01-01 00:00:00', 'nur das Jahr belegt'],
        7       => [$wert . '-01 00:00:00', 'nur Monat und Jahr belegt'],
        default => [$wert . ' 00:00:00', ''],
    };
};

$herkunftsart = static fn (string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = (SELECT id FROM {$p}nodes_named WHERE name = 'Herkunftsarten' LIMIT 1)",
    $name
))) === null ? null : (int) $id;

$feldnamen = [
    'multiplikator' => 'Multiplikator', 'bustakt_mhz' => 'Bustakt', 'fertigung_nm' => 'Fertigung', 'produktionsende' => 'Gebaut bis',
    'markteinfuehrung' => 'Erscheinungs Monat/Jahr', 'kernspannung_v' => 'Kernspannung', 'io_spannung_v' => 'I/O-Spannung', 'tdp_w' => 'Leistung',
    'takt_mhz' => 'Takt', 'transistoren' => 'Transistoren', 'sockel' => 'Sockel', 'codename' => 'Codename', 'familie' => 'Familie',
    'datenbus_bit' => 'Datenbus', 'adressbus_bit' => 'Adressbus', 'l1_daten_kb' => 'L1-Cache Daten', 'l1_befehle_kb' => 'L1-Cache Befehle',
    'erweiterungen' => 'Erweiterungen', 'befehlssatz' => 'Befehlssatz', 'variante' => 'Teilenummer', 'hersteller' => 'Hersteller',
];

// ── Die CPUs ───────────────────────────────────────────────────────────────────────────────────────
$neu = 0;
$ergaenzt = 0;

foreach ($daten as $cpu) {
    // ⚠️ *RapidCAD ist ein Satz aus zwei Chips, je Takt ein Satz «RapidCAD-25» (D-874, sein Wort: «behalten ein satz»). Der Hilfschip
    // RapidCAD-2 ist kein eigener Prozessor; was ihn betrifft, trägt der Satz — `rapidcad-als-satz.php`.*
    if (preg_match('/^RapidCAD-2 /', (string) $cpu['bezeichnung']) === 1) {
        continue;
    }

    // *Die DX- und SX-Taktstufen heissen wie die vorhandenen Sätze («80386DX-33»), damit der Bestand einheitlich bleibt.*
    $name = (string) preg_replace(['/^i386(DX|SX)-(\d+)$/', '/^RapidCAD-1 \((\d+) MHz\)$/'], ['80386$1-$2', 'RapidCAD-$1'], (string) $cpu['bezeichnung']);
    $satz = $wpdb->get_var($wpdb->prepare(
        "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
         WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s LIMIT 1",
        CPUS,
        BEZEICHNUNG,
        $name
    ));

    if ($satz === null) {
        $sagen("neu: {$name}");
        ++$neu;

        if (! $schreiben) {
            continue;
        }

        $satz = $data->create(CPUS, RecordType::User)->id;
        $data->put($satz, BEZEICHNUNG, TypedValue::ofText($name));
    } else {
        $satz = (int) $satz;
        $sagen("ergänzt: {$name} (#{$satz})");
        ++$ergaenzt;

        if (! $schreiben) {
            continue;
        }
    }

    $data->put($satz, HERSTELLER, TypedValue::ofRecordReference(INTEL));

    if (($cpu['variante'] ?? null) !== null && ! $steht($satz, TEILENUMMER)) {
        $data->appendValue($satz, TEILENUMMER, TypedValue::ofText((string) $cpu['variante']));
    }

    $familie = match (true) {
        str_starts_with((string) $cpu['familie'], 'Pentium')                                   => FAMILIE_PENTIUM,
        preg_match('/^(i386DX|80386DX|RapidCAD|i376)/', (string) $cpu['bezeichnung']) === 1    => FAMILIE_DX,
        default                                                                                 => FAMILIE_SX,
    };
    $data->put($satz, (int) $geerbt['familie'], TypedValue::ofRecordReference($familie));

    if (($cpu['codename'] ?? null) !== null) {
        $data->put($satz, (int) $f['codename'], TypedValue::ofText((string) $cpu['codename']));
    }

    if (($cpu['transistoren'] ?? null) !== null) {
        $data->put($satz, (int) $f['transistoren'], TypedValue::ofInt((int) $cpu['transistoren']));
    }

    if (($cpu['multiplikator'] ?? null) !== null) {
        $data->put($satz, (int) $f['multi'], TypedValue::ofDecimal(rtrim(rtrim(number_format((float) $cpu['multiplikator'], 3, '.', ''), '0'), '.')));
    }

    foreach ((array) ($cpu['sockel'] ?? []) as $sockel) {
        $id = $konstante($sockelWahl, (string) $sockel);

        if ($id !== null) {
            $data->appendValue($satz, (int) $f['sockel'], TypedValue::ofReference($id));
        }
    }

    if (($cpu['befehlssatz'] ?? null) !== null && ($id = $konstante($befehlWahl, (string) $cpu['befehlssatz'])) !== null) {
        $data->put($satz, (int) $f['befehlssatz'], TypedValue::ofReference($id));
    }

    foreach ((array) ($cpu['erweiterungen'] ?? []) as $erweiterung) {
        if (($id = $konstante($erweitWahl, (string) $erweiterung)) !== null) {
            $data->appendValue($satz, (int) $f['erweiterung'], TypedValue::ofReference($id));
        }
    }

    $menge($satz, $geerbt['takt'], $cpu['takt_mhz'] ?? null, MEGA, HERTZ);
    $menge($satz, $f['bustakt'], $cpu['bustakt_mhz'] ?? null, MEGA, HERTZ);
    $menge($satz, $f['datenbus'], $cpu['datenbus_bit'] ?? null, null, BIT);
    $menge($satz, $f['adressbus'], $cpu['adressbus_bit'] ?? null, null, BIT);
    $menge($satz, $f['leistung'], $cpu['tdp_w'] ?? null, null, WATT);
    $menge($satz, $f['fertigung'], $cpu['fertigung_nm'] ?? null, NANO, METER);
    $menge($satz, $f['l1d'], $cpu['l1_daten_kb'] ?? null, KILO, BYTE);
    $menge($satz, $f['l1b'], $cpu['l1_befehle_kb'] ?? null, KILO, BYTE);
    $menge($satz, $f['l2'], $cpu['l2_kb'] ?? null, KILO, BYTE);

    foreach (['kernspannung_v' => ['kern_von', 'kern_bis'], 'io_spannung_v' => ['io_von', 'io_bis']] as $schluessel => [$von, $bis]) {
        $wert = $cpu[$schluessel] ?? null;

        if (is_array($wert)) {
            $menge($satz, $f[$von], $wert['min'] ?? null, null, VOLT);
            $menge($satz, $f[$bis], $wert['max'] ?? null, null, VOLT);
        } elseif ($wert !== null) {
            $menge($satz, $f[$von], $wert, null, VOLT);
        }
    }

    $hinweise = [];

    foreach (['markteinfuehrung' => 'erschien', 'produktionsende' => 'gebaut'] as $schluessel => $ziel) {
        [$wert, $genauigkeit] = $datum($cpu[$schluessel] ?? null);

        if ($wert !== null) {
            $data->put($satz, (int) $geerbt[$ziel], TypedValue::ofDate($wert));

            if ($genauigkeit !== '') {
                $hinweise[] = $feldnamen[$schluessel] . ': ' . $genauigkeit;
            }
        }
    }

    // *Herkunft am Satz (D-868): welche Arten vorkommen, und im Hinweis, welche Werte abgeleitet sind.*
    $arten      = array_values(array_unique(array_values((array) ($cpu['herkunft'] ?? []))));
    $abgeleitet = array_keys(array_filter((array) ($cpu['herkunft'] ?? []), static fn ($a): bool => $a === 'abgeleitet'));

    if (! $steht($satz, $geerbt['herkunft'])) {
        foreach ($arten as $art) {
            if (($id = $herkunftsart((string) $art)) !== null) {
                $data->appendValue($satz, (int) $geerbt['herkunft'], TypedValue::ofReference($id));
            }
        }
    }

    if ($abgeleitet !== []) {
        array_unshift($hinweise, 'Abgeleitet: ' . implode(', ', array_map(static fn (string $k): string => $feldnamen[$k] ?? $k, $abgeleitet)));
    }

    if (trim((string) ($cpu['anmerkung'] ?? '')) !== '') {
        $hinweise[] = trim((string) $cpu['anmerkung']);
    }

    if ($hinweise !== []) {
        $data->put($satz, (int) $geerbt['hinweis'], TypedValue::ofText(implode(' — ', $hinweise)));
    }

    if (! $steht($satz, $geerbt['quellen'])) {
        foreach (array_values(array_unique(array_values((array) ($cpu['quellen'] ?? [])))) as $url) {
            if (isset($quelleFuer[$url])) {
                $data->appendValue($satz, (int) $geerbt['quellen'], TypedValue::ofRecordReference($quelleFuer[$url]));
            }
        }
    }
}

$sagen("\n{$neu} neu, {$ergaenzt} ergänzt" . ($schreiben ? '.' : ' — nur gezeigt. Mit --write übernehmen.'));
