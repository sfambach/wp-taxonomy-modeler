<?php declare(strict_types=1);

/**
 * Sein Wareneingang (TablePress 15) als «Käufe» (2026-09-21).
 *
 *     php scripts/dev/kaeufe-aus-wareneingang.php <t-15.json>            # nur zeigen
 *     php scripts/dev/kaeufe-aus-wareneingang.php <t-15.json> --write    # übernehmen
 *
 * ⚠️ *Zu [D-897](../../docs/NewConcept/90-decision-log.md): ein Kauf ist kein Exemplar — eine Zeile sind oft mehrere Stück
 * («24 CPU-Lüfter»), und für einen 40-mm-Lüfter braucht es kein Modell. Ein Kauf zeigt auf sein Modell, wenn es eines gibt.*
 *
 * *Gelesen wie es dasteht; berichtigt nur die Schreibweise («Graphikarte» → «Grafikkarte») und die Zahlen (Komma → Punkt).
 * «Ebay, rudys-resterampe» heisst Plattform Ebay, Händler rudys-resterampe; steht nur ein Name da, ist es der Händler.*
 *
 * Wiederholbar: ein Kauf wird an Bezeichnung, Kaufdatum und Gesamtpreis erkannt.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);
$tabelle   = json_decode((string) file_get_contents($argv[1] ?? ''), true) ?: exit("keine Tabelle\n");

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
/** @var \Taxmod\Core\Service\DataEntry $data */
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Gemessen am 2026-09-21
const BEZEICHNUNG = 149000103839, EURO = 8571, MAINBOARDS = 149000107691, CPUS = 149000103845;

$knoten = (int) $wpdb->get_var("SELECT id FROM {$p}nodes_named WHERE name = 'Käufe' AND parent_node_id = 402");

if ($knoten === 0) {
    exit("Knoten «Käufe» fehlt — erst modell-karten-kaeufe.php\n");
}

$feld = static fn (string $name): int => (int) $wpdb->get_var($wpdb->prepare(
    "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s AND r.hide = 0",
    $knoten,
    $name
));

$f = [];

foreach (['Modell', 'Art', 'Anschluss', 'Anzahl', 'Preis je Stück', 'Preis gesamt', 'Währung', 'Gekauft am', 'Eingegangen am', 'Plattform', 'Händler'] as $name) {
    $f[$name] = $feld($name);
}

$monate = ['Jan' => 1, 'Feb' => 2, 'Mär' => 3, 'Mrz' => 3, 'Apr' => 4, 'Mai' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Okt' => 10, 'Nov' => 11, 'Dez' => 12];

$datum = static function (string $roh) use ($monate): ?string {
    $roh = trim($roh);

    if (preg_match('/^(\d{1,2})\.\s*([A-Za-zä]{3})\w*\.?\s+(\d{4})$/u', $roh, $m) === 1 && isset($monate[$m[2]])) {
        return sprintf('%04d-%02d-%02d 00:00:00', (int) $m[3], $monate[$m[2]], (int) $m[1]);
    }

    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $roh, $m) === 1) {
        return sprintf('%04d-%02d-%02d 00:00:00', (int) $m[3], (int) $m[2], (int) $m[1]);
    }

    return null;
};

$zahl = static function (string $roh): ?string {
    $roh = str_replace(['€', ' '], '', trim($roh));
    $roh = str_replace(',', '.', $roh);

    return preg_match('/^\d+(\.\d+)?$/', $roh) === 1 ? $roh : null;
};

/** Ein Modell, dessen Bezeichnung im Text steht — Groß- und Kleinschreibung und «+» egal. */
$modellFuer = static function (string $text) use ($wpdb, $p): ?int {
    $norm  = static fn (string $s): string => strtolower(preg_replace('/[^a-z0-9]/i', '', $s));
    $suche = $norm($text);
    $best  = null;

    foreach ($wpdb->get_results("SELECT r.id, v.value_text AS name FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
        WHERE v.relation_id = " . BEZEICHNUNG . ' AND r.node_id IN (' . MAINBOARDS . ', ' . CPUS . ") AND r.record_type = 'user'") as $satz) {
        $name = $norm((string) $satz->name);

        if (strlen($name) >= 5 && str_contains($suche, $name) && ($best === null || strlen($name) > $best[1])) {
            $best = [(int) $satz->id, strlen($name), (string) $satz->name];
        }
    }

    return $best[0] ?? null;
};

