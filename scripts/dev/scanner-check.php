<?php declare(strict_types=1);

/**
 * The scanner's test page: a tab of the Configuration and a page under Logistik › Einstellungen, with one focused field; a
 * scanned code shows its record.
 *
 *     php scripts/dev/scanner-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-1206](../../docs/NewConcept/90-decision-log.md)**, his word: *«in den einstellungen einen punkt scanner und dort test
 * seite mit feld für den scan»*. *The reading of the keystrokes is guarded in the core run (`ScannedCodeTest`).*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
require __DIR__ . '/lib/no-write.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Printing\ScannerTab;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$reiter = SettingsScreen::moduleTabs();
$pruefe(isset($reiter[ScannerTab::SLUG]) && $reiter[ScannerTab::SLUG]['page'] !== null, 'the Configuration page has a «Scanner» tab that is a page of its own');

$_GET['tab'] = ScannerTab::SLUG;
unset($_GET['code']);
$leer = (new SettingsScreen())->render();
$pruefe(str_contains($leer, 'nav-tab-active') && str_contains($leer, 'id="taxmod-scan-code"') && str_contains($leer, 'autofocus'), 'the tab shows one focused field');
$pruefe(str_contains($leer, 'method="get"') && ! str_contains($leer, 'name="action" value="' . SettingsScreen::ACTION . '"'), 'it is a lookup, not the settings form with its «Save»');
$pruefe(! str_contains($leer, 'Last scan'), 'without a scan there is no result');

preg_match('#name="_taxmod_scan_nonce" value="([^"]+)"#', $leer, $m);
$_GET['_taxmod_scan_nonce'] = $m[1] ?? '';

$_GET['code'] = 'X-12';
$falsch = (new SettingsScreen())->render();
$pruefe(str_contains($falsch, 'Last scan') && str_contains($falsch, 'not a Taxmod code') && str_contains($falsch, '«X-12»'), 'an unknown code is said so, with what arrived');

$_GET['code'] = 'M-999999999';
$pruefe(str_contains((new SettingsScreen())->render(), 'there is no record 999999999'), 'a code without its record is said so');

global $wpdb;
$satz = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}taxmod_node_records ORDER BY id LIMIT 1");
$pruefe($satz > 0, 'there is a record to scan');

$links = static fn (array $links, string $kind, int $record): array => $links + ($kind === 'M' ? ['__sc open in stock' => 'https://example.org/stock?thing=' . $record] : []);
add_filter(ScannerTab::LINKS_FILTER, $links, 10, 3);
$_GET['code'] = 'Mß' . $satz;
$gefunden = (new SettingsScreen())->render();
$pruefe(str_contains($gefunden, 'M-' . $satz) && str_contains($gefunden, 'Material') && str_contains($gefunden, 'taxmod_open_record=' . $satz), 'a material code from a German keyboard opens its record, with the modeller link');
$pruefe(str_contains($gefunden, '__sc open in stock') && str_contains($gefunden, 'stock?thing=' . $satz), 'a module adds its own link for the kind of code');
$pruefe(strpos($gefunden, 'Last scan') > strpos($gefunden, 'id="taxmod-scan-code"'), 'the field stays on top, ready for the next scan');
remove_filter(ScannerTab::LINKS_FILTER, $links, 10);

$_GET['code'] = (string) $satz;
$pruefe(str_contains((new SettingsScreen())->render(), 'Record id'), 'a bare id is taken as a record id');

$_GET['_taxmod_scan_nonce'] = 'x';
$pruefe(str_contains((new SettingsScreen())->render(), 'The link has expired') , 'without its nonce nothing is looked up');

unset($_GET['tab'], $_GET['code'], $_GET['_taxmod_scan_nonce']);

// D-1211, his word: «solle in einstellung zweig des logistik menüs sein».
$seite = null;
foreach ((array) apply_filters(\Taxmod\WordPress\Printing\PrintTab::LOGISTICS_FILTER, []) as $eintrag) {
    $seite = ($eintrag['slug'] ?? '') === ScannerTab::LOGISTICS_SLUG ? $eintrag : $seite;
}
$pruefe(is_array($seite) && ($seite['area'] ?? '') === 'settings' && is_callable($seite['render'] ?? null), 'the scanner is announced as a page under Logistik › Einstellungen');
$_GET['page']   = 'taxmod-lager-modul';
$_GET['module'] = ScannerTab::LOGISTICS_SLUG;
$logistik = is_array($seite) ? (string) ($seite['render'])() : '';
$pruefe(str_contains($logistik, 'id="taxmod-scan-code"') && str_contains($logistik, 'name="module" value="' . ScannerTab::LOGISTICS_SLUG . '"') && ! str_contains($logistik, 'value="taxmod-settings"'), 'there the field scans back into the same page');
unset($_GET['page'], $_GET['module']);

exit($fehler === 0 ? 0 : 1);
