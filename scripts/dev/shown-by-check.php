<?php declare(strict_types=1);

/**
 * D-1311 as a failing check: a part shown by a field follows that field also when the field is a record chosen from the
 * quick search, which is checked by script and fires no «change» (D-1386).
 *
 *     php scripts/dev/shown-by-check.php
 */

$assets = dirname(__DIR__, 2) . '/assets/';
$admin  = (string) @file_get_contents($assets . 'admin.js');
$shown  = (string) @file_get_contents($assets . 'shown-by.js');
$failed = 0;

$check = static function (string $claim, bool $holds) use (&$failed): void {
    echo ($holds ? 'ok   ' : 'FAIL ') . $claim . "\n";
    $failed += $holds ? 0 : 1;
};

$check('the quick search announces a picked record', str_contains($admin, "new CustomEvent( 'taxmod-record-picked', { bubbles: true } )"));
$check('a shown-by part listens to a picked record', str_contains($shown, "addEventListener( 'taxmod-record-picked'"));
$check('a shown-by part still listens to change', str_contains($shown, "addEventListener( 'change'"));

echo $failed === 0 ? "shown-by-check: all claims hold\n" : "shown-by-check: {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
