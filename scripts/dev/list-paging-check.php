<?php declare(strict_types=1);

/**
 * D-1545: every list is drawn page by page through the one building block. A file that draws a `ListPage::table` gets its
 * rows through `ListPage::paged` — or stands in PAGING_PENDING (not yet moved, shrinks with each module's release) or in
 * BOUNDED (the list can never grow long, with its reason). A pending file that already pages is red too: strike it, so the
 * list only shrinks. Looks at this repository and every wp_tax_* beside it.
 *
 *     php scripts/dev/list-paging-check.php
 *
 * Needs no WordPress.
 *
 * @see docs/NewConcept/90-decision-log.md D-1545
 */

const PAGING_PENDING = [
    'wp-taxonomy-modeler/src/WordPress/Printing/PrintJobsScreen.php',
    'wp_tax_api_ebay/src/WordPress/ShippingScreen.php',
    'wp_tax_ebay/src/WordPress/ShippingScreen.php',
    'wp_tax_label/src/WordPress/Admin/LabelsPage.php',
    'wp_tax_label/src/WordPress/Admin/PrintersPage.php',
    'wp_tax_label/src/WordPress/Admin/RulesPage.php',
    'wp_tax_label/src/WordPress/Admin/WorkplacesPage.php',
    'wp_tax_log_sales/src/WordPress/Admin/SalesPage.php',
    'wp_tax_log_sales/src/WordPress/Admin/StagePage.php',
    'wp_tax_manufacturing/src/WordPress/Admin/OrdersPage.php',
    'wp_tax_stock/src/WordPress/Admin/ChoiceListsPage.php',
    'wp_tax_stock/src/WordPress/Admin/ContactsPage.php',
    'wp_tax_stock/src/WordPress/Admin/InboundPage.php',
    'wp_tax_stock/src/WordPress/Admin/PurchasePage.php',
    'wp_tax_stock/src/WordPress/Admin/StockPage.php',
    'wp_tax_stock/src/WordPress/Admin/TestRulesPage.php',
];

const BOUNDED = [
    'wp-taxonomy-modeler/src/WordPress/Admin/ChangesScreen.php' => 'the latest changes only, a fixed number',
    'wp-taxonomy-modeler/src/WordPress/Admin/ListPage.php'      => 'the building block itself',
    'wp-taxonomy-modeler/src/WordPress/Plugin.php'              => 'names the script handle only, draws no list',
];

$root  = dirname(__DIR__, 3);
$red   = 0;
$seen  = [];
$repos = array_merge([$root . '/wp-taxonomy-modeler'], glob($root . '/wp_tax_*', GLOB_ONLYDIR) ?: []);

foreach ($repos as $repo) {
    if (! is_dir($repo . '/src')) {
        continue;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = (string) $file;

        if (! str_ends_with($path, '.php')) {
            continue;
        }

        $name   = basename($repo) . substr($path, strlen($repo));
        $source = (string) file_get_contents($path);
        $seen[$name] = true;

        if (! str_contains($source, 'ListPage::table(') || isset(BOUNDED[$name])) {
            continue;
        }

        $pages   = str_contains($source, 'ListPage::paged(');
        $pending = in_array($name, PAGING_PENDING, true);

        if ($pages && $pending) {
            echo "FAIL $name: pages through ListPage::paged now — strike it from PAGING_PENDING\n";
            $red++;
        } elseif (! $pages && ! $pending) {
            echo "FAIL $name: draws a list without ListPage::paged (D-1545)\n";
            $red++;
        } else {
            echo ($pending ? 'wait ' : 'ok   ') . "$name\n";
        }
    }
}

foreach ([...PAGING_PENDING, ...array_keys(BOUNDED)] as $name) {
    $repo = strtok($name, '/');

    if (is_dir($root . '/' . $repo) && ! isset($seen[$name])) {
        echo "FAIL $name: no such file any more — strike it\n";
        $red++;
    }
}

echo "list-paging-check: " . ($red === 0 ? 'green' : "$red red") . ', ' . count(PAGING_PENDING) . " pending\n";
exit($red === 0 ? 0 : 1);
