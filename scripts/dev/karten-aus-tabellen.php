<?php declare(strict_types=1);

/**
 * Seine Erweiterungskarten aus den Vergleichstabellen (TablePress 21, 23, 27) — geprüft und berichtigt (2026-09-21).
 *
 *     php scripts/dev/karten-aus-tabellen.php <karten-out-A.json> [<…B> <…C>]            # nur zeigen
 *     php scripts/dev/karten-aus-tabellen.php <karten-out-A.json> [<…B> <…C>] --write    # übernehmen
 *
 * ⚠️ *Zu [D-897](../../docs/NewConcept/90-decision-log.md) und [D-899](../../docs/NewConcept/90-decision-log.md): die Felder der
 * Karten stehen seit der Probe mit neuen Daten; hier kommen die Karten selbst, je Karte gegen The Retro Web, Handbücher und VOGONS
 * geprüft (Rechercheaufträge vom selben Tag, Ergebnisdateien als Argumente). Wie bei den Mainboards (D-875): Modell unter seiner
 * Kartenart, sein Stück ein Exemplar mit Datecode.*
 *
 * Grafikkarten unter «Graphics», Netzwerkkarten unter einem neuen «Netzwerkkarten», Multi-I/O-Karten unter «IO».
 *
 * Wiederholbar: eine Karte wird an Bezeichnung und Kartenart erkannt, ein Exemplar an Tabelle und Spalte.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);
$karten    = [];

foreach (array_slice($argv, 1) as $datei) {
    if ($datei === '--write') {
        continue;
    }

    $inhalt = json_decode((string) file_get_contents($datei), true);

    if (! is_array($inhalt)) {
        exit("unlesbar: {$datei}\n");
    }

    array_push($karten, ...$inhalt);
}

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

// Gemessen am 2026-09-21
const KARTEN = 32485, GRAFIK = 32487, IO = 32489, MODEL = 402, EXEMPLARE = 149000108212;
const BEZEICHNUNG = 149000103839, HERSTELLER = 149000102544, TEILENUMMER = 149000103844, ERSCHIEN = 149000103840;
const HERSTELLERLISTE = 149000102677, HERSTELLER_NAME = 149000102666;
const K_BUS = 149000109571, K_KONFIG = 149000109574, K_SPEICHER = 149000109572, K_SPEICHER_MAX = 149000109573;
const K_BOOTROM = 149000109575, K_KOMPATIBEL = 149000109576, K_ANSCHLUESSE = 149000109634, K_ONBOARD = 149000109636;
const ALT_BEZ = 149000109570, AB_BEZ = 149000108623, AB_BEZIEHUNG = 149000108624, AB_HINWEIS = 149000108625;
const A_SCHNITTSTELLE = 149000108705, A_ANZAHL = 149000108706, A_HINWEIS = 149000108707, O_ART = 149000108710, O_CHIP = 149000108711;
const SCHNITTSTELLEN = 149000103870, BUSSE = 149000108006, EW_WERT = 4234, EW_PREFIX = 4235, EW_EINHEIT = 4236, KILO = 4004, BYTE = 149000103868;
const QUELLEN = 149000108186, HERKUNFT = 149000108187, HERKUNFTSHINWEIS = 149000108188;
const EX_MODELL = 149000108189, EX_HINWEIS = 149000108198;

$sagen = static fn (string $satz) => print($satz . "\n");

$satzMit = static fn (int $knotenId, int $feldId, string $text): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
     WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s AND r.record_type = 'user' LIMIT 1",
    $knotenId,
    $feldId,
    $text
))) === null ? null : (int) $id;

/** Einen Satz irgendwo unter einem Knoten am Namen finden — für Busse und Schnittstellen, die in Gruppen stehen. */
$satzUnter = static function (int $wurzel, string $name) use ($wpdb, $p): ?int {
    $id = $wpdb->get_var($wpdb->prepare(
        "WITH RECURSIVE ast(id) AS (SELECT %d UNION ALL SELECT n.id FROM {$p}nodes n JOIN ast ON n.parent_node_id = ast.id)
         SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id JOIN ast ON ast.id = r.node_id
         WHERE v.relation_id = %d AND v.value_text = %s AND r.record_type = 'user' LIMIT 1",
        $wurzel,
        BEZEICHNUNG,
        $name
    ));

    return $id === null ? null : (int) $id;
};

$konstante = static fn (string $wahl, string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = (SELECT id FROM {$p}nodes_named WHERE name = %s LIMIT 1)",
    $name,
    $wahl
))) === null ? null : (int) $id;

