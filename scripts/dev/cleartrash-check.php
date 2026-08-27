<?php declare(strict_types=1);

/**
 * Does clearing the trash remove the thing and keep the record that it existed?
 *
 * The owner asked for the act as a button — *build a button behind the Trash label, «clear», so we can
 * tidy up* — and [row 10](../../docs/NewConcept/97-implementation-plan.md#the-working-list) had the
 * shape written down since the hand-run that emptied 42 parked nodes.
 *
 * ⚠️ **A boundary check, because every assertion here is about rows.** Whether a node *resolves* proves
 * nothing: the question is what is left in five tables afterwards, and only a real database answers it.
 *
 * ⚠️ **It builds its own rubbish and parks it**, on the rule [row 23](../../docs/NewConcept/97-implementation-plan.md#the-working-list)
 * states from the reading side and today's damage taught from the writing side: *a checker must not
 * touch data a person is editing.* **Nothing here goes near the owner's own trash** — it makes two
 * nodes, an attribute, a setting and a label, parks them, clears, and then asserts.
 *
 * Usage: php scripts/dev/cleartrash-check.php C:/Devel/Wordpress
 *
 * @see docs/NewConcept/90-decision-log.md
 */

$wordpress = $argv[1] ?? 'C:/Devel/Wordpress';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
use Taxmod\WordPress\SystemClock;

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;

        echo "  ok   {$what}\n";

        return;
    }

    $failed++;

    echo "  FAIL {$what}" . ($detail === '' ? '' : " — {$detail}") . "\n";
}

$ids       = new TableIdentityAllocator();
$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$rows      = new WpdbSettingRepository();
$labelRows = new WpdbLabelRepository();
$framework = new SeededFrameworkNodes($nodes, $edges, $ids, $log);
$settings  = new Settings($rows, $nodes, $framework, $log);

$editor = new ModelEditor($nodes, $edges, $ids, $framework, $log, $rows, $labelRows, $settings);

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

/** How many rows a table holds for these owners — the only question that matters here. */
function rowsFor(string $table, array $ownerIds, string $column = 'owner_id'): int
{
    global $wpdb, $p;

    if ($ownerIds === []) {
        return 0;
    }

    $in = implode(',', array_map('intval', $ownerIds));

    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table} WHERE {$column} IN ({$in})");
}

echo "\n== something to throw away ==\n";

$root  = $framework->rootOf(Branch::Compositions);
$doomed = $editor->createNode('__ct doomed', $root->id);
$target = $editor->createNode('__ct target', $root->id);
$edge   = $editor->addField($doomed->id, $target->id, 'feld');

$settings->put($settings->chainFor($doomed), SettingKey::RangeMax->value, TypedValue::ofInt(77));
// ⚠️ *The role is a **seeded node** ([D-196](../../docs/NewConcept/90-decision-log.md)), so `role_id`
// is a real id and not an enum value. Taking one that exists keeps the foreign key honest — inventing
// a number here would test the check's imagination rather than the act.*
$roleId = (int) $wpdb->get_var("SELECT role_id FROM {$p}labels LIMIT 1");

if ($roleId === 0) {
    echo "  Keine Label-Rolle im Baum — die Pruefung kann nichts ueber Labels sagen.\n";

    exit(1);
}

$labelRows->put(new Label($doomed->id, '', $roleId, '', 'de_DE', 'Weg damit'));

$owners = [$doomed->id, $edge->id];

check('the node holds settings', rowsFor('settings', $owners) > 0, (string) rowsFor('settings', $owners));
check('and a label', rowsFor('labels', $owners) > 0, (string) rowsFor('labels', $owners));

// ⚠️ *Only the doomed one is parked. `__ct target` stays in the model — which is what makes the last
// assertion mean something: a purge that took a living node's attribute target would be a disaster,
// and this is the shape that would catch it.*
$editor->moveToTrash($doomed->id);

$trash  = $framework->trash();
$parked = count($nodes->subtreeOf($trash));

check('it sits in the trash', $parked > 0, (string) $parked);

echo "\n== clearing it ==\n";

$gone = $editor->clearTrash();

printf("  %d Knoten, %d Kanten, %d Settings, %d Labels\n", $gone['nodes'], $gone['edges'], $gone['settings'], $gone['labels']);

check('the trash is empty', count($nodes->subtreeOf($trash)) === 0, (string) count($nodes->subtreeOf($trash)));
check('the node is gone', $nodes->find($doomed->id) === null);
check('its settings went with it', rowsFor('settings', $owners) === 0, (string) rowsFor('settings', $owners));
check('its labels went with it', rowsFor('labels', $owners) === 0, (string) rowsFor('labels', $owners));
check('its edges went with it', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}relations WHERE id = {$edge->id}") === 0);

echo "\n== and what must survive ==\n";

// ⚠️ **[D-340](../../docs/NewConcept/90-decision-log.md): an id once handed out is never reissued.**
// *So the identity row stays — and this is the assertion that would fail if somebody ever «tidied up»
// the identities table, which is the one tidy-up that cannot be undone.*
check(
    'the identity is kept, so the id can never be handed out again',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}identities WHERE id = {$doomed->id}") === 1
);

// ⚠️ **[D-065](../../docs/NewConcept/90-decision-log.md): the changelog outlives what it refers to.**
check(
    'its history is kept',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}changelog WHERE owner_id = {$doomed->id}") > 0
);

check(
    'and the act itself is journalled',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}changelog WHERE owner_id = {$trash->id} AND what = 'trash cleared'") > 0
);

// ⚠️ **The counter-check that gives the whole file its meaning**: the attribute's **target** was never
// parked, so it must still be there. *Without this, a purge that followed edges outward would pass
// every assertion above and quietly delete half the model.*
check('a living node the rubbish pointed at is untouched', $nodes->find($target->id) !== null);

echo "\n== tidying up ==\n";

// ⚠️ **By name, not only this run's ids — the same self-healing `package7-check` has.** *Two runs of
// mine died before this line while the act was being got right, and the third then reported **their**
// leftovers as its own failure. A cleanup that only knows the ids of the run it is in blames the wrong
// run.*
$mine  = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}nodes WHERE name LIKE '\\_\\_ct %'"));
$in    = $mine === [] ? (string) $target->id : implode(',', $mine);
$stray = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}relations WHERE from_id IN ({$in}) OR to_id IN ({$in})"));
$own   = $stray === [] ? $in : $in . ',' . implode(',', $stray);

$wpdb->query("DELETE FROM {$p}settings WHERE owner_id IN ({$own})");
$wpdb->query("DELETE FROM {$p}labels WHERE owner_id IN ({$own})");

if ($stray !== []) {
    $wpdb->query("DELETE FROM {$p}relations WHERE id IN (" . implode(',', $stray) . ')');
}

$wpdb->query("DELETE FROM {$p}nodes WHERE id IN ({$in})");

check(
    'the check leaves nothing behind',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes WHERE name LIKE '\\_\\_ct %'") === 0
);

printf("\n%d ok, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
