<?php declare(strict_types=1);
/**
 * The full-screen frame and the taxmod user (D-1585 step 2): one switch per person, the frame only on taxmod pages, the
 * taxmod user always in it, sent to the start page of its role from every other wp-admin page, without toolbar, and
 * «only taxmod» never on an administrator.
 *
 *     php scripts/dev/taxmod-user-check.php [path/to/wordpress]
 *
 * @see docs/pakete/rechte/stufe-2.md
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: '';

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Pass the WordPress folder as the first argument or WP_ROOT.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
define('WP_ADMIN', true);
require $root . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use Taxmod\WordPress\Admin\FullScreen;
use Taxmod\WordPress\Admin\TaxmodUser;
use Taxmod\WordPress\Rights;

$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

Rights::ensureRoles();
$admin  = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
$reader = (int) wp_insert_user(['user_login' => '__tu_reader', 'user_pass' => wp_generate_password(), 'role' => 'taxmod_read_only']);
$app    = (int) wp_insert_user(['user_login' => '__tu_app', 'user_pass' => wp_generate_password(), 'role' => 'taxmod_read_only']);
check('test users stand', $reader > 0 && $app > 0);

echo "«Only taxmod»\n";
check('an administrator never gets it', ! TaxmodUser::setFor($admin, true) && ! user_can($admin, Rights::APP_ONLY));
check('a person gets it and loses it again', TaxmodUser::setFor($app, true) && user_can($app, Rights::APP_ONLY) && TaxmodUser::setFor($app, false) && ! user_can($app, Rights::APP_ONLY));
TaxmodUser::setFor($app, true);
wp_set_current_user($admin);
check('the tick stands on another person\'s profile, not on an administrator\'s', str_contains(TaxmodUser::field(new WP_User($app)), 'taxmod_app_only') && TaxmodUser::field(new WP_User($admin)) === '');

echo "\nThe switch\n";
wp_set_current_user($reader);
check('off by default', ! FullScreen::isOn());
update_user_meta($reader, 'taxmod_stock_fullscreen', '1');
check('the stock\'s former switch is the starting value', FullScreen::isOn());
FullScreen::set(false);
check('the own switch wins once set', ! FullScreen::isOn());
FullScreen::set(true);
check('and switches on', FullScreen::isOn());
check('the taxmod user is always in full screen', FullScreen::isOn($app));

echo "\nThe frame and the start page\n";
global $menu, $submenu, $pagenow;
wp_set_current_user($app);
$menu = $submenu = [];
remove_action('admin_menu', [TaxmodUser::class, 'guard'], PHP_INT_MAX);
do_action('admin_menu', '');
check('the pages under the taxmod menu are known', isset(FullScreen::pages()['taxmod-print-jobs']), implode(', ', array_keys(FullScreen::pages())));
$pagenow = 'admin.php';
$_GET['page'] = 'taxmod-print-jobs';
check('on a taxmod page the WordPress bars go', str_contains(FullScreen::style(), '#wpadminbar'));
$bar = FullScreen::bar();
check('the bar offers start, profile and logout, and no way out of full screen for a taxmod user', str_contains($bar, 'page=taxmod-print-jobs') && str_contains($bar, 'profile.php') && str_contains($bar, 'action=logout') && ! str_contains($bar, FullScreen::ACTION));
$_GET['page'] = 'not-taxmod';
check('elsewhere the frame stays away', FullScreen::style() === '');
unset($_GET['page']);

check('read only: the start page is the first taxmod page it may open', TaxmodUser::start() === add_query_arg('page', 'taxmod-print-jobs', admin_url('admin.php')), TaxmodUser::start());
add_filter(TaxmodUser::START_PAGES_FILTER, static fn (array $p): array => [...$p, ['right' => Rights::MODEL, 'page' => 'taxmod'], ['right' => Rights::VIEW, 'page' => 'taxmod-print-jobs', 'args' => ['tab' => 'x']]]);
check('a start page needs its right and a page the person may open', TaxmodUser::start() === add_query_arg(['page' => 'taxmod-print-jobs', 'tab' => 'x'], admin_url('admin.php')), TaxmodUser::start());

check('the taxmod user stays on a taxmod page, its profile and the page requests', TaxmodUser::destination('admin.php', 'taxmod-print-jobs') === null && TaxmodUser::destination('profile.php', '') === null && TaxmodUser::destination('admin-post.php', '') === null);
check('every other wp-admin page sends it to its start page', TaxmodUser::destination('index.php', '') !== null && TaxmodUser::destination('edit.php', '') !== null && TaxmodUser::destination('admin.php', 'not-taxmod') !== null);
check('no toolbar for the taxmod user', TaxmodUser::showAdminBar(true) === false);
check('after login the taxmod user goes to wp-admin, others where they were going', TaxmodUser::loginRedirect('x', '', new WP_User($app)) === admin_url('index.php') && TaxmodUser::loginRedirect('x', '', new WP_User($reader)) === 'x');

wp_set_current_user($reader);
check('others keep the toolbar', TaxmodUser::showAdminBar(true) === true);
$_GET['page'] = 'taxmod-print-jobs';
check('others get the switch in the bar', str_contains(FullScreen::bar(), FullScreen::ACTION));

echo $bad === 0 ? "\nAll green.\n" : "\n$bad failed.\n";
exit($bad === 0 ? 0 : 1);