$hersteller = static function (?string $name) use ($satzMit, $data, $schreiben): ?int {
    $name = trim((string) $name);

    // *«unbekannt» ist kein Hersteller: dann bleibt das Feld leer, und der Zweifel steht im Herkunftshinweis.*
    if ($name === '' || mb_strtolower($name) === 'unbekannt') {
        return null;
    }

    $steht = $satzMit(HERSTELLERLISTE, HERSTELLER_NAME, $name);

    if ($steht !== null || ! $schreiben) {
        return $steht;
    }

    $neu = $data->create(HERSTELLERLISTE, RecordType::User)->id;
    $data->put($neu, HERSTELLER_NAME, TypedValue::ofText($name));

    return $neu;
};

// ── Quellen, wie beim Mainboard-Import ─────────────────────────────────────────────────────────────
$verzeichnis = (int) $wpdb->get_var("SELECT id FROM {$p}nodes_named WHERE name = 'Quellenverzeichnis' AND parent_node_id = " . MODEL);
$qFeld       = static fn (string $name): int => (int) $wpdb->get_var($wpdb->prepare(
    "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s AND r.hide = 0",
    $verzeichnis,
    $name
));
$qAdresse = $qFeld('Adresse');
$qArt     = $qFeld('Art');
$qAbruf   = $qFeld('Abgerufen');

$quelle = static function (array $q) use ($wpdb, $p, $verzeichnis, $qAdresse, $qArt, $qAbruf, $data, $konstante): ?int {
    $url = trim((string) ($q['url'] ?? ''));

    if ($url === '') {
        return null;
    }

    $steht = $wpdb->get_var($wpdb->prepare("SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d AND value_text = %s LIMIT 1", $qAdresse, $url));

    if ($steht !== null) {
        return (int) $steht;
    }

    $neu = $data->create($verzeichnis, RecordType::User)->id;
    $data->put($neu, BEZEICHNUNG, TypedValue::ofText(trim((string) ($q['titel'] ?? '')) !== '' ? (string) $q['titel'] : $url));
    $data->put($neu, $qAdresse, TypedValue::ofText($url));

    $art = preg_match('/\.pdf($|\?)/i', $url) === 1 ? 'Handbuch' : (str_contains($url, 'vogons.org') ? 'Forum' : 'Webseite');

    if (($artId = $konstante('Quellenarten', $art)) !== null) {
        $data->put($neu, $qArt, TypedValue::ofReference($artId));
    }

    $data->put($neu, $qAbruf, TypedValue::ofDate('2026-09-21 00:00:00'));

    return $neu;
};

$speicher = static function (int $satz, int $feld, $kb) use ($data): void {
    if ($kb === null || (float) $kb <= 0) {
        return;
    }

    $teil = $data->createPart($satz, $feld);
    $data->put($teil->id, EW_WERT, TypedValue::ofDecimal(rtrim(rtrim(number_format((float) $kb, 3, '.', ''), '0'), '.')));
    $data->put($teil->id, EW_PREFIX, TypedValue::ofReference(KILO));
    $data->put($teil->id, EW_EINHEIT, TypedValue::ofReference(BYTE));
};

// ── Die Kartenarten ────────────────────────────────────────────────────────────────────────────────
$netzwerk = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", 'Netzwerkkarten', KARTEN));

if ($netzwerk === null) {
    $sagen('Knoten «Netzwerkkarten» unter Enhancement Cards');
    $netzwerk = $schreiben ? $editor->createNode('Netzwerkkarten', KARTEN, Category::class)->id : null;
}

// *Vor allem anderen: jedes Wort der Recherche muss im Modell stehen — sonst ginge es beim Schreiben still verloren.*
foreach ($karten as $k) {
    $pruefen = [];

    foreach ((array) ($k['bus'] ?? []) as $w) {
        $pruefen[] = ['Bus', $w, $satzUnter(BUSSE, (string) $w)];
    }

    foreach ((array) ($k['anschluesse'] ?? []) as $a) {
        $pruefen[] = ['Schnittstelle', $a['schnittstelle'], $satzUnter(SCHNITTSTELLEN, (string) $a['schnittstelle'])];
    }

    foreach ([['onboard', 'Onboard-Funktionen', 'art'], ['alternative_bezeichnungen', 'Bezeichnungsbeziehungen', 'beziehung']] as [$feld, $wahl, $schluessel]) {
        foreach ((array) ($k[$feld] ?? []) as $e) {
            $pruefen[] = [$wahl, $e[$schluessel] ?? '', $konstante($wahl, (string) ($e[$schluessel] ?? ''))];
        }
    }

    foreach ([['konfiguration', 'Konfigurationsarten'], ['herkunft', 'Herkunftsarten']] as [$feld, $wahl]) {
        foreach ((array) ($k[$feld] ?? []) as $w) {
            $pruefen[] = [$wahl, $w, $konstante($wahl, (string) $w)];
        }
    }

    foreach ($pruefen as [$was, $wort, $id]) {
        if ($id === null) {
            $sagen("⚠ {$k['tabelle']}/{$k['spalte']}: {$was} «{$wort}» steht nicht im Modell");
        }
    }
}

