<?php declare(strict_types=1);

/**
 * Die Kauffelder des Exemplars in je einen Kauf überführen und die Felder am Exemplar streichen (2026-09-22).
 *
 *     php scripts/dev/kauffelder-vom-exemplar.php            # nur zeigen
 *     php scripts/dev/kauffelder-vom-exemplar.php --write    # übernehmen
 *
 * ⚠️ *Zu [D-902](../../docs/NewConcept/90-decision-log.md) (INF-066): «Gekauft am», «Kaufpreis», «Währung» und «Händler» standen am
 * Exemplar und am Kauf. Ein Exemplar mit Kaufwerten bekommt einen Kauf, der es nennt; dann werden die vier Felder geparkt —
 * zurückholbar, ihre Werte bleiben liegen (removeField, D-371).*
 *
 * Wiederholbar: ein Exemplar, das schon ein Kauf nennt, bekommt keinen zweiten; ein geparktes Feld wird nicht noch einmal geparkt.
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
/** @var \Taxmod\Core\Service\ModelEditor $editor */
$editor = (new ReflectionProperty($screen, 'editor'))->getValue($screen);
/** @var \Taxmod\Core\Service\DataEntry $data */
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Gemessen am 2026-09-22
const EXEMPLARE = 149000108212, KAEUFE = 149000109585, BEZEICHNUNG = 149000103839, EX_MODELL = 149000108189;
const EX_GEKAUFT = 149000108194, EX_PREIS = 149000108195, EX_WAEHRUNG = 149000108196, EX_HAENDLER = 149000108197;
const K_MODELL = 149000109578, K_ANZAHL = 149000109581, K_STUECK = 149000109582, K_GESAMT = 149000109583, K_WAEHRUNG = 149000109584;
const K_GEKAUFT = 149000109585, K_HAENDLER = 149000109588, K_EXEMPLARE = 149000109589;

$sagen = static fn (string $satz) => print($satz . "\n");

$zeile = static fn (int $satz, int $feld): ?array => $wpdb->get_row($wpdb->prepare(
    "SELECT value_text, value_decimal, value_date, value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d LIMIT 1",
    $satz,
    $feld
), ARRAY_A);

$mitKauf = array_map(intval(...), $wpdb->get_col($wpdb->prepare(
    "SELECT DISTINCT node_record_id FROM {$p}relation_records WHERE relation_id IN (%d, %d, %d, %d)",
    EX_GEKAUFT,
    EX_PREIS,
    EX_WAEHRUNG,
    EX_HAENDLER
)));

foreach ($mitKauf as $exemplar) {
    $genannt = $wpdb->get_var($wpdb->prepare(
        "SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d AND value_ref = %d LIMIT 1",
        K_EXEMPLARE,
        $exemplar
    ));

    if ($genannt !== null) {
        $sagen("Exemplar #{$exemplar}: schon im Kauf #{$genannt}");
        continue;
    }

    $modell   = (int) ($zeile($exemplar, EX_MODELL)['value_ref'] ?? 0);
    $name     = (string) ($zeile($modell, BEZEICHNUNG)['value_text'] ?? ('Exemplar #' . $exemplar));
    $gekauft  = $zeile($exemplar, EX_GEKAUFT)['value_date'] ?? null;
    $preis    = $zeile($exemplar, EX_PREIS)['value_decimal'] ?? null;
    $waehrung = $zeile($exemplar, EX_WAEHRUNG)['value_ref'] ?? null;
    $haendler = $zeile($exemplar, EX_HAENDLER)['value_text'] ?? null;

    $sagen("Exemplar #{$exemplar} «{$name}»: Kauf " . ($gekauft ?? '—') . ', ' . ($preis ?? '—') . ($haendler === null ? '' : ", {$haendler}"));

    if (! $schreiben) {
        continue;
    }

    $kauf = $data->create(KAEUFE, RecordType::User)->id;
    $data->put($kauf, BEZEICHNUNG, TypedValue::ofText($name));
    $data->put($kauf, K_ANZAHL, TypedValue::ofInt(1));
    $modell > 0 && $data->put($kauf, K_MODELL, TypedValue::ofRecordReference($modell));
    $gekauft !== null && $data->put($kauf, K_GEKAUFT, TypedValue::ofDate((string) $gekauft));
    $waehrung !== null && $data->put($kauf, K_WAEHRUNG, TypedValue::ofReference((int) $waehrung));
    $haendler !== null && $data->put($kauf, K_HAENDLER, TypedValue::ofText((string) $haendler));

    if ($preis !== null) {
        $zahl = rtrim(rtrim((string) $preis, '0'), '.');
        $data->put($kauf, K_STUECK, TypedValue::ofDecimal($zahl));
        $data->put($kauf, K_GESAMT, TypedValue::ofDecimal($zahl));
    }

    $data->appendValue($kauf, K_EXEMPLARE, TypedValue::ofRecordReference($exemplar));
}

$geparkt = array_map(static fn ($k): int => $k->id, $editor->removedFieldsOf(EXEMPLARE));

foreach ([EX_GEKAUFT, EX_PREIS, EX_WAEHRUNG, EX_HAENDLER] as $feld) {
    if (in_array($feld, $geparkt, true)) {
        continue;
    }

    $sagen("Feld {$feld} am Exemplar parken");
    $schreiben && $editor->removeField(EXEMPLARE, $feld);
}

$sagen($schreiben ? 'übernommen.' : '— nur gezeigt. Mit --write übernehmen.');
