<?php declare(strict_types=1);

/**
 * Seine Mainboards aus der Vergleichstabelle (TablePress 16) ins Modell — berichtigt, mit Quellen und Herkunft (2026-09-19).
 *
 *     php scripts/dev/mainboards-aus-tabelle.php <mb-out-A.json> [<mb-out-B.json> …]            # nur zeigen
 *     php scripts/dev/mainboards-aus-tabelle.php <mb-out-A.json> [<mb-out-B.json> …] --write    # übernehmen
 *
 * ⚠️ *Seine Worte: «1. ja exemplar, 2. beim übernehmen berichtigen» (D-867, D-868), «ja passt, mach mit den mainboards weiter»
 * (D-875). Jede Spalte der Tabelle ist ein Board, das er besitzt. Die Werte sind je Board gegen The Retro Web, Handbücher und
 * Herstellerseiten geprüft (Rechercheaufträge vom selben Tag, Ergebnisdateien als Argumente); die Tabelle war nur Rohmaterial.*
 *
 * Aufbau nach D-853 und D-867: Modell unter «Mainboards» (was das Board kann), darüber die Reihe, wenn es eine gibt, darunter die
 * Revision, wenn sein Stück eine trägt. Sein Stück selbst ist ein Exemplar, das auf die Revision zeigt (sonst auf das Modell) und
 * trägt, was nur für dieses Stück gilt — BIOS-Kennung, getauschte Bausteine, Beobachtungen.
 *
 * Neu im Modell (D-875): an «Mainboards» Sockel, Unterstützte CPUs, Multiplikatoren, Speichersteckplätze, Speichertakt, Anschlüsse,
 * Onboard, Lüfteranschlüsse CPU/Gehäuse, BIOS-Hersteller, BIOS-Baustein, BIOS-Setup-Taste; «Stromversorgung» wird 0..*. Teile
 * «Anschluss», «Speicherplatz», «Onboard-Baustein»; am «Steckplatz» die «Ausführung». Auswahlen «Speichermodule» und
 * «Onboard-Funktionen»; eine Gruppe «Bildschirmschnittstellen» und die fehlenden Schnittstellen, Busse, Formfaktoren, Stromanschlüsse.
 *
 * Wiederholbar: Modell, Revision und Exemplar werden am Namen erkannt; ein vorhandenes Modell wird nicht neu beschrieben.
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
$boards    = [];

foreach (array_slice($argv, 1) as $datei) {
    if ($datei === '--write') {
        continue;
    }

    $inhalt = json_decode((string) file_get_contents($datei), true);

    if (! is_array($inhalt)) {
        exit("unlesbar: {$datei}\n");
    }

    array_push($boards, ...$inhalt);
}

usort($boards, static fn (array $a, array $b): int => $a['spalte'] <=> $b['spalte']);

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
const MODEL = 402, KOMPOSITIONEN = 404, PC_KONSTANTEN = 149000102259, MODELS = 149000103431, HARDWARE = 149000103001;
const TEXT = 1175, INTEGER = 1171, DECIMAL = 1173, EINHEITENWERT = 4232;
const EW_WERT = 4234, EW_PREFIX = 4235, EW_EINHEIT = 4236, HERTZ = 4054, BYTE = 149000103868, MEGA = 4002, GIGA = 4000;
const MAINBOARDS = 149000107691, REVISIONEN = 149000107757, REIHEN = 149000107758, CHIPSAETZE = 149000107688, EXEMPLARE = 149000108212;
const HERSTELLERLISTE = 149000102677, HERSTELLER_NAME = 149000102666, BEZEICHNUNG = 149000103839, TEILENUMMER = 149000103844;
const HERSTELLER = 149000102544, ERSCHIEN = 149000103840, SCHNITTSTELLEN = 149000103870, SCHNITTSTELLE_BEZ = 149000107978;
const SCHNITTSTELLE_HINWEIS = 149000107988, ERWEITERUNGSBUSSE = 149000108006, LAUFWERK = 149000108007, PERIPHERIE = 149000108008;
const NETZWERK = 149000108010, FORMFAKTOREN = 149000107687, FORMFAKTOR_BEZ = 149000107749, STROMANSCHLUESSE = 149000107683;
const STECKPLATZ = 149000107689, STECKPLATZ_BUS = 149000107990, STECKPLATZ_ANZAHL = 149000107755, SOCKELWAHL = 149000108332;
const MB_CHIPSATZ = 149000107761, MB_TAKT = 149000107763, MB_RAM = 149000107764, MB_CACHE = 149000107765, MB_FORMFAKTOR = 149000107766;
const MB_STROM = 149000107767, MB_STECKPLAETZE = 149000107768, MB_REVISIONEN = 149000107896, REIHE_MODELLE = 149000107895;
const CHIPSATZ_BAUSTEINE = 149000107804, REV_CPU_FEST = 149000107890, EX_MODELL = 149000108189, EX_BIOS = 149000108191;
const EX_HINWEIS = 149000108198, QUELLEN = 149000108186, HERKUNFT = 149000108187, HERKUNFTSHINWEIS = 149000108188;

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
    if ($eigner === null) {
        return null;
    }

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

/** Ein Satz unter `$knoten`, dessen Feld `$feldId` den Text `$text` trägt — oder null. */
$satzMit = static fn (int $knotenId, int $feldId, string $text): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
     WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s LIMIT 1",
    $knotenId,
    $feldId,
    $text
))) === null ? null : (int) $id;

