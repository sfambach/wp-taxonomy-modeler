<?php declare(strict_types=1);

/**
 * Benchmarks: Programme, Messgrößen, Testaufbauten und seine Messwerte (2026-09-20).
 *
 *     php scripts/dev/benchmarks.php <bm-normal.json>            # nur zeigen
 *     php scripts/dev/benchmarks.php <bm-normal.json> --write    # übernehmen
 *
 * ⚠️ *Seine Worte ([D-893](../../docs/NewConcept/90-decision-log.md), aus INF-058): «jeder Test hat einen Testaufbau»,
 * «so ein Benchmark ist so eine eigene Softwarekategorie», «ein Test als eigener Datensatz, ja, aber facettenreicher»,
 * «Betriebssysteme nicht weiter als Windows 2000», und heute: «ja mach die benchmarks».*
 *
 * **Vier Dinge, und jedes ist etwas anderes:**
 * 1. **Benchmark** — das Programm, eine Softwarekategorie: 3DBench, Doom, Speedsys, PCMark 2002 …
 * 2. **Messgröße** — was das Programm in einem Szenario ausgibt: «Doom max Detail [FPS]», mit Einheit, Richtung und dem,
 *    was sie misst. Eine Zeile seiner Tabellen ist genau eine Messgröße.
 * 3. **Testaufbau** — der Rechner, auf dem gemessen wurde: Board, Revision, CPU, Karte, Platte. Eine Spalte seiner Tabellen.
 * 4. **Messwert** — eine Zahl zu einem Testaufbau und einer Messgröße. Eine Zelle.
 *
 * *Die Tabellen (TablePress 13, 14, 19, 20, 22) sind vorher zu einer Liste normalisiert worden; diese Datei liest sie.*
 *
 * Wiederholbar: alles wird am Namen erkannt; ein vorhandener Satz wird nicht neu beschrieben.
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
use Taxmod\Core\Model\Setting\SettingsValue;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);
$quelle    = $argv[1] ?? '';
$daten     = json_decode((string) file_get_contents($quelle), true) ?: exit("keine Daten: {$quelle}\n");

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
/** @var \Taxmod\Core\Service\ModelEditor $editor */
$editor = (new ReflectionProperty($screen, 'editor'))->getValue($screen);
/** @var \Taxmod\Core\Service\DataEntry $data */
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);
/** @var \Taxmod\Core\Service\SettingsEditor $attributes */
$attributes = (new ReflectionProperty($screen, 'attributes'))->getValue($screen);
$store      = (new ReflectionProperty($attributes, 'settings'))->getValue($attributes);

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Gemessen am 2026-09-20
const MODEL = 402, SOFTWARE = 149000102258, KONSTANTEN = 410, TEXT = 1175, DECIMAL = 1173, MEDIUM = 149000107775;
const BEZEICHNUNG = 149000103839, BESCHREIBUNG = 149000107372;
const CPUS = 149000103845, MAINBOARDS = 149000107691, REVISIONEN = 149000107757, EXEMPLARE = 149000108212;

$sagen = static fn (string $satz) => print($satz . "\n");

$knoten = static function (string $name, int $vater, string $klasse) use ($editor, $wpdb, $p, $schreiben, $sagen): ?int {
    $steht = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", $name, $vater));

    if ($steht !== null) {
        return (int) $steht;
    }

    $sagen("Knoten «{$name}»");

    return $schreiben ? $editor->createNode($name, $vater, $klasse)->id : null;
};

$feldAn = static fn (int $eigner, string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
    $eigner,
    $name
))) === null ? null : (int) $id;

$feld = static function (?int $eigner, ?int $ziel, string $name, RelationKind $art, Multiplicity $wieOft) use ($editor, $feldAn, $schreiben, $sagen): ?int {
    if ($eigner === null || $ziel === null) {
        return null;
    }

    $steht = $feldAn($eigner, $name);

    if ($steht !== null) {
        return $steht;
    }

    $sagen("  Feld «{$name}» ({$wieOft->value})");

    if (! $schreiben) {
        return null;
    }

    $kante = $editor->addField($eigner, $ziel, $name, $art);

    return $kante->multiplicity === $wieOft ? $kante->id : $editor->setMultiplicity($eigner, $kante->id, $wieOft)->id;
};

// ── 1. Die Knoten ──────────────────────────────────────────────────────────────────────────────────
$benchmarks   = $knoten('Benchmarks', SOFTWARE, Category::class);
$messwesen    = $knoten('Messwesen', MODEL, Category::class);
$messgroessen = $messwesen === null ? null : $knoten('Messgrößen', $messwesen, Category::class);
$aufbauten    = $messwesen === null ? null : $knoten('Testaufbauten', $messwesen, Category::class);
$messwerte    = $messwesen === null ? null : $knoten('Messwerte', $messwesen, Category::class);

