<?php declare(strict_types=1);

/**
 * The one table head of every list (D-1227), as a failing check: the script exists and offers sort, group, the filter
 * over the whole table and the filter row; the plugin registers it; `ListPage::table()` asks for it, so every list has it.
 *
 *     php scripts/dev/list-head-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1227
 */

$root     = dirname(__DIR__, 2);
$script   = (string) @file_get_contents($root . '/assets/list-head.js');
$plugin   = (string) @file_get_contents($root . '/src/WordPress/Plugin.php');
$listPage = (string) @file_get_contents($root . '/src/WordPress/Admin/ListPage.php');
$failed   = 0;

$claims = [
    'the script stands'                                  => $script !== '',
    'it finds every list by its class'                   => str_contains($script, "'table.taxmod-list'"),
    'it filters the whole table'                         => str_contains($script, 'taxmod-list-head-search'),
    'it groups by a column'                              => str_contains($script, 'taxmod-list-group'),
    'it adds a filter row where the server draws none'   => str_contains($script, 'taxmod-filter-row-client'),
    'it keeps the rows that belong to a row together'    => str_contains($script, 'taxmod-list-more') && str_contains($script, 'taxmod-list-details'),
    'a cell may name its sort value'                     => str_contains($script, 'data-taxmod-sort'),
    'a hidden row loses its tick'                        => str_contains($script, 'box.checked = false'),
    'the plugin registers it under one handle'           => str_contains($plugin, "LIST_HEAD_SCRIPT = 'taxmod-list-head'") && str_contains($plugin, 'wp_register_script(') && str_contains($plugin, "'assets/list-head.js'"),
    'its words are translatable'                         => str_contains($plugin, 'window.taxmodListHeadWords'),
    'every table of the template asks for it'            => preg_match('/function table\(.*?wp_enqueue_script\(Plugin::LIST_HEAD_SCRIPT\)/s', $listPage) === 1,
];

foreach ($claims as $claim => $holds) {
    echo ($holds ? 'ok   ' : 'FAIL ') . $claim . "\n";
    $failed += $holds ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
