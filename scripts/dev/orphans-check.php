<?php declare(strict_types=1);

/**
 * Does anything own a setting or a label that no longer exists?
 *
 * ⚠️ **This is [row 28](../../docs/NewConcept/97-implementation-plan.md#the-working-list)'s second
 * half, and it is the half that lasts.** The first half was a one-time clean-up — **892 setting rows
 * and 126 label rows** belonged to owners that were gone. *A clean-up without a guard is a clean-up
 * that has to be repeated, and the thing producing the litter was this very net.*
 *
 * ⚠️ **Measured after the sweep, check by check: exactly one leaked.** `package7-check` left **five
 * rows a run** — it deleted its scratch nodes and edges and left their settings standing. It took two
 * goes to fix, because the raw delete finds its edges by **name** while the first fix only knew them by
 * **endpoint**, and edges whose nodes had already gone had no endpoint left to be found by.
 *
 * ⚠️ **The installation identity is not an orphan and every naive query says it is.** It is an identity
 * with no node and no relation behind it ([D-079](../../docs/NewConcept/90-decision-log.md)), and its
 * rows are the declared defaults for the switches ([D-404](../../docs/NewConcept/90-decision-log.md)).
 * *A check that swept it up would take the answer to «what does `persistent` mean when nobody said» with
 * it.*
 *
 * ⚠️ *It reads and never writes. `orphans-clean.php` is the one that removes, and it is a separate file
 * for that reason — a check that repairs what it measures cannot fail twice.*
 *
 * Usage: php scripts/dev/orphans-check.php
 *
 * @see docs/NewConcept/97-implementation-plan.md
 */

define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), new WpdbChangelog(new SystemClock()));

$installation = $framework->installationId();

global $wpdb;

$p      = $wpdb->prefix . 'taxmod_';
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $failed;

    if ($ok) {
        echo "  ok   {$what}\n";

        return;
    }

    $failed++;

    echo "  FAIL {$what}" . ($detail === '' ? '' : " — {$detail}") . "\n";
}

/** Rows whose owner is neither a node, nor an edge, nor the installation. */
function orphanRows(string $table, int $installation): int
{
    global $wpdb, $p;

    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}{$table} t
         WHERE t.owner_id <> %d
           AND NOT EXISTS (SELECT 1 FROM {$p}nodes n WHERE n.id = t.owner_id)
           AND NOT EXISTS (SELECT 1 FROM {$p}relations r WHERE r.id = t.owner_id)",
        $installation
    ));

    if ($wpdb->last_error !== '') {
        // ⚠️ *An empty result and a broken query look identical through `$wpdb`, and that mistake was
        // reported to the owner as a fact about his model on 2026-08-26. Never again silently.*
        echo "  FEHLER in der Abfrage auf {$table}: ", $wpdb->last_error, "\n";

        exit(1);
    }

    return $count;
}

echo "\n== nichts gehoert einem Besitzer, den es nicht gibt ==\n";

$settings = orphanRows('settings', $installation);
$labels   = orphanRows('labels', $installation);

check('keine Waisen-Settings', $settings === 0, "{$settings} Zeilen");
check('keine Waisen-Labels', $labels === 0, "{$labels} Zeilen");

// ⚠️ **Die Gegenpruefung, die den zwei oben erst Bedeutung gibt.** *Ohne sie waere eine Abfrage, die
// versehentlich nichts findet, genauso gruen — und die Installationsidentitaet ist der eine Besitzer,
// der garantiert keinen Knoten hat und trotzdem bleiben muss.*
$declared = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}settings WHERE owner_id = %d", $installation));

check(
    'die Installationsidentitaet behaelt ihre erklaerten Standardwerte',
    $declared > 0,
    "{$declared} Zeilen bei {$installation}"
);

echo "\n", $failed === 0 ? "all green\n" : "{$failed} failed\n";

exit($failed === 0 ? 0 : 1);
