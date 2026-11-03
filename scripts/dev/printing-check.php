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

// ⚠️ *D-1103: dieselbe Seite auch in der Logistik, seit D-1115 unter Logistik › Druck › Etiketten.*
$logistik = array_values(array_filter((array) apply_filters(PrintTab::LOGISTICS_FILTER, []), static fn ($p): bool => is_array($p) && ($p['slug'] ?? '') === PrintTab::LOGISTICS_SLUG));
$pruefe(count($logistik) === 1 && $logistik[0]['area'] === 'print', 'the printing page is announced under Logistik › Druck (D-1115)');
$pruefe(count($logistik) === 1 && str_contains((string) ($logistik[0]['render'])(), 'test=1'), 'it shows the same formats with their test prints');

$_GET['tab'] = PrintTab::SLUG;
$seite = (new SettingsScreen())->render();
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

echo $fehler === 0 ? "printing: all good\n" : "printing: $fehler failed\n";
exit($fehler === 0 ? 0 : 1);
