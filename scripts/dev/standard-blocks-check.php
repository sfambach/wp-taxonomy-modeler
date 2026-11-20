<?php declare(strict_types=1);

/**
 * Architecture gate 20.11. (Stefan: «trotz Architekt werden immer noch nicht dem Standard entsprechender Code produziert»):
 * two shortcuts that slipped through the gate get a guard of their own, across this repository and every wp_tax_* beside it.
 *
 * 1. Choosing a record of the model (a contact, a firm, a material, a place …) is the record chooser `RecordPick::field`
 *    (D-1488), never a page's own `SelectMarkup::of` filled from records. A select is right only for a fixed word list.
 *    Every `SelectMarkup::of` outside the renderer is counted per file; a file may not have more than the list below says,
 *    and a file not on the list may have none. Raising a number needs a reason in the line beside it: «fixed word list: …».
 * 2. Which printer prints is the workplace's (D-1502), read through the label module — no module keeps a printer or a
 *    print queue setting of its own, and no module draws a printer choice. Outside the label module and the modeller's
 *    print tab a printer/queue setting or field is red, without a ceiling.
 *
 *     php scripts/dev/standard-blocks-check.php
 *
 * Needs no WordPress.
 *
 * @see docs/NewConcept/90-decision-log.md D-1508, D-1488, D-1502
 */

/** Counted 2026-11-20 on main; only ever lowered. Each entry: a fixed word list, not a choice of records. */
const SELECT_CEILINGS = [
    'wp-taxonomy-modeler/src/WordPress/Admin/SettingsScreen.php'      => 5, // fixed word lists: modes, intervals, languages
    'wp-taxonomy-modeler/src/WordPress/Admin/NodesScreen.php'         => 7, // fixed word lists: kinds, multiplicities, renderers
    'wp-taxonomy-modeler/src/WordPress/Plugin.php'                    => 1, // to be checked
    'wp-taxonomy-modeler/src/WordPress/Import/FetchInterval.php'      => 1, // fixed word list: intervals
    'wp_tax_api_dhl/src/WordPress/SettingsScreen.php'                 => 2, // fixed word lists: server, product
    'wp_tax_api_ebay/src/WordPress/SettingsScreen.php'                => 2, // fixed word lists: server, marketplace
    'wp_tax_api_ebay/src/WordPress/ShippingScreen.php'                => 3, // fixed word lists: filters
    'wp_tax_ebay/src/WordPress/SettingsScreen.php'                    => 2, // same repository under its old name
    'wp_tax_ebay/src/WordPress/ShippingScreen.php'                    => 3,
    'wp_tax_api_gls/src/WordPress/SettingsScreen.php'                 => 1, // fixed word list: server
    'wp_tax_label/src/WordPress/LabelButton.php'                      => 4, // the label module owns labels and printers
    'wp_tax_label/src/WordPress/Admin/WorkplacesPage.php'             => 1,
    'wp_tax_label/src/WordPress/Admin/PrintersPage.php'               => 1,
    'wp_tax_label/src/WordPress/Admin/LabelsPage.php'                 => 1,
    'wp_tax_mail/src/WordPress/SettingsScreen.php'                    => 1, // fixed word list: interval
    'wp_tax_manufacturing/src/WordPress/Admin/OrdersPage.php'         => 5, // to be checked: record choices move to RecordPick
    'wp_tax_manufacturing/src/WordPress/Admin/SettingsTab.php'        => 1,
    'wp_tax_stock/src/WordPress/Admin/StockPage.php'                  => 14, // to be checked: record choices move to RecordPick
    'wp_tax_stock/src/WordPress/StockService.php'                     => 2, // to be checked
    'wp_tax_stock/src/WordPress/Admin/ChoiceListsPage.php'            => 1, // fixed word list: the list's own entries
    'wp_tax_stock/src/WordPress/Admin/SettingsPage.php'               => 1, // to be checked
    'wp_tax_stock/src/WordPress/Admin/PurchasePage.php'               => 2, // to be checked
    'wp_tax_shipping/src/WordPress/ShippingPage.php'                  => 1, // to be checked
];

/** Where a printer choice belongs: the label module and the modeller's own print tab. */
const PRINTER_OWNERS = ['wp_tax_label/', 'wp-taxonomy-modeler/src/WordPress/Printing/'];

/** Known before this guard; each falls out with the named release, and the guard turns red when it is gone but still listed. */
const PRINTER_PENDING = [
];

/** A printer or print-queue setting or field: a stored key, a drawn field, a select of printers. */
const PRINTER_SETTING = [
    '/update_option\s*\([^;]*[\'"](printer|printer_id|queue|drucker)[\'"]\s*=>/is',
    '/(SelectMarkup::of|ListPage::field|RecordPick::field)\s*\([^;]*?[\'"](printer|printer_id|queue|drucker)[\'"]/is',
    '/name=\\\\?[\'"](printer|printer_id|queue|drucker)\\\\?[\'"]/i',
];

$root  = dirname(__DIR__, 3);
$red   = 0;
$seen  = [];
$repos = array_merge([$root . '/wp-taxonomy-modeler'], glob($root . '/wp_tax_*', GLOB_ONLYDIR) ?: []);

foreach ($repos as $repo) {
    if (! is_dir($repo . '/src')) {
        continue;
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = (string) $file;

        if (! str_ends_with($path, '.php')) {
            continue;
        }

        $name   = basename($repo) . substr($path, strlen($repo));
        $source = (string) file_get_contents($path);

        // The renderer's own drawers are where a select is made, not where one is used.
        if (! str_contains($name, '/Draw/') && ! str_contains($name, '/src/Core/') && ! str_ends_with($name, '/ListPage.php')) {
            $count   = preg_match_all('/SelectMarkup::of\s*\(|<select\b/', $source);
            $ceiling = SELECT_CEILINGS[$name] ?? 0;

            if ($count > $ceiling) {
                printf("FAIL %s: %d own selects, ceiling %d — a choice of records is RecordPick::field (D-1488); a fixed word list raises the ceiling with its reason\n", $name, $count, $ceiling);
                $red++;
            } elseif ($count > 0) {
                $seen[$name] = $count;
            }
        }

        $owner = false;

        foreach (PRINTER_OWNERS as $prefix) {
            $owner = $owner || str_starts_with($name, $prefix);
        }

        if (! $owner) {
            $found = false;
            foreach (PRINTER_SETTING as $pattern) {
                if (preg_match($pattern, $source, $m) === 1) {
                    $found = true;
                    if (isset(PRINTER_PENDING[$name])) {
                        break;
                    }
                    printf("FAIL %s: own printer setting or field («%s») — the printer is the workplace's (D-1502), read through the label module\n", $name, trim(substr($m[0], 0, 60)));
                    $red++;
                    break;
                }
            }
            if (! $found && isset(PRINTER_PENDING[$name])) {
                printf("FAIL %s: no printer setting any more — strike it from PRINTER_PENDING\n", $name);
                $red++;
            }
        }
    }
}

foreach (SELECT_CEILINGS as $name => $ceiling) {
    if (isset($seen[$name]) && $seen[$name] < $ceiling) {
        printf("note %s: %d own selects, ceiling %d — lower the ceiling\n", $name, $seen[$name], $ceiling);
    }
}

echo $red === 0 ? "standard-blocks-check: all green\n" : "standard-blocks-check: $red red\n";
exit($red === 0 ? 0 : 1);
