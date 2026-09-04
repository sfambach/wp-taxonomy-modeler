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
final class FieldTest extends TestCase
{
    #[Test]
    public function an_attribute_can_be_removed_and_it_is_parked_not_purged(): void
    {
        // ⚠️ This was missing since Package 3, and the reason was **storage**: `relations` had
        // nowhere to record that an edge was gone (D-371). Two stages as everywhere (D-123).
        $part = $this->under('model', 'Part');
        $edge = $this->editor->addField($part->id, $this->under('data-types', 'text')->id, 'label');

        $removed = $this->editor->removeField($part->id, $edge->id);

        self::assertTrue($removed->isParked());
        self::assertNotNull($removed->parkedByGroup, 'it names the act that removed it (D-128)');

        // Hidden by default in its owning node — a model full of ghosts is unreadable (D-128).
        self::assertSame([], $this->editor->fieldsOf($part->id));
        self::assertCount(1, $this->editor->removedFieldsOf($part->id));
    }

    #[Test]
    public function a_removed_attribute_can_come_back(): void
    {
        $part = $this->under('model', 'Part');
        $edge = $this->editor->addField($part->id, $this->under('data-types', 'text')->id, 'label');

        $this->editor->removeField($part->id, $edge->id);
        $back = $this->editor->restoreField($part->id, $edge->id);

        // ⚠️ Everything it had comes back with it — the name, the target, the kind. Parking is not
        // purging (D-123), which is exactly why nothing else had to be preserved by hand.
        self::assertFalse($back->isParked());
        self::assertSame($edge->name, $back->name);
        self::assertSame($edge->toId, $back->toId);
        self::assertCount(1, $this->editor->fieldsOf($part->id));
        self::assertSame([], $this->editor->removedFieldsOf($part->id));
    }

    #[Test]
    public function removing_it_twice_changes_nothing_the_second_time(): void
    {
        $part = $this->under('model', 'Part');
        $edge = $this->editor->addField($part->id, $this->under('data-types', 'text')->id, 'label');

        $first  = $this->editor->removeField($part->id, $edge->id);
        $second = $this->editor->removeField($part->id, $edge->id);

        // ⚠️ The same act, not a second one — otherwise a double click would write two brackets and
        // the history would claim it was removed twice.
        self::assertSame($first->parkedByGroup, $second->parkedByGroup);
    }

    #[Test]
    public function an_inherited_attribute_is_not_removable_from_the_descendant(): void
    {
        // ⚠️ It belongs to the ancestor that declared it. Removing it lower down would be
        // D-155's *moved down* by another route, which is a different act.
        $part     = $this->under('model', 'Part');
        $resistor = $this->editor->createNode('Resistor', $part->id);

        $this->editor->addField($part->id, $this->under('data-types', 'text')->id, 'label');

        $inherited = $this->editor->fieldsOf($resistor->id)[0];

        $this->expectException(NotAPossibleTarget::class);

        $this->editor->removeField($resistor->id, $inherited->id);
    }

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

        $edge = $this->editor->addField($order->id, $supplier->id, 'supplied by');