/** Einen Satz mit Bezeichnung anlegen, wenn es ihn nicht gibt. */
$satzNamens = static function (int $knotenId, int $feldId, string $text, array $weitere = []) use ($satzMit, $data, $schreiben, $sagen): ?int {
    $steht = $satzMit($knotenId, $feldId, $text);

    if ($steht !== null) {
        return $steht;
    }

    $sagen("  Satz «{$text}» unter {$knotenId}");

    if (! $schreiben) {
        return null;
    }

    $neu = $data->create($knotenId, RecordType::User)->id;
    $data->put($neu, $feldId, TypedValue::ofText($text));

    foreach ($weitere as $weiteresFeld => $wert) {
        $data->put($neu, $weiteresFeld, $wert);
    }

    return $neu;
};

$zahl = static fn (float $z): string => rtrim(rtrim(number_format($z, 6, '.', ''), '0'), '.');

$menge = static function (int $satz, int $feldId, float $wert, ?int $vorsatz, int $einheit) use ($data, $zahl): void {
    $teil = $data->createPart($satz, $feldId);
    $data->put($teil->id, EW_WERT, TypedValue::ofDecimal($zahl($wert)));
    $data->put($teil->id, EW_EINHEIT, TypedValue::ofReference($einheit));

    if ($vorsatz !== null) {
        $data->put($teil->id, EW_PREFIX, TypedValue::ofReference($vorsatz));
    }
};

// ── Modell: Auswahlen, Gruppen, Teile, Felder ─────────────────────────────────────────────────────────
$modulWahl  = $knoten('Speichermodule', PC_KONSTANTEN, Choice::class);
$onboardWahl = $knoten('Onboard-Funktionen', PC_KONSTANTEN, Choice::class);
$bildschirm = $knoten('Bildschirmschnittstellen', SCHNITTSTELLEN, Category::class);
$konstante  = static fn (?int $wahl, string $name): ?int => $knoten($name, $wahl, Constant::class);

$tAnschluss = $knoten('Anschluss', KOMPOSITIONEN, Category::class);
$tSpeicher  = $knoten('Speicherplatz', KOMPOSITIONEN, Category::class);
$tOnboard   = $knoten('Onboard-Baustein', KOMPOSITIONEN, Category::class);

