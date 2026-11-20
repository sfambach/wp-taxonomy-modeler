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
 * 3. Same function, same building block (D-1511, Stefan 20.11. «ist die gleiche Funktionalität»): reading the text of a
 *    label or stamp PDF belongs to the label module — no other module parses a PDF, in PHP or in its scripts.
 *
 * 4. The way back stands in the page head (D-1521).
 * 5. One process bar, one look (Stefan 20.11. «dass du hier unterschiedliche Sachen wieder verwendest … jedes Mal wieder sagen»):
 *    the arrows of a process are `ListPage::steps` everywhere — list, status of a record, a section — and the Modeller's
 *    stylesheet draws them once. No other file carries the `taxmod-step…` classes (no own markup, no own CSS, no own script),
 *    and a variant of the bar (`.is-status`) may change only how wide it is, never its height, padding, alignment or type.
 *
 * Needs no WordPress.
 *
 * @see docs/NewConcept/90-decision-log.md D-1508, D-1511, D-1488, D-1502
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

/** Reading a PDF's text: only the label module (D-1511). */
const PDF_READER = '/getTextContent\s*\(|ToUnicode|FlateDecode/';

/** Known before D-1511; each falls out when its module takes the label module's reader, and turns red when gone but still listed. */
const PDF_PENDING = [
    'wp_tax_ebay/src/Core/Shipping/LabelText.php'     => 'same repository under its old name',
];

/** D-1521: the way back stands in the page head (ListPage::head), never as a button of its own in a toolbar. */
const BACK_BUTTON = '/ListPage::back\s*\(/';

/** Known before D-1521; each falls out when its page takes ListPage::head, and turns red when gone but still listed. */
const BACK_PENDING = [
    'wp_tax_api_jlcpcb/src/WordPress/ReorderPage.php'       => 'JLCPCB, next release',
    'wp_tax_shipping/src/WordPress/ShippingPage.php'        => 'Versand, next release',
    'wp_tax_stock/src/WordPress/Admin/ChoiceListsPage.php'  => 'Lager, next release',
    'wp_tax_stock/src/WordPress/Admin/ContactsPage.php'     => 'Lager, next release',
    'wp_tax_stock/src/WordPress/Admin/PurchasePage.php'     => 'Lager, next release',
    'wp_tax_stock/src/WordPress/Admin/StockPage.php'        => 'Lager, next release',
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

        if (! str_ends_with($name, '/ListPage.php')) {
            $back = preg_match(BACK_BUTTON, $source) === 1;
            if ($back && ! isset(BACK_PENDING[$name])) {
                printf("FAIL %s: a back button of its own — the way back stands before the title in ListPage::head (D-1521)\n", $name);
                $red++;
            } elseif (! $back && isset(BACK_PENDING[$name])) {
                printf("FAIL %s: no back button any more — strike it from BACK_PENDING\n", $name);
                $red++;
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

foreach ($repos as $repo) {
    foreach (['src', 'assets'] as $folder) {
        if (! is_dir($repo . '/' . $folder) || str_starts_with(basename($repo), 'wp_tax_label')) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/' . $folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = (string) $file;
            $name = basename($repo) . substr($path, strlen($repo));

            if (str_contains($name, '/pdfjs/') || ! preg_match('/\.(php|js)$/', $path)) {
                continue;
            }

            $reads = preg_match(PDF_READER, (string) file_get_contents($path)) === 1;

            if ($reads && ! isset(PDF_PENDING[$name])) {
                printf("FAIL %s: reads a PDF itself — that is the label module's building block (D-1511)\n", $name);
                $red++;
            } elseif (! $reads && isset(PDF_PENDING[$name]) && is_file($path)) {
                printf("FAIL %s: reads no PDF any more — strike it from PDF_PENDING\n", $name);
                $red++;
            }
        }
    }
}

// Rule 5: the process bar has one owner and one look.
const STEPS_CLASS = '/(?<![a-z0-9_-])taxmod-steps?(?:-[a-z]+)*(?![a-z0-9_-])/';
const STEPS_OWNERS = ['wp-taxonomy-modeler/src/WordPress/Admin/ListPage.php', 'wp-taxonomy-modeler/assets/admin.css'];
/** What a variant of the bar may set: its width and the cursor — nothing that changes how a tile looks. */
const STEPS_VARIANT_MAY = ['cursor', 'margin', 'flex', 'flex-wrap', 'min-width'];

foreach ($repos as $repo) {
    foreach (['src', 'assets'] as $folder) {
        if (! is_dir($repo . '/' . $folder)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/' . $folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = (string) $file;
            $name = basename($repo) . substr($path, strlen($repo));

            if (in_array($name, STEPS_OWNERS, true) || ! preg_match('/\.(php|js|css)$/', $path) || str_contains($name, '/vendor/')) {
                continue;
            }

            if (preg_match(STEPS_CLASS, (string) file_get_contents($path), $m) === 1) {
                printf("FAIL %s: «%s» — the process bar is ListPage::steps, drawn by the Modeller's stylesheet only (rule 5)\n", $name, $m[0]);
                $red++;
            }
        }
    }
}

$css = (string) file_get_contents($root . '/wp-taxonomy-modeler/assets/admin.css');
preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) preg_replace('#/\*.*?\*/#s', '', $css), $rules, PREG_SET_ORDER);

foreach ($rules as [, $selector, $body]) {
    if (! str_contains($selector, '.is-status') || preg_match(STEPS_CLASS, $selector) !== 1) {
        continue;
    }

    foreach (array_filter(array_map('trim', explode(';', $body))) as $declaration) {
        $property = strtolower(trim(explode(':', $declaration, 2)[0]));

        if (! in_array($property, STEPS_VARIANT_MAY, true)) {
            printf("FAIL admin.css «%s»: a variant of the process bar sets «%s» — it may change only its width (rule 5)\n", trim($selector), $property);
            $red++;
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
