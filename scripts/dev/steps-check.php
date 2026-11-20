<?php declare(strict_types=1);

/**
 * The process arrows (`ListPage::steps()`, D-1197) as a failing check: the stages in their order, the open one marked, and
 * since his wish of 2026-11-09 the view over every stage as a narrow arrow under them, marked like a stage when it is open.
 *
 *     php scripts/dev/steps-check.php [/path/to/wordpress]
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

$url   = static fn (string $slug): string => 'https://example.test/?tab=' . $slug;
$steps = [['a', 'First', 3], ['b', 'Second', 0], ['c', 'Third']];

$plain = ListPage::steps($steps, 'b', $url);
$check('the stages stand as arrows in their order', strpos($plain, 'First') < strpos($plain, 'Second') && strpos($plain, 'Second') < strpos($plain, 'Third') && preg_match_all('/class="taxmod-step[ "]/', $plain) === 3);
$check('the open stage is marked and named as the current step', str_contains($plain, 'taxmod-step is-active is-empty" href="https://example.test/?tab=b" aria-current="step"'));
$check('without a view over all, nothing is drawn under the stages', ! str_contains($plain, 'taxmod-steps-all') && ! str_contains($plain, 'taxmod-steps-process'));

$all = ListPage::steps($steps, 'c', $url, [], ['x', 'All', 8]);
// D-1530 replaces his wish of 2026-11-09 (a narrow bar under the stages): Stefan's card 20.11. 18:40 «'Alle' vorne in der Zeile».
$check('D-1530: «Alle» is the first stage of the same row, with its count', preg_match('#<ol><li><a class="taxmod-step" href="https://example.test/\?tab=x"><span class="taxmod-step-label">All</span><span class="taxmod-step-count">8</span></a></li><li><a class="taxmod-step" href="https://example.test/\?tab=a">#', $all) === 1);
$check('D-1530: no second bar under the stages', ! str_contains($all, 'taxmod-steps-all') && ! str_contains($all, 'taxmod-steps-process'));
$open = ListPage::steps($steps, 'x', $url, [], ['x', 'All']);
$check('open, it is marked like a stage', str_contains($open, 'taxmod-step is-active" href="https://example.test/?tab=x" aria-current="step"'));
$status = ListPage::steps($steps, 'a', null, [], ['x', 'All', 8]);
$check('as a record\'s status (no links), there is no «Alle»', ! str_contains($status, '>All<'));

echo $failed === 0 ? "steps-check: all claims hold\n" : "steps-check: {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