$f = [
    'a_schnittstelle' => $feld($tAnschluss, SCHNITTSTELLEN, 'Schnittstelle', RelationKind::Aggregation, Multiplicity::ExactlyOne),
    'a_anzahl'        => $feld($tAnschluss, INTEGER, 'Anzahl', RelationKind::Composition, Multiplicity::ZeroToOne),
    'a_hinweis'       => $feld($tAnschluss, TEXT, 'Hinweis', RelationKind::Composition, Multiplicity::ZeroToOne),
    's_modul'         => $feld($tSpeicher, $modulWahl, 'Modulart', RelationKind::Aggregation, Multiplicity::ExactlyOne),
    's_anzahl'        => $feld($tSpeicher, INTEGER, 'Anzahl', RelationKind::Composition, Multiplicity::ExactlyOne),
    'o_art'           => $feld($tOnboard, $onboardWahl, 'Art', RelationKind::Aggregation, Multiplicity::ExactlyOne),
    'o_chip'          => $feld($tOnboard, TEXT, 'Baustein', RelationKind::Composition, Multiplicity::ZeroToOne),
    'st_ausfuehrung'  => $feld(STECKPLATZ, TEXT, 'Ausführung', RelationKind::Composition, Multiplicity::ZeroToOne),
    'sockel'          => $feld(MAINBOARDS, SOCKELWAHL, 'Sockel', RelationKind::Aggregation, Multiplicity::ZeroToMany),
    'cpus'            => $feld(MAINBOARDS, TEXT, 'Unterstützte CPUs', RelationKind::Composition, Multiplicity::ZeroToMany),
    'multi'           => $feld(MAINBOARDS, DECIMAL, 'Multiplikatoren', RelationKind::Composition, Multiplicity::ZeroToMany),
    'speicher'        => $feld(MAINBOARDS, $tSpeicher, 'Speichersteckplätze', RelationKind::Composition, Multiplicity::ZeroToMany),
    'speichertakt'    => $feld(MAINBOARDS, TEXT, 'Speichertakt', RelationKind::Composition, Multiplicity::ZeroToOne),
    'anschluesse'     => $feld(MAINBOARDS, $tAnschluss, 'Anschlüsse', RelationKind::Composition, Multiplicity::ZeroToMany),
    'onboard'         => $feld(MAINBOARDS, $tOnboard, 'Onboard', RelationKind::Composition, Multiplicity::ZeroToMany),
    'luefter_cpu'     => $feld(MAINBOARDS, INTEGER, 'Lüfteranschlüsse CPU', RelationKind::Composition, Multiplicity::ZeroToOne),
    'luefter_geh'     => $feld(MAINBOARDS, INTEGER, 'Lüfteranschlüsse Gehäuse', RelationKind::Composition, Multiplicity::ZeroToOne),
    'bios_hersteller' => $feld(MAINBOARDS, TEXT, 'BIOS-Hersteller', RelationKind::Composition, Multiplicity::ZeroToOne),
    'bios_baustein'   => $feld(MAINBOARDS, TEXT, 'BIOS-Baustein', RelationKind::Composition, Multiplicity::ZeroToOne),
    'bios_taste'      => $feld(MAINBOARDS, TEXT, 'BIOS-Setup-Taste', RelationKind::Composition, Multiplicity::ZeroToOne),
];

// ⚠️ *AT und ATX zugleich (M560TG, M598): ein Board kann mehrere Stromanschlüsse haben.*
foreach ($editor->fieldsOf(MAINBOARDS) as $kante) {
    if ($kante->id === MB_STROM && $kante->multiplicity !== Multiplicity::ZeroToMany) {
        $sagen('Feld «Stromversorgung» an Mainboards wird 0..*');

        if ($schreiben) {
            $editor->setMultiplicity(MAINBOARDS, MB_STROM, Multiplicity::ZeroToMany);
        }
    }
}

// ⚠️ *Ohne Einstellung zeigt ein Satz in jeder Auswahl sein erstes Textfeld — bei Hardware ist das der Herkunftshinweis, weil «Model»
// als oberster Besitzer zuerst kommt. Gemessen am 2026-09-19: die CPU-Seite wuchs mit den neuen Boards von 893 auf 1059 KB, die
// Auswahl «Models» allein auf 242 KB. Hersteller und Bezeichnung an «Hardware» gelten für jedes Gerät darunter, das nichts eigenes sagt.*
$attributes = (new ReflectionProperty($screen, 'attributes'))->getValue($screen);

