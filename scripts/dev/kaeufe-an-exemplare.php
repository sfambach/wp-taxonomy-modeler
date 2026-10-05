<?php declare(strict_types=1);

/**
 * Seine Käufe an ihre Exemplare binden, wo die Zuordnung sicher ist (2026-09-22).
 *
 *     php scripts/dev/kaeufe-an-exemplare.php            # nur zeigen
 *     php scripts/dev/kaeufe-an-exemplare.php --write    # übernehmen
 *
 * ⚠️ *Zu [D-901](../../docs/NewConcept/90-decision-log.md): ein Kauf nennt die Exemplare daraus (D-897). Sicher ist die Zuordnung, wo der
 * Kauf schon ein Modell trägt und es davon genau ein Exemplar gibt, oder wo der Name des Kaufs das Modell eindeutig nennt. Fehlt zum
 * Modell eines Kaufs das Exemplar, entsteht es — gekauft heisst: er hat eins. Alles andere bleibt offen und steht im Bericht.*
 *
 * Nebenbei: seine Bestätigung zur ET3000-Karte (512 KB) in ihren Herkunftshinweis.
 *
 * Wiederholbar: ein Exemplar, das der Kauf schon nennt, wird nicht noch einmal gebunden.
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

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
/** @var \Taxmod\Core\Service\DataEntry $data */
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Gemessen am 2026-09-22
const KAEUFE = 149000109585, EXEMPLARE = 149000108212, BEZEICHNUNG = 149000103839;
const K_MODELL = 149000109578, K_EXEMPLARE = 149000109589, EX_MODELL = 149000108189, EX_HINWEIS = 149000108198;
const HERKUNFTSHINWEIS = 149000108188;

// *Käufe, deren Name das Modell eindeutig nennt, ohne dass der Kauf es schon trägt: Kauf-Bezeichnung ⇒ Modell-Bezeichnung.*
const NACH_NAMEN = ['GRAFIKKARTE S3 Virge DX 4MB' => 'S3 ViRGE/DX 4MB PCI'];

$sagen = static fn (string $satz) => print($satz . "\n");

$wert = static fn (int $satz, int $feld, string $spalte): ?string => $wpdb->get_var($wpdb->prepare(
    "SELECT {$spalte} FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d ORDER BY position LIMIT 1",
    $satz,
    $feld
));

$kaeufe = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}node_records WHERE node_id = %d AND record_type = 'user' ORDER BY id", KAEUFE));
$zahl   = ['gebunden' => 0, 'neu' => 0];

foreach (array_map(intval(...), $kaeufe) as $kauf) {
    $name   = (string) $wert($kauf, BEZEICHNUNG, 'value_text');
    $modell = $wert($kauf, K_MODELL, 'value_ref');
    $modell = $modell === null ? null : (int) $modell;

    if ($modell === null && isset(NACH_NAMEN[$name])) {
        $modell = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d AND value_text = %s LIMIT 1",
            BEZEICHNUNG,
            NACH_NAMEN[$name]
        )) ?: null;

        if ($modell !== null) {
            $sagen("Kauf #{$kauf} «{$name}»: Modell #{$modell} aus dem Namen");
            $schreiben && $data->put($kauf, K_MODELL, TypedValue::ofRecordReference($modell));
        }
    }

    if ($modell === null) {
        continue;
    }

    $genannt = array_map(intval(...), $wpdb->get_col($wpdb->prepare(
        "SELECT value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d",
        $kauf,
        K_EXEMPLARE
    )));

    if ($genannt !== []) {
        continue;
    }

    $exemplare = array_map(intval(...), $wpdb->get_col($wpdb->prepare(
        "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
         WHERE r.node_id = %d AND v.relation_id = %d AND v.value_ref = %d",
        EXEMPLARE,
        EX_MODELL,
        $modell
    )));

    if (count($exemplare) > 1) {
        $sagen("⚠ Kauf #{$kauf} «{$name}»: " . count($exemplare) . ' Exemplare zum Modell — offen gelassen');
        continue;
    }

    $exemplar = $exemplare[0] ?? null;

    if ($exemplar === null) {
        ++$zahl['neu'];
        $sagen("Kauf #{$kauf} «{$name}»: neues Exemplar");

        if ($schreiben) {
            $exemplar = $data->create(EXEMPLARE, RecordType::User)->id;
            $data->put($exemplar, EX_MODELL, TypedValue::ofRecordReference($modell));
            $data->put($exemplar, EX_HINWEIS, TypedValue::ofText("Sein Stück, angelegt aus dem Kauf «{$name}» (D-901)."));
        }
    }

    ++$zahl['gebunden'];
    $sagen("Kauf #{$kauf} «{$name}» → Exemplar #" . ($exemplar ?? 'neu'));

    if ($schreiben && $exemplar !== null) {
        $data->appendValue($kauf, K_EXEMPLARE, TypedValue::ofRecordReference($exemplar));
    }
}

// ── Seine Bestätigung zur ET3000-Karte ──────────────────────────────────────────────────────────────
$et3000 = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d AND value_text = %s LIMIT 1",
    BEZEICHNUNG,
    'ISA-SVGA mit Tseng Labs ET3000AX'
));
$hinweis = $et3000 === 0 ? '' : (string) $wert($et3000, HERKUNFTSHINWEIS, 'value_text');
$offen   = '1 MB in seiner Tabelle widerspricht dem Chip — bitte Chip auf der Karte nachlesen (evtl. doch ET4000); ';

if (str_contains($hinweis, $offen)) {
    $sagen('ET3000: 512 KB von ihm bestätigt');
    $schreiben && $data->put($et3000, HERKUNFTSHINWEIS, TypedValue::ofText(
        str_replace($offen, '', $hinweis) . ' · 512 KB von ihm bestätigt (2026-09-22, D-901).'
    ));
}

$sagen("\n{$zahl['gebunden']} Käufe gebunden, {$zahl['neu']} Exemplare neu" . ($schreiben ? '.' : ' — nur gezeigt. Mit --write übernehmen.'));
