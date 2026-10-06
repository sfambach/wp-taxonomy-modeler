<?php declare(strict_types=1);

/**
 * Ein geöffneter Satz nennt, wer auf ihn zeigt — und ein Teil erscheint als der Satz, der ihn hält.
 *
 *     php scripts/dev/verwiesen-von-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-902](../../docs/NewConcept/90-decision-log.md), sein Wort:** *«ok»* — auf den Vorschlag, am Exemplar den Kauf zu zeigen statt
 * eigene Kauffelder zu führen. *Gemessen am 2026-09-22: im ersten Bau verschwand das Exemplar vom Modell, weil jeder Satz, auf den
 * irgendwer zeigt, als Teil galt; Teil ist nur, was über eine Komposition gehalten wird.*
 *
 * *Gesucht wird die Gestalt, kein Name: (1) ein eigenständiger Satz, auf den ein anderer über eine Aggregation zeigt — der andere muss
 * als Link dastehen; (2) ein Satz, auf den ein Teil zeigt — dort muss der Halter des Teils stehen, nicht der Teil.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende alles zurück.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Plugin;

global $wpdb;
wp_set_current_user(1);

$p = $wpdb->prefix . 'taxmod_';

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

$geoeffnet = static function (int $satz) use ($plugin): string {
    $_GET     = ['page' => 'taxmod', 'taxmod_open_record' => (string) $satz];
    $_REQUEST = $_GET;

    return $plugin->screen()->render();
};

$link = static fn (int $satz): string => 'taxmod_open_record=' . $satz . '#taxmod-preview-edit';

// ⚠️ *Nur Halter, die selbst eigenständig sind — keiner zeigt über eine Komposition auf sie.*
$eigenstaendig = "NOT EXISTS (SELECT 1 FROM {$p}relation_records hh JOIN {$p}relations hk ON hk.id = hh.relation_id AND hk.kind = 'composition'
                   WHERE hh.value_ref = v.node_record_id AND hh.value_ref_kind = 'record')";

$fehler = 0;

$aggregation = $wpdb->get_row(
    "SELECT v.value_ref AS ziel, v.node_record_id AS von FROM {$p}relation_records v
       JOIN {$p}relations k ON k.id = v.relation_id AND k.kind = 'aggregation' AND k.hide = 0
       JOIN {$p}node_records r ON r.id = v.node_record_id AND r.record_type = 'user'
      WHERE v.value_ref_kind = 'record' AND {$eigenstaendig}
        -- *Der schwierige Fall zuerst: ein Verweisender, auf den selbst ein anderer über eine Aggregation zeigt (das Exemplar, das ein
        -- Kauf aufzählt). Genau ihn hat der erste Bau verschluckt.*
      ORDER BY EXISTS (SELECT 1 FROM {$p}relation_records ah JOIN {$p}relations ak ON ak.id = ah.relation_id AND ak.kind = 'aggregation'
                        WHERE ah.value_ref = v.node_record_id AND ah.value_ref_kind = 'record') DESC, v.id DESC LIMIT 1"
);

$teil = $wpdb->get_row(
    "SELECT v.value_ref AS ziel, h.node_record_id AS von FROM {$p}relation_records v
       JOIN {$p}relation_records h ON h.value_ref = v.node_record_id AND h.value_ref_kind = 'record'
       JOIN {$p}relations hk ON hk.id = h.relation_id AND hk.kind = 'composition'
       JOIN {$p}node_records r ON r.id = h.node_record_id AND r.record_type = 'user'
      WHERE v.value_ref_kind = 'record' ORDER BY v.id DESC LIMIT 1"
);

foreach (['über eine Aggregation' => $aggregation, 'über einen Teil' => $teil] as $wie => $fund) {
    if ($fund === null) {
        printf("  FAIL kein Satz, auf den %s gezeigt wird — der Wächter prüft nichts\n", $wie);
        ++$fehler;
        continue;
    }

    $markup = $geoeffnet((int) $fund->ziel);
    $start  = strpos($markup, 'taxmod-referrers');
    $zeile  = $start === false ? '' : substr($markup, $start, (int) strpos($markup, '</div>', $start) - $start);
    $gut    = str_contains($zeile, $link((int) $fund->von));

    printf("  %s Satz #%d, %s: «Verwiesen von» nennt #%d\n", $gut ? 'ok  ' : 'FAIL', $fund->ziel, $wie, $fund->von);
    $fehler += $gut ? 0 : 1;
}

exit($fehler === 0 ? 0 : 1);
