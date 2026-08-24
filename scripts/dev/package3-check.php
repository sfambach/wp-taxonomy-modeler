<?php declare(strict_types=1);
/**
 * Package 3 acceptance check — attributes as relations, against a real database.
 *
 *     php scripts/dev/package3-check.php [path/to/wordpress]
 *
 * The owner's test: give a node an attribute, and the relation kind appears by itself.
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

use Taxmod\Core\Exception\NotAPossibleTarget;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Service\ModelEditor;
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
    if ($passed) { $ok++; echo "  OK   $what\n"; }
    else { $bad++; echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

Schema::install();
update_option(Schema::VERSION_OPTION, Schema::VERSION, true);

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$ids       = new TableIdentityAllocator();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, $ids, $log);
$framework->seed();

$editor = new ModelEditor($nodes, $edges, $ids, $framework, $log);

echo "\n== 1. The branches exist and are protected ==\n";
foreach (Branch::cases() as $branch) {
    $node = $framework->rootOf($branch);
    check("{$branch->value} → «{$node->name}»", $node->id > 0 && $framework->isProtected($node));
}

echo "\n== 2. The branch decides the kind, and nobody chooses it ==\n";
$order    = $editor->createNode('__p3 Order', $framework->rootOf(Branch::Model)->id);
$supplier = $editor->createNode('__p3 Supplier', $framework->rootOf(Branch::Model)->id);
$line     = $editor->createNode('__p3 Line', $framework->rootOf(Branch::Compositions)->id);
$text     = $editor->createNode('__p3 Text', $framework->rootOf(Branch::DataTypes)->id);
$gram     = $editor->createNode('__p3 Gramm', $framework->rootOf(Branch::Constants)->id);

$byModel        = $editor->addAttribute($order->id, $supplier->id, 'supplied by');
$byComposition  = $editor->addAttribute($order->id, $line->id, 'lines');
$byDataType     = $editor->addAttribute($order->id, $text->id, 'note');
$byConstant     = $editor->addAttribute($order->id, $gram->id, 'unit');

check('Model → aggregation', $byModel->kind === RelationKind::Aggregation, $byModel->kind->value);
check('Compositions → composition', $byComposition->kind === RelationKind::Composition, $byComposition->kind->value);
check('Data Types → composition', $byDataType->kind === RelationKind::Composition, $byDataType->kind->value);
check('Constants → aggregation', $byConstant->kind === RelationKind::Aggregation, $byConstant->kind->value);

echo "\n== 3. It is a row in relations, with an identity of its own ==\n";
$row = $wpdb->get_row($wpdb->prepare(
    'SELECT id, from_id, to_id, kind, name FROM ' . Schema::table('relations') . ' WHERE id = %d',
    $byModel->id
), ARRAY_A);
check('the edge is stored', $row !== null);
check('it points from the owner to the target', (int) $row['from_id'] === $order->id && (int) $row['to_id'] === $supplier->id);
check('it carries its name', $row['name'] === 'supplied by', (string) $row['name']);
check('its id came from the shared identity space', (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('identities') . ' WHERE id = %d', $byModel->id)) === 1);

echo "\n== 4. Attributes are inherited ==\n";
$part    = $editor->createNode('__p3 Part', $order->id);
$deeper  = $editor->createNode('__p3 Deeper', $part->id);
$ownEdge = $editor->addAttribute($part->id, $text->id, 'part number');

$names = static fn (int $id): array => array_map(
    static fn (Relation $r): string => $r->name,
    $editor->attributesOf($id)
);

check('the child sees what the parent declares', in_array('supplied by', $names($part->id), true), implode(', ', $names($part->id)));
check('and its own alongside', in_array('part number', $names($part->id), true));
check('a grandchild sees both too', count(array_intersect(['supplied by', 'part number'], $names($deeper->id))) === 2);
check('the parent does not see the child\'s', ! in_array('part number', $names($order->id), true));

echo "\n== 5. Refusals ==\n";
try { $editor->addAttribute($part->id, $framework->rootOf(Branch::DataTypes)->id, 'x'); check('a branch root is refused', false); }
catch (NotAPossibleTarget $e) { check('a branch root is refused', true); }

try { $editor->addAttribute($part->id, $framework->root()->id, 'x'); check('a node in no branch is refused', false); }
catch (NotAPossibleTarget $e) { check('a node in no branch is refused', true); }

$editor->moveToTrash($gram->id);
try { $editor->addAttribute($part->id, $gram->id, 'x'); check('a parked target is refused', false); }
catch (NotAPossibleTarget $e) { check('a parked target is refused', true); }

echo "\n== 6. The inheritance edge is not an attribute ==\n";
check('the tree edge stays out of the list', ! in_array('', $names($part->id), true));
check('and the child itself is not one either', count($names($order->id)) === 4, implode(', ', $names($order->id)));

echo "\n== 7. The check cleans up after itself ==\n";
foreach ([$order->id, $supplier->id, $line->id, $text->id, $gram->id] as $scratch) {
    $node = $nodes->find($scratch);
    if ($node !== null) { $edges->purgeEdgesTouching($node->id); $nodes->purgeSubtree($node); }
}
$wpdb->query('DELETE FROM ' . Schema::table('relations') . ' WHERE name LIKE "%supplied by%" OR name IN ("lines","note","unit","part number")');
$wpdb->query('DELETE FROM ' . Schema::table('changelog') . ' WHERE after_state LIKE "%__p3%"');
$left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' WHERE name LIKE "__p3%"');
check('scratch nodes are gone', $left === 0, "$left left");
$dangling = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('relations') . ' r
     LEFT JOIN ' . Schema::table('nodes') . ' n ON n.id = r.to_id
     WHERE n.id IS NULL'
);
check('no edge points at a node that is gone', $dangling === 0, "$dangling dangling");

echo "\n---- $ok passed, $bad failed ----\n";
exit($bad === 0 ? 0 : 1);