        self::assertSame(RelationKind::Aggregation, $edge->kind);
    }

    #[Test]
    public function a_target_in_compositions_is_reached_by_composition(): void
    {
        $order = $this->under('model', 'Order');
        $line  = $this->under('compositions', 'Order line');

        self::assertSame(
            RelationKind::Composition,
            $this->editor->addField($order->id, $line->id, 'lines')->kind
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
            $this->editor->addField($part->id, $text->id, 'description')->kind
        );
        self::assertSame(
            RelationKind::Aggregation,
            $this->editor->addField($part->id, $unit->id, 'unit')->kind
        );
    }

    #[Test]
    /**
     * ⚠️ **Eine Einstellung ist eine Komposition** ([D-526](../../../docs/NewConcept/90-decision-log.md)),
     * *und das ist die einzige Zusage, die diese Art überhaupt trägt: «welchen Renderer benutze ich»
     * gehört dem Knoten. **Fällt sie, ist `Setting` nur ein vierter Name ohne Bedeutung.***
     */
    public function a_setting_is_a_composition(): void
    {
        self::assertTrue(RelationKind::Setting->isComposition());
        self::assertTrue(RelationKind::Composition->isComposition());
        self::assertFalse(RelationKind::Aggregation->isComposition());
        self::assertFalse(RelationKind::Inheritance->isComposition());
    }

    /**
     * ⚠️ *Und sie ist die **einzige**, die nicht aus einem Ast folgt — kein Zweig gibt sie zurück,
     * jemand setzt sie. Fiele das um, entschiede wieder der Ort, was eine Einstellung ist, und die
     * eine Kollision käme zurück: `Prefixes.exponent` und `Passiv.Tolerance` zeigen beide auf
     * `Integer`.*
     */
    #[Test]
    public function no_branch_hands_out_the_setting_kind(): void
    {
        foreach (Branch::cases() as $branch) {
            self::assertNotSame(RelationKind::Setting, $branch->relationKind(), $branch->value);
        }

        self::assertTrue(RelationKind::Setting->isSetting());
        self::assertFalse(RelationKind::Composition->isSetting());
    }

    #[Test]
    public function the_branch_decides_where_a_value_would_be_stored(): void
    {
        self::assertSame(Storage::ExternalReference, Branch::Model->storage());
        self::assertSame(Storage::OwnRecords, Branch::Compositions->storage());
        self::assertSame(Storage::InsideTheRecord, Branch::DataTypes->storage());
        self::assertSame(Storage::NodeRef, Branch::Constants->storage());
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

        $this->editor->addField($part->id, $this->branchRoot['data-types']->id, 'description');
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

        $this->editor->addField($part->id, $limbo->id, 'something');
    }

    #[Test]
    public function a_parked_target_cannot_be_the_target(): void
    {
        $part = $this->under('model', 'Part');
        $gone = $this->under('data-types', 'Text');

        $this->editor->moveToTrash($gone->id);

        $this->expectException(NotAPossibleTarget::class);

        $this->editor->addField($part->id, $gone->id, 'description');
    }

    #[Test]
    public function an_attribute_needs_a_name(): void
    {
        $part = $this->under('model', 'Part');
        $text = $this->under('data-types', 'Text');

        $this->expectException(InvalidName::class);

        $this->editor->addField($part->id, $text->id, '   ');
    }

    #[Test]
    public function the_attribute_edge_takes_its_own_identity(): void
    {
        $part = $this->under('model', 'Part');
        $text = $this->under('data-types', 'Text');

        $edge = $this->editor->addField($part->id, $text->id, 'description');

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

        $this->editor->addField($thing->id, $text->id, 'description');

        $names = array_map(
            static fn (Relation $r): string => $r->name,
            $this->editor->fieldsOf($part->id)
        );

        self::assertSame(['description'], $names);
    }

    #[Test]
    public function its_own_attributes_come_with_the_inherited_ones(): void
    {
        $thing = $this->under('model', 'Thing');
        $part  = $this->editor->createNode('Part', $thing->id);
        $text  = $this->under('data-types', 'Text');

        $this->editor->addField($thing->id, $text->id, 'description');
        $this->editor->addField($part->id, $text->id, 'part number');

        $names = array_map(
            static fn (Relation $r): string => $r->name,
            $this->editor->fieldsOf($part->id)
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

        $this->editor->addField($part->id, $text->id, 'part number');

        self::assertSame([], $this->editor->fieldsOf($other->id));
    }

    #[Test]
    public function the_inheritance_edge_is_not_an_attribute(): void
    {
        // Both are relations; only one is an attribute seen from the node that owns it.
        $thing = $this->under('model', 'Thing');
        $this->editor->createNode('Part', $thing->id);

        self::assertSame([], $this->editor->fieldsOf($thing->id));
    }
}