if ($schreiben) {
    $attributes->setMembers($editor->find(HARDWARE), 'summary_fields', [HERSTELLER, BEZEICHNUNG]);
    $attributes->setMembers($editor->find(EXEMPLARE), 'summary_fields', [EX_MODELL]);
}

// ── Nachschlagen und anlegen: Hersteller, Chipsätze, Busse, Schnittstellen, Formfaktoren ─────────────
$hersteller = static function (?string $name) use ($satzNamens): ?int {
    return $name === null || trim($name) === '' ? null : $satzNamens(HERSTELLERLISTE, HERSTELLER_NAME, trim($name));
};

$schnittstelleGruppe = [
    'IDE/ATA' => LAUFWERK, 'SATA' => LAUFWERK, 'Floppy-Controller' => LAUFWERK, 'SCSI' => LAUFWERK,
    'RS-232' => PERIPHERIE, 'Centronics' => PERIPHERIE, 'PS/2' => PERIPHERIE, 'AT-Tastatur (DIN)' => PERIPHERIE,
    'USB 1.1' => PERIPHERIE, 'USB 2.0' => PERIPHERIE, 'USB 3.0' => PERIPHERIE, 'Gameport' => PERIPHERIE, 'IrDA' => PERIPHERIE,
    'Ethernet' => NETZWERK, 'VGA' => 'bild', 'DVI' => 'bild', 'HDMI' => 'bild', 'DisplayPort' => 'bild',
];

/** @return array{0: ?int, 1: string} die Schnittstelle und was vom Namen als Hinweis bleibt («Maus» bei «PS/2 Maus»). */
$schnittstelle = static function (string $name) use ($schnittstelleGruppe, $bildschirm, $wpdb, $p, $satzNamens): array {
    $rest = '';

    if (preg_match('/^PS\/2 (Maus|Tastatur)$/', $name, $m) === 1) {
        [$name, $rest] = ['PS/2', $m[1]];
    }

    // *Erst im ganzen Ast suchen — «Ethernet» oder «SCSI» stehen schon, gleich unter welcher Gruppe.*
    $steht = $wpdb->get_var($wpdb->prepare(
        "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
         JOIN {$p}nodes_named n ON n.id = r.node_id
         WHERE (n.parent_node_id = %d OR n.id = %d) AND v.relation_id = %d AND v.value_text = %s AND r.record_type = 'user' LIMIT 1",
        SCHNITTSTELLEN,
        SCHNITTSTELLEN,
        SCHNITTSTELLE_BEZ,
        $name
    ));

    if ($steht !== null) {
        return [(int) $steht, $rest];
    }

    $gruppe = $schnittstelleGruppe[$name] ?? null;
    $gruppe = $gruppe === 'bild' ? $bildschirm : $gruppe;

    if ($gruppe === null) {
        return [null, $rest];
    }

    return [$satzNamens($gruppe, SCHNITTSTELLE_BEZ, $name, [
        SCHNITTSTELLE_HINWEIS => TypedValue::ofText('Angelegt beim Übernehmen seiner Mainboards (D-875); Kennwerte fehlen noch.'),
    ]), $rest];
};

$bus = static fn (string $name): ?int => $satzNamens(ERWEITERUNGSBUSSE, SCHNITTSTELLE_BEZ, $name, [
    SCHNITTSTELLE_HINWEIS => TypedValue::ofText('Angelegt beim Übernehmen seiner Mainboards (D-875); Kennwerte fehlen noch.'),
]);

$formfaktor = static fn (string $name): ?int => $satzNamens(FORMFAKTOREN, FORMFAKTOR_BEZ, $name);

$strom = static fn (string $name): ?int => $konstante(STROMANSCHLUESSE, $name);

