<?php declare(strict_types=1);

/**
 * Labels reach the browser as PDF, and every format has its test print.
 *
 *     php scripts/dev/printing-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-1032](../../docs/NewConcept/90-decision-log.md)** labels with QR code and prefixes O/M/E/K; **[D-1096](../../docs/NewConcept/90-decision-log.md)**
 * *«jeder ausdruck muss ein testbutton haben»*. *Stage 1 prints through the browser; nothing is stored.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
require __DIR__ . '/lib/no-write.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Printing\LabelContent;
use Taxmod\Core\Printing\LabelFormat;
use Taxmod\Core\Printing\LabelPrefix;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Printing\LabelPrint;
use Taxmod\WordPress\Printing\PrintTab;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$pruefe(isset(SettingsScreen::moduleTabs()[PrintTab::SLUG]), 'the Configuration page has a «Printing» tab');
$pruefe(has_action('admin_post_' . LabelPrint::ACTION) !== false, 'the label PDF has its admin-post action');

// ⚠️ *D-1103/D-1115: die Etiketten stehen unter Logistik › Druck › Etiketten; seit D-1117 nur noch dort, die Configuration hält den Zugang.*
$logistik = array_values(array_filter((array) apply_filters(PrintTab::LOGISTICS_FILTER, []), static fn ($p): bool => is_array($p) && ($p['slug'] ?? '') === PrintTab::LOGISTICS_SLUG));
$pruefe(count($logistik) === 1 && $logistik[0]['area'] === 'print', 'the printing page is announced under Logistik › Druck (D-1115)');
$seite = count($logistik) === 1 ? (string) ($logistik[0]['render'])() : '';
$_GET['tab'] = PrintTab::SLUG;
$konfiguration = (new SettingsScreen())->render();
$pruefe(! str_contains($konfiguration, 'test=1') && str_contains($konfiguration, 'Logistik › Druck › Etiketten'), 'the Configuration tab holds no formats, it points to Logistik › Druck › Etiketten (D-1117)');
foreach (LabelFormat::shipped() as $format) {
    $pruefe(str_contains($seite, 'format=' . $format->key) && str_contains($seite, 'test=1'), sprintf('format %s has a test print button', $format->key));
}
$pruefe(substr_count($seite, '_taxmod_nonce=') >= count(LabelFormat::shipped()), 'every test print link carries a nonce');

$url = LabelPrint::url('roll62', LabelContent::of(LabelPrefix::Place, 77, 'Fach 3 · Kleinteile', 'Keller'));
parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
$pruefe(wp_verify_nonce((string) $query['_taxmod_nonce'], LabelPrint::ACTION) !== false, 'a module link carries a valid nonce');
$pruefe(($query['code'] ?? '') === 'O-77' && ($query['title'] ?? '') === 'Fach 3 · Kleinteile', 'a module link carries code and title unchanged');

$druck = new LabelPrint();
$pdf = $druck->pdfFor($query);
$pruefe(is_string($pdf) && str_starts_with($pdf, '%PDF-') && str_contains($pdf, '(O-77) Tj'), 'a label request gives a PDF with its code');
$test = $druck->pdfFor(['format' => 'roll102', 'test' => '1']);
$pruefe(is_string($test) && str_contains($test, '(' . LabelContent::sample()->code . ') Tj'), 'the test print uses the sample label');
$pruefe($druck->pdfFor(['format' => 'a4', 'test' => '1']) instanceof WP_Error, 'an unknown format is refused');
$pruefe($druck->pdfFor(['format' => 'roll62', 'code' => 'X-1', 'title' => 'x']) instanceof WP_Error, 'a code without a fixed prefix is refused');
$pruefe($druck->pdfFor(['format' => 'roll62', 'code' => 'O-1', 'title' => '']) instanceof WP_Error, 'a label without a title is refused');

// D-1152: the label dialog asks how many, starting with what the module says, held to 1 … 50.
$knopf = \Taxmod\WordPress\Printing\LabelButton::render('K', 77, 'Kiste', '', 2);
$pruefe(str_contains($knopf, 'name="copies"') && str_contains($knopf, 'value="2"'), 'the label dialog offers «Anzahl», preset by the module');
$pruefe(\Taxmod\WordPress\Printing\LabelButton::copies(0) === 1 && \Taxmod\WordPress\Printing\LabelButton::copies(500) === 50, 'the number of copies stays between 1 and 50');

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
    $read = \Taxmod\WordPress\Printing\LabelButton::recordValues((int) $probe['record_id'], [mb_strtoupper($fieldName), (string) $probe['relation_id'], 'gibt es nicht']);
    $pruefe(($read['values'][mb_strtoupper($fieldName)] ?? '') !== '' && $read['values'][mb_strtoupper($fieldName)] === ($read['values'][(string) $probe['relation_id']] ?? null), 'a label field is read from the record, by name in any case or by id');
    $pruefe($read['unknown'] === ['gibt es nicht'], 'a name that matches no field is reported, not guessed');
    $template = ['name' => 'Test', 'format' => ['key' => 'material62', 'w' => 62, 'h' => 30], 'record' => (int) $probe['record_id'],
        'layout' => json_encode([['type' => 'text', 'field' => $fieldName, 'x' => 2, 'y' => 2, 'w' => 58, 'h' => 10]])];
    $filled = \Taxmod\WordPress\Printing\LabelButton::filled(LabelContent::of(LabelPrefix::Material, (int) $probe['record_id'], 'x'), $template);
    $pruefe($filled !== null && ($filled->values[$fieldName] ?? '') === $read['values'][mb_strtoupper($fieldName)], 'a label given a record prints that record\'s field');
} else {
    $pruefe(false, 'the check found a record with a text value to read');
}

// D-1161: a label handed to the preview renderer comes back as a PDF frame, with the examples or a record's words.
$vorschau = (string) apply_filters('taxmod_label_preview', '', ['name' => 'Test', 'format' => ['key' => 'material62', 'w' => 62, 'h' => 30],
    'layout' => '[{"type":"qr","field":"code","x":2,"y":2,"w":20,"example":"O-5"},{"type":"text","field":"title","x":24,"y":2,"w":36,"h":10,"example":"Beispiel"},{"type":"text","field":"leer","x":24,"y":14,"w":36,"h":6}]']);
$pruefe(str_contains($vorschau, 'data:application/pdf;base64,') && str_contains(base64_decode((string) preg_replace('/^.*base64,([^#"]+).*$/s', '$1', $vorschau)), '(Beispiel) Tj'), 'the preview renderer draws the layout with its examples');
$pruefe(str_contains($vorschau, 'leer'), 'the preview names a field that stays empty');
$pruefe(str_contains((string) apply_filters('taxmod_label_preview', '', ['layout' => 'kaputt']), 'notice-error'), 'a broken layout says why instead of drawing');
if ($fieldName !== '') {
    $mitSatz = (string) apply_filters('taxmod_label_preview', '', ['format' => ['key' => 'material62', 'w' => 62, 'h' => 30], 'layout' => json_encode([['type' => 'text', 'field' => $fieldName, 'x' => 2, 'y' => 2, 'w' => 58, 'h' => 10], ['type' => 'text', 'field' => 'gibt es nicht', 'x' => 2, 'y' => 14, 'w' => 58, 'h' => 6]])], (int) $probe['record_id']);
    $pruefe(str_contains($mitSatz, 'notice-warning') && str_contains($mitSatz, 'gibt es nicht'), 'with a record the preview warns about a field the record does not have');
}

echo $fehler === 0 ? "printing: all good\n" : "printing: $fehler failed\n";
exit($fehler === 0 ? 0 : 1);
