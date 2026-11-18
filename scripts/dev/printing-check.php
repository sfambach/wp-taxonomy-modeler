<?php declare(strict_types=1);

/**
 * What the modeller keeps of printing, and that it draws no label itself any more.
 *
 *     php scripts/dev/printing-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-1296](../../docs/NewConcept/90-decision-log.md), [D-1439](../../docs/NewConcept/90-decision-log.md)**: labels, their
 * fixed formats, test prints, dialog and preview are the labels module's (wp_tax_label, with its own tests). The checks of the
 * label PDF link, the dialog, the preview switch and the preview renderer left with that code (PR-9, named in the commit);
 * the modeller keeps the «Printing» tab, the jobs, the door's words for a label (D-1295) and the finished-PDF way (D-1294).
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
require __DIR__ . '/lib/no-write.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Printing\PrintTab;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$pruefe(isset(SettingsScreen::moduleTabs()[PrintTab::SLUG]), 'the Configuration page has a «Printing» tab');
// D-1296: the label code is gone from the modeller — no renderer, no dialog, no ability of its own.
$pruefe(! class_exists('Taxmod\\Core\\Printing\\LabelDocument') && ! class_exists('Taxmod\\WordPress\\Printing\\LabelButton') && ! class_exists('Taxmod\\WordPress\\Printing\\LabelPrint'), 'D-1296: the modeller has no label renderer, dialog or PDF link of its own');
$pruefe(has_action('admin_post_taxmod_label_pdf') === false || defined('TAXMOD_LABEL_VERSION'), 'D-1296: the modeller answers no label PDF link');
$pruefe(! function_exists('wp_get_ability') || wp_get_ability('taxmod/print-label') === null, 'D-1296: «taxmod/print-label» is gone (it is the labels module\'s «taxmod-label/print»)');

// ⚠️ *D-1165: die Etiketten sind Sätze und stehen beim Modul, das sie führt; der Modeller meldet keine zweite Seite «Etiketten» mit festen Formaten an.*
$logistik = array_values(array_filter((array) apply_filters(PrintTab::LOGISTICS_FILTER, []), static fn ($p): bool => is_array($p) && ($p['area'] ?? '') === 'print'));
$pruefe(array_values(array_filter(array_column($logistik, 'slug'), static fn (string $slug): bool => ! str_starts_with($slug, 'taxmod-label-'))) === [\Taxmod\WordPress\Printing\PrintJobsScreen::PAGE], 'the modeller announces only «Aufträge» under Logistik › Druck, no page of fixed formats (D-1165)');
$_GET['tab'] = PrintTab::SLUG;
$konfiguration = (new SettingsScreen())->render();
$pruefe(! str_contains($konfiguration, 'test=1') && str_contains($konfiguration, 'Logistik › Druck › Etiketten'), 'the Configuration tab holds no formats, it points to Logistik › Druck › Etiketten (D-1117)');

// D-1160: the label reads the fields its layout names from the record itself, by name in any case or by id.
global $wpdb;
$probe = $wpdb->get_row("SELECT v.node_record_id AS record_id, v.relation_id, r.node_id FROM {$wpdb->prefix}taxmod_relation_records v JOIN {$wpdb->prefix}taxmod_node_records r ON r.id = v.node_record_id WHERE v.value_text IS NOT NULL AND v.value_text <> '' LIMIT 1", ARRAY_A);
$fieldName = '';
if (is_array($probe)) {
    $rc = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
    $ctor = $rc->getConstructor();
    $plugin = $rc->newInstanceWithoutConstructor();
    $ctor->setAccessible(true);
    $ctor->invoke($plugin, WP_PLUGIN_DIR . '/wp-taxonomy-modeler/wp-taxonomy-modeler.php');
    foreach ($plugin->screen()->fieldsWithTypes((int) $probe['node_id']) as ['relation' => $relation]) {
        $fieldName = $relation->id === (int) $probe['relation_id'] ? $relation->name : $fieldName;
    }
}
if ($fieldName !== '') {
    // D-1295: the labels module asks the same words through the door; by name in any case or by id, an unknown name said so.
    $read = (new \Taxmod\WordPress\ModelDoor())->labelValues((int) $probe['record_id'], [mb_strtoupper($fieldName), (string) $probe['relation_id'], 'gibt es nicht']);
    $pruefe(($read['values'][mb_strtoupper($fieldName)] ?? '') !== '' && $read['values'][mb_strtoupper($fieldName)] === ($read['values'][(string) $probe['relation_id']] ?? null), 'a label field is read from the record, by name in any case or by id');
    $pruefe($read['unknown'] === ['gibt es nicht'], 'a name that matches no field is reported, not guessed');
} else {
    $pruefe(false, 'the check found a record with a text value to read');
}

// D-1284: a module reads the node a field points to by the field's id.
global $wpdb;
$feld = $wpdb->get_row("SELECT id, to_node_id FROM " . \Taxmod\WordPress\Persistence\Schema::table('relations') . " WHERE to_node_id > 0 ORDER BY id LIMIT 1", ARRAY_A);
$pruefe(is_array($feld) && (new \Taxmod\WordPress\ModelDoor())->targetOf((int) $feld['id']) === (int) $feld['to_node_id'] && (new \Taxmod\WordPress\ModelDoor())->targetOf(PHP_INT_MAX) === 0, 'D-1284: ModelDoor::targetOf answers the target of a field, 0 for an unknown one');
// D-1291: a module asks the modeller's words for fields of records through the door, one answer per field and record.
$einSatz = (int) $wpdb->get_var("SELECT id FROM " . \Taxmod\WordPress\Persistence\Schema::table('node_records') . " ORDER BY id LIMIT 1");
$worte   = (new \Taxmod\WordPress\ModelDoor())->fieldWords([$einSatz], [(int) $feld['id']]);
$pruefe(is_array($worte) && (new \Taxmod\WordPress\ModelDoor())->fieldWords([], [1]) === [] && has_filter(\Taxmod\WordPress\Plugin::FIELD_WORDS_FILTER), 'D-1291: ModelDoor::fieldWords asks the one renderer, nothing for nothing');
// D-1285: the tree dialog's search asks the same record search as the quick field, and keeps a clicked group's records.
$js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.js');
$pruefe(preg_match("/function saetzeSuchen\\(.{0,400}data-record-search/s", $js) === 1 && str_contains($js, "saetzeSuchen( dialog, this )") && str_contains($js, "getAttribute( 'data-search-hits' ) === '1'"), 'D-1285: the tree dialog searches records through data-record-search and clears only its own hits');

// D-1295: the labels module reads a record's words for its fields through the door, as the print button does.
$vorher = get_current_user_id();
wp_set_current_user((int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1));
$gefunden = wp_get_ability('taxmod/query')->execute(['search' => 'e', 'limit' => 1]);
$wertSatz = (int) (is_array($gefunden) ? ($gefunden['records'][0]['id'] ?? 0) : 0);

// *Ohne einen Satz im Bestand ein eigener (Grenzlauf 2026-11-17, D-1427: auf einer frischen Datenbank prüfte die Zusage nichts und war rot).*
if ($wertSatz === 0) {
    require_once __DIR__ . '/lib/beispiel.php';

    $wertSatz = taxmodBeispiel([
        ['op' => 'create_node', 'ref' => 'k', 'name' => '__pr Etikettknoten', 'parent' => (int) get_option('taxmod_branch_model_id')],
        ['op' => 'create_record', 'ref' => 's', 'node' => '@k'],
    ])['s'] ?? 0;
}
$tuer     = (new \Taxmod\WordPress\ModelDoor())->labelValues($wertSatz, ['node', '__kein Feld__']);
wp_set_current_user($vorher);
$pruefe(($tuer['unknown'] ?? null) === ['__kein Feld__'] && isset($tuer['values']['node']) && $tuer['node'] > 0, 'D-1295: ModelDoor::labelValues answers the node name and names unknown fields ');
// D-1294: a finished job reaches the service as it was queued; something that is not a PDF is refused.
\Taxmod\WordPress\Printing\PrintJobSchema::upgrade();
$fertig = "%PDF-1.4\n%check D-1294\n";
$jobId  = (new \Taxmod\WordPress\ModelDoor())->queuePrint($fertig, 'x40x30', 'M-1', 'D-1294 check', 2, ['queue' => 'QL810W', 'options' => ['-o', 'media=62x30'], 'roll' => 62]);
$geholt = null;
foreach ((array) ((new \Taxmod\WordPress\Printing\PrintRest(new \Taxmod\WordPress\Printing\PrintJobs()))->pull(new \WP_REST_Request('POST', '/taxmod/v1/print/pull'))->get_data()['jobs'] ?? []) as $job) {
    if ((int) $job['id'] === $jobId) {
        $geholt = $job;
    }
}
$pruefe(is_array($geholt) && base64_decode((string) $geholt['pdf']) === $fertig && $geholt['queue'] === 'QL810W' && $geholt['roll'] === 62 && $geholt['copies'] === 2, 'D-1294: a finished job reaches the service unchanged, with its queue and roll');
try {
    (new \Taxmod\WordPress\ModelDoor())->queuePrint('not a pdf', 'x', 'M-1', 't');
    $pruefe(false, 'D-1294: something that is not a PDF is refused');
} catch (\InvalidArgumentException) {
    $pruefe(true, 'D-1294: something that is not a PDF is refused');
}
(new \Taxmod\WordPress\Printing\PrintJobs())->finish($jobId, true, 'check');
$wpdb->delete(\Taxmod\WordPress\Printing\PrintJobSchema::table(), ['id' => $jobId]);
// D-1296: an older job stored without a PDF is drawn by the labels module through «taxmod_print_render»; without an answer it
// fails with a message and nothing breaks.
$wpdb->insert(\Taxmod\WordPress\Printing\PrintJobSchema::table(), ['format' => 'roll62', 'code' => 'O-1', 'title' => 'alt', 'subtitle' => '', 'copies' => 1, 'is_test' => 1, 'status' => 'queued', 'created_by' => 1, 'created_at' => current_time('mysql', true)]);
$altId = (int) $wpdb->insert_id;
$ohneAntwort = static fn (): mixed => null;
remove_all_filters(\Taxmod\WordPress\Printing\PrintRest::RENDER_FILTER);
add_filter(\Taxmod\WordPress\Printing\PrintRest::RENDER_FILTER, $ohneAntwort, 99);
$geholt = array_filter((array) ((new \Taxmod\WordPress\Printing\PrintRest(new \Taxmod\WordPress\Printing\PrintJobs()))->pull(new \WP_REST_Request('POST', '/taxmod/v1/print/pull'))->get_data()['jobs'] ?? []), static fn (array $job): bool => (int) $job['id'] === $altId);
$alt    = $wpdb->get_row($wpdb->prepare('SELECT status, message FROM ' . \Taxmod\WordPress\Printing\PrintJobSchema::table() . ' WHERE id = %d', $altId), ARRAY_A);
$pruefe($geholt === [] && ($alt['status'] ?? '') === 'failed' && str_contains((string) ($alt['message'] ?? ''), 'labels module'), 'D-1296: a job without PDF and without the labels module fails with a message');
$wpdb->delete(\Taxmod\WordPress\Printing\PrintJobSchema::table(), ['id' => $altId]);

echo $fehler === 0 ? "printing: all good\n" : "printing: $fehler failed\n";
exit($fehler === 0 ? 0 : 1);