$knotenFuer = ['Grafikkarte' => GRAFIK, 'Netzwerkkarte' => $netzwerk === null ? null : (int) $netzwerk, 'Multi-I/O-Karte' => IO];
$neu        = ['karte' => 0, 'exemplar' => 0];

foreach ($karten as $k) {
    $knoten = $knotenFuer[$k['art']] ?? null;
    $name   = trim((string) ($k['bezeichnung'] ?? ''));

    if ($name === '') {
        $sagen("⚠ Tabelle {$k['tabelle']}, Spalte {$k['spalte']}: keine Bezeichnung — übersprungen");
        continue;
    }

    // *Erkannt an Bezeichnung **und** Hersteller: «AT-2500TX V3» steht zweimal in seiner Tabelle, von Allied Telesyn und von Longshine.*
    $herstellerId = $hersteller($k['hersteller'] ?? null);
    $satz         = $knoten === null ? null : (($id = $wpdb->get_var($wpdb->prepare(
        "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
         LEFT JOIN {$p}relation_records h ON h.node_record_id = r.id AND h.relation_id = %d
         WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s AND r.record_type = 'user'
           AND COALESCE(h.value_ref, 0) = %d LIMIT 1",
        HERSTELLER,
        $knoten,
        BEZEICHNUNG,
        $name,
        $herstellerId ?? 0
    ))) === null ? null : (int) $id);

    if ($satz === null) {
        ++$neu['karte'];
        $sagen("{$k['art']} «{$k['hersteller']} {$name}»");

        if ($schreiben && $knoten !== null) {
            $satz = $data->create($knoten, RecordType::User)->id;
            $data->put($satz, BEZEICHNUNG, TypedValue::ofText($name));

            if ($herstellerId !== null) {
                $data->put($satz, HERSTELLER, TypedValue::ofRecordReference($herstellerId));
            }

            foreach ((array) ($k['teilenummern'] ?? []) as $nummer) {
                $data->appendValue($satz, TEILENUMMER, TypedValue::ofText((string) $nummer));
            }

            if (($k['erscheinungsjahr'] ?? null) !== null) {
                $data->put($satz, ERSCHIEN, TypedValue::ofDate(((int) $k['erscheinungsjahr']) . '-01-01 00:00:00'));
            }

            foreach ((array) ($k['bus'] ?? []) as $bus) {
                if (($id = $satzUnter(BUSSE, (string) $bus)) !== null) {
                    $data->appendValue($satz, K_BUS, TypedValue::ofRecordReference($id));
                } else {
                    $sagen("  ⚠ Bus «{$bus}» nicht im Modell");
                }
            }

            foreach ((array) ($k['onboard'] ?? []) as $o) {
                $teil = $data->createPart($satz, K_ONBOARD);

                if (($art = $konstante('Onboard-Funktionen', (string) ($o['art'] ?? ''))) !== null) {
                    $data->put($teil->id, O_ART, TypedValue::ofReference($art));
                }

                if (trim((string) ($o['chip'] ?? '')) !== '') {
                    $data->put($teil->id, O_CHIP, TypedValue::ofText(trim((string) $o['chip'])));
                }
            }

            $speicher($satz, K_SPEICHER, $k['speicher_kb'] ?? null);
            $speicher($satz, K_SPEICHER_MAX, $k['speicher_hoechstens_kb'] ?? null);

            foreach ((array) ($k['anschluesse'] ?? []) as $a) {
                if (($id = $satzUnter(SCHNITTSTELLEN, (string) $a['schnittstelle'])) === null) {
                    $sagen("  ⚠ Schnittstelle «{$a['schnittstelle']}» nicht im Modell");
                    continue;
                }

                $teil = $data->createPart($satz, K_ANSCHLUESSE);
                $data->put($teil->id, A_SCHNITTSTELLE, TypedValue::ofRecordReference($id));

                if (($a['anzahl'] ?? null) !== null) {
                    $data->put($teil->id, A_ANZAHL, TypedValue::ofInt((int) $a['anzahl']));
                }

                if (trim((string) ($a['hinweis'] ?? '')) !== '') {
                    $data->put($teil->id, A_HINWEIS, TypedValue::ofText(trim((string) $a['hinweis'])));
                }
            }

            foreach ((array) ($k['konfiguration'] ?? []) as $art) {
                if (($id = $konstante('Konfigurationsarten', (string) $art)) !== null) {
                    $data->appendValue($satz, K_KONFIG, TypedValue::ofReference($id));
                }
            }

            if (trim((string) ($k['boot_rom'] ?? '')) !== '') {
                $data->put($satz, K_BOOTROM, TypedValue::ofText(trim((string) $k['boot_rom'])));
            }

            foreach ((array) ($k['kompatibel_mit'] ?? []) as $was) {
                $data->appendValue($satz, K_KOMPATIBEL, TypedValue::ofText((string) $was));
            }

            foreach ((array) ($k['alternative_bezeichnungen'] ?? []) as $ab) {
                $teil = $data->createPart($satz, ALT_BEZ);
                $data->put($teil->id, AB_BEZ, TypedValue::ofText((string) $ab['bezeichnung']));

                if (($id = $konstante('Bezeichnungsbeziehungen', (string) ($ab['beziehung'] ?? ''))) !== null) {
                    $data->put($teil->id, AB_BEZIEHUNG, TypedValue::ofReference($id));
                }

                if (trim((string) ($ab['hinweis'] ?? '')) !== '') {
                    $data->put($teil->id, AB_HINWEIS, TypedValue::ofText(trim((string) $ab['hinweis'])));
                }
            }

            foreach (array_values(array_unique(array_map('strval', (array) ($k['herkunft'] ?? [])))) as $art) {
                if (($id = $konstante('Herkunftsarten', $art)) !== null) {
                    $data->appendValue($satz, HERKUNFT, TypedValue::ofReference($id));
                }
            }

            $zweifel = array_values(array_filter(array_map('trim', array_map('strval', (array) ($k['zweifel'] ?? [])))));
            $hinweis = trim((string) ($k['herkunftshinweis'] ?? ''));

            $data->put($satz, HERKUNFTSHINWEIS, TypedValue::ofText(
                "Aus seiner Tabelle (TablePress {$k['tabelle']}, Spalte {$k['spalte']}), beim Übernehmen geprüft (D-899)."
                . ($hinweis === '' ? '' : ' · ' . $hinweis)
                . ($zweifel === [] ? '' : ' · Offen: ' . implode('; ', $zweifel))
            ));

            $gesehen = [];

            foreach ((array) ($k['quellen'] ?? []) as $q) {
                if (($qId = $quelle((array) $q)) !== null && ! isset($gesehen[$qId])) {
                    $gesehen[$qId] = true;
                    $data->appendValue($satz, QUELLEN, TypedValue::ofRecordReference($qId));
                }
            }
        }
    }

    // Sein Stück
    $marke = "TablePress {$k['tabelle']}, Spalte {$k['spalte']}";
    $steht = $wpdb->get_var($wpdb->prepare(
        "SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d AND value_text LIKE %s LIMIT 1",
        EX_HINWEIS,
        '%(' . $wpdb->esc_like($marke) . ')%'
    ));

    if ($steht !== null) {
        continue;
    }

    ++$neu['exemplar'];

    if (! $schreiben || $satz === null) {
        continue;
    }

    $ex = $data->create(EXEMPLARE, RecordType::User)->id;
    $data->put($ex, EX_MODELL, TypedValue::ofRecordReference($satz));

    $exemplar = (array) ($k['exemplar'] ?? []);
    $datecode = (int) $wpdb->get_var("SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = " . EXEMPLARE . " AND n.name = 'Datecode'");

    if (trim((string) ($exemplar['datecode'] ?? '')) !== '' && $datecode > 0) {
        $data->put($ex, $datecode, TypedValue::ofText(trim((string) $exemplar['datecode'])));
    }

    $data->put($ex, EX_HINWEIS, TypedValue::ofText(trim(
        "Seine Karte, aus seiner Tabelle ({$marke})."
        . (trim((string) ($exemplar['hinweis'] ?? '')) === '' ? '' : ' ' . trim((string) $exemplar['hinweis']))
    )));
}

$sagen("\n{$neu['karte']} Karten, {$neu['exemplar']} Exemplare" . ($schreiben ? ' übernommen.' : ' — nur gezeigt. Mit --write übernehmen.'));
