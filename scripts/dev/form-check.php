<?php declare(strict_types=1);
/**
 * A form a page draws itself has the layout of the modeller's form renderer: label column, field, «?» hint, buttons below.
 *
 *     php scripts/dev/form-check.php [path/to/wordpress]
 *
 * @see docs/gestaltung-und-architektur.md A8
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

$html = ListPage::form([
    ['label' => 'Menge <1>', 'control' => '<input id="q" name="q">', 'for' => 'q', 'hint' => 'Wie viele'],
    ['label' => 'Grund', 'control' => '<input name="g">'],
], '<button class="button button-primary">Buchen</button>');

check('the form is the renderer\'s form', str_starts_with($html, '<div class="taxmod-form">'));
check('each field is a form row with label and field', substr_count($html, 'class="taxmod-form-row"') === 2 && substr_count($html, 'class="taxmod-form-field"') === 2);
check('a label names its control', str_contains($html, '<label class="taxmod-form-label" for="q">'));
check('the label is escaped', str_contains($html, 'Menge &lt;1&gt;'));
check('the hint is the «?» of the renderer, not a line of text', substr_count($html, 'taxmod-hint') >= 1 && str_contains($html, 'Wie viele'));
// D-1308 (changed with the concept, PR-9): the buttons stand above the fields, as the toolbar — before, this checked «below».
check('the buttons stand above the fields, in the toolbar (D-1308)', strpos($html, 'class="taxmod-toolbar"') !== false && strpos($html, 'class="taxmod-toolbar"') < strpos($html, 'taxmod-form-row') && str_contains($html, 'Buchen</button>'));
$bar = ListPage::toolbar([['<button>💾</button>'], '', ['<button>A</button>', '<button>B</button>'], '<a>X</a>']);
check('the toolbar draws a group per entry, a divider between them, empty groups fall away', substr_count($bar, 'taxmod-toolbar-group') === 3 && substr_count($bar, 'taxmod-toolbar-sep') === 2, $bar);
check('no buttons, no toolbar', ListPage::toolbar(['', []]) === '');
$css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.css');
check('the stylesheet places the toolbar on the left', str_contains($css, '.taxmod-toolbar {') && str_contains($css, 'justify-content: flex-start') && ! str_contains($css, '.taxmod-form-actions'));

echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
exit($bad === 0 ? 0 : 1);