$richtungen = $knoten('Messrichtungen', KONSTANTEN, Choice::class);
$gegenstand = $knoten('Messgegenstände', KONSTANTEN, Choice::class);
$konstante  = static fn (?int $wahl, string $name): ?int => $wahl === null ? null : $knoten($name, $wahl, Constant::class);

$richtung = [];

foreach (['höher ist besser', 'niedriger ist besser'] as $name) {
    $richtung[$name] = $konstante($richtungen, $name);
}

$misst = [];

foreach (['CPU', 'FPU', 'Grafik', 'Arbeitsspeicher', 'Festplatte', 'System'] as $name) {
    $misst[$name] = $konstante($gegenstand, $name);
}

// ── 2. Die Felder ──────────────────────────────────────────────────────────────────────────────────
$f = [
    'paket'        => $feld($benchmarks, TEXT, 'Paket', RelationKind::Composition, Multiplicity::ZeroToOne),
    'laeuft'       => $feld($benchmarks, TEXT, 'Läuft unter', RelationKind::Composition, Multiplicity::ZeroToOne),
    'voraus'       => $feld($benchmarks, TEXT, 'Voraussetzungen', RelationKind::Composition, Multiplicity::ZeroToOne),
    'bm_links'     => $feld($benchmarks, MEDIUM, 'Links', RelationKind::Composition, Multiplicity::ZeroToMany),

    'mg_benchmark' => $feld($messgroessen, $benchmarks, 'Benchmark', RelationKind::Aggregation, Multiplicity::ExactlyOne),
    'mg_szenario'  => $feld($messgroessen, TEXT, 'Szenario', RelationKind::Composition, Multiplicity::ZeroToOne),
    'mg_groesse'   => $feld($messgroessen, TEXT, 'Größe', RelationKind::Composition, Multiplicity::ZeroToOne),
    'mg_einheit'   => $feld($messgroessen, TEXT, 'Einheit', RelationKind::Composition, Multiplicity::ZeroToOne),
    'mg_richtung'  => $feld($messgroessen, $richtungen, 'Richtung', RelationKind::Aggregation, Multiplicity::ZeroToOne),
    'mg_misst'     => $feld($messgroessen, $gegenstand, 'Misst', RelationKind::Aggregation, Multiplicity::ZeroToOne),

    'ta_mainboard' => $feld($aufbauten, MAINBOARDS, 'Mainboard', RelationKind::Aggregation, Multiplicity::ZeroToOne),
    'ta_revision'  => $feld($aufbauten, REVISIONEN, 'Revision', RelationKind::Aggregation, Multiplicity::ZeroToOne),
    'ta_cpu'       => $feld($aufbauten, CPUS, 'CPU', RelationKind::Aggregation, Multiplicity::ZeroToOne),
    'ta_exemplar'  => $feld($aufbauten, EXEMPLARE, 'Exemplar', RelationKind::Aggregation, Multiplicity::ZeroToOne),
    'ta_ram'       => $feld($aufbauten, TEXT, 'Arbeitsspeicher', RelationKind::Composition, Multiplicity::ZeroToOne),
    'ta_grafik'    => $feld($aufbauten, TEXT, 'Grafikkarte', RelationKind::Composition, Multiplicity::ZeroToOne),
    'ta_platte'    => $feld($aufbauten, TEXT, 'Festplatte', RelationKind::Composition, Multiplicity::ZeroToOne),
    'ta_os'        => $feld($aufbauten, TEXT, 'Betriebssystem', RelationKind::Composition, Multiplicity::ZeroToOne),
    'ta_hinweis'   => $feld($aufbauten, TEXT, 'Hinweis', RelationKind::Composition, Multiplicity::ZeroToOne),

    'mw_aufbau'    => $feld($messwerte, $aufbauten, 'Testaufbau', RelationKind::Aggregation, Multiplicity::ExactlyOne),
    'mw_groesse'   => $feld($messwerte, $messgroessen, 'Messgröße', RelationKind::Aggregation, Multiplicity::ExactlyOne),
    'mw_wert'      => $feld($messwerte, DECIMAL, 'Wert', RelationKind::Composition, Multiplicity::ZeroToOne),
    'mw_hinweis'   => $feld($messwerte, TEXT, 'Hinweis', RelationKind::Composition, Multiplicity::ZeroToOne),
];

