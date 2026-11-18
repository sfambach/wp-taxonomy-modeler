<?php declare(strict_types=1);

/**
 * The one table head of every list (D-1227, D-1462), as a failing check: the script exists and offers sort, group, the filter
 * over the whole table and the filter row; the plugin registers it; `ListPage::table()` asks for it, so every list has it.
 * D-1462: the bar is the server's and stays where it is drawn; one search field with one meaning; a column filter keeps the
 * same rows in the core and on the page — the shared cases run through `FilterTerm` and through the script.
 *
 *     php scripts/dev/list-head-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1227, D-1462
 */

$root     = dirname(__DIR__, 2);
$script   = (string) @file_get_contents($root . '/assets/list-head.js');
$plugin   = (string) @file_get_contents($root . '/src/WordPress/Plugin.php');
$listPage = (string) @file_get_contents($root . '/src/WordPress/Admin/ListPage.php');
$live     = (string) @file_get_contents($root . '/assets/live.js');
$failed   = 0;

$wildcard = null;

// ⚠️ *Mit `canonical()`, die `matches()` seit D-1378 ruft (eine Schreibweise für Einheiten). Ohne sie brach node mit «canonical is not
// defined» ab und die Zusage war rot, ohne dass die Leiste falsch suchte — gemessen im Grenzlauf 2026-11-17 (D-1427).*
if (preg_match('/function canonical\( text \) \{.*?\n\t\}\n/s', $script, $c) === 1
    && preg_match('/function matches\( text, term \) \{.*?\n\t\}\n/s', $script, $m) === 1
    && trim((string) shell_exec('command -v node')) !== ''
) {
    $cases    = "[matches('LM2930T','lm*30'),matches('Quarz 8 MHz','q*mhz'),matches('Quarz 8 MHz','mhz*q'),matches('LM2930T','lm%30'),matches('x','*')].join(',')";
    $wildcard = trim((string) shell_exec('node -e ' . escapeshellarg($c[0] . $m[0] . 'console.log(' . $cases . ')') . ' 2>&1'));
}

// D-1462: the same cases through the core's rule and the script's; both must keep exactly the same.
$parity = null;
$cases  = json_decode((string) @file_get_contents(__DIR__ . '/lib/filter-term-cases.json'), true);

if (is_array($cases) && is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
    $php = array_map(static fn (array $case): bool => \Taxmod\Core\Listing\FilterTerm::matches($case[0], $case[1], $case[2]), $cases);
    $own = array_map(static fn (array $case): bool => $case[3], $cases);
    $parity = $php === $own;

    if ($parity && trim((string) shell_exec('command -v node')) !== ''
        && preg_match('/function canonical\( text \) \{.*?\n\t\}\n/s', $script, $c) === 1
        && preg_match('/function matches\( text, term \) \{.*?\n\t\}\n/s', $script, $m) === 1
        && preg_match('/var NUMBER = .*?function filterMatches\( text, term, number \) \{.*?\n\t\}\n/s', $script, $f) === 1
    ) {
        $js     = $c[0] . $m[0] . $f[0] . 'console.log(JSON.stringify(' . json_encode($cases) . '.map(function(c){return filterMatches(c[0],c[1],c[2]);})))';
        $parity = trim((string) shell_exec('node -e ' . escapeshellarg($js) . ' 2>&1')) === json_encode($php);
    }
}

$claims = [
    'the script stands'                                  => $script !== '',
    'it finds every list by its class'                   => str_contains($script, "'table.taxmod-list'"),
    'it filters the whole table'                         => str_contains($script, 'taxmod-list-head-search'),
    'it groups by a column'                              => str_contains($script, 'taxmod-list-group'),
    'it adds a filter row where the server draws none'   => str_contains($script, 'taxmod-filter-row-client'),
    'it keeps the rows that belong to a row together'    => str_contains($script, 'taxmod-list-more') && str_contains($script, 'taxmod-list-details'),
    'a cell may name its sort value'                     => str_contains($script, 'data-taxmod-sort'),
    'the bar is the server\'s, the script moves none of it (D-1462)' => ! str_contains($script, 'above.remove()') && ! str_contains($script, 'taxmod-list-head-actions'),
    '«Group by» stands in the menu «Columns» (D-1462)'  => str_contains($script, ".taxmod-columns form"),
    'a hidden row loses its tick'                        => str_contains($script, 'box.checked = false'),
    // D-1357: the bar's rule is the core's — «*» for any characters, nothing else special; run in node where there is one.
    'the bar matches «*» as the core does'               => $wildcard === null || $wildcard === 'true,true,false,false,true',
    'one field, one meaning: the page narrows a complete answer, else the server is asked (D-1462)'
        => str_contains($script, 'data-taxmod-complete') && str_contains($script, 'function narrows(') && str_contains($script, 'window.taxmodShow')
            && ! str_contains($script, 'requestSubmit') && ! str_contains($script, "event.key !== 'Enter'"),
    'the address holds the question, without loading (D-1462, D-1408)' => str_contains($script, 'history.replaceState') && str_contains($live, 'window.taxmodShow = show'),
    'a list put in place has its head again'             => str_contains($script, "'taxmod:replaced'"),
    'a column filter keeps the same rows in core and script (D-1462)' => $parity === true,
    'the server marks a complete answer and number columns' => str_contains($listPage, 'data-taxmod-complete') && str_contains($listPage, 'data-taxmod-number') && str_contains($listPage, '$column->filterable'),
    'the plugin registers it under one handle'           => str_contains($plugin, "LIST_HEAD_SCRIPT = 'taxmod-list-head'") && str_contains($plugin, 'wp_register_script(') && str_contains($plugin, "'assets/list-head.js'"),
    'its words are translatable'                         => str_contains($plugin, 'window.taxmodListHeadWords'),
    'every table of the template asks for it'            => preg_match('/function table\(.*?wp_enqueue_script\(Plugin::LIST_HEAD_SCRIPT\)/s', $listPage) === 1,
];

foreach ($claims as $claim => $holds) {
    echo ($holds ? 'ok   ' : 'FAIL ') . $claim . "\n";
    $failed += $holds ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
