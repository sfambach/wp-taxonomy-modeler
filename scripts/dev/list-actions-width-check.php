<?php declare(strict_types=1);

/**
 * The actions column of a list (his screenshot 2026-11-21 17:14): a list may name its own width, and where it names none the
 * column keeps 21em — so the lists that never asked look as they did.
 *
 *     php scripts/dev/list-actions-width-check.php
 *
 * Reads the source only; needs no WordPress.
 */

$root = dirname(__DIR__, 2);
$page = (string) file_get_contents($root . '/src/WordPress/Admin/ListPage.php');
$red  = 0;

foreach ([
    'the default width is no longer 21em'                    => str_contains($page, "public const ACTIONS_WIDTH = '21em';"),
    'table() does not take the width with that default'       => preg_match('/function table\([^)]*string \$actionsWidth = self::ACTIONS_WIDTH\)/', $page) === 1,
    'the actions head does not draw the width it was given'  => str_contains($page, "style=\"width:' . esc_attr(\$actionsWidth === '' ? self::ACTIONS_WIDTH : \$actionsWidth) . ';text-align:right\""),
    'a fixed width is left in the actions head'             => ! str_contains($page, 'style="width:21em;text-align:right"'),
] as $what => $green) {
    if (! $green) {
        echo "FAIL {$what}\n";
        $red++;
    }
}

echo $red === 0 ? "list-actions-width-check: green\n" : "list-actions-width-check: {$red} red\n";
exit($red === 0 ? 0 : 1);