// ⚠️ *Die Bezeichnung eines Messwerts wird gerechnet (D-888): Testaufbau und Messgröße sagen alles, und niemand tippt 919 Namen.*
if ($schreiben && $messwerte !== null && $f['mw_aufbau'] !== null && $f['mw_groesse'] !== null) {
    $steht = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}settings_value WHERE attribut = 'summary_fields' AND node_id = %d AND relation_id = %d",
        $messwerte,
        BEZEICHNUNG
    ));

    if ($steht === 0) {
        // ⚠️ *Die Klasse des Knotens selbst — sie erklärt die Einstellung. Gemessen: eine beliebige Zeile brachte `Choice`,
        // und die Auflösung sah die Zusage dann nicht an (derselbe Fehler wie bei den Bauteilen).*
        $klasse = (string) $editor->find($messwerte)?->klasse;

        foreach ([$f['mw_aufbau'], $f['mw_groesse']] as $stelle => $feldId) {
            $store->addValue(SettingsValue::atNode($messwerte, $klasse, 'summary_fields', TypedValue::ofRelationReference($feldId), BEZEICHNUNG, $stelle));
        }

        $sagen('  Bezeichnung des Messwerts: Testaufbau · Messgröße (gerechnet)');
    }
}

// ── 3. Die Messgrößen aus den Zeilen lesen ─────────────────────────────────────────────────────────
/** @return array{programm: string, szenario: string, groesse: string, einheit: string, os: string, unsicher: bool} */
$zerlegen = static function (string $label): array {
    $os       = '';
    $unsicher = str_contains($label, '?');
    $label    = trim(str_replace('?', '', $label));

    foreach (['WinXP' => 'Windows XP', 'WinXp' => 'Windows XP', 'Win 7' => 'Windows 7'] as $vorne => $system) {
        if (str_starts_with($label, $vorne . ' ')) {
            $os    = $system;
            $label = trim(substr($label, strlen($vorne)));
        }
    }

    $groesse = '';

    if (preg_match('/\[([^\]]+)\]/u', $label, $m) === 1) {
        $groesse = trim($m[1]);
        $label   = trim(str_replace($m[0], '', $label));
    }

    $szenario = '';

    if (preg_match('/\(([^)]+)\)/u', $label, $m) === 1) {
        $szenario = trim($m[1]);
        $label    = trim(str_replace($m[0], '', $label));
    }

    if (preg_match('/(\d{3,4}\s*x\s*\d{3,4})/u', $label, $m) === 1) {
        $szenario = trim(preg_replace('/\s+/', '', $m[1]) . ($szenario === '' ? '' : ', ' . $szenario));
        $label    = trim(str_replace($m[0], '', $label));
    }

    // *Was hinter dem Programmnamen an Worten übrig bleibt, ist das Szenario: «Doom min Detail», «Speedsys RAM MB/S L1».*
    $programme = ['3D Bench', 'Chris 3D Benchmark', 'PC Player Benchmark', 'Doom', 'Quake', 'System Info', 'Landmark',
        'Top Bench', 'Speedsys', 'HW Info', 'PCMark 2002', '3DMark 03', '3DMark11', 'PCMark7'];
    $programm  = '';

    foreach ($programme as $kandidat) {
        if (stripos($label, $kandidat) === 0) {
            $programm = $kandidat;
            $label    = trim(substr($label, strlen($kandidat)));

            break;
        }
    }

    if ($programm === '') {
        $programm = $label;
        $label    = '';
    }

    // *«Quake Quake 640x480» — der Name stand zweimal da.*
    if (stripos($label, $programm) === 0) {
        $label = trim(substr($label, strlen($programm)));
    }

    $szenario = trim($label . ($label !== '' && $szenario !== '' ? ', ' : '') . $szenario);

    // *Steht die Größe nicht in Klammern, ist es die Zahl, die das Programm selbst nennt.*
    if ($groesse === '' && preg_match('/(MB\/S\s*\w+|Score)/iu', $szenario, $m) === 1) {
        $groesse  = trim($m[1]);
        $szenario = trim(str_replace($m[0], '', $szenario), " ,\t");
    }

    $einheiten = ['FPS' => 'Bilder je Sekunde', 'Zeit' => 'Sekunden', 'Realtics' => 'Realtics', 'frames' => 'Bilder',
        'Frames' => 'Bilder', 'Score' => 'Punkte', 'Data transfer rate' => 'MB/s'];
    $einheit   = $einheiten[$groesse] ?? (stripos($groesse, 'MB/S') === 0 ? 'MB/s' : '');

    return ['programm' => $programm, 'szenario' => $szenario, 'groesse' => $groesse, 'einheit' => $einheit, 'os' => $os, 'unsicher' => $unsicher];
};