$sockelKonstante = static fn (string $name): ?int => $konstante(SOCKELWAHL, $name);

// ── Quellen ────────────────────────────────────────────────────────────────────────────────────────
$verzeichnis = (int) $wpdb->get_var("SELECT id FROM {$p}nodes_named WHERE name = 'Quellenverzeichnis' AND parent_node_id = " . MODEL);
$qTitel      = (int) $feldAn($verzeichnis, 'Titel');
$qAdresse    = (int) $feldAn($verzeichnis, 'Adresse');
$qArt        = (int) $feldAn($verzeichnis, 'Art');
$qAbgerufen  = (int) $feldAn($verzeichnis, 'Abgerufen');
$quellenart  = static fn (string $art): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = (SELECT id FROM {$p}nodes_named WHERE name = 'Quellenarten' LIMIT 1)",
    $art
))) === null ? null : (int) $id;

$quelle = static function (array $q) use ($wpdb, $p, $qAdresse, $qTitel, $qArt, $qAbgerufen, $verzeichnis, $data, $schreiben, $quellenart): ?int {
    $url = trim((string) ($q['url'] ?? ''));

    if ($url === '') {
        return null;
    }

    $steht = $wpdb->get_var($wpdb->prepare("SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d AND value_text = %s LIMIT 1", $qAdresse, $url));

    if ($steht !== null || ! $schreiben) {
        return $steht === null ? null : (int) $steht;
    }

    $neu = $data->create($verzeichnis, RecordType::User)->id;
    $data->put($neu, $qTitel, TypedValue::ofText(trim((string) ($q['titel'] ?? '')) !== '' ? (string) $q['titel'] : $url));
    $data->put($neu, $qAdresse, TypedValue::ofText($url));
    $art = preg_match('/\.pdf($|\?)/i', $url) === 1 || stripos((string) ($q['titel'] ?? ''), 'handbuch') !== false || stripos((string) ($q['titel'] ?? ''), 'manual') !== false
        ? 'Handbuch'
        : (str_contains($url, 'vogons.org') ? 'Forum' : 'Webseite');

    if (($artId = $quellenart($art)) !== null) {
        $data->put($neu, $qArt, TypedValue::ofReference($artId));
    }

    $data->put($neu, $qAbgerufen, TypedValue::ofDate('2026-09-19 00:00:00'));

    return $neu;
};

$herkunftsart = static fn (string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = (SELECT id FROM {$p}nodes_named WHERE name = 'Herkunftsarten' LIMIT 1)",
    $name
))) === null ? null : (int) $id;

// ── Die Boards ─────────────────────────────────────────────────────────────────────────────────────
$zaehler = ['modell' => 0, 'revision' => 0, 'exemplar' => 0];

