<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\InvalidName;
use Taxmod\Core\Exception\NotAPossibleTarget;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\Storage;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Tests\Core\Fake\CountingIdentities;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemoryRelations;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/**
 * An attribute is a relation, and its kind is read off the target's branch — never chosen.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class AttributeTest extends TestCase
{
    private InMemoryNodes $nodes;
    private InMemoryRelations $edges;
    private ModelEditor $editor;
    private Node $root;
    private Node $trash;
    /** @var array<string,Node> */
    private array $branchRoot = [];

    protected function setUp(): void
    {
        $this->edges = new InMemoryRelations();
        $this->nodes = new InMemoryNodes($this->edges);
        $identities  = new CountingIdentities();

        $make = function (string $name, ?Node $parent) use ($identities): Node {
            $node = Node::create($identities->next(), $name, $parent?->path);
            $this->nodes->add($node);

            if ($parent !== null) {
                $this->edges->add(Relation::inheritance(
                    $identities->next(),
                    $parent->id,
                    $node->id,
                    $this->edges->nextPositionUnder($parent->id)
                ));
            }

            return $node;
        };

        $this->root  = $make('Root', null);
        $this->trash = $make('Trash', $this->root);

        $this->branchRoot['model']        = $make('Model', $this->root);
        $this->branchRoot['compositions'] = $make('Compositions', $this->root);

        $primitives = $make('Primitives', $this->root);

        $this->branchRoot['data-types'] = $make('Data Types', $primitives);
        $this->branchRoot['constants']  = $make('Constants', $primitives);

        $this->primitives = $primitives;

        $this->editor = new ModelEditor(
            $this->nodes,
            $this->edges,
            $identities,
            new FixedFramework($this->root, $this->trash, $this->branchRoot),
            new RecordedChanges()
        );
    }

    private Node $primitives;

    private function under(string $branch, string $name): Node
    {
        return $this->editor->createNode($name, $this->branchRoot[$branch]->id);
    }

    #[Test]
    public function a_target_in_model_is_reached_by_aggregation(): void
    {
        // Something that stands on its own: a supplier is not owned by the order using it.
        $order    = $this->under('model', 'Order');
        $supplier = $this->under('model', 'Supplier');

        $edge = $this->editor->addAttribute($order->id, $supplier->id, 'supplied by');

        self::assertSame(RelationKind::Aggregation, $edge->kind);
    }

    #[Test]
    public function a_target_in_compositions_is_reached_by_composition(): void
    {
        $order = $this->under('model', 'Order');
        $line  = $this->under('compositions', 'Order line');

        self::assertSame(
            RelationKind::Composition,
            $this->editor->addAttribute($order->id, $line->id, 'lines')->kind
        );
    }

    #[Test]
    public function a_data_type_is_composed_and_a_constant_is_aggregated(): void
    {
        // ⚠️ The pair that shows the rule is not *primitive versus not*: both sit under
        // `Primitives` and they differ, because one has no instances and the other is a node
        // a person may extend.
        $part = $this->under('model', 'Part');
        $text = $this->under('data-types', 'Text');
        $unit = $this->under('constants', 'Gramm');

        self::assertSame(
            RelationKind::Composition,
            $this->editor->addAttribute($part->id, $text->id, 'description')->kind
        );
        self::assertSame(
            RelationKind::Aggregation,
            $this->editor->addAttribute($part->id, $unit->id, 'unit')->kind
        );
    }

    #[Test]
    public function the_branch_decides_where_a_value_would_be_stored(): void
    {
        self::assertSame(Storage::ExternalReference, Branch::Model->storage());
        self::assertSame(Storage::OwnRecords, Branch::Compositions->storage());
        self::assertSame(Storage::InsideTheRecord, Branch::DataTypes->storage());
        self::assertSame(Storage::NodeReference, Branch::Constants->storage());
    }

    #[Test]
    public function only_the_two_branches_that_have_instances_hold_data(): void
    {
        self::assertTrue(Branch::Model->holdsData());
        self::assertTrue(Branch::Compositions->holdsData());
        self::assertFalse(Branch::DataTypes->holdsData());
        self::assertFalse(Branch::Constants->holdsData());
    }

    #[Test]
    public function a_branch_root_cannot_be_the_target(): void
    {
        // D-238: everything **but** the branch root is selectable — the root stands for the
        // branch itself, not for a thing in it.
        $part = $this->under('model', 'Part');

        $this->expectException(NotAPossibleTarget::class);

        $this->editor->addAttribute($part->id, $this->branchRoot['data-types']->id, 'description');
    }

    #[Test]
    public function a_node_in_no_branch_cannot_be_the_target(): void
    {
        // ⚠️ `Primitives` splits into two branches and the concept says nothing about the space
        // between, so a node hung directly under it has no kind to read. Refusing is honest;
        // guessing would invent a rule.
        $part  = $this->under('model', 'Part');
        $limbo = $this->editor->createNode('Neither', $this->primitives->id);

        $this->expectException(NotAPossibleTarget::class);

        $this->editor->addAttribute($part->id, $limbo->id, 'something');
    }

    #[Test]
    public function a_parked_target_cannot_be_the_target(): void
    {
        $part = $this->under('model', 'Part');
        $gone = $this->under('data-types', 'Text');

        $this->editor->moveToTrash($gone->id);

        $this->expectException(NotAPossibleTarget::class);

        $this->editor->addAttribute($part->id, $gone->id, 'description');
    }

    #[Test]
    public function an_attribute_needs_a_name(): void
    {
        $part = $this->under('model', 'Part');
        $text = $this->under('data-types', 'Text');

        $this->expectException(InvalidName::class);

        $this->editor->addAttribute($part->id, $text->id, '   ');
    }

    #[Test]
    public function the_attribute_edge_takes_its_own_identity(): void
    {
        $part = $this->under('model', 'Part');
        $text = $this->under('data-types', 'Text');

        $edge = $this->editor->addAttribute($part->id, $text->id, 'description');

        self::assertNotSame($edge->id, $part->id);
        self::assertNotSame($edge->id, $text->id);
    }

    #[Test]
    public function a_node_carries_what_its_ancestors_declare(): void
    {
        // The tree *is* inheritance (D-041), so an attribute declared above applies below.
        $thing = $this->under('model', 'Thing');
        $part  = $this->editor->createNode('Part', $thing->id);
        $text  = $this->under('data-types', 'Text');

        $this->editor->addAttribute($thing->id, $text->id, 'description');

        $names = array_map(
            static fn (Relation $r): string => $r->name,
            $this->editor->attributesOf($part->id)
        );

        self::assertSame(['description'], $names);
    }

    #[Test]
    public function its_own_attributes_come_with_the_inherited_ones(): void
    {
        $thing = $this->under('model', 'Thing');
        $part  = $this->editor->createNode('Part', $thing->id);
        $text  = $this->under('data-types', 'Text');

        $this->editor->addAttribute($thing->id, $text->id, 'description');
        $this->editor->addAttribute($part->id, $text->id, 'part number');

        $names = array_map(
            static fn (Relation $r): string => $r->name,
            $this->editor->attributesOf($part->id)
        );

        sort($names);

        self::assertSame(['description', 'part number'], $names);
    }

    #[Test]
    public function a_sibling_does_not_see_what_the_other_declares(): void
    {
        $thing = $this->under('model', 'Thing');
        $part  = $this->editor->createNode('Part', $thing->id);
        $other = $this->editor->createNode('Other', $thing->id);
        $text  = $this->under('data-types', 'Text');

        $this->editor->addAttribute($part->id, $text->id, 'part number');

        self::assertSame([], $this->editor->attributesOf($other->id));
    }

    #[Test]
    public function the_inheritance_edge_is_not_an_attribute(): void
    {
        // Both are relations; only one is an attribute seen from the node that owns it.
        $thing = $this->under('model', 'Thing');
        $this->editor->createNode('Part', $thing->id);

        self::assertSame([], $this->editor->attributesOf($thing->id));
    }
}