$richtungFuer = static fn (string $groesse): string => in_array(strtolower($groesse), ['zeit', 'realtics'], true)
    ? 'niedriger ist besser'
    : 'höher ist besser';

$misstFuer = static function (string $programm, string $groesse): string {
    $g = strtolower($groesse);
    $q = strtolower($programm);

    return match (true) {
        str_contains($g, 'hdd'), str_contains($g, 'data transfer'), str_contains($g, 'platte') => 'Festplatte',
        str_contains($g, 'ram'), str_contains($g, 'speicher'), str_contains($g, 'mb/s')        => 'Arbeitsspeicher',
        str_contains($g, 'fpu'), str_contains($g, 'mmx')                                       => 'FPU',
        str_contains($g, 'video'), str_contains($g, 'gpu')                                     => 'Grafik',
        str_contains($g, 'cpu')                                                                => 'CPU',
        in_array($q, ['3d bench', 'chris 3d benchmark', 'pc player benchmark', 'doom', 'quake', '3dmark 03', '3dmark11'], true) => 'Grafik',
        $q === 'top bench', $q === 'pcmark7'                                                   => 'System',
        default                                                                                 => 'CPU',
    };
};

$satzMit = static fn (int $knotenId, int $feldId, string $text): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
     WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s LIMIT 1",
    $knotenId,
    $feldId,
    $text
))) === null ? null : (int) $id;

$programmFuer = [];
$groesseFuer  = [];
$neu          = ['programm' => 0, 'messgroesse' => 0, 'aufbau' => 0, 'wert' => 0];

foreach ($daten['messgroessen'] as $label) {
    $teile = $zerlegen((string) $label);

    if ($benchmarks === null || $messgroessen === null) {
        continue;
    }

    $programmId = $programmFuer[$teile['programm']] ?? $satzMit($benchmarks, BEZEICHNUNG, $teile['programm']);

    if ($programmId === null) {
        ++$neu['programm'];
        $sagen("Benchmark «{$teile['programm']}»");

        if ($schreiben) {
            $programmId = $data->create($benchmarks, RecordType::User)->id;
            $data->put($programmId, BEZEICHNUNG, TypedValue::ofText($teile['programm']));

            if ($teile['os'] !== '' && $f['laeuft'] !== null) {
                $data->put($programmId, (int) $f['laeuft'], TypedValue::ofText($teile['os']));
            }
        }
    }

    $programmFuer[$teile['programm']] = $programmId;

    $name = trim($teile['programm'] . ' ' . $teile['szenario'] . ($teile['groesse'] === '' ? '' : ' [' . $teile['groesse'] . ']'));
    $steht = $satzMit($messgroessen, BEZEICHNUNG, $name);

    if ($steht === null) {
        ++$neu['messgroesse'];
        $sagen("  Messgröße «{$name}»");

        if ($schreiben && $programmId !== null) {
            $steht = $data->create($messgroessen, RecordType::User)->id;
            $data->put($steht, BEZEICHNUNG, TypedValue::ofText($name));
            $data->put($steht, (int) $f['mg_benchmark'], TypedValue::ofRecordReference($programmId));

            foreach ([['mg_szenario', $teile['szenario']], ['mg_groesse', $teile['groesse']], ['mg_einheit', $teile['einheit']]] as [$schluessel, $wert]) {
                if ($wert !== '' && $f[$schluessel] !== null) {
                    $data->put($steht, (int) $f[$schluessel], TypedValue::ofText($wert));
                }
            }

            if (($id = $richtung[$richtungFuer($teile['groesse'])] ?? null) !== null && $f['mg_richtung'] !== null) {
                $data->put($steht, (int) $f['mg_richtung'], TypedValue::ofReference($id));
            }

            if (($id = $misst[$misstFuer($teile['programm'], $teile['groesse'])] ?? null) !== null && $f['mg_misst'] !== null) {
                $data->put($steht, (int) $f['mg_misst'], TypedValue::ofReference($id));
            }

            if ($teile['unsicher'] && $f['mw_hinweis'] !== null) {
                $data->put($steht, BESCHREIBUNG, TypedValue::ofText('In seiner Tabelle mit «?» vermerkt — die Werte sind unsicher.'));
            }
        }
    }

    $groesseFuer[(string) $label] = $steht;
}