$neu = 0;

foreach (array_slice($tabelle, 1) as $zeile) {
    $zelle = static fn (int $i): string => trim(html_entity_decode(strip_tags((string) ($zeile[$i] ?? ''))));

    if (trim(implode('', array_map($zelle, range(0, 8)))) === '') {
        continue;
    }

    $art          = str_replace(['Graphikarte', 'Graphikkarte'], 'Grafikkarte', $zelle(0));
    $beschreibung = $zelle(1) !== '' ? $zelle(1) : $art . ' (ohne Beschreibung)';
    $gekauft      = $datum($zelle(7));
    $gesamt       = $zahl($zelle(5));

    $steht = $wpdb->get_var($wpdb->prepare(
        "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
         LEFT JOIN {$p}relation_records d ON d.node_record_id = r.id AND d.relation_id = %d
         LEFT JOIN {$p}relation_records g ON g.node_record_id = r.id AND g.relation_id = %d
         WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s AND (d.value_date <=> %s) AND (g.value_decimal <=> %s) LIMIT 1",
        $f['Gekauft am'],
        $f['Preis gesamt'],
        $knoten,
        BEZEICHNUNG,
        $beschreibung,
        $gekauft,
        // *Mit dem Preis: zweimal dieselbe Karte am selben Tag sind zwei Käufe (gemessen: 2 × 7,19 und 3 × 8,41 €).*
        $gesamt
    ));

    if ($steht !== null) {
        continue;
    }

    ++$neu;
    $modell = $modellFuer($beschreibung);
    echo "Kauf «{$beschreibung}» · {$zelle(3)} × {$zelle(4)} € · " . ($gekauft ?? '—') . ($modell === null ? '' : " → Modell #{$modell}") . "\n";

    if (! $schreiben) {
        continue;
    }

    $satz = $data->create($knoten, RecordType::User)->id;
    $data->put($satz, BEZEICHNUNG, TypedValue::ofText($beschreibung));

    if ($art !== '') {
        $data->put($satz, $f['Art'], TypedValue::ofText($art));
    }

    if ($zelle(2) !== '') {
        $data->put($satz, $f['Anschluss'], TypedValue::ofText($zelle(2)));
    }

    if (ctype_digit($zelle(3))) {
        $data->put($satz, $f['Anzahl'], TypedValue::ofInt((int) $zelle(3)));
    }

    if (($stueck = $zahl($zelle(4))) !== null) {
        $data->put($satz, $f['Preis je Stück'], TypedValue::ofDecimal($stueck));
    }

    if ($gesamt !== null) {
        $data->put($satz, $f['Preis gesamt'], TypedValue::ofDecimal($gesamt));
    }

    $data->put($satz, $f['Währung'], TypedValue::ofReference(EURO));

    if ($gekauft !== null) {
        $data->put($satz, $f['Gekauft am'], TypedValue::ofDate($gekauft));
    }

    if (($eingang = $datum($zelle(6))) !== null) {
        $data->put($satz, $f['Eingegangen am'], TypedValue::ofDate($eingang));
    }

    $wo = $zelle(8);

    if (str_contains($wo, ',')) {
        [$plattform, $haendler] = array_map('trim', explode(',', $wo, 2));
        $data->put($satz, $f['Plattform'], TypedValue::ofText($plattform));
        $data->put($satz, $f['Händler'], TypedValue::ofText($haendler));
    } elseif ($wo !== '') {
        $data->put($satz, $f['Händler'], TypedValue::ofText($wo));
    }

    if ($modell !== null) {
        $data->put($satz, $f['Modell'], TypedValue::ofRecordReference($modell));
    }
}

echo "\n{$neu} Käufe" . ($schreiben ? ' übernommen.' : ' — nur gezeigt. Mit --write übernehmen.') . "\n";
