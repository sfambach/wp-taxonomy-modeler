<?php declare(strict_types=1);

/**
 * D-1500: dates, money and numbers reach a Taxmod page only through `ListPage::date`, `ListPage::money` and `ListPage::number`.
 * A ratchet (PR-9): it counts the direct calls of `wp_date`, `date_i18n`, `number_format` and `number_format_i18n` in each
 * repository's `src/` — `ListPage.php` itself excepted — and turns red when a repository has more than its ceiling. Moving a
 * caller onto the building block lowers the count; the ceiling is then lowered in the same commit, never raised.
 *
 * `gmdate`/`date` for stored values (`Y-m-d`, ISO 8601) are not display and not counted.
 *
 *     php scripts/dev/format-call-check.php   (this repository and every wp_tax_* beside it)
 *
 * @see docs/NewConcept/90-decision-log.md D-1500
 */

/** Counted 2026-11-20 on main, lowered the same day after the D-1500 module PRs; only ever lowered. */
const CEILINGS = [
    'wp-taxonomy-modeler'    => 22,
    'wp_tax_api_amazon'      => 0,
    'wp_tax_api_dhl'         => 0,
    'wp_tax_api_dpd'         => 0,
    'wp_tax_api_ebay'        => 6,
    'wp_tax_api_gls'         => 0,
    'wp_tax_api_hermes'      => 0,
    'wp_tax_api_jlcpcb'      => 2,
    'wp_tax_api_mailreaders' => 0,
    'wp_tax_api_mouser'      => 0,
    'wp_tax_api_paypal'      => 0,
    'wp_tax_ebay'            => 12,
    'wp_tax_label'           => 1,
    'wp_tax_log_sales'       => 0,
    'wp_tax_mail'            => 0,
    'wp_tax_manufacturing'   => 1,
    'wp_tax_stock'           => 3,
];

$self  = dirname(__DIR__, 2);
$repos = array_merge([$self], glob(dirname($self) . '/wp_tax_*', GLOB_ONLYDIR) ?: []);
$red   = 0;

foreach ($repos as $repo) {
    $name  = basename($repo);
    $count = 0;
    $where = [];

    if (! is_dir($repo . '/src')) {
        continue;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! str_ends_with((string) $file, '.php') || str_ends_with((string) $file, '/Admin/ListPage.php')) {
            continue;
        }

        $code = (string) file_get_contents((string) $file);
        $n    = 0;

        foreach (preg_split('/\R/', $code) ?: [] as $line) {
            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            $n += preg_match_all('/(?<![\w>:$])(wp_date|date_i18n|number_format_i18n|number_format)\(/', $line);
        }

        if ($n > 0) {
            $count  += $n;
            $where[] = substr((string) $file, strlen($repo) + 1) . " ({$n})";
        }
    }

    $ceiling = CEILINGS[$name] ?? 0;
    $state   = $count > $ceiling ? 'RED ' : ($count < $ceiling ? 'LOWER' : 'ok  ');
    $red    += $count > $ceiling ? 1 : 0;

    printf("%s %-24s %3d / %3d  %s\n", $state, $name, $count, $ceiling, $count > $ceiling ? implode(', ', $where) : '');
}

if ($red > 0) {
    echo "\nDirect date/number formatting outside ListPage::date/money/number grew (D-1500). Use the building block.\n";
}

exit($red === 0 ? 0 : 1);
