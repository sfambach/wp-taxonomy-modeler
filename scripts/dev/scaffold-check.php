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
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\BaseScaffold;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
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
$scaffold  = new BaseScaffold($editor, $framework);

$dataTypes = $framework->rootOf(Branch::DataTypes);

echo "\n== 1. Every simple type is there ==\n";
$scaffold->import();

$present = [];

foreach ($editor->childrenOf($dataTypes->id) as $child) {
    $present[$child->name] = $child;
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
$edge  = $editor->addAttribute($thing->id, $present['int']->id, '__sc count');
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
$edges->purgeEdgesTouching($thing->id);
$nodes->purgeSubtree($nodes->byId($thing->id));
$wpdb->query('DELETE FROM ' . Schema::table('relations') . ' WHERE name LIKE "__sc%"');
$wpdb->query('DELETE FROM ' . Schema::table('changelog') . ' WHERE after_state LIKE "%__sc%"');
$left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' WHERE name LIKE "__sc%"');
check('scratch nodes are gone', $left === 0, "$left left");

$colourNow = $colour === null ? null : $nodes->byId($colour->id);
check('and color is back under Data Types',
    $colourNow === null || str_starts_with($colourNow->path, $dataTypes->path . '.'),
    $colourNow?->path ?? '—');

echo "\n---- $ok passed, $bad failed ----\n";
exit($bad === 0 ? 0 : 1);
