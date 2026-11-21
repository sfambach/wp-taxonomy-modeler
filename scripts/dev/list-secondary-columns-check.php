<?php declare(strict_types=1);

/**
 * Narrow lists (Live-Check 7, 21.11.2026: 706 px beside the navigation): a column marked secondary gives way below a container
 * width, by its place in the row, never by its name; a column the user chose himself stays. The details toggle is a square like
 * every other row button (D-1511 rule 3).
 *
 *     php scripts/dev/list-secondary-columns-check.php
 *
 * Reads the source only; needs no WordPress.
 */

$root   = dirname(__DIR__, 2);
$page   = (string) file_get_contents($root . '/src/WordPress/Admin/ListPage.php');
$column = (string) file_get_contents($root . '/src/Core/Listing/ListColumn.php');
$rules  = (string) file_get_contents($root . '/src/WordPress/Admin/RulesPage.php');
$css    = (string) file_get_contents($root . '/assets/admin.css');
$red    = 0;

foreach ([
    'a column cannot be secondary'                           => str_contains($column, 'public readonly bool $secondary = false,'),
    'the heads mark a secondary column despite a choice'     => str_contains($page, '$column->secondary && ! $view->chose()'),
    'a secondary column does not give way by its place'      => str_contains($page, "\$cell   = ':nth-child(' . (\$at + 2) . ')';"),
    'the details row loses a cell with the columns'          => str_contains($page, '>tbody>tr:not(.taxmod-list-details)>'),
    'the width is not asked of the list\'s own container'    => str_contains($page, '{container-type:inline-size}@container (max-width:'),
    'a rule type cannot name its secondary fields'           => str_contains($rules, "\$this->types[\$type]['secondary']"),
    'the details toggle is no square'                        => str_contains($page, 'class="button taxmod-square taxmod-list-toggle"'),
    'the details toggle keeps its own padding'               => ! str_contains($page, 'vertical-align:middle;padding:0 6px}'),
    'a long head word does not break at its syllables'       => str_contains($page, '{hyphens:auto;overflow-wrap:break-word}'),
    'the rules list keeps the 21em actions column'           => str_contains($rules, ", '', false, self::ACTIONS_WIDTH)]]"),
    // Live-Check 7: the choices in the bar shrank to 2 px and their selects overlapped.
    'the choices box shrinks below its selects'              => str_contains($page, '.tablenav.taxmod-list-bar>.taxmod-list-choices,.tablenav.taxmod-list-bar>.bulkactions{flex:0 1 auto;min-width:min-content}'),
    'a select in the bar does not give way to 5.5em'          => str_contains($page, ':is(.taxmod-list-choices,.bulkactions) select{flex:1 1 10em;width:0;min-width:5.5em;max-width:10em}'),
    // Live-Check 13: a clipped switch (`position:absolute`) without a frame widened the whole page past the list's scroll box.
    'a chooser gives its switch no frame'                    => str_contains($css, ".taxmod-chooser {\n    display: inline-block;\n    max-width: 100%;\n    /* Live-Check 13") && str_contains($css, "    position: relative;\n}"),
    'a toggle gives its input no frame'                      => str_contains($css, "    cursor: pointer;\n    position: relative;\n}"),
] as $what => $green) {
    if (! $green) {
        echo "FAIL {$what}\n";
        $red++;
    }
}

echo $red === 0 ? "list-secondary-columns-check: green\n" : "list-secondary-columns-check: {$red} red\n";
exit($red === 0 ? 0 : 1);
