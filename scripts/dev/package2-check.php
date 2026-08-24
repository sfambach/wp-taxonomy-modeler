<?php declare(strict_types=1);
/**
 * Package 2 acceptance check — the tree, against a real database.
 *
 *     php scripts/dev/package2-check.php [path/to/wordpress]
 *
 * The owner's test: hang a node under another, change the order, reload — and the tree looks
 * the way it was left.
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

use Taxmod\Core\Exception\CannotRestore;
use Taxmod\Core\Exception\ImpossibleMove;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Tree;
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
$tree   = new Tree($nodes, $edges);

$treeRoot = $framework->root();
$trash    = $framework->trash();

echo "\n== 1. Every node except the root has an inheritance edge ==\n";
$orphans = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' n
     LEFT JOIN ' . Schema::table('relations') . ' r ON r.to_id = n.id AND r.kind = "inheritance"
     WHERE n.path LIKE "%.%" AND r.id IS NULL'
);
check('no node is left without one', $orphans === 0, "$orphans without an edge");

echo "\n== 2. The path can be rebuilt from the edges alone ==\n";
// ⚠️ D-014: the path is derived, rebuildable, never a second truth. If this drifts, the tree
// and its shortcut disagree and every descendant query is quietly wrong.
$parentOf = [];
foreach ($wpdb->get_results('SELECT from_id, to_id FROM ' . Schema::table('relations') . ' WHERE kind = "inheritance"', ARRAY_A) as $r) {
    $parentOf[(int) $r['to_id']] = (int) $r['from_id'];
}
$wrong = [];
foreach ($wpdb->get_results('SELECT id, path, name FROM ' . Schema::table('nodes'), ARRAY_A) as $r) {
    $chain = [(int) $r['id']];
    $walk  = (int) $r['id'];
    while (isset($parentOf[$walk])) {
        $walk = $parentOf[$walk];
        array_unshift($chain, $walk);
    }
    if (implode('.', $chain) !== $r['path']) {
        $wrong[] = $r['name'] . ': ' . $r['path'] . ' vs ' . implode('.', $chain);
    }
}
check('every stored path matches the edges', $wrong === [], implode('; ', $wrong));

echo "\n== 3. Creating, hanging and ordering ==\n";
$a = $editor->createNode('__check A', $treeRoot->id);
$b = $editor->createNode('__check B', $treeRoot->id);
$x = $editor->createNode('__check X', $a->id);

$edge = $edges->inheritanceEdgeTo($x->id);
check('the child got an edge from its parent', $edge !== null && $edge->fromId === $a->id);
check('the edge has an id of its own', $edge !== null && $edge->id !== $x->id, 'edge #' . ($edge?->id ?? 0));
check('the path follows the parent', $x->path === $a->path . '.' . $x->id, $x->path);

$deep  = $editor->createNode('__check deep', $x->id);
$moved = $editor->move($x->id, $b->id);
check('moving repoints the edge', $edges->inheritanceEdgeTo($x->id)->fromId === $b->id);
check('moving rewrites the path', $moved->path === $b->path . '.' . $x->id, $moved->path);
check('the subtree came along', $nodes->byId($deep->id)->path === $moved->path . '.' . $deep->id, $nodes->byId($deep->id)->path);

echo "\n== 4. Order lives on the edge ==\n";
$one = $editor->createNode('__check 1', $a->id);
$two = $editor->createNode('__check 2', $a->id);
$names = static fn (): array => array_map(
    static fn (Node $n): string => $n->name,
    $editor->childrenOf($a->id)
);
check('children come in creation order', $names() === ['__check 1', '__check 2'], implode(', ', $names()));
$editor->moveUp($two->id);
check('moving up swaps them', $names() === ['__check 2', '__check 1'], implode(', ', $names()));
$editor->moveDown($two->id);
check('moving down swaps them back', $names() === ['__check 1', '__check 2'], implode(', ', $names()));

echo "\n== 5. Refusals ==\n";
try { $editor->move($a->id, $one->id); check('a node cannot move into its own subtree', false); }
catch (ImpossibleMove $e) { check('a node cannot move into its own subtree', true); }

echo "\n== 6. Drawing the tree ==\n";
$rows = $tree->rowsUnder($treeRoot, [$trash->id]);
$drawn = [];
foreach ($rows as $row) { $drawn[$row['node']->id] = $row['depth']; }
check('the moved node is drawn one level under its new parent', ($drawn[$x->id] ?? -1) === ($drawn[$b->id] ?? -1) + 1);
check('the deep node is drawn two levels under it', ($drawn[$deep->id] ?? -1) === ($drawn[$b->id] ?? -1) + 2);
check('the trash is left out when asked', ! isset($drawn[$trash->id]));

echo "\n== 7. Parking is a move, and it cascades ==\n";
$editor->moveToTrash($x->id);
check('the edge now points at the trash', $edges->inheritanceEdgeTo($x->id)->fromId === $trash->id);
check('the subtree is parked with it', str_starts_with($nodes->byId($deep->id)->path, $trash->path . '.'));

echo "\n== 8. Deleting only a node promotes its children ==\n";
$g = $editor->createNode('__check G', $treeRoot->id);
$m = $editor->createNode('__check M', $g->id);
$k1 = $editor->createNode('__check K1', $m->id);
$k2 = $editor->createNode('__check K2', $m->id);
$deepK = $editor->createNode('__check K1a', $k1->id);

$editor->moveToTrashPromotingChildren($m->id);

check('the child hangs on the grandparent', $edges->inheritanceEdgeTo($k1->id)->fromId === $g->id);
check('and its path says so', $nodes->byId($k1->id)->path === $g->path . '.' . $k1->id, $nodes->byId($k1->id)->path);
check('the second child came too', $edges->inheritanceEdgeTo($k2->id)->fromId === $g->id);
check('their own subtrees came along', $nodes->byId($deepK->id)->path === $nodes->byId($k1->id)->path . '.' . $deepK->id, $nodes->byId($deepK->id)->path);
check('the node itself is parked, empty', str_starts_with($nodes->byId($m->id)->path, $trash->path . '.') && $editor->childrenOf($m->id) === []);
check('their order among themselves survived',
    array_map(static fn (Node $n): string => $n->name, $editor->childrenOf($g->id)) === ['__check K1', '__check K2'],
    implode(', ', array_map(static fn (Node $n): string => $n->name, $editor->childrenOf($g->id))));

echo "\n== 9. Collapsing ==\n";
$open      = $tree->rowsUnder($treeRoot, [$trash->id]);
$collapsed = $tree->rowsUnder($treeRoot, [$trash->id], [$g->id]);
$idsIn = static fn (array $rows): array => array_map(static fn (array $r): int => $r['node']->id, $rows);
check('the collapsed node is still shown', in_array($g->id, $idsIn($collapsed), true));
check('its children are not', ! in_array($k1->id, $idsIn($collapsed), true));
check('and they were, when open', in_array($k1->id, $idsIn($open), true));
check('nothing else disappeared', count($open) - count($collapsed) === 3, (count($open) - count($collapsed)) . ' rows fewer');
$gRow = array_values(array_filter($collapsed, static fn (array $r): bool => $r['node']->id === $g->id))[0];
check('the row says it has children and is collapsed', $gRow['hasChildren'] && $gRow['collapsed']);

echo "\n== 10. Restoring ==\n";
$r1 = $editor->createNode('__check R', $treeRoot->id);
$r2 = $editor->createNode('__check R child', $r1->id);
$wasR1 = $r1->path;
$wasR2 = $nodes->byId($r2->id)->path;

$editor->moveToTrash($r1->id);
check('it is in the trash', str_starts_with($nodes->byId($r1->id)->path, $trash->path . '.'));

$editor->restore($r1->id);
check('restored to exactly where it was', $nodes->byId($r1->id)->path === $wasR1, $nodes->byId($r1->id)->path);
check('its subtree came back too', $nodes->byId($r2->id)->path === $wasR2, $nodes->byId($r2->id)->path);
check('the edge points at the old parent again', $edges->inheritanceEdgeTo($r1->id)->fromId === $treeRoot->id);

try { $editor->restore($r2->id); check('a node that was never parked is refused', false); }
catch (CannotRestore $e) { check('a node that was never parked is refused', true); }

echo "\n== 11. Collapsing behaves the same inside the trash ==\n";
// ⚠️ Found by the owner: the first version passed the collapsed set to the tree table and not
// to the trash table — the same function, two call sites, one forgotten. It is the argument
// for R18 in miniature, so it is guarded here rather than merely fixed.
$editor->moveToTrash($r1->id);
$openTrash      = $tree->rowsUnder($trash, [], []);
$collapsedTrash = $tree->rowsUnder($trash, [], [$r1->id]);
$idsInTrash = static fn (array $rows): array => array_map(static fn (array $r): int => $r['node']->id, $rows);
check('the parked node is shown when collapsed', in_array($r1->id, $idsInTrash($collapsedTrash), true));
check('its child is not', ! in_array($r2->id, $idsInTrash($collapsedTrash), true));
check('and it was, when open', in_array($r2->id, $idsInTrash($openTrash), true));

echo "\n== 12. The check cleans up after itself ==\n";
foreach ([$x->id, $a->id, $b->id, $g->id, $m->id, $k1->id, $k2->id, $r1->id, $r2->id] as $scratch) {
    $node = $nodes->find($scratch);
    if ($node !== null) { $nodes->purgeSubtree($node); }
}
$wpdb->query('DELETE FROM ' . Schema::table('changelog') . ' WHERE after_state LIKE "%__check%"');
$left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' WHERE name LIKE "__check%"');
check('scratch nodes are gone', $left === 0, "$left left");
$dangling = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('relations') . ' r
     LEFT JOIN ' . Schema::table('nodes') . ' n ON n.id = r.to_id
     WHERE n.id IS NULL'
);
check('purging took the edges with it', $dangling === 0, "$dangling dangling edges");

echo "\n---- $ok passed, $bad failed ----\n";
exit($bad === 0 ? 0 : 1);
