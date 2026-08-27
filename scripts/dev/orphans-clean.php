<?php declare(strict_types=1);

/**
 * Remove settings and labels whose owner no longer exists.
 *
 * [Row 28](../../docs/NewConcept/97-implementation-plan.md#the-working-list) has two halves, and this
 * is the one-time half: **895 setting rows and 126 label rows belong to owners that are gone.** The
 * other half — *parking or purging a node deals with what belongs to it* — is built, in
 * {@see \Taxmod\Core\Service\ModelEditor::clearTrash()}.
 *
 * ⚠️ **The installation identity is kept, and it is the one exception that matters.** It is an identity
 * with **no node and no relation behind it** ([D-079](../../docs/NewConcept/90-decision-log.md)), so
 * every naive «owner does not exist» query calls it an orphan — *and its three rows are the declared
 * defaults for `hide`, `read_only` and `persistent` ([D-401](../../docs/NewConcept/90-decision-log.md),
 * [D-404](../../docs/NewConcept/90-decision-log.md)). Deleting them would make every switch in the
 * model fall back to an invented value again.*
 *
 * ⚠️ **Harmless today and not harmless later.** Nothing resolves a missing owner, so no screen is
 * wrong — but [D-061](../../docs/NewConcept/90-decision-log.md) makes the changelog the migration
 * script, and a migration replaying this model would carry hundreds of answers to questions nobody can
 * ask.
 *
 * ⚠️ *Dry run by default. It prints what it would take before it takes anything, because a script that
 * deletes nine hundred rows should not be able to do so by accident.*
 *
 * Usage: php scripts/dev/orphans-clean.php          (count only)
 *        php scripts/dev/orphans-clean.php --go     (remove them)
 *
 * @see docs/NewConcept/97-implementation-plan.md
 */

define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
use Taxmod\WordPress\SystemClock;

$go = in_array('--go', $argv, true);

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), new WpdbChangelog(new SystemClock()));

$installation = $framework->installationId();

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

/** @return list<int> The owners a table names that are neither a node, nor an edge, nor the installation. */
function orphanOwners(string $table, int $installation): array
{
    global $wpdb, $p;

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT t.owner_id FROM {$p}{$table} t
         WHERE t.owner_id <> %d
           AND NOT EXISTS (SELECT 1 FROM {$p}nodes n WHERE n.id = t.owner_id)
           AND NOT EXISTS (SELECT 1 FROM {$p}relations r WHERE r.id = t.owner_id)",
        $installation
    ));

    if ($wpdb->last_error !== '') {
        throw new RuntimeException("Abfrage auf {$table} fehlgeschlagen: " . $wpdb->last_error);
    }

    return array_map('intval', $ids);
}

$settingOwners = orphanOwners('settings', $installation);
$labelOwners   = orphanOwners('labels', $installation);

$settingRows = $settingOwners === [] ? 0 : (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$p}settings WHERE owner_id IN (" . implode(',', $settingOwners) . ')'
);
$labelRows = $labelOwners === [] ? 0 : (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$p}labels WHERE owner_id IN (" . implode(',', $labelOwners) . ')'
);

printf(
    "Installationsidentitaet %d — bleibt, mit %d erklaerten Standardwerten.\n\n",
    $installation,
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}settings WHERE owner_id = %d", $installation))
);

printf("Settings: %d Zeilen bei %d verschwundenen Besitzern\n", $settingRows, count($settingOwners));
printf("Labels:   %d Zeilen bei %d verschwundenen Besitzern\n", $labelRows, count($labelOwners));

if (! $go) {
    echo "\n— Probelauf. Mit --go werden sie entfernt. —\n";

    exit(0);
}

$settings = new WpdbSettingRepository();
$labels   = new WpdbLabelRepository();

$goneSettings = $settingOwners === [] ? 0 : $settings->forgetOwners($settingOwners);
$goneLabels   = $labelOwners === [] ? 0 : $labels->forgetOwners($labelOwners);

printf("\nEntfernt: %d Settings, %d Labels\n", $goneSettings, $goneLabels);

printf(
    "Uebrig: %d Settings, %d Labels ohne Besitzer\n",
    count(orphanOwners('settings', $installation)),
    count(orphanOwners('labels', $installation))
);
