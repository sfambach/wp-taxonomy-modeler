<?php declare(strict_types=1);

/**
 * RapidCAD als ein Satz je Takt statt zweier Chips (2026-09-19).
 *
 *     php scripts/dev/rapidcad-als-satz.php            # nur zeigen, was geschähe
 *     php scripts/dev/rapidcad-als-satz.php --write    # umbauen
 *
 * ⚠️ *Sein Wort: «behalten ein satz» (D-874). Intel verkaufte RapidCAD-1 (der Prozessor, 386-Sockel PGA-132) und RapidCAD-2 (ein
 * Hilfschip für den 387-Sockel PGA-68, der dem Board den Coprozessor vorspielt) nur zusammen. Der Satz «RapidCAD-25» trägt deshalb
 * beide Sockel und beide sSpec-Nummern; die Einzelsätze RapidCAD-2 werden entfernt (in den Schatten, nicht gelöscht).*
 *
 * Wiederholbar: umbenannt wird nur, was noch «RapidCAD-1-…» heisst; entfernt nur, was noch da ist.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

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

// Gemessen am 2026-09-19
const CPUS = 149000103845, BEZEICHNUNG = 149000103839, TEILENUMMER = 149000103844, SOCKEL = 149000108404;
const HERKUNFTSHINWEIS = 149000108188, PGA_68 = 149000108348;

$satzNamens = static fn (string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
     WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s LIMIT 1",
    CPUS,
    BEZEICHNUNG,
    $name
))) === null ? null : (int) $id;

$werte = static fn (int $satz, int $feld): array => $wpdb->get_col($wpdb->prepare(
    "SELECT COALESCE(value_text, value_ref) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d ORDER BY position",
    $satz,
    $feld
));

foreach (['25', '33'] as $takt) {
    $satz  = $satzNamens("RapidCAD-{$takt}") ?? $satzNamens("RapidCAD-1-{$takt}");
    $hilfe = $satzNamens("RapidCAD-2-{$takt}");

    if ($satz === null) {
        echo "RapidCAD-{$takt}: kein Satz gefunden\n";
        continue;
    }

    echo "RapidCAD-{$takt}: Satz {$satz}", $hilfe === null ? '' : ", Hilfschip {$hilfe} wird entfernt", "\n";

    if (! $schreiben) {
        continue;
    }

    $data->put($satz, BEZEICHNUNG, TypedValue::ofText("RapidCAD-{$takt}"));

    if (count($werte($satz, TEILENUMMER)) < 2) {
        $data->put($satz, TEILENUMMER, TypedValue::ofText('RapidCAD-1: Prozessor-Chip (486DX-Kern im 386-Gehäuse), sSpec SZ624'));
        $data->appendValue($satz, TEILENUMMER, TypedValue::ofText('RapidCAD-2: Hilfschip im 387-Sockel (erzeugt FERR), sSpec SZ625'));
    }

    if (! in_array((string) PGA_68, $werte($satz, SOCKEL), true)) {
        $data->appendValue($satz, SOCKEL, TypedValue::ofReference(PGA_68));
    }

    $hinweis = (string) ($werte($satz, HERKUNFTSHINWEIS)[0] ?? '');

    if (! str_starts_with($hinweis, 'Satz aus zwei Chips')) {
        $data->put($satz, HERKUNFTSHINWEIS, TypedValue::ofText(
            'Satz aus zwei Chips, nur zusammen verkauft: RapidCAD-1 im 386-Sockel (PGA-132) ist der Prozessor mit der FPU, RapidCAD-2 im '
            . '387-Sockel (PGA-68) ist programmierbare Logik, die das FERR-Signal erzeugt (de.wikipedia 275.000 Transistoren, en.wikipedia '
            . '«citation needed»). Die Werte hier gelten für RapidCAD-1. · ' . $hinweis
        ));
    }

    if ($hilfe !== null) {
        // ⚠️ *Seine Teile (Takt, Spannung …) gehen mit — `removeRecord` lässt sie sonst als Waisen unter «Primitives» liegen, und der
        // Wächter `simple-type` schlägt an. Gemessen am 2026-09-19: acht Teile.*
        $teile = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT value_ref FROM {$p}relation_records WHERE node_record_id = %d AND value_ref_kind = 'record' AND value_ref IS NOT NULL",
            $hilfe
        )));

        $data->removeRecord($hilfe);

        foreach ($teile as $teil) {
            $data->removeRecord($teil);
        }
    }
}