// ── 4. Die Testaufbauten aus den Spalten ───────────────────────────────────────────────────────────
$modellFuer = static function (string $system) use ($wpdb, $p): array {
    // *Ein Board wird am Namen gesucht: die längste Modellbezeichnung, die in der Spaltenüberschrift steckt.*
    $treffer = [null, null];

    foreach ($wpdb->get_results("SELECT r.id, r.node_id, v.value_text AS name FROM {$p}relation_records v
        JOIN {$p}node_records r ON r.id = v.node_record_id
        WHERE v.relation_id = " . BEZEICHNUNG . ' AND r.node_id IN (' . MAINBOARDS . ', ' . CPUS . ") AND r.record_type = 'user'") as $satz) {
        $name = (string) $satz->name;

        if ($name === '' || stripos($system, $name) === false) {
            continue;
        }

        $stelle = (int) $satz->node_id === MAINBOARDS ? 0 : 1;

        if ($treffer[$stelle] === null || strlen($name) > strlen((string) $treffer[$stelle][1])) {
            $treffer[$stelle] = [(int) $satz->id, $name];
        }
    }

    return $treffer;
};

$aufbauFuer = [];

foreach ($daten['systeme'] as $system) {
    if ($aufbauten === null) {
        continue;
    }

    $steht = $satzMit($aufbauten, BEZEICHNUNG, (string) $system);

    if ($steht === null) {
        ++$neu['aufbau'];
        [$board, $cpu] = $modellFuer((string) $system);
        $sagen("Testaufbau «{$system}»" . ($board === null ? '' : " → Board «{$board[1]}»") . ($cpu === null ? '' : " → CPU «{$cpu[1]}»"));

        if ($schreiben) {
            $steht = $data->create($aufbauten, RecordType::User)->id;
            $data->put($steht, BEZEICHNUNG, TypedValue::ofText((string) $system));

            if ($board !== null && $f['ta_mainboard'] !== null) {
                $data->put($steht, (int) $f['ta_mainboard'], TypedValue::ofRecordReference($board[0]));
            }

            if ($cpu !== null && $f['ta_cpu'] !== null) {
                $data->put($steht, (int) $f['ta_cpu'], TypedValue::ofRecordReference($cpu[0]));
            }
        }
    }

    $aufbauFuer[(string) $system] = $steht;
}

// ── 5. Die Messwerte ───────────────────────────────────────────────────────────────────────────────
$zahl = static function (string $wert): ?string {
    $roh = str_replace(['.', ' '], '', $wert);
    $roh = str_replace(',', '.', $roh);

    return preg_match('/^-?\d+(\.\d+)?$/', $roh) === 1 ? $roh : null;
};

$gesehen = [];

foreach ($daten['werte'] as [$tabelle, $system, $label, $wert]) {
    $aufbau  = $aufbauFuer[$system] ?? null;
    $groesse = $groesseFuer[$label] ?? null;

    if ($aufbau === null || $groesse === null || $messwerte === null) {
        continue;
    }

    $schluessel = $aufbau . ':' . $groesse;

    if (isset($gesehen[$schluessel])) {
        continue;
    }

    $gesehen[$schluessel] = true;

    $steht = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}relation_records a
         JOIN {$p}relation_records b ON b.node_record_id = a.node_record_id AND b.relation_id = %d AND b.value_ref = %d
         WHERE a.relation_id = %d AND a.value_ref = %d",
        (int) $f['mw_groesse'],
        $groesse,
        (int) $f['mw_aufbau'],
        $aufbau
    ));

    if ($steht > 0) {
        continue;
    }

    ++$neu['wert'];

    if (! $schreiben) {
        continue;
    }

    $satz = $data->create($messwerte, RecordType::User)->id;
    $data->put($satz, (int) $f['mw_aufbau'], TypedValue::ofRecordReference($aufbau));
    $data->put($satz, (int) $f['mw_groesse'], TypedValue::ofRecordReference($groesse));

    $gerechnet = $zahl((string) $wert);

    if ($gerechnet !== null) {
        $data->put($satz, (int) $f['mw_wert'], TypedValue::ofDecimal($gerechnet));
    }

    // ⚠️ *Was keine Zahl ist, bleibt im Wortlaut stehen — «Kein CoPro», «Funktioniert nicht», «7398,48 ???» sind Befunde.*
    if ($gerechnet === null || $gerechnet !== str_replace(',', '.', (string) $wert)) {
        $data->put($satz, (int) $f['mw_hinweis'], TypedValue::ofText('Aus seiner Tabelle ' . $tabelle . ': «' . $wert . '».'));
    }
}

$sagen("\n{$neu['programm']} Programme, {$neu['messgroesse']} Messgrößen, {$neu['aufbau']} Testaufbauten, {$neu['wert']} Messwerte"
    . ($schreiben ? ' übernommen.' : ' — nur gezeigt. Mit --write übernehmen.'));
