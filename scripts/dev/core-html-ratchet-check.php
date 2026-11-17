<?php declare(strict_types=1);

/**
 * The ratchet of the renderer plan (D-1424): what docs/pakete/renderer-ereignisse/plan.md says must leave the core may only
 * shrink. Each count has a ceiling here; a count above it turns the check red, a count below it says the ceiling may follow.
 * A ceiling rises only with a decision, never in passing (PR-9).
 *
 *     php scripts/dev/core-html-ratchet-check.php
 */

const ROOT = __DIR__ . '/../..';

/** Measured 2026-11-17 on main 7b92449f. */
const CEILINGS = [
    'core files with HTML tags'          => 54,
    'RenderResult::escape() in the core' => 249,
    'hook calls in the core'             => 0,
    'arms of the node page dispatcher'   => 38,
    'modeller pages without the template' => 9,
];

const HTML_TAG = '/<(div|span|a|table|tr|td|th|p|ul|ol|li|input|select|option|label|form|button|details|summary|img|strong|em|h[1-6]|br|textarea|nav|section|fieldset|legend|dl|dt|dd|code|small|time|figure|svg|template)[ >\/"\']/';

/** @return list<string> */
function phpFiles(string $dir): array
{
    $out = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $out[] = $file->getPathname();
        }
    }

    sort($out);

    return $out;
}

$counts = array_fill_keys(array_keys(CEILINGS), 0);
$where  = array_fill_keys(array_keys(CEILINGS), []);

foreach (phpFiles(ROOT . '/src/Core') as $file) {
    $code = (string) file_get_contents($file);
    $name = substr($file, strlen(ROOT) + 1);

    if (preg_match(HTML_TAG, $code)) {
        $counts['core files with HTML tags']++;
        $where['core files with HTML tags'][] = $name;
    }

    $counts['RenderResult::escape() in the core'] += substr_count($code, 'RenderResult::escape(');
    $hooks = preg_match_all('/\b(add_action|add_filter|do_action|apply_filters)\s*\(/', $code);

    if ($hooks > 0) {
        $counts['hook calls in the core'] += $hooks;
        $where['hook calls in the core'][] = $name;
    }
}

// The dispatcher of the node page: one arm per `do` value, until the plan's step 3 makes them listeners.
$nodes = (string) file_get_contents(ROOT . '/src/WordPress/Admin/NodesScreen.php');

if (preg_match('/match \(\$do\) \{(.*?)\n\s*\};/s', $nodes, $match) !== 1) {
    fwrite(STDERR, "The dispatcher «match (\$do)» was not found — the measurement no longer fits the code.\n");
    exit(2);
}

$counts['arms of the node page dispatcher'] = preg_match_all('/^\s*(\'[^\']+\'\s*,\s*)*\'[^\']+\'\s*=>/m', $match[1]);

// G16 for the modeller itself: the design guard only asks modules (review 2026-11-17, R-5).
foreach (phpFiles(ROOT . '/src/WordPress') as $file) {
    if (preg_match('#/Admin/ListPage\.php$#', $file)) {
        continue;
    }

    $wraps = preg_match_all('/<div class=.?"wrap\b/', (string) file_get_contents($file));

    if ($wraps > 0) {
        $counts['modeller pages without the template'] += $wraps;
        $where['modeller pages without the template'][] = substr($file, strlen(ROOT) + 1);
    }
}

$red = 0;

foreach (CEILINGS as $what => $ceiling) {
    $now = $counts[$what];
    printf("%-38s %5d  (ceiling %d)%s\n", $what, $now, $ceiling, $now > $ceiling ? '  ← ROSE' : ($now < $ceiling ? '  ← fell, the ceiling may follow' : ''));

    if ($now > $ceiling) {
        $red++;

        foreach ($where[$what] as $place) {
            echo "    {$place}\n";
        }
    }
}

if ($red > 0) {
    echo "\nNo new HTML in the core (D-1424): draw it at the edge, or lower another count first.\n";
    exit(1);
}

echo "\nNothing rose.\n";
exit(0);
