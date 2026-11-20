<?php declare(strict_types=1);

/**
 * D-1501: an input on a Taxmod page is drawn by the form renderer — `ListPage::field`, `ListPage::quantity`, `ListPage::form`,
 * the record editor — never written as `<input>`, `<select>` or `<textarea>` by the page itself. A ratchet (PR-9): it counts
 * those tags (hidden inputs excepted) in each file of `src/` in this repository and every wp_tax_* beside it, the renderer's
 * own drawers excepted, and turns red when a file has more than the list below says — or a file not on the list has any.
 * Moving a field onto the renderer lowers its count; the list is then lowered in the same commit, never raised.
 *
 *     php scripts/dev/raw-input-check.php
 *
 * Needs no WordPress.
 *
 * @see docs/NewConcept/90-decision-log.md D-1501
 */

/** Counted 2026-11-20 on main; only ever lowered. */
const CEILINGS = [
    'wp-taxonomy-modeler/src/Core/Service/Rendering.php' => 4,
    'wp-taxonomy-modeler/src/WordPress/Admin/BackupScreen.php' => 1,
    'wp-taxonomy-modeler/src/WordPress/Admin/NodesScreen.php' => 11,
    'wp-taxonomy-modeler/src/WordPress/Import/AutoTake.php' => 1,
    'wp-taxonomy-modeler/src/WordPress/Import/FetchInterval.php' => 1,
    'wp-taxonomy-modeler/src/WordPress/Import/ImportTab.php' => 2,
    'wp-taxonomy-modeler/src/WordPress/Import/InboxScreen.php' => 2,
    'wp-taxonomy-modeler/src/WordPress/Import/MailReading.php' => 4,
    'wp-taxonomy-modeler/src/WordPress/Plugin.php' => 2,
    'wp-taxonomy-modeler/src/WordPress/Printing/PrintTab.php' => 1,
    'wp-taxonomy-modeler/src/WordPress/Printing/ScannerTab.php' => 1,
    'wp_tax_api_ebay/src/WordPress/ShippingScreen.php' => 1,
    'wp_tax_ebay/src/WordPress/SettingsScreen.php' => 4,
    'wp_tax_ebay/src/WordPress/ShippingScreen.php' => 10,
    'wp_tax_label/src/WordPress/Admin/PrintersPage.php' => 1,
    'wp_tax_label/src/WordPress/LabelButton.php' => 2,
    'wp_tax_log_sales/src/WordPress/Admin/SalesPage.php' => 1,
    'wp_tax_manufacturing/src/WordPress/Admin/OrdersPage.php' => 10,
    'wp_tax_manufacturing/src/WordPress/Admin/SettingsTab.php' => 1,
    'wp_tax_manufacturing/src/WordPress/Admin/StagingSection.php' => 4,
    'wp_tax_manufacturing/src/WordPress/Admin/TestSection.php' => 1,
    'wp_tax_shipping/src/WordPress/SettingsPage.php' => 2,
    'wp_tax_shipping/src/WordPress/ShippingPage.php' => 3,
    'wp_tax_stock/src/WordPress/Admin/ChoiceListsPage.php' => 3,
    'wp_tax_stock/src/WordPress/Admin/ContactsPage.php' => 2,
    'wp_tax_stock/src/WordPress/Admin/InboundPage.php' => 4,
    'wp_tax_stock/src/WordPress/Admin/MaterialPicker.php' => 2,
    'wp_tax_stock/src/WordPress/Admin/PurchasePage.php' => 13,
    'wp_tax_stock/src/WordPress/Admin/SettingsPage.php' => 11,
    'wp_tax_stock/src/WordPress/Admin/SettingsTab.php' => 1,
    'wp_tax_stock/src/WordPress/Admin/StockPage.php' => 48,
    'wp_tax_stock/src/WordPress/StockService.php' => 2,
];

function rawInputs(string $code): int
{
    preg_match_all('/<(input|select|textarea)\b([^>]*)/i', $code, $hits, PREG_SET_ORDER);
    $count = 0;

    foreach ($hits as $hit) {
        $count += strtolower($hit[1]) === 'input' && preg_match('/type=\\\\?["\']hidden/i', $hit[2]) === 1 ? 0 : 1;
    }

    return $count;
}

$self   = dirname(__DIR__, 2);
$base   = dirname($self);
$repos  = ['wp-taxonomy-modeler' => $self];
$failed = 0;

foreach (glob($base . '/wp_tax_*', GLOB_ONLYDIR) ?: [] as $dir) {
    $repos[basename($dir)] = $dir;
}

foreach ($repos as $name => $dir) {
    if (! is_dir($dir . '/src')) {
        continue;
    }

    $total  = 0;
    $before = $failed;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path     = (string) $file;
        $relative = substr($path, strlen($dir) + 1);

        if (! str_ends_with($path, '.php') || ($name === 'wp-taxonomy-modeler' && preg_match('#^src/(WordPress/Draw/|Core/Renderer/|WordPress/Admin/ListPage\.php$)#', $relative) === 1)) {
            continue;
        }

        $count   = rawInputs((string) file_get_contents($path));
        $ceiling = CEILINGS[$name . '/' . $relative] ?? 0;
        $total  += $count;

        if ($count > $ceiling) {
            $failed++;
            printf("FAIL %s/%s: %d raw inputs, ceiling %d — draw them with ListPage::field / ListPage::quantity\n", $name, $relative, $count, $ceiling);
        } elseif ($count < $ceiling) {
            printf("lower %s/%s: %d raw inputs, ceiling %d — lower the ceiling in this commit\n", $name, $relative, $count, $ceiling);
        }
    }

    printf("%s %s: %d raw inputs\n", $failed === $before ? 'ok  ' : 'FAIL', $name, $total);
}

exit($failed === 0 ? 0 : 1);
