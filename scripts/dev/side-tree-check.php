<?php declare(strict_types=1);

/**
 * The tree in the left column (`ListPage::sideTree()`, his wish 2026-11-09, architecture thread: one building block for every
 * side tree, D-1186), as a failing check: it draws «Expand all» and «Collapse all» and carries the open branches kept for
 * the user; the branch last chosen comes back when the request names none; the script is registered and answers both.
 *
 *     php scripts/dev/side-tree-check.php [/path/to/wordpress]
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

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));
ListPage::resetTreeStates();

$tree = '__sidetreecheck';
$check('a tree never left opens at nothing', ListPage::treeChosen($tree, 0) === 0);
$check('a branch the request names is the one shown', ListPage::treeChosen($tree, 42) === 42);
$check('his word «hier fange ich immer neu an»: without one, the branch he left comes back', ListPage::treeChosen($tree, 0) === 42);
$check('another branch named replaces it', ListPage::treeChosen($tree, 7) === 7 && ListPage::treeChosen($tree, 0) === 7);

$html = ListPage::sideTree($tree, 'Places', '<div class="taxmod-chooser-tree"></div>', '<label class="button">+</label>', 'own-box');
$check('the box names its tree', str_contains($html, 'data-taxmod-tree="' . $tree . '"'));
$check('it carries the kept open branches and where to keep them', str_contains($html, 'data-taxmod-tree-open="[]"') && str_contains($html, 'action=' . ListPage::TREE_STATE_ACTION));
$check('«Expand all» and «Collapse all» stand beside the page\'s own buttons', str_contains($html, 'taxmod-side-tree-expand') && str_contains($html, 'taxmod-side-tree-collapse') && str_contains($html, '<label class="button">+</label>'));
$check('the module\'s own class stays on the box', str_contains($html, 'taxmod-side-tree own-box'));
$listPage = (string) @file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Admin/ListPage.php');
$plugin   = (string) @file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Plugin.php');
$check('it asks for its script, which the plugin registers', preg_match('/function sideTree\(.*?wp_enqueue_script\(Plugin::SIDE_TREE_SCRIPT\)/s', $listPage) === 1
    && str_contains($plugin, "SIDE_TREE_SCRIPT = 'taxmod-side-tree'") && str_contains($plugin, "'assets/side-tree.js'"));
$check('the ajax action that keeps the open branches is wired', has_action('wp_ajax_' . ListPage::TREE_STATE_ACTION) !== false);

$script = (string) @file_get_contents(dirname(__DIR__, 2) . '/assets/side-tree.js');
$check('the script expands and collapses all', str_contains($script, 'taxmod-side-tree-expand') && str_contains($script, 'taxmod-side-tree-collapse'));
$check('the script keeps the open branches after a fold', str_contains($script, "closest( '.taxmod-tree-fold' )") && str_contains($script, "'open[]'"));
$check('the script draws the kept branches open on load', str_contains($script, 'data-taxmod-tree-open'));

ListPage::resetTreeStates();
$check('Benutzereinstellungen can forget every tree', ListPage::treeChosen($tree, 0) === 0);

exit($failed === 0 ? 0 : 1);
