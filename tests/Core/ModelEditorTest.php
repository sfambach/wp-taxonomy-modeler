<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\NodeIsProtected;
use Taxmod\Core\Exception\NodeNotFound;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Tests\Core\Fake\CountingIdentities;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/**
 * Making, renaming and parking — with no database and no WordPress anywhere.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class ModelEditorTest extends TestCase
{
    private InMemoryNodes $nodes;
    private RecordedChanges $changes;
    private ModelEditor $editor;
    private Node $root;
    private Node $trash;

    protected function setUp(): void
    {
        $this->nodes   = new InMemoryNodes();
        $this->changes = new RecordedChanges();
        $identities    = new CountingIdentities();

        $this->root  = Node::create($identities->next(), 'Root', null);
        $this->trash = Node::create($identities->next(), 'Trash', $this->root->path);

        $this->nodes->add($this->root);
        $this->nodes->add($this->trash);

        $this->editor = new ModelEditor(
            $this->nodes,
            $identities,
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
    public function children_are_listed_without_walking_the_tree(): void
    {
        $parent = $this->editor->createNode('Board', $this->root->id);
        $this->editor->createNode('Resistor', $parent->id);
        $this->editor->createNode('Capacitor', $parent->id);

        $names = array_map(static fn (Node $n): string => $n->name, $this->editor->childrenOf($parent->id));

        self::assertSame(['Capacitor', 'Resistor'], $names);
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
}
