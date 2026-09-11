<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use Taxmod\Core\Exception\ConcurrentChange;
use Taxmod\Core\Exception\CannotRestore;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\NodeIsProtected;
use Taxmod\Core\Exception\NodeNotFound;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Tests\Core\Fake\CountingIdentities;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\RecordedChanges;
use Taxmod\Core\Exception\ImpossibleMove;
use Taxmod\Core\Model\Relation;
use Taxmod\Tests\Core\Fake\InMemoryRelations;

/**
 * Making, renaming and parking — with no database and no WordPress anywhere.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class ModelEditorTest extends TestCase
{
    private InMemoryNodes $nodes;
    private InMemoryRelations $relations;
    private RecordedChanges $changes;
    private ModelEditor $editor;
    private Node $root;
    private Node $trash;

    /** Kept so a test can build a second editor over the same repositories without reissuing ids. */
    private CountingIdentities $identities;

    protected function setUp(): void
    {
        $this->relations   = new InMemoryRelations();
        $this->nodes   = new InMemoryNodes($this->relations);
        $this->changes = new RecordedChanges();
        $identities    = $this->identities = new CountingIdentities();

        $this->root  = Node::create($identities->next(), 'Root', null);
        $this->trash = Node::create($identities->next(), 'Trash', $this->root->path, $this->root->id, 0);

        $this->nodes->add($this->root);
        $this->nodes->add($this->trash);

        $this->editor = new ModelEditor(
            $this->nodes,
            $this->relations,
            new FixedFramework($this->root, $this->trash),
            $this->changes
        );
    }

    #[Test]
    public function a_created_node_is_stored_and_logged(): void
    {
        $node = $this->editor->createNode('Board', $this->root->id);

        self::assertEquals($node, $this->nodes->byId($node->id));
        self::assertSame(['created'], $this->changes->verbsFor($node->id));
    }

    #[Test]
    public function creating_under_a_node_that_does_not_exist_is_refused(): void
    {
        $this->expectException(NodeNotFound::class);

        $this->editor->createNode('Board', 999);
    }

    #[Test]
    public function renaming_writes_once_and_logs_once(): void
    {
        $node    = $this->editor->createNode('Board', $this->root->id);
        $renamed = $this->editor->rename($node->id, 'Platine');

        self::assertSame('Platine', $this->nodes->byId($node->id)->name);
        self::assertSame(2, $renamed->version);
        self::assertSame(['created', 'renamed'], $this->changes->verbsFor($node->id));
    }

    #[Test]
    public function an_unchanged_save_writes_nothing_and_logs_nothing(): void
    {
        // D-282, and the reason it matters: a history full of "renamed to the same thing"
        // is a history nobody reads.
        $node = $this->editor->createNode('Board', $this->root->id);
        $this->editor->rename($node->id, 'Board');

        self::assertSame(1, $this->nodes->byId($node->id)->version);
        self::assertSame(['created'], $this->changes->verbsFor($node->id));
    }

    #[Test]
    public function parking_moves_the_node_under_the_trash(): void
    {
        $node   = $this->editor->createNode('Board', $this->root->id);
        $parked = $this->editor->moveToTrash($node->id);

        self::assertSame($this->trash->path . '.' . $node->id, $parked->path);
        self::assertSame(['created', 'parked'], $this->changes->verbsFor($node->id));
    }

    #[Test]
    public function parking_takes_the_whole_subtree_with_it(): void
    {
        // D-127: restoring must cascade, so parking must too — otherwise a node comes back
        // without the attributes that stayed behind.
        $parent = $this->editor->createNode('Board', $this->root->id);
        $child  = $this->editor->createNode('Resistor', $parent->id);

        $parked = $this->editor->moveToTrash($parent->id);

        self::assertSame($parked->path . '.' . $child->id, $this->nodes->byId($child->id)->path);
    }

    #[Test]
    public function a_parked_node_is_still_a_node(): void
    {
        // The point of two-stage deletion (D-123): nothing that pointed at it dangles.
        $node = $this->editor->createNode('Board', $this->root->id);
        $this->editor->moveToTrash($node->id);

        self::assertNotNull($this->nodes->find($node->id));
    }

    #[Test]
    public function the_old_path_survives_in_the_changelog_and_nowhere_else(): void
    {
        // This is what a restore will read, and it is why the node needs no parked_from column.
        $node = $this->editor->createNode('Board', $this->root->id);
        $was  = $node->path;

        $this->editor->moveToTrash($node->id);

        $parked = array_values(array_filter(
            $this->changes->entries,
            static fn (array $e): bool => $e[2] === 'parked'
        ))[0];

        self::assertStringContainsString('path=' . $was, (string) $parked[3]);
    }

    #[Test]
    public function the_root_cannot_be_thrown_away(): void
    {
        $this->expectException(NodeIsProtected::class);

        $this->editor->moveToTrash($this->root->id);
    }

    #[Test]
    public function the_trash_cannot_be_thrown_away_either(): void
    {
        $this->expectException(NodeIsProtected::class);

        $this->editor->moveToTrash($this->trash->id);
    }

    #[Test]
    public function children_come_back_in_the_order_the_relations_give_them(): void
    {
        // Not alphabetical. Order is a property of the relation, so it is the order somebody put
        // them in — and it stays that way until somebody moves one.
        $parent = $this->editor->createNode('Board', $this->root->id);
        $this->editor->createNode('Resistor', $parent->id);
        $this->editor->createNode('Capacitor', $parent->id);

        $names = array_map(static fn (Node $n): string => $n->name, $this->editor->childrenOf($parent->id));

        self::assertSame(['Resistor', 'Capacitor'], $names);
    }

    #[Test]
    public function a_grandchild_is_not_a_child(): void
    {
        $parent     = $this->editor->createNode('Board', $this->root->id);
        $child      = $this->editor->createNode('Resistor', $parent->id);
        $this->editor->createNode('Tolerance', $child->id);

        self::assertCount(1, $this->editor->childrenOf($parent->id));
    }

    #[Test]
    public function two_siblings_may_carry_the_same_name(): void
    {
        // D-022: names are deliberately not unique. What tells two things apart is the id,
        // and a name is something a person chose — the model has no business refusing it.
        $parent = $this->editor->createNode('Board', $this->root->id);

        $one = $this->editor->createNode('Resistor', $parent->id);
        $two = $this->editor->createNode('Resistor', $parent->id);

        self::assertNotSame($one->id, $two->id);
        self::assertNotSame($one->path, $two->path);
        self::assertCount(2, $this->editor->childrenOf($parent->id));
    }

    #[Test]
    public function a_renamed_node_may_take_a_name_that_is_already_in_use(): void
    {
        $one = $this->editor->createNode('Resistor', $this->root->id);
        $two = $this->editor->createNode('Capacitor', $this->root->id);

        $renamed = $this->editor->rename($two->id, 'Resistor');

        self::assertSame('Resistor', $renamed->name);
        self::assertSame('Resistor', $this->nodes->byId($one->id)->name);
    }

    #[Test]
    public function creating_a_node_writes_its_place_onto_the_node(): void
    {
        // ⚠️ *Seit TASK-018 ist die Einordnung eine Spalte und keine zweite Zeile*
        // *([D-581](../../../docs/NewConcept/90-decision-log.md)). **Der Prüfsatz hat sich mit ihr
        // gedreht**: er hiess «legt genau eine Vererbungskante an» und misst jetzt dieselbe Zusage
        // an der Stelle, an der sie heute steht — nicht entschärft, sondern umgezogen (`PR-9`).*
        $node = $this->editor->createNode('Board', $this->root->id);

        self::assertSame($this->root->id, $node->parentNodeId);
        self::assertSame($this->root->id, $this->nodes->byId($node->id)->parentNodeId);
        self::assertSame(0, $this->relations->count(), 'ein neuer Knoten legt keine Kante an');
    }

    #[Test]
    public function a_new_node_lands_behind_its_siblings(): void
    {
        // ⚠️ *Hier stand «die Kante bekommt ihre eigene Identität» — eine Aussage über eine Zeile,
        // die es seit TASK-018 nicht mehr gibt. **An ihrer Stelle steht die Zusage, die `sort_order`
        // am Knoten überhaupt trägt**: das nächste Kind kommt hinter das letzte.*
        $erste  = $this->editor->createNode('Board', $this->root->id);
        $zweite = $this->editor->createNode('Resistor', $this->root->id);

        self::assertGreaterThan($erste->sortOrder, $zweite->sortOrder);
    }

    #[Test]
    public function moving_repoints_the_relation_and_rewrites_the_path(): void
    {
        $a = $this->editor->createNode('Model', $this->root->id);
        $b = $this->editor->createNode('Primitives', $this->root->id);
        $x = $this->editor->createNode('Board', $a->id);

        $moved = $this->editor->move($x->id, $b->id);

        self::assertSame($b->id, $this->nodes->byId($x->id)->parentNodeId);
        self::assertSame($b->path . '.' . $x->id, $moved->path);
        self::assertSame(['created', 'moved'], $this->changes->verbsFor($x->id));
    }

    #[Test]
    public function moving_takes_the_whole_subtree_along(): void
    {
        $a     = $this->editor->createNode('Model', $this->root->id);
        $b     = $this->editor->createNode('Primitives', $this->root->id);
        $x     = $this->editor->createNode('Board', $a->id);
        $deep  = $this->editor->createNode('Resistor', $x->id);

        $moved = $this->editor->move($x->id, $b->id);

        self::assertSame($moved->path . '.' . $deep->id, $this->nodes->byId($deep->id)->path);
    }

    #[Test]
    public function a_node_cannot_be_moved_into_its_own_subtree(): void
    {
        // The easiest mistake to make by dragging, and the hardest to notice: the subtree
        // would simply vanish from the tree it was cut out of.
        $parent = $this->editor->createNode('Board', $this->root->id);
        $child  = $this->editor->createNode('Resistor', $parent->id);

        $this->expectException(ImpossibleMove::class);

        $this->editor->move($parent->id, $child->id);
    }

    #[Test]
    public function a_node_cannot_be_moved_into_itself(): void
    {
        $node = $this->editor->createNode('Board', $this->root->id);

        $this->expectException(ImpossibleMove::class);

        $this->editor->move($node->id, $node->id);
    }

    #[Test]
    public function the_root_cannot_be_moved(): void
    {
        $target = $this->editor->createNode('Board', $this->root->id);

        $this->expectException(NodeIsProtected::class);

        $this->editor->move($this->root->id, $target->id);
    }

    #[Test]
    public function parking_is_a_move_and_the_relation_says_so(): void
    {
        $node = $this->editor->createNode('Board', $this->root->id);
        $this->editor->moveToTrash($node->id);

        self::assertSame($this->trash->id, $this->nodes->byId($node->id)->parentNodeId);
    }

    #[Test]
    public function reordering_changes_which_sibling_comes_first(): void
    {
        $parent = $this->editor->createNode('Board', $this->root->id);
        $first  = $this->editor->createNode('Resistor', $parent->id);
        $second = $this->editor->createNode('Capacitor', $parent->id);

        $this->editor->reorder($second->id, 0);
        $this->editor->reorder($first->id, 1);

        $names = array_map(static fn (Node $n): string => $n->name, $this->editor->childrenOf($parent->id));

        self::assertSame(['Capacitor', 'Resistor'], $names);
    }

    #[Test]
    public function reordering_to_where_it_already_is_writes_nothing(): void
    {
        $parent = $this->editor->createNode('Board', $this->root->id);
        $child  = $this->editor->createNode('Resistor', $parent->id);
        $before = $this->nodes->byId($child->id)->version;

        $this->editor->reorder($child->id, 0);

        self::assertSame($before, $this->nodes->byId($child->id)->version);
    }

    #[Test]
    public function every_path_can_be_rebuilt_from_the_relations_alone(): void
    {
        // ⚠️ This is the property D-014 actually asks for: path is derived, rebuildable, and
        // never a second truth. If this ever fails, the tree and its shortcut have drifted.
        $a = $this->editor->createNode('Model', $this->root->id);
        $b = $this->editor->createNode('Board', $a->id);
        $c = $this->editor->createNode('Resistor', $b->id);
        $this->editor->move($b->id, $this->root->id);

        foreach ([$this->trash, $a, $this->nodes->byId($b->id), $this->nodes->byId($c->id)] as $node) {
            self::assertSame($this->pathFromRelations($node->id), $node->path, "path of «{$node->name}»");
        }
    }

    /** Walk the relations upwards and build the path the long way round. */
    private function pathFromRelations(int $id): string
    {
        $ids = [$id];

        while (($vater = $this->nodes->byId($id)->parentNodeId) !== null) {
            $id = $vater;
            array_unshift($ids, $id);
        }

        return implode('.', $ids);
    }

    #[Test]
    public function a_node_can_be_swapped_with_the_sibling_above_it(): void
    {
        $parent = $this->editor->createNode('Board', $this->root->id);
        $this->editor->createNode('Resistor', $parent->id);
        $second = $this->editor->createNode('Capacitor', $parent->id);
        $this->editor->createNode('Diode', $parent->id);

        $this->editor->moveUp($second->id);

        self::assertSame(
            ['Capacitor', 'Resistor', 'Diode'],
            array_map(static fn (Node $n): string => $n->name, $this->editor->childrenOf($parent->id))
        );
    }

    #[Test]
    public function a_node_can_be_swapped_with_the_sibling_below_it(): void
    {
        $parent = $this->editor->createNode('Board', $this->root->id);
        $first  = $this->editor->createNode('Resistor', $parent->id);
        $this->editor->createNode('Capacitor', $parent->id);

        $this->editor->moveDown($first->id);

        self::assertSame(
            ['Capacitor', 'Resistor'],
            array_map(static fn (Node $n): string => $n->name, $this->editor->childrenOf($parent->id))
        );
    }

    #[Test]
    public function the_first_child_cannot_move_up_and_the_last_cannot_move_down(): void
    {
        $parent = $this->editor->createNode('Board', $this->root->id);
        $first  = $this->editor->createNode('Resistor', $parent->id);
        $last   = $this->editor->createNode('Capacitor', $parent->id);

        $this->editor->moveUp($first->id);
        $this->editor->moveDown($last->id);

        self::assertSame(
            ['Resistor', 'Capacitor'],
            array_map(static fn (Node $n): string => $n->name, $this->editor->childrenOf($parent->id))
        );
        self::assertSame([], $this->changes->verbsFor($first->id) === ['created'] ? [] : ['unexpected write']);
    }

    #[Test]
    public function deleting_only_a_node_hangs_its_children_on_the_grandparent(): void
    {
        // U4, the owner's own words: when the node is deleted, the children get hung on the
        // father.
        $grandparent = $this->editor->createNode('Model', $this->root->id);
        $middle      = $this->editor->createNode('Board', $grandparent->id);
        $child       = $this->editor->createNode('Resistor', $middle->id);

        $this->editor->moveToTrashPromotingChildren($middle->id);

        self::assertSame($grandparent->id, $this->nodes->byId($child->id)->parentNodeId);
        self::assertSame(
            $grandparent->path . '.' . $child->id,
            $this->nodes->byId($child->id)->path
        );
    }

    #[Test]
    public function the_promoted_children_keep_their_own_subtrees(): void
    {
        $grandparent = $this->editor->createNode('Model', $this->root->id);
        $middle      = $this->editor->createNode('Board', $grandparent->id);
        $child       = $this->editor->createNode('Resistor', $middle->id);
        $deep        = $this->editor->createNode('Tolerance', $child->id);

        $this->editor->moveToTrashPromotingChildren($middle->id);

        self::assertSame(
            $this->nodes->byId($child->id)->path . '.' . $deep->id,
            $this->nodes->byId($deep->id)->path
        );
    }

    #[Test]
    public function the_promoted_children_keep_their_order_among_themselves(): void
    {
        $grandparent = $this->editor->createNode('Model', $this->root->id);
        $keep        = $this->editor->createNode('Existing', $grandparent->id);
        $middle      = $this->editor->createNode('Board', $grandparent->id);
        $this->editor->createNode('First', $middle->id);
        $this->editor->createNode('Second', $middle->id);

        $this->editor->moveToTrashPromotingChildren($middle->id);

        self::assertSame(
            ['Existing', 'First', 'Second'],
            array_map(static fn (Node $n): string => $n->name, $this->editor->childrenOf($grandparent->id))
        );
        self::assertNotNull($this->nodes->find($keep->id));
    }

    #[Test]
    public function the_node_itself_still_lands_in_the_trash(): void
    {
        $grandparent = $this->editor->createNode('Model', $this->root->id);
        $middle      = $this->editor->createNode('Board', $grandparent->id);
        $this->editor->createNode('Resistor', $middle->id);

        $parked = $this->editor->moveToTrashPromotingChildren($middle->id);

        self::assertSame($this->trash->path . '.' . $middle->id, $parked->path);
        self::assertSame([], $this->editor->childrenOf($middle->id), 'it takes nothing with it');
    }

    #[Test]
    public function deleting_the_whole_branch_still_takes_the_children(): void
    {
        // The other half of U4, unchanged — both operations are wanted.
        $grandparent = $this->editor->createNode('Model', $this->root->id);
        $middle      = $this->editor->createNode('Board', $grandparent->id);
        $child       = $this->editor->createNode('Resistor', $middle->id);

        $parked = $this->editor->moveToTrash($middle->id);

        self::assertSame($parked->path . '.' . $child->id, $this->nodes->byId($child->id)->path);
    }

    #[Test]
    public function a_parked_node_can_be_put_back_where_it_came_from(): void
    {
        // D-172: undo reaches exactly as far as the trash. Without this the first stage of the
        // two-stage deletion buys nothing and the trash is a graveyard.
        $parent = $this->editor->createNode('Model', $this->root->id);
        $node   = $this->editor->createNode('Board', $parent->id);
        $was    = $node->path;

        $this->editor->moveToTrash($node->id);
        $back = $this->editor->restore($node->id)->node;

        self::assertSame($was, $back->path);
        self::assertSame($parent->id, $this->nodes->byId($node->id)->parentNodeId);
        self::assertSame(['created', 'parked', 'restored'], $this->changes->verbsFor($node->id));
    }

    #[Test]
    public function restoring_brings_the_whole_subtree_back(): void
    {
        $parent = $this->editor->createNode('Model', $this->root->id);
        $node   = $this->editor->createNode('Board', $parent->id);
        $child  = $this->editor->createNode('Resistor', $node->id);
        $was    = $this->nodes->byId($child->id)->path;

        $this->editor->moveToTrash($node->id);
        $this->editor->restore($node->id);

        self::assertSame($was, $this->nodes->byId($child->id)->path);
    }

    #[Test]
    public function a_node_that_was_never_parked_cannot_be_restored(): void
    {
        $node = $this->editor->createNode('Board', $this->root->id);

        $this->expectException(CannotRestore::class);

        $this->editor->restore($node->id);
    }

    #[Test]
    public function restoring_is_refused_when_the_old_place_is_itself_parked(): void
    {
        // Otherwise it would report success and move the node from one part of the trash to
        // another.
        $parent = $this->editor->createNode('Model', $this->root->id);
        $node   = $this->editor->createNode('Board', $parent->id);

        $this->editor->moveToTrash($node->id);
        $this->editor->moveToTrash($parent->id);

        $this->expectException(CannotRestore::class);

        $this->editor->restore($node->id);
    }

    #[Test]
    public function a_node_whose_old_place_is_gone_stays_in_the_trash_but_is_not_stuck(): void
    {
        // Restoring is a convenience, not the only way out: an ordinary move works on a parked
        // node like on any other.
        $parent = $this->editor->createNode('Model', $this->root->id);
        $node   = $this->editor->createNode('Board', $parent->id);

        $this->editor->moveToTrash($node->id);
        $this->nodes->purgeSubtree($this->nodes->byId($parent->id));

        try {
            $this->editor->restore($node->id);
            self::fail('restoring should have been refused');
        } catch (CannotRestore) {
            // expected
        }

        $moved = $this->editor->move($node->id, $this->root->id);

        self::assertSame($this->root->path . '.' . $node->id, $moved->path);
    }

    #[Test]
    public function a_name_containing_the_word_path_does_not_confuse_the_restore(): void
    {
        // ⚠️ The old place is parsed out of the changelog's frozen state, so the format is a
        // contract. Reading from the last `path=` rather than the first is what makes it safe.
        $parent = $this->editor->createNode('Model', $this->root->id);
        $node   = $this->editor->createNode('weird path=1.2 name', $parent->id);
        $was    = $node->path;

        $this->editor->moveToTrash($node->id);

        self::assertSame($was, $this->editor->restore($node->id)->node->path);
    }

    #[Test]
    public function deleting_only_a_node_writes_the_promotion_and_the_parking_under_one_bracket(): void
    {
        // D-348: a person meant this as one thing, so the history says so.
        $grandparent = $this->editor->createNode('Model', $this->root->id);
        $middle      = $this->editor->createNode('Board', $grandparent->id);
        $child       = $this->editor->createNode('Resistor', $middle->id);

        $this->editor->moveToTrashPromotingChildren($middle->id);

        $group = $this->changes->groupsFor($middle->id);
        $under = $this->changes->group(end($group));

        self::assertContains([$child->id, 'promoted'], $under);
        self::assertContains([$middle->id, 'parked'], $under);
    }

    #[Test]
    public function the_history_says_which_child_went_where(): void
    {
        // ⚠️ *These children moved* is not an answer a restore can act on.
        $grandparent = $this->editor->createNode('Model', $this->root->id);
        $middle      = $this->editor->createNode('Board', $grandparent->id);
        $child       = $this->editor->createNode('Resistor', $middle->id);
        $was         = $child->path;

        $this->editor->moveToTrashPromotingChildren($middle->id);

        $promotion = array_values(array_filter(
            $this->changes->entries,
            static fn (array $e): bool => $e[2] === 'promoted'
        ))[0];

        self::assertSame($child->id, $promotion[0]);
        self::assertSame($was, $promotion[3]);
        self::assertSame($this->nodes->byId($child->id)->path, $promotion[4]);
    }

    #[Test]
    public function restoring_brings_a_promoted_child_back_under_the_node(): void
    {
        // D-347: restoring is undo, and undo's reach is the trash (D-172) — so the child
        // that was promoted comes back with the node it was promoted out of.
        $grandparent = $this->editor->createNode('Model', $this->root->id);
        $middle      = $this->editor->createNode('Board', $grandparent->id);
        $child       = $this->editor->createNode('Resistor', $middle->id);

        $this->editor->moveToTrashPromotingChildren($middle->id);
        $result = $this->editor->restore($middle->id);

        self::assertSame(
            $result->node->path . '.' . $child->id,
            $this->nodes->byId($child->id)->path
        );
        self::assertSame(['Resistor'], $result->broughtBack);
        self::assertTrue($result->everythingCameBack());
    }

    #[Test]
    public function a_child_moved_since_is_left_where_it_is_and_named(): void
    {
        // ⚠️ Bringing it back would overwrite a newer, deliberate decision with an older one,
        // and silently. The worse failure is the quiet one.
        $grandparent = $this->editor->createNode('Model', $this->root->id);
        $elsewhere   = $this->editor->createNode('Primitives', $this->root->id);
        $middle      = $this->editor->createNode('Board', $grandparent->id);
        $stays       = $this->editor->createNode('Resistor', $middle->id);
        $moves       = $this->editor->createNode('Capacitor', $middle->id);

        $this->editor->moveToTrashPromotingChildren($middle->id);
        $this->editor->move($moves->id, $elsewhere->id);

        $result = $this->editor->restore($middle->id);

        self::assertSame(['Resistor'], $result->broughtBack);
        self::assertSame(['Capacitor'], $result->leftBehind);
        self::assertFalse($result->everythingCameBack());
        self::assertSame(
            $elsewhere->path . '.' . $moves->id,
            $this->nodes->byId($moves->id)->path,
            'the newer decision stands'
        );
        self::assertSame(
            $result->node->path . '.' . $stays->id,
            $this->nodes->byId($stays->id)->path
        );
    }

    #[Test]
    public function restoring_a_branch_deletion_reports_nothing_left_behind(): void
    {
        // Nothing was promoted, so there is nothing to leave — the message must not appear.
        $parent = $this->editor->createNode('Model', $this->root->id);
        $node   = $this->editor->createNode('Board', $parent->id);
        $this->editor->createNode('Resistor', $node->id);

        $this->editor->moveToTrash($node->id);
        $result = $this->editor->restore($node->id);

        self::assertSame([], $result->broughtBack);
        self::assertSame([], $result->leftBehind);
        self::assertTrue($result->everythingCameBack());
    }

    #[Test]
    public function moving_a_branch_moves_the_counter_of_everything_in_it(): void
    {
        // ⚠️ D-349, and it is a safety property rather than bookkeeping: `save()` writes name
        // and path together, so a descendant whose path changed without its counter moving
        // could be renamed from a stale copy and have its old path written back — silently
        // undoing the move.
        $a    = $this->editor->createNode('Model', $this->root->id);
        $b    = $this->editor->createNode('Primitives', $this->root->id);
        $mid  = $this->editor->createNode('Board', $a->id);
        $deep = $this->editor->createNode('Resistor', $mid->id);

        $before = $this->nodes->byId($deep->id)->version;

        $this->editor->move($mid->id, $b->id);

        self::assertSame($before + 1, $this->nodes->byId($deep->id)->version);
    }

    #[Test]
    public function a_stale_copy_cannot_write_an_old_path_back(): void
    {
        $a   = $this->editor->createNode('Model', $this->root->id);
        $b   = $this->editor->createNode('Primitives', $this->root->id);
        $x   = $this->editor->createNode('Board', $a->id);

        // Somebody opens the node, somebody else moves it.
        $stale = $this->nodes->byId($x->id);
        $this->editor->move($x->id, $b->id);

        $this->expectException(ConcurrentChange::class);

        $this->nodes->save($stale->renamedTo('Platine'), $stale->version);
    }

    #[Test]
    public function a_promoted_child_and_a_restored_one_move_the_counter_the_same_way(): void
    {
        // The inconsistency that started this: the same event — the child gets a new parent —
        // used to move the counter once and not the other time.
        $g     = $this->editor->createNode('Model', $this->root->id);
        $mid   = $this->editor->createNode('Board', $g->id);
        $child = $this->editor->createNode('Resistor', $mid->id);

        $atStart = $this->nodes->byId($child->id)->version;

        $this->editor->moveToTrashPromotingChildren($mid->id);
        $afterPromotion = $this->nodes->byId($child->id)->version;

        $this->editor->restore($mid->id);
        $afterRestore = $this->nodes->byId($child->id)->version;

        self::assertGreaterThan($atStart, $afterPromotion, 'promotion moves it');
        self::assertGreaterThan($afterPromotion, $afterRestore, 'and so does coming back');
    }

    /**
     * `Used by` — [D-199](../../docs/NewConcept/90-decision-log.md)'s one direction.
     *
     * ⚠️ *Die Gegenrichtung ist die Probe, die zählt: `usedBy()` darf **nicht** aufzählen, was der
     * Knoten selbst benutzt — sonst wäre der Abschnitt eine zweite Attributtabelle.*
     */
    /**
     * An editor that knows a `Model` branch, so `addField()` can read a relation kind off a target.
     *
     * ⚠️ *Derselbe Identitätszähler wie in {@see setUp()}, denn ein zweiter finge wieder bei 1 an und
     * gäbe eine Id zweimal aus — genau das, was ein Allokator nie tut.*
     */
    private function withModelBranch(): Node
    {
        $model = $this->editor->createNode('Model', $this->root->id);

        $this->editor = new ModelEditor(
            $this->nodes,
            $this->relations,
            new FixedFramework($this->root, $this->trash, [\Taxmod\Core\Model\Branch::Model->value => $model]),
            $this->changes
        );

        return $model;
    }

    #[Test]
    public function used_by_lists_the_attributes_of_other_nodes_typed_by_this_one(): void
    {
        $model  = $this->withModelBranch();
        $unit   = $this->editor->createNode('Einheit', $model->id);
        $part   = $this->editor->createNode('Teil', $model->id);
        $recipe = $this->editor->createNode('Rezept', $model->id);

        $this->editor->addField($part->id, $unit->id, 'einheit');
        $this->editor->addField($recipe->id, $unit->id, 'menge_einheit');

        // Was der Knoten selbst benutzt — die andere Richtung, und sie gehört nicht hierher.
        $this->editor->addField($unit->id, $part->id, 'beispielteil');

        $used = $this->editor->usedBy($unit->id);

        self::assertSame(
            ['einheit', 'menge_einheit'],
            array_map(static fn (Relation $relation): string => $relation->name, $used)
        );

        self::assertSame(
            [$part->id, $recipe->id],
            array_map(static fn (Relation $relation): int => $relation->fromNodeId, $used)
        );

        // ⚠️ *Eine Vererbungskante ist ein **Kind**, und das zeichnet der Baum. Der Knoten `Einheit`
        // hat `Model` als Elternteil und `Model` hat drei Kinder — keines davon steht hier.*
        self::assertSame([], $this->editor->usedBy($model->id));
    }

    #[Test]
    public function a_parked_attribute_is_not_a_use(): void
    {
        $model = $this->withModelBranch();
        $unit  = $this->editor->createNode('Einheit', $model->id);
        $part  = $this->editor->createNode('Teil', $model->id);

        $relation = $this->editor->addField($part->id, $unit->id, 'einheit');

        self::assertCount(1, $this->editor->usedBy($unit->id));

        $this->editor->removeField($part->id, $relation->id);

        // ⚠️ *D-128: eine geparkte Kante ist in ihrem eigenen Knoten versteckt. Sie hier zu zeigen
        // hiesse, eine Abhängigkeit zu melden, die ihr eigener Knoten nicht zeigt.*
        self::assertSame([], $this->editor->usedBy($unit->id));
    }

    #[Test]
    public function a_child_gets_the_default_class_of_its_parent(): void
    {
        $prefixes = $this->editor->createNode('Prefixes', $this->root->id, \Taxmod\Core\Model\NodeClass\Choice::class);
        $kilo     = $this->editor->createNode('kilo', $prefixes->id);

        self::assertSame(\Taxmod\Core\Model\NodeClass\Choice::class, $prefixes->klasse);
        self::assertSame(\Taxmod\Core\Model\NodeClass\Constant::class, $kilo->klasse, 'die Vorwahl der Auswahl');
        self::assertSame(\Taxmod\Core\Model\NodeClass\Category::class, $this->editor->createNode('Kontakt', $this->root->id)->klasse, 'die Vorwahl der Kategorie');
    }

    #[Test]
    public function a_class_the_parent_does_not_allow_is_refused(): void
    {
        $prefixes = $this->editor->createNode('Prefixes', $this->root->id, \Taxmod\Core\Model\NodeClass\Choice::class);

        $this->expectException(\Taxmod\Core\Exception\ClassNotAllowedUnder::class);

        $this->editor->createNode('Zahl', $prefixes->id, \Taxmod\Core\Model\Type\IntType::class);
    }

    #[Test]
    public function an_unknown_class_is_refused_before_anything_is_written(): void
    {
        $vorher = $this->nodes->count();

        try {
            $this->editor->createNode('Ding', $this->root->id, 'Nope\Nothing');
            self::fail('sollte abweisen');
        } catch (\Taxmod\Core\Exception\UnknownNodeClass) {
        }

        self::assertSame($vorher, $this->nodes->count());
    }

    #[Test]
    public function a_duplicate_keeps_the_class_of_the_original(): void
    {
        $auswahl = $this->editor->createNode('Auswahl', $this->root->id, \Taxmod\Core\Model\NodeClass\Choice::class);

        self::assertSame(\Taxmod\Core\Model\NodeClass\Choice::class, $this->editor->duplicate($auswahl->id)->klasse);
    }
}
