<?php declare(strict_types=1);

/**
 * A bulk dialog that only fills the form (`ListPage::fillAction()`, his wish 2026-11-09 «platz für alle wählen», D-1253):
 * its button names the row field and the dialog field, says how many rows it set, and the bar opens it without counting
 * `selected[]`; the handler lives once in the shared script.
 *
 *     php scripts/dev/bulk-fill-check.php [/path/to/wordpress]
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php. Pass the WordPress folder as the first argument.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — siehe `lib/no-write.php`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

require_once ABSPATH . 'wp-admin/includes/template.php';

use Taxmod\WordPress\Admin\ListPage;

$failed = 0;
$check  = static function (string $claim, bool $holds) use (&$failed): void {
    echo ($holds ? 'ok   ' : 'FAIL ') . $claim . "\n";
    $failed += $holds ? 0 : 1;
};


$dialog = ListPage::fillAction('fill-x', 'Place for all', '<select name="fill_place"><option value="7">A</option></select>', 'place', 'fill_place', 'Apply');
$check('the button names the field of the rows and the field of the dialog', str_contains($dialog, 'data-taxmod-fill="place"') && str_contains($dialog, 'data-taxmod-fill-from="fill_place"'));
$check('it closes the dialog like «OK» and sends nothing', str_contains($dialog, 'for="fill-x"') && ! str_contains($dialog, 'type="submit"'));
$check('it says how many rows it set', str_contains($dialog, 'data-taxmod-fill-done="') && str_contains($dialog, '%d'));
$bar = ListPage::bulkBar('fill-form', 'fill-nonce', [], '2', '', false, ['fill-x' => ['Place for all …', $dialog]]);
$check('the bulk bar offers it as an asking action', str_contains($bar, 'value="ask:fill-x"') && str_contains($bar, 'data-taxmod-fill="place"'));
$check('a filling dialog opens without counting selected[]: the rows of another form count themselves', str_contains($bar, '[data-taxmod-fill][for=') && strpos($bar, '[data-taxmod-fill][for=') < strpos($bar, 'n===0'));
$js = (string) @file_get_contents(dirname(__DIR__, 2) . '/assets/admin.js');
$check('the shared script writes into every ticked row and reports it', str_contains($js, "closest( '[data-taxmod-fill]' )") && str_contains($js, 'taxmodFillDone') && str_contains($js, ':scope > .check-column input[type=checkbox]'));

echo $failed === 0 ? "bulk-fill-check: all claims hold\n" : "bulk-fill-check: {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
