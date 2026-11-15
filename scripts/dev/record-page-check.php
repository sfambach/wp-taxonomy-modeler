<?php declare(strict_types=1);

/**
 * D-1376 as a failing check: the single record with a status (`ListPage::record()`) — title, status arrows without links,
 * the toolbar with the back button always left and the forward button last, the master data, then the sections.
 *
 *     php scripts/dev/record-page-check.php [/path/to/wordpress]
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

use Taxmod\WordPress\Admin\ListPage;

$failed = 0;
$check  = static function (string $claim, bool $holds) use (&$failed): void {
    echo ($holds ? 'ok   ' : 'FAIL ') . $claim . "\n";
    $failed += $holds ? 0 : 1;
};

$steps   = [['created', 'Angelegt'], ['staged', 'Bereitstellung'], ['making', 'In Fertigung'], [['done', 'Fertig'], ['cancelled', 'Abgebrochen']]];
$back    = ListPage::stepButton('Liste', false, 'https://example.test/?list=1');
$forward = ListPage::stepButton('In Bereitstellung', true, '', 'do', 'stage', 'f1');
$page    = ListPage::record('FA-12', $steps, 'created', $back, $forward, ['<button class="button">Abbrechen</button>'], '<div class="taxmod-form">DATA</div>', [
    ['title' => 'Pickliste', 'html' => 'PICK'],
    ['title' => 'Früher', 'html' => 'OLD', 'open' => false],
]);

$check('the status arrows are a display: no link in them', preg_match('#<nav class="taxmod-steps is-status".*?</nav>#s', $page, $nav) === 1 && ! str_contains($nav[0], 'href='));
$check('the current status is marked', str_contains($nav[0] ?? '', 'taxmod-step is-active" aria-current="step"'));
$check('the arrow stands inside the button: «← Liste» and «In Bereitstellung →»', str_contains($page, '← Liste') && str_contains($page, 'In Bereitstellung →'));
$check('back comes first, the side action between, forward last', strpos($page, '← Liste') < strpos($page, 'Abbrechen') && strpos($page, 'Abbrechen') < strpos($page, 'In Bereitstellung →'));
$check('forward is the main button and sends its form', str_contains($page, 'class="button button-primary" name="do" value="stage" form="f1"'));
$check('the order is fixed: title, status, toolbar, master data, sections', strpos($page, 'FA-12') < strpos($page, 'taxmod-steps') && strpos($page, 'taxmod-steps') < strpos($page, 'taxmod-toolbar') && strpos($page, 'taxmod-toolbar') < strpos($page, 'DATA') && strpos($page, 'DATA') < strpos($page, 'PICK'));
$check('the current section is open, an earlier one folded', str_contains($page, '<h3>Pickliste</h3>PICK') && str_contains($page, '<details class="taxmod-record-section"><summary>Früher</summary>OLD</details>'));
$check('without a forward step nothing is drawn on the right', ! str_contains(ListPage::record('X', $steps, 'done', $back, '', [], ''), 'taxmod-record-forward'));
$check('the process arrows with links are unchanged', str_contains(ListPage::steps($steps, 'created', static fn (string $s): string => '?t=' . $s), 'href="?t=created"'));

$css = (string) @file_get_contents(dirname(__DIR__, 2) . '/assets/admin.css');
$check('the forward button is pushed right in the stylesheet', str_contains($css, ':has(> .taxmod-record-forward)'));
$check('the status arrows share the full width evenly, with room to the edge (D-1383)', (bool) preg_match('/\.taxmod-steps\.is-status li \{[^}]*flex: 1 1 0/', $css) && (bool) preg_match('/\.taxmod-steps\.is-status \{[^}]*margin: 12px 8px/', $css));

echo $failed === 0 ? "record-page-check: all claims hold\n" : "record-page-check: {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
