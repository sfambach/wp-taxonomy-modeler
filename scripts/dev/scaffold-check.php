<?php declare(strict_types=1);
/**
 * Base scaffold acceptance check — the simple data types, against a real database.
 *
 *     php scripts/dev/scaffold-check.php [path/to/wordpress]
 *
 * The owner's test: the simple types are there to build models with, and a type he throws away
 * stays thrown away.
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php. Pass the WordPress folder as the first argument.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SettingShape;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
use Taxmod\WordPress\Persistence\BaseScaffold;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;
$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;

    if ($passed) {
        $ok++;
        echo "  OK   $what\n";

        return;
    }

    $bad++;
    echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
}

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), new WpdbChangelog(new SystemClock()));
$editor    = new ModelEditor($nodes, $edges, new TableIdentityAllocator(), $framework, new WpdbChangelog(new SystemClock()));
$types     = new SeededTypeNodes($nodes, $framework);
$scaffold  = new BaseScaffold($editor, $framework, $types);

$dataTypes = $framework->rootOf(Branch::DataTypes);
$settings  = new Settings(new WpdbSettingRepository(), $nodes, $framework);

echo "\n== 1. Every simple type is there ==\n";
$scaffold->import();

$present = [];

// ⚠️ **Found by the id the seed wrote down, not by the node's name** ([D-510](../../docs/NewConcept/90-decision-log.md)).
// *This is the line that has to change, not only the code under test: keying by name here would leave
// the check green whatever the binding did — and a check that is green for the wrong reason is worse
// than none. The nodes are called `Integer` and `Text`; the enum value stayed `int` and `text`, so
// every `$present['int']` below keeps meaning what it always meant.*
$byId = [];

foreach ($editor->childrenOf($dataTypes->id) as $child) {
    $byId[$child->id] = $child;
}

foreach (SimpleType::cases() as $type) {
    $id = $types->nodeId($type);

    if ($id !== null && isset($byId[$id])) {
        $present[$type->value] = $byId[$id];
    }
}

foreach (SimpleType::cases() as $type) {
    check($type->value, isset($present[$type->value]));
}

echo "\n== 2. They sit in the Data Types branch, so the kind follows by itself ==\n";
$text = $present['text'] ?? null;
check('text is under Data Types', $text !== null && $framework->branchOf($text) === Branch::DataTypes);
check('and a Data Types target is reached by composition',
    Branch::DataTypes->relationKind() === RelationKind::Composition);
check('and has no records of its own — the value sits in the holder\'s record',
    Branch::DataTypes->holdsData() === false);

echo "\n== 3. An attribute pointing at one gets its kind without being asked ==\n";
$thing = $editor->createNode('__sc thing', $framework->rootOf(Branch::Model)->id);
$edge  = $editor->addField($thing->id, $present['int']->id, '__sc count');
check('the kind is composition', $edge->kind === RelationKind::Composition, $edge->kind->value);

echo "\n== 4. Imported once, then hands off (D-119) ==\n";
$before = (int) get_option(BaseScaffold::OPTION, 0);
check('the scaffold records that it was delivered', $before >= 1, "option = $before");

$colour = $present['color'] ?? null;

if ($colour !== null) {
    $editor->moveToTrash($colour->id);
    $again = $scaffold->importOnce();
    check('a type the owner threw away is not put back', $again === [], implode(', ', $again));

    $parked = $nodes->byId($colour->id);
    check('and it is still in the trash where he put it',
        str_starts_with($parked->path, $framework->trash()->path . '.'), $parked->path);

    // Put it back so the check leaves the model as it found it.
    $editor->restore($colour->id);
}

echo "\n== 5. The check cleans up after itself ==\n";

// ⚠️ **By name and not only by this run's id** — the same self-healing `package7-check` already has,
// and it is here because two runs of mine died before this line on 2026-08-26 and left a `__sc thing`
// each. *A cleanup that only knows the ids of the run it is in reports the **previous** run's litter
// as its own failure, which is the least useful thing a check can say.*
foreach ($wpdb->get_col('SELECT id FROM ' . Schema::table('nodes') . ' WHERE name LIKE "\\_\\_sc%" ORDER BY LENGTH(path) DESC') as $stale) {
    $node = $nodes->find((int) $stale);

    if ($node !== null) {
        $edges->purgeEdgesTouching($node->id);
        $nodes->purgeSubtree($node);
    }
}

// ⚠️ *The loop above already takes this run's node — it matches the same pattern. Looking it up
// again unguarded is how the first version of this cleanup threw `NodeNotFound` on its own success.*
$still = $nodes->find($thing->id);

if ($still !== null) {
    $edges->purgeEdgesTouching($still->id);
    $nodes->purgeSubtree($still);
}
$wpdb->query('DELETE FROM ' . Schema::table('relations') . ' WHERE name LIKE "__sc%"');
$wpdb->query('DELETE FROM ' . Schema::table('changelog') . ' WHERE after_state LIKE "%__sc%"');
$left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' WHERE name LIKE "__sc%"');
check('scratch nodes are gone', $left === 0, "$left left");

$colourNow = $colour === null ? null : $nodes->byId($colour->id);
check('and color is back under Data Types',
    $colourNow === null || str_starts_with($colourNow->path, $dataTypes->path . '.'),
    $colourNow?->path ?? '—');

// ⚠️ **[D-401](../../docs/NewConcept/90-decision-log.md): every switch has one declared default, and
// it lives on the key.** Before this, three keys had three homes — `persistent` was written onto the
// installation identity by hand, `hide` and `read_only` were invented inside readers as `?? false`, and
// that is how the data layer came to read `persistent` as **on** while the switch drew it **off**.
echo "\n== 6. Every switch declares its default in one place (D-401) ==\n";

$installation = $framework->installationId();
$declared     = $settings->resolve([$installation]);

foreach (SettingKey::cases() as $key) {
    if ($key->shape() !== SettingShape::Switch) {
        continue;
    }

    $stored = ($declared[$key->value] ?? null)?->value->asBool();

    check(
        "«{$key->value}» is declared on the installation identity",
        $stored !== null,
        $stored === null ? 'no row' : 'ok'
    );

    // ⚠️ *The row and the key must agree.* Two homes that happen to hold the same value today is
    // exactly the state this decision ended — the failure only shows when one of them is changed.
    check(
        "  · and it agrees with the key",
        $stored === $key->defaultSwitch(),
        var_export($stored, true) . ' vs ' . var_export($key->defaultSwitch(), true)
    );
}

// ⚠️ **The counter-check that gives the block its meaning**: a key that is *not* a switch must have
// **no** boolean default to hand out. *Without this, a `declaredDefault()` that answered `false` for
// everything would pass every assertion above.*
$threw = false;

try {
    SettingKey::Min->defaultSwitch();
} catch (\LogicException) {
    $threw = true;
}

check('asking a range for its switch default is refused', $threw);

// ⚠️ **And the point of the whole thing, measured at a node rather than at the installation**: a node
// with no row of its own resolves all three, because the installation is the first link of the chain
// ([D-079](../../docs/NewConcept/90-decision-log.md)) — so no reader ever needs a fallback.
$probe = $editor->childrenOf($dataTypes->id)[0] ?? null;

if ($probe !== null) {
    $at = $settings->resolve($settings->chainFor($probe));

    foreach (SettingKey::cases() as $key) {
        if ($key->shape() !== SettingShape::Switch) {
            continue;
        }

        check(
            "«{$probe->name}» resolves «{$key->value}» without a fallback",
            isset($at[$key->value]),
            'nothing resolved'
        );
    }
}

echo "\n---- $ok passed, $bad failed ----\n";
exit($bad === 0 ? 0 : 1);
