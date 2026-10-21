<?php declare(strict_types=1);

/**
 * A module shows up as its own tab on the Configuration page.
 *
 *     php scripts/dev/config-tabs-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-945](../../docs/NewConcept/90-decision-log.md), his choice:** *«Tabs in Configuration»*. *A test module registers
 * through `taxmod_config_tabs`; an entry without a save callable is left out.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
require __DIR__ . '/lib/no-write.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Admin\SettingsScreen;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

add_filter(SettingsScreen::TABS_FILTER, static fn (array $tabs): array => $tabs + [
    'stock'   => ['title' => 'Stock', 'render' => static fn (): string => '<p id="__ct-stock">stock settings</p>', 'save' => static function (array $post): void {}, 'guide' => static fn (): string => '<ol id="__ct-guide"><li>get a key</li></ol>', 'version' => '9.8.7'],
    'kaputt'  => ['title' => 'Broken', 'render' => static fn (): string => ''],
    'general' => ['title' => 'Clash', 'render' => static fn (): string => '', 'save' => static function (array $post): void {}],
]);

$reiter = SettingsScreen::moduleTabs();
$pruefe(array_key_first(SettingsScreen::moduleTabs()) === 'import', 'the Import tab stands before the module tabs');
// ⚠️ *Seit dem Eingang der Import-Module meldet der Modeller selbst den Reiter «import» an (D-953); er steht vor dem Testmodul.*
$pruefe(array_keys($reiter) === ['import', 'stock'], 'only a complete tab with its own slug is taken');

$_GET['tab'] = 'stock';
$seite = (new SettingsScreen())->render();
$pruefe(str_contains($seite, 'nav-tab-active') && str_contains($seite, '__ct-stock') && str_contains($seite, 'name="tab" value="stock"'), 'the module tab renders its form with the tab marker');
// ⚠️ *D-963: der Kasten rechts, «wie man an die daten kommt und was man tun muss».*
// ⚠️ *D-968: «warum haben die andern module nicht die version im reiter ?»*
$pruefe(str_contains($seite, 'Stock, version 9.8.7'), 'a module version stands under the tab bar');
$pruefe(str_contains($seite, '<aside class="postbox taxmod-guide"') && str_contains($seite, '__ct-guide') && strpos($seite, '__ct-stock') < strpos($seite, '__ct-guide'), 'a module guide stands as a box beside the form');

unset($_GET['tab']);
$seite = (new SettingsScreen())->render();
$pruefe(str_contains($seite, 'nav-tab') && ! str_contains($seite, '__ct-stock') && str_contains($seite, 'neutral_locale'), 'General still shows the installation settings');

exit($fehler === 0 ? 0 : 1);
