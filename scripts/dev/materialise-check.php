<?php declare(strict_types=1);

/**
 * Do a new node and a new attribute get their **own** settings rows?
 *
 * [D-423](../../docs/NewConcept/90-decision-log.md), in the owner's three rules: *on inheriting, the
 * settings are written into the inheriting node, where they can be changed*; *when an attribute is
 * created, all settings of the node are taken into the attribute*; and `reset` *fetches it from the
 * next higher node's setting* instead of forgetting the row.
 *
 * ⚠️ **A boundary check because the question is about rows.** Whether a value *resolves* proves
 * nothing here — it resolved before this decision too, through the chain. **What has to be shown is
 * that the row exists at the owner**, and that only a real table can answer.
 *
 * ⚠️ **It builds the editor *with* the materialiser on purpose.** The argument is optional, so every
 * other check builds the editor without one and would pass whatever this decision did or did not do.
 *
 * Usage: php scripts/dev/materialise-check.php C:/Devel/Wordpress
 *
 * @see docs/NewConcept/90-decision-log.md
 */

$wordpress = $argv[1] ?? 'C:/Devel/Wordpress';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Branch;
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
$framework = new SeededFrameworkNodes($nodes, $edges, $ids, $log);
$settings  = new Settings($rows, $nodes, $framework, $log);

$editor = new ModelEditor(
    $nodes,
    $edges,
    $ids,
    $framework,
    $log,
    $rows,
    new WpdbLabelRepository(),
    $settings
);

global $wpdb;

$table = $wpdb->prefix . 'taxmod_settings';

/** How many rows this owner holds in its own name — the only question that matters here. */
function ownRows(int $ownerId): int
{
    global $wpdb, $table;

    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE owner_id = %d", $ownerId));
}

function ownValue(int $ownerId, string $key): ?string
{
    global $wpdb, $table;

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT value_int, value_text FROM {$table} WHERE owner_id = %d AND setting_key = %s AND path = '' LIMIT 1",
        $ownerId,
        $key
    ), ARRAY_A);

    if ($row === null) {
        return null;
    }

    return $row['value_text'] ?? (string) $row['value_int'];
}

$made = [];

echo "\n== a parent with something to say ==\n";

$root   = $framework->rootOf(Branch::Compositions);
$parent = $editor->createNode('__mat_parent', $root->id);
$made[] = $parent->id;

// Something unmistakably the parent's own, so a copy cannot be confused with an installation default.
$settings->put($settings->chainFor($parent), SettingKey::Max->value, TypedValue::ofInt(4711));
$settings->put($settings->chainFor($parent), SettingKey::ReadOnly->value, TypedValue::ofBool(true));

check('the parent holds its own rows', ownRows($parent->id) >= 2, (string) ownRows($parent->id));

echo "\n== rule 1 · a child is furnished on creation ==\n";

$child  = $editor->createNode('__mat_child', $parent->id);
$made[] = $child->id;

check('the child has rows of its own', ownRows($child->id) > 0, (string) ownRows($child->id));
check('  · range_max came along', ownValue($child->id, SettingKey::Max->value) === '4711', ownValue($child->id, SettingKey::Max->value) ?? '—');
check('  · read_only came along', ownValue($child->id, SettingKey::ReadOnly->value) === '1', ownValue($child->id, SettingKey::ReadOnly->value) ?? '—');

// ⚠️ **The switches the installation declares travel too** (D-401/D-404) — otherwise «every setting
// has a row» would be true of the edited ones and false of the defaulted ones.
//
// ⚠️ *`hide` left this list on 2026-08-28: it is a **column** on the identity now and not a setting
// at all ([D-457]), so there is no row for it to materialise and no declared default on the
// installation identity. `persistent` and `read_only` stay settings and stay in the list ([D-460],
// [D-461]) — only `hide` had a second meaning nobody asked for.*
foreach ([SettingKey::Persistent] as $key) {
    check("  · {$key->value} is a row rather than a resolution", ownValue($child->id, $key->value) !== null, 'missing');
}

// ⚠️ **The counter-check that gives the block its meaning**: `multiplicity` is edge-only, so it must
// **not** land on a node. *Without this, a materialiser that copied everything blindly would pass.*
check(
    'multiplicity did not land on the node',
    ownValue($child->id, SettingKey::Multiplicity->value) === null,
    ownValue($child->id, SettingKey::Multiplicity->value) ?? ''
);

echo "\n== the child can now be changed without touching the parent ==\n";

$settings->put($settings->chainFor($child), SettingKey::Max->value, TypedValue::ofInt(20));

check('the child says 20', ownValue($child->id, SettingKey::Max->value) === '20', ownValue($child->id, SettingKey::Max->value) ?? '—');
check('the parent still says 4711', ownValue($parent->id, SettingKey::Max->value) === '4711', ownValue($parent->id, SettingKey::Max->value) ?? '—');

echo "\n== rule 3 · an attribute is furnished from its target ==\n";

$type   = $editor->createNode('__mat_type', $root->id);
$made[] = $type->id;

$settings->put($settings->chainFor($type), SettingKey::Min->value, TypedValue::ofInt(7));

$holder = $editor->createNode('__mat_holder', $root->id);
$made[] = $holder->id;

$edge = $editor->addField($holder->id, $type->id, 'feld');

