<?php declare(strict_types=1);
/**
 * A record shown in a dialog (D-1309): one building block, the frame loads on opening, the page can be opened fully.
 *
 *     php scripts/dev/view-dialog-check.php [path/to/wordpress]
 *
 * @see docs/gestaltung-und-architektur.md S14
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: '';

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Pass the WordPress folder as the first argument or WP_ROOT.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\WordPress\Admin\ListPage;

$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

$url  = 'https://example.org/wp-admin/admin.php?page=x&record=57143';
$html = ListPage::viewDialog('kauf-57143', '#57143', 'Kauf <57143>', $url);

check('the opener is the text he clicks', str_contains($html, '#57143') && str_contains($html, 'taxmod-view-dialog-open'));
check('the frame waits for the dialog: data-src, no src', str_contains($html, 'data-src="' . esc_url($url) . '"') && ! preg_match('/<iframe[^>]* src=/', $html));
check('the title is escaped', str_contains($html, 'Kauf &lt;57143&gt;'));
check('the page can be opened fully in a new tab', str_contains($html, 'target="_blank"') && str_contains($html, 'href="' . esc_url($url) . '"'));
check('the loader is queued', wp_script_is('taxmod-view-dialog', 'enqueued'));
wp_dequeue_script('taxmod-view-dialog');
do_action(\Taxmod\WordPress\Plugin::STYLE_ACTION);
check('a module page queues the loader with its style, for rows that arrive by ajax', wp_script_is('taxmod-view-dialog', 'enqueued'));
$js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/view-dialog.js');
check('the loader sets src from data-src when the dialog opens', str_contains($js, 'taxmod-dialog-switch') && str_contains($js, "setAttribute( 'src'"));
$icon = ListPage::viewDialog('kauf-1', 'Kauf', 'Kauf', $url, 'visibility');
check('with an icon the opener is an icon button', str_contains($icon, 'taxmod-icon-button') && str_contains($icon, 'dashicons-visibility'), $icon);

// D-1311: dependent fields.
$part = ListPage::shownBy('project', '42', '<input type="checkbox" name="board[]" value="7">');
check('a dependent part names its field and value', str_contains($part, 'data-taxmod-shown-by="project"') && str_contains($part, 'data-taxmod-shown-for="42"') && str_contains($part, 'name="board[]"'));
check('its loader is queued', wp_script_is('taxmod-shown-by', 'enqueued'));
$shown = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/shown-by.js');
check('hidden parts disable their fields, so they are not sent', str_contains($shown, 'field.disabled = ! shown'));

$steps = ListPage::steps([['a', 'Angelegt', 2], ['b', 'In Arbeit', 1], [['done', 'Fertiggestellt', 4], ['cancelled', 'Abgebrochen', 0]]], 'cancelled', static fn (string $s): string => '?s=' . $s);
check('a process may end in two ways, stacked in one place (D-1346)', substr_count($steps, 'taxmod-steps-ends') === 1 && substr_count($steps, '<a class="taxmod-step') === 4, $steps);
check('the open end is marked', str_contains($steps, 'is-active is-empty" href="?s=cancelled"'), $steps);
check('stacked ends have their style', str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.css'), '.taxmod-steps-ends'));

echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
exit($bad === 0 ? 0 : 1);
