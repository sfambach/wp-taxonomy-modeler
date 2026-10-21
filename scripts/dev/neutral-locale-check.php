<?php declare(strict_types=1);

/**
 * Saving the General tab never moves the default language, and the one-time repair puts it back on its texts.
 *
 *     php scripts/dev/neutral-locale-check.php [path/to/wordpress]
 *
 * ⚠️ *On 2026-10-21 every node and field name on fambach.net went blank: the select offered only the site's language, a save
 * stored `de_DE`, and the texts stand under `en_US` — the language the owner declared (D-387).*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
require __DIR__ . '/lib/no-write.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\Schema;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

update_option(SettingsScreen::NEUTRAL_LOCALE, 'en_US', true);
$seite = (new SettingsScreen())->render();
$pruefe((bool) preg_match('/<option value="en_US"[^>]*selected/', $seite), 'General offers en_US and keeps it selected');

global $wpdb;
$texts  = Schema::table('label_texts');
$enUs   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$texts} WHERE locale = %s", 'en_US'));
$andere = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$texts} WHERE locale = %s", 'xx_XX'));

update_option(SettingsScreen::NEUTRAL_LOCALE, 'xx_XX', true);
delete_option(SettingsScreen::NEUTRAL_LOCALE_REPAIR);
SettingsScreen::repairNeutralLocale();
$pruefe(get_option(SettingsScreen::NEUTRAL_LOCALE) === ($enUs > $andere ? 'en_US' : 'xx_XX'), 'the repair returns to en_US only where the texts are');

update_option(SettingsScreen::NEUTRAL_LOCALE, 'xx_XX', true);
SettingsScreen::repairNeutralLocale();
$pruefe(get_option(SettingsScreen::NEUTRAL_LOCALE) === 'xx_XX', 'the repair runs once; a later choice stays');

exit($fehler === 0 ? 0 : 1);
