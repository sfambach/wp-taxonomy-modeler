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
];

foreach ($claims as $claim => $holds) {
    echo ($holds ? 'ok   ' : 'FAIL ') . $claim . "\n";
    $failed += $holds ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
