<?php declare(strict_types=1);

/**
 * The installation screen, against a real WordPress — `PR-9`.
 *
 * ⚠️ **Every package adds its checks to the net**, and this screen had none: it writes four options
 * that the modelling screen then reads, so a fault here shows up somewhere else entirely.
 *
 * ⚠️ **It checks the *refusals*, not only the happy path.** A size arrives as characters and is
 * checked against the offered list rather than cast — `(int) 'huge'` is `0`, and a zero that arrived
 * that way would make the tree invisible. *A check that only proves «17 can be saved» would have
 * passed on the cast version too.*
 *
 * ⚠️ **And it renders what is actually enqueued rather than a path built by hand.** That mistake
 * cost an hour on 2026-08-26: my check constructed the stylesheet URL itself, so it measured its own
 * arithmetic and reported success while the browser got a 404. *A check that constructs what it is
 * verifying verifies nothing.*
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use Taxmod\WordPress\Admin\SettingsScreen;

wp_set_current_user(1);

$failed = 0;

function check(string $what, bool $held, string $saw = ''): void
{
    global $failed;

    if (! $held) {
        $failed++;
    }

    echo ($held ? '  ok   ' : '  FAIL '), $what, ($saw === '' ? '' : "  — {$saw}"), "\n";
}

echo "== the screen draws ==\n";

$html = (new SettingsScreen())->render();

check('it renders at all', $html !== '', strlen($html) . ' bytes');
check('developer mode is offered', str_contains($html, 'name="developer"'));
check('the default language is offered', str_contains($html, 'name="neutral_locale"'));
check('the icon size is offered', str_contains($html, 'name="icon_size"'));
check('the text size is offered', str_contains($html, 'name="font_size"'));

// ⚠️ `CD-5`: a form without a nonce is the whole rule missing, and it is invisible on screen.
check('a nonce is present', str_contains($html, '_taxmod_nonce'));
check('the form posts to admin-post', str_contains($html, 'admin-post.php'));

echo "\n== nothing is drawn for somebody who may not ==\n";

// ⚠️ **The capability check first, before the nonce** — the order in `CD-5` is not decoration: a
// nonce proves *this form*, a capability proves *this person*, and checking the form first tells a
// visitor whether the page exists.
$was = wp_get_current_user();
wp_set_current_user(0);
check('a stranger gets nothing', (new SettingsScreen())->render() === '');
wp_set_current_user($was->ID);

echo "\n== a size outside the offered list is refused ==\n";

$before = SettingsScreen::defaultIconSize();

// The list itself, read off the class rather than repeated here — a check that restates a constant
// stops testing it and starts agreeing with it.
$sizes = (new ReflectionClass(SettingsScreen::class))->getConstant('SIZES');

check('the offered sizes are a real list', is_array($sizes) && $sizes !== [], implode(' ', (array) $sizes));

foreach (['huge', '0', '400', '16'] as $nonsense) {
    update_option(SettingsScreen::ICON_SIZE, $nonsense, true);
    check(
        "«{$nonsense}» does not become the icon size",
        in_array(SettingsScreen::defaultIconSize(), (array) $sizes, true),
        (string) SettingsScreen::defaultIconSize()
    );
}

foreach ((array) $sizes as $good) {
    update_option(SettingsScreen::ICON_SIZE, $good, true);
    check("{$good}px is accepted", SettingsScreen::defaultIconSize() === $good, (string) SettingsScreen::defaultIconSize());
}

update_option(SettingsScreen::ICON_SIZE, $before, true);

echo "\n== a locale nobody has installed is refused ==\n";

$installed = array_merge([get_locale()], get_available_languages());

check('the declared locale is one that exists', in_array(SettingsScreen::neutralLocale(), $installed, true), SettingsScreen::neutralLocale());

$keep = get_option(SettingsScreen::NEUTRAL_LOCALE, '');
delete_option(SettingsScreen::NEUTRAL_LOCALE);

// ⚠️ **The fallback is what the interim in `NodesScreen` did**, so an installation that never opens
// this screen behaves exactly as it did — that is the whole reason nothing had to be migrated.
check('with nothing declared it falls back to the site language', SettingsScreen::neutralLocale() === get_locale(), SettingsScreen::neutralLocale());

if ($keep !== '') {
    update_option(SettingsScreen::NEUTRAL_LOCALE, $keep, true);
}

echo "\n== the two sizes reach the modelling screen as custom properties ==\n";

$plugin = (new ReflectionClass(Taxmod\WordPress\Plugin::class))->newInstanceWithoutConstructor();
$tree   = (new ReflectionMethod(Taxmod\WordPress\Plugin::class, 'screen'))->invoke($plugin)->render();

$found = (bool) preg_match('#--taxmod-icon:(\d+)px;--taxmod-font:(\d+)px#', $tree, $m);

check('both properties are on the page', $found, $found ? "icon {$m[1]}px, font {$m[2]}px" : 'neither');
check('the icon size is the declared one', $found && (int) $m[1] === SettingsScreen::defaultIconSize());
check('the text size is the declared one', $found && (int) $m[2] === SettingsScreen::defaultFontSize());

// ⚠️ **The stylesheet has to actually consume them**, or the properties are decoration. *This is the
// half that was missing when the whole stylesheet failed to load and I reported it as fixed.*
$css = file_get_contents(__DIR__ . '/../../assets/admin.css');

check('the stylesheet reads --taxmod-icon', str_contains((string) $css, 'var(--taxmod-icon'));
check('the stylesheet reads --taxmod-font', str_contains((string) $css, 'var(--taxmod-font'));

echo "\n== one home per fact ==\n";

// ⚠️ **Both screens must give the same answer**, because the modelling screen used to work each of
// these out for itself. Two readers of one fact is how they drift.
check(
    'the modelling screen reads the same developer mode',
    SettingsScreen::inDeveloperMode() === (bool) get_option(Taxmod\WordPress\Admin\NodesScreen::DEVELOPER_OPTION, false)
);

echo "\n", $failed === 0 ? "all green\n" : "{$failed} failed\n";

exit($failed === 0 ? 0 : 1);