foreach ($boards as $b) {
    $modellName = trim((string) ($b['modell'] ?? ''));

    if ($modellName === '') {
        $sagen("Spalte {$b['spalte']}: kein Modell erkannt — übersprungen");
        continue;
    }

    $sagen("Spalte {$b['spalte']} «{$b['tabellenname']}» → {$b['hersteller']} {$modellName}" . (($b['revision'] ?? null) ? " Rev. {$b['revision']}" : ''));
    $herstellerId = $hersteller($b['hersteller'] ?? null);
    $modell       = $satzMit(MAINBOARDS, BEZEICHNUNG, $modellName);
    $modellNeu    = $modell === null;

    if ($modellNeu) {
        ++$zaehler['modell'];
        $modell = $satzNamens(MAINBOARDS, BEZEICHNUNG, $modellName);
    }

    if ($modellNeu && $schreiben && $modell !== null) {
        if ($herstellerId !== null) {
            $data->put($modell, HERSTELLER, TypedValue::ofRecordReference($herstellerId));
        }

        foreach ((array) ($b['teilenummern'] ?? []) as $nummer) {
            $data->appendValue($modell, TEILENUMMER, TypedValue::ofText((string) $nummer));
        }

        if (($b['erscheinungsjahr'] ?? null) !== null) {
            $data->put($modell, ERSCHIEN, TypedValue::ofDate(((int) $b['erscheinungsjahr']) . '-01-01 00:00:00'));
        }

        if (($c = $b['chipsatz'] ?? null) !== null && trim((string) ($c['name'] ?? '')) !== '') {
            $chipsatzNeu = $satzMit(CHIPSAETZE, BEZEICHNUNG, trim((string) $c['name'])) === null;
            $chipsatz    = $satzNamens(CHIPSAETZE, BEZEICHNUNG, trim((string) $c['name']));

            if ($chipsatzNeu && $chipsatz !== null && ($ch = $hersteller($c['hersteller'] ?? null)) !== null) {
                $data->put($chipsatz, HERSTELLER, TypedValue::ofRecordReference($ch));
            }

            if ($chipsatz !== null) {
                $data->put($modell, MB_CHIPSATZ, TypedValue::ofRecordReference($chipsatz));
            }
        }

        foreach ((array) ($b['sockel'] ?? []) as $sockel) {
            if (($id = $sockelKonstante((string) $sockel)) !== null) {
                $data->appendValue($modell, (int) $f['sockel'], TypedValue::ofReference($id));
            }
        }

        foreach ((array) ($b['cpus'] ?? []) as $cpu) {
            $data->appendValue($modell, (int) $f['cpus'], TypedValue::ofText((string) $cpu));
        }

        foreach ((array) ($b['bustakt_mhz'] ?? []) as $takt) {
            $menge($modell, MB_TAKT, (float) $takt, MEGA, HERTZ);
        }

        foreach ((array) ($b['multiplikatoren'] ?? []) as $multi) {
            $data->appendValue($modell, (int) $f['multi'], TypedValue::ofDecimal($zahl((float) $multi)));
        }

        if (trim((string) ($b['cache'] ?? '')) !== '') {
            $data->put($modell, MB_CACHE, TypedValue::ofText(trim((string) $b['cache'])));
        }

        $ram = (array) ($b['ram'] ?? []);

        foreach ((array) ($ram['module'] ?? []) as $modul) {
            if (($art = $konstante($modulWahl, (string) $modul['art'])) === null) {
                continue;
            }

            $teil = $data->createPart($modell, (int) $f['speicher']);
            $data->put($teil->id, (int) $f['s_modul'], TypedValue::ofReference($art));
            $data->put($teil->id, (int) $f['s_anzahl'], TypedValue::ofInt((int) $modul['anzahl']));
        }

        if (($ram['hoechstens_mb'] ?? null) !== null) {
            $mb = (float) $ram['hoechstens_mb'];
            $mb >= 1024 && fmod($mb, 1024.0) === 0.0
                ? $menge($modell, MB_RAM, $mb / 1024, GIGA, BYTE)
                : $menge($modell, MB_RAM, $mb, MEGA, BYTE);
        }

        if (trim((string) ($ram['takt'] ?? '')) !== '') {
            $data->put($modell, (int) $f['speichertakt'], TypedValue::ofText(trim((string) $ram['takt'])));
        }

        if (($b['formfaktor'] ?? null) !== null && ($ff = $formfaktor((string) $b['formfaktor'])) !== null) {
            $data->put($modell, MB_FORMFAKTOR, TypedValue::ofRecordReference($ff));
        }

        foreach ((array) ($b['strom'] ?? []) as $anschluss) {
            if (($id = $strom((string) $anschluss)) !== null) {
                $data->appendValue($modell, MB_STROM, TypedValue::ofReference($id));
            }
        }

        if (is_array($b['luefter'] ?? null)) {
            foreach (['cpu' => 'luefter_cpu', 'gehaeuse' => 'luefter_geh'] as $schluessel => $ziel) {
                if (($b['luefter'][$schluessel] ?? null) !== null) {
                    $data->put($modell, (int) $f[$ziel], TypedValue::ofInt((int) $b['luefter'][$schluessel]));
                }
            }
        }

        foreach ((array) ($b['steckplaetze'] ?? []) as $platz) {
            if ((int) ($platz['anzahl'] ?? 0) < 1 || ($busId = $bus((string) $platz['bus'])) === null) {
                continue;
            }

            $teil = $data->createPart($modell, MB_STECKPLAETZE);
            $data->put($teil->id, STECKPLATZ_BUS, TypedValue::ofRecordReference($busId));
            $data->put($teil->id, STECKPLATZ_ANZAHL, TypedValue::ofInt((int) $platz['anzahl']));

            if (trim((string) ($platz['ausfuehrung'] ?? '')) !== '') {
                $data->put($teil->id, (int) $f['st_ausfuehrung'], TypedValue::ofText(trim((string) $platz['ausfuehrung'])));
            }
        }

        foreach ((array) ($b['anschluesse'] ?? []) as $a) {
            [$sId, $rest] = $schnittstelle((string) $a['schnittstelle']);

            if ($sId === null) {
                $sagen("  ⚠ unbekannte Schnittstelle «{$a['schnittstelle']}»");
                continue;
            }

            $teil = $data->createPart($modell, (int) $f['anschluesse']);
            $data->put($teil->id, (int) $f['a_schnittstelle'], TypedValue::ofRecordReference($sId));

            if (($a['anzahl'] ?? null) !== null) {
                $data->put($teil->id, (int) $f['a_anzahl'], TypedValue::ofInt((int) $a['anzahl']));
            }

            $hinweis = trim($rest . ' ' . trim((string) ($a['hinweis'] ?? '')));

            if ($hinweis !== '') {
                $data->put($teil->id, (int) $f['a_hinweis'], TypedValue::ofText($hinweis));
            }
        }

        foreach ((array) ($b['onboard'] ?? []) as $o) {
            if (($art = $konstante($onboardWahl, (string) $o['art'])) === null) {
                continue;
            }

            $teil = $data->createPart($modell, (int) $f['onboard']);
            $data->put($teil->id, (int) $f['o_art'], TypedValue::ofReference($art));

            if (trim((string) ($o['chip'] ?? '')) !== '') {
                $data->put($teil->id, (int) $f['o_chip'], TypedValue::ofText(trim((string) $o['chip'])));
            }
        }

        foreach (['hersteller' => 'bios_hersteller', 'baustein' => 'bios_baustein', 'setup_taste' => 'bios_taste'] as $schluessel => $ziel) {
            if (trim((string) ($b['bios'][$schluessel] ?? '')) !== '') {
                $data->put($modell, (int) $f[$ziel], TypedValue::ofText(trim((string) $b['bios'][$schluessel])));
            }
        }

        foreach (array_values(array_unique(array_map('strval', (array) ($b['herkunft'] ?? [])))) as $art) {
            if (($id = $herkunftsart($art)) !== null) {
                $data->appendValue($modell, HERKUNFT, TypedValue::ofReference($id));
            }
        }

        $hinweis = trim((string) ($b['herkunftshinweis'] ?? ''));
        $zweifel = array_values(array_filter(array_map('trim', array_map('strval', (array) ($b['zweifel'] ?? [])))));

        if ($zweifel !== []) {
            $hinweis .= ($hinweis === '' ? '' : ' · ') . 'Offen: ' . implode('; ', $zweifel);
        }

        $data->put($modell, HERKUNFTSHINWEIS, TypedValue::ofText(
            "Aus seiner Tabelle «Mainboard-Vergleich» (TablePress 16, «{$b['tabellenname']}»), beim Übernehmen geprüft (D-875)."
            . ($hinweis === '' ? '' : ' · ' . $hinweis)
        ));

        $gesehen = [];

        foreach ((array) ($b['quellen'] ?? []) as $q) {
            $qId = $quelle((array) $q);

            if ($qId !== null && ! isset($gesehen[$qId])) {
                $gesehen[$qId] = true;
                $data->appendValue($modell, QUELLEN, TypedValue::ofRecordReference($qId));
            }
        }
    }

    // Reihe
    if (trim((string) ($b['reihe'] ?? '')) !== '' && $modell !== null) {
        $reihe = $satzNamens(REIHEN, BEZEICHNUNG, trim((string) $b['reihe']));

        if ($schreiben && $reihe !== null) {
            if ($herstellerId !== null && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $reihe, HERSTELLER)) === 0) {
                $data->put($reihe, HERSTELLER, TypedValue::ofRecordReference($herstellerId));
            }

            $haengt = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d AND value_ref = %d",
                $reihe,
                REIHE_MODELLE,
                $modell
            ));

            if ($haengt === 0) {
                $data->appendValue($reihe, REIHE_MODELLE, TypedValue::ofRecordReference($modell));
            }
        }
    }

    // Revision
    $ziel     = $modell;
    $revision = trim((string) ($b['revision'] ?? ''));

    if ($revision !== '' && $modell !== null) {
        $revId = $wpdb->get_var($wpdb->prepare(
            "SELECT v.node_record_id FROM {$p}relation_records v
             JOIN {$p}relation_records h ON h.value_ref = v.node_record_id AND h.relation_id = %d AND h.node_record_id = %d
             WHERE v.relation_id = %d AND v.value_text = %s LIMIT 1",
            MB_REVISIONEN,
            $modell,
            BEZEICHNUNG,
            $revision
        ));

        if ($revId === null) {
            ++$zaehler['revision'];
            $sagen("  Revision «{$revision}»");

            if ($schreiben) {
                $revId = $data->create(REVISIONEN, RecordType::User)->id;
                $data->put($revId, BEZEICHNUNG, TypedValue::ofText($revision));

                if ($herstellerId !== null) {
                    $data->put($revId, HERSTELLER, TypedValue::ofRecordReference($herstellerId));
                }

                // *Verlötet heisst hier: der Sockel ist ein Gehäuse zum Auflöten (PQFP) — so steht es in der Recherche.*
                $verloetet = array_filter((array) ($b['sockel'] ?? []), static fn ($s): bool => str_starts_with((string) $s, 'PQFP')) !== [];
                $data->put($revId, REV_CPU_FEST, TypedValue::ofBool($verloetet));
                $data->appendValue($modell, MB_REVISIONEN, TypedValue::ofRecordReference($revId));
            }
        }

        $ziel = $revId === null ? null : (int) $revId;
    }

    // Exemplar — seins
    $marke = "TablePress 16, Spalte {$b['spalte']}";
    $steht = $wpdb->get_var($wpdb->prepare(
        "SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d AND value_text LIKE %s LIMIT 1",
        EX_HINWEIS,
        '%' . $wpdb->esc_like($marke) . '%'
    ));

    if ($steht === null) {
        ++$zaehler['exemplar'];
        $sagen('  Exemplar');

        if ($schreiben && $ziel !== null) {
            $ex = $data->create(EXEMPLARE, RecordType::User)->id;
            $data->put($ex, EX_MODELL, TypedValue::ofRecordReference($ziel));

            $exemplar = (array) ($b['exemplar'] ?? []);

            if (trim((string) ($exemplar['bios'] ?? '')) !== '') {
                $data->put($ex, EX_BIOS, TypedValue::ofText(trim((string) $exemplar['bios'])));
            }

            $data->put($ex, EX_HINWEIS, TypedValue::ofText(trim(
                "Sein Board, aus seiner Tabelle ({$marke}, «{$b['tabellenname']}»)."
                . (trim((string) ($exemplar['hinweis'] ?? '')) === '' ? '' : ' ' . trim((string) $exemplar['hinweis']))
            )));
        }
    }
}

$sagen("\n{$zaehler['modell']} Modelle, {$zaehler['revision']} Revisionen, {$zaehler['exemplar']} Exemplare neu" . ($schreiben ? '.' : ' — nur gezeigt. Mit --write übernehmen.'));
