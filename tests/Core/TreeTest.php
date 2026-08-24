<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Tree;
use Taxmod\Tests\Core\Fake\CountingIdentities;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemoryRelations;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/**
 * The tree assembled for drawing: order, depth, and what is left out.
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class TreeTest extends TestCase
{
    private InMemoryNodes $nodes;
    private InMemoryRelations $edges;
    private ModelEditor $editor;
    private Tree $tree;
    private Node $root;
    private Node $trash;

    protected function setUp(): void
    {
        $this->edges = new InMemoryRelations();
        $this->nodes = new InMemoryNodes($this->edges);
        $identities  = new CountingIdentities();

        $this->root  = Node::create($identities->next(), 'Root', null);
        $this->trash = Node::create($identities->next(), 'Trash', $this->root->path);

        $this->nodes->add($this->root);
        $this->nodes->add($this->trash);
        $this->edges->add(Relation::inheritance($identities->next(), $this->root->id, $this->trash->id, 0));

        $this->editor = new ModelEditor(
            $this->nodes,
            $this->edges,
            $identities,
            new FixedFramework($this->root, $this->trash),
            new RecordedChanges()
        );

        $this->tree = new Tree($this->nodes, $this->edges);
    }

    /** @return list<string> */
    private function drawn(array $skip = []): array
    {
        return array_map(
            static fn (array $row): string => str_repeat('-', $row['depth']) . $row['node']->name,
            $this->tree->rowsUnder($this->root, $skip)
        );
    }

    #[Test]
    public function a_child_appears_directly_under_its_parent_one_level_in(): void
    {
        $model = $this->editor->createNode('Model', $this->root->id);
        $this->editor->createNode('Board', $model->id);

        self::assertSame(['Trash', 'Model', '-Board'], $this->drawn());
    }

    #[Test]
    public function a_whole_branch_is_drawn_before_the_next_one_starts(): void
    {
        // Depth first, not level by level: what belongs together stays together on screen.
        $a = $this->editor->createNode('Model', $this->root->id);
        $b = $this->editor->createNode('Primitives', $this->root->id);
        $this->editor->createNode('Board', $a->id);
        $this->editor->createNode('Integer', $b->id);

        self::assertSame(['Trash', 'Model', '-Board', 'Primitives', '-Integer'], $this->drawn());
    }

    #[Test]
    public function depth_keeps_going_as_far_as_the_tree_does(): void
    {
        $a = $this->editor->createNode('Model', $this->root->id);
        $b = $this->editor->createNode('Board', $a->id);
        $c = $this->editor->createNode('Resistor', $b->id);
        $this->editor->createNode('Tolerance', $c->id);

        self::assertSame(
            ['Trash', 'Model', '-Board', '--Resistor', '---Tolerance'],
            $this->drawn()
        );
    }

    #[Test]
    public function siblings_come_in_edge_order_and_follow_a_reorder(): void
    {
        $this->editor->createNode('Model', $this->root->id);
        $second = $this->editor->createNode('Primitives', $this->root->id);

        self::assertSame(['Trash', 'Model', 'Primitives'], $this->drawn());

        $this->editor->moveUp($second->id);

        self::assertSame(['Trash', 'Primitives', 'Model'], $this->drawn());
    }

    #[Test]
    public function a_skipped_subtree_is_left_out_whole(): void
    {
        // What the screen does with the trash: shown separately, not twice.
        $parked = $this->editor->createNode('Board', $this->root->id);
        $this->editor->createNode('Resistor', $parked->id);
        $this->editor->moveToTrash($parked->id);

        self::assertSame([], $this->drawn([$this->trash->id]));
        self::assertSame(['Trash', '-Board', '--Resistor'], $this->drawn());
    }

    #[Test]
    public function a_moved_branch_is_drawn_in_its_new_place(): void
    {
        $a    = $this->editor->createNode('Model', $this->root->id);
        $b    = $this->editor->createNode('Primitives', $this->root->id);
        $leaf = $this->editor->createNode('Board', $a->id);
        $this->editor->createNode('Resistor', $leaf->id);

        $this->editor->move($leaf->id, $b->id);

        self::assertSame(['Trash', 'Model', 'Primitives', '-Board', '--Resistor'], $this->drawn());
    }

    #[Test]
    public function an_empty_tree_draws_nothing(): void
    {
        self::assertSame([], $this->drawn([$this->trash->id]));
    }
}
