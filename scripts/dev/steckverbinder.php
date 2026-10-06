<?php declare(strict_types=1);

/**
 * Netzteil-Steckverbinder in den Teilekatalog, mit Belegung, Teilenummern und Datenblättern (2026-09-19).
 *
 *     php scripts/dev/steckverbinder.php <steckverbinder.json>            # nur zeigen
 *     php scripts/dev/steckverbinder.php <steckverbinder.json> --write    # anlegen
 *
 * ⚠️ *Sein Wort: «nimm mal bitte steckverbinder mit auf … der p8/p9 stecker vom at mainboard ist ein molex ich glaube 396 nimm den und die
 * 4 poligen mal mit auf versehen sie mit zusatz infos und auch mit den datenblättern». Die Werte stammen aus einer Recherche vom selben Tag
 * (Hersteller- und Händlerseiten, Wikipedia, AllPinouts); was die Quellen widersprüchlich nennen, steht im Herkunftshinweis.*
 *
 * Felder (Annahme, ihm gemeldet): Hersteller, Serie, Teilenummer, Einsatz am Vater «Electronic Parts»; Strom je Kontakt, Nennspannung,
 * AWG von/bis und das Teil «Pinbelegung» an «Verbinder». Beschrieben ist jeweils die Kabelseite des Netzteils (Buchsenkontakte).
 *
 * Wiederholbar: ein Stecker, dessen Beschreibung schon steht, wird nicht noch einmal angelegt.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\NodeClass\Constant;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);
$daten     = json_decode((string) file_get_contents($argv[1] ?? ''), true) ?: exit("keine Daten\n");

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
const TEILE = 3634, VERBINDER = 149000104418, STECKVERBINDER = 149000104423, KOMPOSITIONEN = 404, MODEL = 402;
const TEXT = 1175, INTEGER = 1171, EINHEITENWERT = 4232;
const EW_WERT = 4234, EW_PREFIX = 4235, EW_EINHEIT = 4236;
const STUECK = 4062, METER = 4036, MILLI = 4014, AMPERE = 4042, VOLT = 4050;
const BESCHREIBUNG = 149000104313, POLZAHL = 149000104333, STECKERTYP = 149000104337, GESCHLECHT = 149000104338, WEIBLICH = 149000104362;
const STECKERTYPEN = 149000104367;

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

$feld = static function (?int $eigner, ?int $ziel, string $name, RelationKind $art, Multiplicity $wieOft) use ($editor, $feldAn, $schreiben, $sagen): ?int {
    if ($eigner === null || $ziel === null) {
        return null;
    }

    $steht = $feldAn($eigner, $name);

    if ($steht !== null) {
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

// ── Felder ─────────────────────────────────────────────────────────────────────────────────────────
$fHersteller = $feld(TEILE, TEXT, 'Hersteller', RelationKind::Composition, Multiplicity::ZeroToOne);
$fSerie      = $feld(TEILE, TEXT, 'Serie', RelationKind::Composition, Multiplicity::ZeroToOne);
$fTeilenr    = $feld(TEILE, TEXT, 'Teilenummer', RelationKind::Composition, Multiplicity::ZeroToMany);
$fEinsatz    = $feld(TEILE, TEXT, 'Einsatz', RelationKind::Composition, Multiplicity::ZeroToOne);
$fStrom      = $feld(VERBINDER, EINHEITENWERT, 'Strom je Kontakt', RelationKind::Composition, Multiplicity::ZeroToOne);
$fSpannung   = $feld(VERBINDER, EINHEITENWERT, 'Nennspannung', RelationKind::Composition, Multiplicity::ZeroToOne);
$fAwgVon     = $feld(VERBINDER, INTEGER, 'AWG von', RelationKind::Composition, Multiplicity::ZeroToOne);
$fAwgBis     = $feld(VERBINDER, INTEGER, 'AWG bis', RelationKind::Composition, Multiplicity::ZeroToOne);
$belegung    = $knoten('Pinbelegung', KOMPOSITIONEN, Category::class);
$bPin        = $feld($belegung, TEXT, 'Pin', RelationKind::Composition, Multiplicity::ExactlyOne);
$bSignal     = $feld($belegung, TEXT, 'Signal', RelationKind::Composition, Multiplicity::ZeroToOne);
$bFarbe      = $feld($belegung, TEXT, 'Farbe', RelationKind::Composition, Multiplicity::ZeroToOne);
$fBelegung   = $feld(VERBINDER, $belegung, 'Belegung', RelationKind::Composition, Multiplicity::ZeroToMany);
$fReihen     = $feldAn(VERBINDER, 'Reihen');
$fRaster     = $feldAn(VERBINDER, 'Rastermaß');

$geerbt = [
    'quellen'  => $feldAn(MODEL, 'Quellen'),
    'herkunft' => $feldAn(MODEL, 'Herkunft'),
    'hinweis'  => $feldAn(MODEL, 'Herkunftshinweis'),
];

// ── Steckertypen ───────────────────────────────────────────────────────────────────────────────────
$typFuer = [
    0 => 'Molex 90331 (AT-Netzteil, 3,96 mm)',
    1 => 'Molex 8981 (Laufwerk, 5,08 mm)',
    2 => 'AMP EI 171822 (Floppy, 2,5 mm)',
    3 => 'Molex Mini-Fit Jr. (4,2 mm)',
];

// ── Quellenverzeichnis ─────────────────────────────────────────────────────────────────────────────
$verzeichnis = (int) $wpdb->get_var("SELECT id FROM {$p}nodes_named WHERE name = 'Quellenverzeichnis' AND parent_node_id = " . MODEL);
// ⚠️ *Der Titel einer Quelle ist seit D-883 ihre «Bezeichnung» (an «Model»); das eigene Feld «Titel» ist darin aufgegangen.*
$qTitel      = 149000103839;
$qAdresse    = $feldAn($verzeichnis, 'Adresse');
$qArt        = $feldAn($verzeichnis, 'Art');
$qAbgerufen  = $feldAn($verzeichnis, 'Abgerufen');
$art         = static fn (string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = (SELECT id FROM {$p}nodes_named WHERE name = 'Quellenarten' LIMIT 1)",
    $name
))) === null ? null : (int) $id;
$quelle = static function (string $url, string $titel, string $artName) use ($wpdb, $p, $data, $verzeichnis, $qTitel, $qAdresse, $qArt, $qAbgerufen, $art, $schreiben, $sagen): ?int {
    $steht = $wpdb->get_var($wpdb->prepare("SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d AND value_text = %s LIMIT 1", $qAdresse, $url));

    if ($steht !== null) {
        return (int) $steht;
    }

    $sagen("  Quelle «{$titel}» ({$artName})");

    if (! $schreiben) {
        return null;
    }

    $neu = $data->create($verzeichnis, RecordType::User);
    $data->put($neu->id, (int) $qTitel, TypedValue::ofText($titel));
    $data->put($neu->id, (int) $qAdresse, TypedValue::ofText($url));

    if (($a = $art($artName)) !== null) {
        $data->put($neu->id, (int) $qArt, TypedValue::ofReference($a));
    }

    $data->put($neu->id, (int) $qAbgerufen, TypedValue::ofDate('2026-09-19 00:00:00'));

    return $neu->id;
};

$menge = static function (int $satz, ?int $feld, $zahl, ?int $vorsatz, int $einheit) use ($data): void {
    if ($feld === null || $zahl === null) {
        return;
    }

    $teil = $data->createPart($satz, $feld);
    $data->put($teil->id, EW_WERT, TypedValue::ofDecimal(rtrim(rtrim(number_format((float) $zahl, 3, '.', ''), '0'), '.')));
    $data->put($teil->id, EW_EINHEIT, TypedValue::ofReference($einheit));

    if ($vorsatz !== null) {
        $data->put($teil->id, EW_PREFIX, TypedValue::ofReference($vorsatz));
    }
};

$herkunftsart = static fn (string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = (SELECT id FROM {$p}nodes_named WHERE name = 'Herkunftsarten' LIMIT 1)",
    $name
))) === null ? null : (int) $id;

$rollen = ['gehaeuse_kabel' => 'Kabelgehäuse', 'gehaeuse_platine/laufwerk' => 'Gehäuse Platine/Laufwerk', 'stiftleiste' => 'Stiftleiste', 'kontakte' => 'Kontakt'];

// ── Die Stecker ────────────────────────────────────────────────────────────────────────────────────
foreach ($daten as $i => $s) {
    $name = (string) $s['bezeichnung'];
    $sagen("\n■ {$name}");

    $steht = $wpdb->get_var($wpdb->prepare(
        "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s",
        STECKVERBINDER,
        BESCHREIBUNG,
        $name
    ));

    $typ = $knoten($typFuer[$i] ?? (string) $s['serie'], STECKERTYPEN, Constant::class);

    $quellenIds = [];

    foreach ((array) ($s['datenblaetter'] ?? []) as $db) {
        $quellenIds[] = $quelle((string) $db['url'], (string) $db['titel'], 'Datenblatt');
    }

    foreach ((array) ($s['produktseiten'] ?? []) as $ps) {
        $quellenIds[] = $quelle((string) $ps['url'], (string) $ps['titel'], 'Webseite');
    }

    foreach (array_unique(array_values((array) ($s['quellen'] ?? []))) as $url) {
        $quellenIds[] = $quelle((string) $url, (string) (parse_url((string) $url, PHP_URL_HOST) ?: $url) . ': ' . basename((string) parse_url((string) $url, PHP_URL_PATH)), preg_match('/\.pdf$/i', (string) $url) === 1 ? 'Datenblatt' : 'Webseite');
    }

    if ($steht !== null) {
        $sagen('  steht schon');
        continue;
    }

    if (! $schreiben) {
        continue;
    }

    $satz = $data->create(STECKVERBINDER, RecordType::User)->id;
    $data->put($satz, BESCHREIBUNG, TypedValue::ofText($name));
    $data->put($satz, STECKERTYP, TypedValue::ofReference((int) $typ));
    $data->put($satz, GESCHLECHT, TypedValue::ofReference(WEIBLICH));

    foreach (['hersteller' => $fHersteller, 'serie' => $fSerie] as $k => $f) {
        if (($s[$k] ?? null) !== null) {
            $data->put($satz, (int) $f, TypedValue::ofText((string) $s[$k]));
        }
    }

    $einsatz = trim((string) ($s['verwendung'] ?? '') . ((array) ($s['auch_genannt'] ?? []) === [] ? '' : ' Auch genannt: ' . implode(', ', (array) $s['auch_genannt']) . '.'));

    if ($einsatz !== '') {
        $data->put($satz, (int) $fEinsatz, TypedValue::ofText($einsatz));
    }

    foreach ($rollen as $schluessel => $rolle) {
        foreach ((array) ($s['teilenummern'][$schluessel] ?? []) as $nummer) {
            $data->appendValue($satz, (int) $fTeilenr, TypedValue::ofText($rolle . ': ' . $nummer));
        }
    }

    $menge($satz, POLZAHL, $s['pole'] ?? null, null, STUECK);
    $menge($satz, $fReihen, $s['reihen'] ?? null, null, STUECK);
    $menge($satz, $fRaster, $s['raster_mm'] ?? null, MILLI, METER);
    $menge($satz, $fStrom, $s['strom_a_je_kontakt'] ?? null, null, AMPERE);
    $menge($satz, $fSpannung, $s['spannung_v'] ?? null, null, VOLT);

    if (($s['awg']['min'] ?? null) !== null) {
        $data->put($satz, (int) $fAwgVon, TypedValue::ofInt((int) $s['awg']['min']));
    }

    if (($s['awg']['max'] ?? null) !== null) {
        $data->put($satz, (int) $fAwgBis, TypedValue::ofInt((int) $s['awg']['max']));
    }

    foreach ((array) ($s['belegung'] ?? []) as $pin) {
        $teil = $data->createPart($satz, (int) $fBelegung);
        $data->put($teil->id, (int) $bPin, TypedValue::ofText((string) $pin['pin']));

        if (($pin['signal'] ?? null) !== null) {
            $data->put($teil->id, (int) $bSignal, TypedValue::ofText((string) $pin['signal']));
        }

        if (($pin['farbe'] ?? null) !== null) {
            $data->put($teil->id, (int) $bFarbe, TypedValue::ofText((string) $pin['farbe']));
        }
    }

    foreach (array_values(array_unique(array_filter($quellenIds))) as $q) {
        $data->appendValue($satz, (int) $geerbt['quellen'], TypedValue::ofRecordReference($q));
    }

    foreach (array_values(array_unique(array_values((array) ($s['herkunft'] ?? [])))) as $a) {
        if (($id = $herkunftsart((string) $a)) !== null) {
            $data->appendValue($satz, (int) $geerbt['herkunft'], TypedValue::ofReference($id));
        }
    }

    $abgeleitet = array_keys(array_filter((array) ($s['herkunft'] ?? []), static fn ($a): bool => $a === 'abgeleitet'));
    $hinweis    = trim(($abgeleitet === [] ? '' : 'Abgeleitet: ' . implode(', ', $abgeleitet) . ' — ')
        . 'Beschrieben ist die Kabelseite des Netzteils (Buchsenkontakte). ' . (string) ($s['anmerkung'] ?? ''));
    $data->put($satz, (int) $geerbt['hinweis'], TypedValue::ofText($hinweis));

    $sagen("  angelegt: Satz {$satz}");
}

$sagen($schreiben ? "\nFertig." : "\nNur gezeigt. Mit --write anlegen.");
