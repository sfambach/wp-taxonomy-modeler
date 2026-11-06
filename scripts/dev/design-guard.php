<?php declare(strict_types=1);

/**
 * Design guard: measures the Taxmod repositories against the shared design and architecture rules
 * (docs/gestaltung-und-architektur.md, D-1186). Report only — it does not fail yet, because the
 * first audit found violations; each rule becomes a failing check once its findings are fixed.
 *
 * Usage: php scripts/dev/design-guard.php [--fail] [--only=G1,G2] <repo> [<repo> …]
 *        (no repo: this repository plus every sibling directory named wp_tax_*)
 */

$fail  = in_array('--fail', $argv, true);
$only  = [];

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $only = explode(',', substr($arg, 7));
    }
}

$repos = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => ! str_starts_with($a, '--')));

if ($repos === []) {
    $self  = dirname(__DIR__, 2);
    $repos = array_merge([$self], glob(dirname($self) . '/wp_tax_*', GLOB_ONLYDIR) ?: []);
}

/** The shared building blocks themselves: they are the one place that draws a heading, a dialog, a style. */
const SHARED_BLOCKS = '#/src/(WordPress/Admin/(ListPage|Tiles)|Core/Tile/TileGrid)\.php$#';

/** Classes of the modeller allowed to hold SQL: the persistence layer and the one owner of each own table. */
const MODELLER_DATA_ACCESS = '#/src/WordPress/(Persistence/|Import/(InboxTable|InboxSchema)\.php|Printing/(PrintJobs|PrintJobSchema)\.php|Currency/(EcbRates|RateSchema)\.php)#';

$rules = [
    'G1 confirm()'      => ['D-804, D-1063', static fn (string $f, string $c): int => preg_match(SHARED_BLOCKS, $f) ? 0 : preg_match_all('/(?<!::)\bconfirm\(/', $c)],
    'G2 own <h1>'       => ['D-989', static fn (string $f, string $c): int => preg_match(SHARED_BLOCKS, $f) ? 0 : preg_match_all('/^(?!.*<body).*<h1[ >]/m', $c)], // a print sheet is its own document
    'G3 inline style/script' => ['D-1068', static fn (string $f, string $c): int => preg_match(SHARED_BLOCKS, $f) ? 0 : preg_match_all('/<(style|script)[ >]/', $c)],
    'G4 SQL outside data layer' => ['D-1186', static fn (string $f, string $c): int => preg_match(MODELLER_DATA_ACCESS, $f) ? 0 : preg_match_all('/\$wpdb->(get_\w+|query|insert|update|delete|replace)\(|\bQuery::(rows|row|value|column|run)\(/', $c)],
    'G5 modeller internals' => ['D-1186', static fn (string $f, string $c, bool $module): int => $module ? preg_match_all('/Taxmod\\\\WordPress\\\\Persistence\\\\/', $c) : 0],
    'G6 ability outside gateway' => ['D-1186', static fn (string $f, string $c, bool $module): int => $module && ! str_ends_with($f, 'ModelGateway.php') ? preg_match_all("/wp_get_ability\(\s*'taxmod\//", $c) : 0],
    'G7 style= attribute' => ['D-1068', static fn (string $f, string $c): int => preg_match_all('/style=\\\\?["\']/', $c)],
];

if ($only !== []) {
    $rules = array_filter($rules, static fn (string $rule): bool => in_array(strtok($rule, ' '), $only, true), ARRAY_FILTER_USE_KEY);
}

$total    = 0;
$byBase   = [];
$lines    = [];

foreach ($repos as $repo) {
    $repo   = rtrim($repo, '/');
    $name   = basename((string) realpath($repo));
    $module = $name !== 'wp-taxonomy-modeler';
    $counts = array_fill_keys(array_keys($rules), 0);
    $where  = [];

    if (! is_dir($repo . '/src')) {
        continue;
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path    = $file->getPathname();
        $content = (string) file_get_contents($path);
        $byBase[$file->getFilename()][] = [$name, $path];

        foreach ($rules as $rule => [, $count]) {
            $n = $count($path, $content, $module);

            if ($n > 0) {
                $counts[$rule] += $n;
                $where[$rule][] = substr($path, strlen($repo) + 1) . ' (' . $n . ')';
            }
        }
    }

    $lines[] = '## ' . $name;

    foreach ($counts as $rule => $n) {
        if ($n > 0) {
            $lines[] = sprintf('  %-28s %4d  [%s]  %s', $rule, $n, $rules[$rule][0], implode(', ', array_slice($where[$rule], 0, 6)) . (count($where[$rule]) > 6 ? ', …' : ''));
            $total  += $rule === 'G7 style= attribute' ? 0 : $n;
        }
    }
}

if ($only !== [] && ! in_array('G8', $only, true)) {
    $byBase = [];
}

$lines[] = '## G8 copied classes (same file name, ≥ 80 % equal lines, different repositories)';

foreach ($byBase as $base => $copies) {
    if ($base === 'Plugin.php' || count(array_unique(array_column($copies, 0))) < 2) {
        continue;
    }

    $first = file((string) $copies[0][1], FILE_IGNORE_NEW_LINES) ?: [];
    $same  = [$copies[0][0]];

    foreach (array_slice($copies, 1) as [$repoName, $path]) {
        $other = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $equal = count(array_intersect($first, $other));

        if ($repoName !== $copies[0][0] && $equal >= 0.8 * max(count($first), count($other))) {
            $same[] = $repoName;
        }
    }

    if (count(array_unique($same)) > 1) {
        $lines[] = '  ' . $base . ': ' . implode(', ', array_unique($same));
        $total++;
    }
}

echo implode(PHP_EOL, $lines), PHP_EOL, PHP_EOL, 'findings (without G7): ', $total, PHP_EOL;

exit($fail && $total > 0 ? 1 : 0);