check('the attribute has rows of its own', ownRows($edge->id) > 0, (string) ownRows($edge->id));
check('  · range_min came from the target, not the owner', ownValue($edge->id, SettingKey::Min->value) === '7', ownValue($edge->id, SettingKey::Min->value) ?? '—');

// ⚠️ *The owner had `range_max = 4711`; the target did not. If the attribute carried it, the source
// would be the owner and [D-423](../../docs/NewConcept/90-decision-log.md)'s «from the target» would
// be wrong in the code however it reads in the log.*
check(
    '  · and nothing came from the holder',
    ownValue($edge->id, SettingKey::Max->value) === null || ownValue($edge->id, SettingKey::Max->value) !== '4711',
    ownValue($edge->id, SettingKey::Max->value) ?? '—'
);

echo "\n== reset pulls instead of forgetting ==\n";

$settings->put($settings->chainFor($child), SettingKey::Max->value, TypedValue::ofInt(99));

$found = $settings->pull($settings->chainFor($child), SettingKey::Max->value);

check('the pull found something above', $found);
check('and the child is back to the parent\'s 4711', ownValue($child->id, SettingKey::Max->value) === '4711', ownValue($child->id, SettingKey::Max->value) ?? '—');

// ⚠️ **A row still exists afterwards, which is the whole difference from the old `reset`.** *Forgetting
// it would leave nothing, because a materialised model has no walk left to fall through.*
check('and the row is still there rather than gone', ownValue($child->id, SettingKey::Max->value) !== null);

// ⚠️ *Nothing above is an answer* — the owner: *if there is nothing there, then they were its own
// settings.* A key nobody above ever set must leave the row alone and say so.
$settings->put($settings->chainFor($child), 'x_own_only', TypedValue::ofText('mine'));

$none = $settings->pull($settings->chainFor($child), 'x_own_only');

check('a pull with nothing above reports false', $none === false);
check('and it left the value alone', ownValue($child->id, 'x_own_only') === 'mine', ownValue($child->id, 'x_own_only') ?? '—');

echo "\n== rows at a path travel too, because a child inherits the same edge ids ==\n";

// ⚠️ **The owner corrected me into this.** I shipped materialising with the empty path only, worried a
// child's rows would point at *edges chosen for its parent*. He: ***I do not understand — nonsense?***
// A `path` is a chain of **edge ids**, and a child does not get copies of its parent's attribute edges
// — it inherits them, the same ids. So the address is the child's own address.
$holder2 = $editor->createNode('__mat_holder2', $root->id);
$made[]  = $holder2->id;

$pathEdge = $editor->addField($holder2->id, $type->id, 'adressiert');

// The parent's answer *for that one attribute* — path rows are what D-413 added.
$settings->put($settings->chainFor($holder2), SettingKey::DefaultValue->value, TypedValue::ofInt(31), (string) $pathEdge->id);

$heir   = $editor->createNode('__mat_heir', $holder2->id);
$made[] = $heir->id;

$atPath = $wpdb->get_var($wpdb->prepare(
    "SELECT value_int FROM {$table} WHERE owner_id = %d AND setting_key = %s AND path = %s LIMIT 1",
    $heir->id,
    SettingKey::DefaultValue->value,
    (string) $pathEdge->id
));

check('the heir carries the parent\'s answer for that attribute', (string) $atPath === '31', var_export($atPath, true));

// ⚠️ **The counter-check**: the address must be the **same** edge id, not the empty path. *Copying a
// path row into the empty path would look like a value the node holds about itself.*
$atEmpty = $wpdb->get_var($wpdb->prepare(
    "SELECT value_int FROM {$table} WHERE owner_id = %d AND setting_key = %s AND path = '' LIMIT 1",
    $heir->id,
    SettingKey::DefaultValue->value
));

check('and it did not collapse into the empty path', $atEmpty === null || (string) $atEmpty !== '31', var_export($atEmpty, true));

// ⚠️ *And the edge it names really is one the heir inherits — otherwise the address would be valid
// only by accident.*
$inherited = false;

foreach ($editor->fieldsOf($heir->id) as $seen) {
    if ($seen->id === $pathEdge->id) {
        $inherited = true;
    }
}

check('the edge the path names is one the heir inherits', $inherited);

echo "\n== tidying up ==\n";

// ⚠️ **Everything that hangs off them, not just the nodes** — leaving the settings and the
// inheritance edges behind is how 720 orphaned rows came about (list row 28).
$in  = implode(',', array_map('intval', $made));
$all = array_map('intval', $wpdb->get_col("SELECT id FROM {$wpdb->prefix}taxmod_relations WHERE from_id IN ({$in}) OR to_id IN ({$in})"));
$own = $all === [] ? $in : $in . ',' . implode(',', $all);

$wpdb->query("DELETE FROM {$table} WHERE owner_id IN ({$own})");
$wpdb->query("DELETE FROM {$wpdb->prefix}taxmod_labels WHERE owner_id IN ({$own})");

if ($all !== []) {
    $wpdb->query("DELETE FROM {$wpdb->prefix}taxmod_relations WHERE id IN (" . implode(',', $all) . ')');
}

$wpdb->query("DELETE FROM {$wpdb->prefix}taxmod_nodes WHERE id IN ({$in})");

$left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}taxmod_nodes WHERE name LIKE '\\_\\_mat\\_%'");

check('the check leaves nothing behind', $left === 0, "{$left} left");

printf("\n%d ok, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
