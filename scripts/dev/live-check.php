<?php declare(strict_types=1);

/**
 * The live building block (D-1340) as a failing check: the tree search swaps the tree instead of loading the page, and
 * what is typed stays. Needs no WordPress.
 *
 *     php scripts/dev/live-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1340
 */

$root   = dirname(__DIR__, 2);
$live   = (string) @file_get_contents($root . '/assets/live.js');
$admin  = (string) @file_get_contents($root . '/assets/admin.js');
$plugin = (string) @file_get_contents($root . '/src/WordPress/Plugin.php');
$nodes  = (string) @file_get_contents($root . '/src/WordPress/Admin/NodesScreen.php');
$failed = 0;

$claims = [
    'the script stands'                                   => $live !== '',
    'it takes over a form marked data-taxmod-live'        => str_contains($live, "'form[data-taxmod-live]'") && str_contains($live, 'event.preventDefault()'),
    'it fetches and swaps only the named area'            => str_contains($live, 'window.fetch(') && str_contains($live, 'getElementById( targetId )') && str_contains($live, 'replaceChild( fresh, old )'),
    'the field being typed in stays the same element'     => str_contains($live, 'function keepTyping') && str_contains($live, 'document.activeElement') && str_contains($live, 'function refocus'),
    'an older answer is dropped'                          => str_contains($live, 'AbortController') && str_contains($live, '.abort()'),
    'the address follows the search'                     => str_contains($live, 'history.replaceState'),
    'it says what it replaced'                            => str_contains($live, "'taxmod:replaced'"),
    'a failure loads the page as before'                  => str_contains($live, 'window.location.href = url'),
    'the plugin loads it on every taxmod page'            => str_contains($plugin, "LIVE_SCRIPT = 'taxmod-live'") && str_contains($plugin, "'assets/live.js'"),
    'the tree search form names the live area'            => str_contains($nodes, "data-taxmod-live=\"' . self::TREE_LIVE") && str_contains($nodes, "'<div id=\"' . self::TREE_LIVE . '\">'"),
    'the fold state is applied to a swapped tree'         => str_contains($admin, "'taxmod:replaced'"),
    'no focus marker is left behind for the next page'    => ! str_contains($admin, 'taxmod.suchfokus'),
    // D-1408: the projection — an act does not load the page, only what differs is put in place.
    'an act is sent in the background, after every other submit listener' => str_contains($live, "window.addEventListener( 'submit'") && str_contains($live, 'event.defaultPrevented'),
    'the answer is put in place row by row, focus untouched' => str_contains($live, 'function morphChildren') && str_contains($live, 'old === document.activeElement'),
    'another page is navigated to, a file is downloaded'     => str_contains($live, 'pageOf( answer.url ) !== pageOf( window.location.href )') && str_contains($live, 'function download'),
    'a failed background act submits the form as before'      => str_contains($live, "setAttribute( 'data-taxmod-reload'"),
    'a save in a dialog brings the page up to date on close'  => str_contains($admin, "'taxmod:saved'") && str_contains($live, "'taxmod:saved'") && str_contains($live, "'taxmod-dialog-switch'"),
    'a page in a view dialog tells its opener'                => str_contains($live, "'taxmod:frame-saved'"),
    'notices of a page put in place reach the message field'  => str_contains((string) @file_get_contents($root . '/assets/messages.js'), "'taxmod:replaced'"),
    // D-1417 (Ladezeit S2/S3): a link of the model page does not load it; a fold triangle fetches only the tree.
    'the model page marks its links live and names the fold part' => str_contains($nodes, "data-taxmod-live-links data-taxmod-fold-part=\"' . self::TREE_PANE") && str_contains($nodes, "id=\"' . self::TREE_PANE . '\""),
    'a fold triangle fetches only the tree and says so'           => str_contains($live, "link.matches( '.taxmod-tree-fold-box' )") && str_contains($live, "'X-Taxmod-Live': part"),
    'a page asked for the tree draws no detail'                   => str_contains($nodes, 'function asksTreeOnly') && str_contains($nodes, "(\$nurBaum ? '' : \$times->measure(__('Detail'"),
    'any other link puts the page in place as a history entry'    => str_contains($live, 'history.pushState') && str_contains($live, "'popstate'"),
    'an older link keeps the fold state of the address'           => str_contains($live, 'function carry') && str_contains($nodes, 'data-taxmod-carry="taxmod_collapsed taxmod_hidden"'),
    'a shut block fetches its content when opened, through the one loader' => str_contains($admin, 'window.taxmodLaterPart = spaeterTeil') && str_contains($admin, "addEventListener( 'toggle'") && str_contains($nodes, 'function laterPart'),
    'which rows of a tree show is walked in one place'          => str_contains((string) @file_get_contents($root . '/assets/tree-show.js'), 'window.taxmodTreeShow = baumZeigen') && str_contains($admin, 'window.taxmodTreeShow(') && str_contains((string) @file_get_contents($root . '/assets/side-tree.js'), 'window.taxmodTreeShow(') && ! str_contains((string) @file_get_contents($root . '/assets/side-tree.js'), 'stack.push'),
    'side-tree.js and admin.js depend on tree-show'             => (bool) preg_match('/self::SIDE_TREE_SCRIPT,\s*plugins_url\([^;]*?\[self::TREE_SHOW_SCRIPT\]/s', $plugin) && (bool) preg_match("/'taxmod-admin',\s*plugins_url\('assets\/admin\.js'[^;]*?\[self::TREE_SHOW_SCRIPT\]/s", $plugin) && str_contains($plugin, "'assets/tree-show.js'"),
    'a missing walk is said in the console, not silent'         => str_contains((string) @file_get_contents($root . '/assets/side-tree.js'), 'console.error'),
    'the drawing times split the detail into its blocks'        => str_contains($nodes, 'private function block(') && substr_count($nodes, '$this->block(__(') >= 6,
    'a jump and an anchor still happen after a tree click'        => str_contains($admin, "'taxmod:navigated'") && str_contains($live, "'taxmod:navigated'"),
];

foreach ($claims as $claim => $holds) {
    echo ($holds ? 'ok   ' : 'FAIL ') . $claim . "\n";
    $failed += $holds ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
