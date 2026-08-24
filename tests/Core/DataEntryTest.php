<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RecordValue;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Tests\Core\Fake\CountingIdentities;
use Taxmod\Tests\Core\Fake\FixedClock;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemoryRecords;
use Taxmod\Tests\Core\Fake\InMemoryRelations;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/**
 * Entering something against a model and finding it again.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class DataEntryTest extends TestCase
{
    private InMemoryNodes $nodes;
    private InMemoryRelations $edges;
    private InMemoryRecords $records;
    private ModelEditor $editor;
    private DataEntry $data;
    /** @var array<string,Node> */
    private array $branchRoot = [];
    private Node $part;
    private Node $text;
    private Node $gram;
    private Relation $description;

    protected function setUp(): void
    {
        $this->edges   = new InMemoryRelations();
        $this->nodes   = new InMemoryNodes($this->edges);
        $this->records = new InMemoryRecords();
        $identities    = new CountingIdentities();

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

        $root  = $make('Root', null);
        $trash = $make('Trash', $root);

        $this->branchRoot['model']        = $make('Model', $root);
        $this->branchRoot['compositions'] = $make('Compositions', $root);

        $primitives = $make('Primitives', $root);

        $this->branchRoot['data-types'] = $make('Data Types', $primitives);
        $this->branchRoot['constants']  = $make('Constants', $primitives);

        $framework = new FixedFramework($root, $trash, $this->branchRoot);

        $this->editor = new ModelEditor($this->nodes, $this->edges, $identities, $framework, new RecordedChanges());
        $this->data   = new DataEntry($this->records, $this->edges, $this->nodes, $framework, new FixedClock());

        $this->part = $this->editor->createNode('Part', $this->branchRoot['model']->id);
        $this->text = $this->editor->createNode('Text', $this->branchRoot['data-types']->id);
        $this->gram = $this->editor->createNode('Gramm', $this->branchRoot['constants']->id);

        $this->description = $this->editor->addAttribute($this->part->id, $this->text->id, 'description');
    }

    #[Test]
    public function a_record_is_entered_against_a_model_node(): void
    {
        $record = $this->data->create($this->part->id);

        self::assertSame($this->part->id, $record->modelId);
        self::assertGreaterThan(0, $record->id);
    }

    #[Test]
    public function it_keeps_the_model_version_it_was_written_against(): void
    {
        // D-060, D-210: *written against*, not *checked against*. Records at several versions
        // are a normal steady state.
        $before = $this->nodes->byId($this->part->id)->version;
        $record = $this->data->create($this->part->id);

        $this->editor->rename($this->part->id, 'Bauteil');

        self::assertSame($before, $this->data->find($record->id)->modelVersion);
        self::assertGreaterThan($before, $this->nodes->byId($this->part->id)->version);
    }

    #[Test]
    public function a_data_type_has_no_records_of_its_own(): void
    {
        // D-183: only branches with instances have them. A `Text` node is not a thing somebody
        // owns three of.
        $this->expectException(NotYetStorable::class);

        $this->data->create($this->text->id);
    }

    #[Test]
    public function a_value_goes_in_and_comes_back(): void
    {
        $record = $this->data->create($this->part->id);

        $this->data->put($record->id, $this->description->id, TypedValue::ofText('a resistor'));

        $values = $this->data->valuesOf($record->id);

        self::assertCount(1, $values);
        self::assertSame('a resistor', $values[0]->value->text);
    }

    #[Test]
    public function the_last_edge_is_kept_beside_the_path(): void
    {
        // ⚠️ D-134, and it is what makes the data searchable: the edge is indexed, the path
        // narrows.
        $record = $this->data->create($this->part->id);

        $this->data->put($record->id, $this->description->id, TypedValue::ofText('x'));

        $value = $this->data->valuesOf($record->id)[0];

        self::assertSame($this->description->id, $value->edgeId);
        self::assertSame((string) $this->description->id, $value->path);
    }

    #[Test]
    public function a_constant_is_stored_as_a_reference_to_a_node(): void
    {
        $unit   = $this->editor->addAttribute($this->part->id, $this->gram->id, 'unit');
        $record = $this->data->create($this->part->id);

        $this->data->put($record->id, $unit->id, TypedValue::ofReference($this->gram->id));

        $value = $this->data->valuesOf($record->id)[0];

        self::assertSame($this->gram->id, $value->value->reference);
        self::assertNull($value->value->text);
    }

    #[Test]
    public function a_composed_target_is_refused_rather_than_stored_in_the_wrong_place(): void
    {
        // ⚠️ Unfinished work, said plainly. Storing it inline would look right until somebody
        // tried to share it.
        $line = $this->editor->createNode('Order line', $this->branchRoot['compositions']->id);
        $has  = $this->editor->addAttribute($this->part->id, $line->id, 'lines');

        $record = $this->data->create($this->part->id);

        $this->expectException(NotYetStorable::class);

        $this->data->put($record->id, $has->id, TypedValue::ofInt(1));
    }

    #[Test]
    public function a_value_can_be_written_against_an_inherited_attribute(): void
    {
        // The tree *is* inheritance, so a child's record answers what the parent declared.
        $child  = $this->editor->createNode('Resistor', $this->part->id);
        $record = $this->data->create($child->id);

        $this->data->put($record->id, $this->description->id, TypedValue::ofText('inherited attribute'));

        self::assertSame('inherited attribute', $this->data->valuesOf($record->id)[0]->value->text);
    }

    #[Test]
    public function an_attribute_of_a_different_model_is_refused(): void
    {
        $other = $this->editor->createNode('Supplier', $this->branchRoot['model']->id);
        $alien = $this->editor->addAttribute($other->id, $this->text->id, 'note');

        $record = $this->data->create($this->part->id);

        $this->expectException(NotYetStorable::class);

        $this->data->put($record->id, $alien->id, TypedValue::ofText('nowhere'));
    }

    #[Test]
    public function a_second_write_replaces_the_first(): void
    {
        $record = $this->data->create($this->part->id);

        $this->data->put($record->id, $this->description->id, TypedValue::ofText('first'));
        $this->data->put($record->id, $this->description->id, TypedValue::ofText('second'));

        $values = $this->data->valuesOf($record->id);

        self::assertCount(1, $values);
        self::assertSame('second', $values[0]->value->text);
    }

    #[Test]
    public function clearing_leaves_the_attribute_unanswered(): void
    {
        // ⚠️ A missing row means *not answered*, never *no* — three states at `0..1`, and
        // collapsing the last two loses them for good.
        $record = $this->data->create($this->part->id);

        $this->data->put($record->id, $this->description->id, TypedValue::ofText('something'));
        $this->data->clear($record->id, $this->description->id);

        self::assertSame([], $this->data->valuesOf($record->id));
    }

    #[Test]
    public function the_records_of_a_model_can_be_listed(): void
    {
        $this->data->create($this->part->id);
        $this->data->create($this->part->id);
        $this->data->create($this->editor->createNode('Supplier', $this->branchRoot['model']->id)->id);

        self::assertCount(2, $this->data->recordsOf($this->part->id));
    }

    #[Test]
    public function a_record_is_found_again_by_what_it_holds(): void
    {
        // The owner's own check for this package.
        $wanted = $this->data->create($this->part->id);
        $other  = $this->data->create($this->part->id);

        $this->data->put($wanted->id, $this->description->id, TypedValue::ofText('4k7'));
        $this->data->put($other->id, $this->description->id, TypedValue::ofText('10k'));

        $found = $this->data->findByValue($this->description->id, TypedValue::ofText('4k7'));

        self::assertCount(1, $found);
        self::assertSame($wanted->id, $found[0]->id);
    }

    #[Test]
    public function record_ids_are_their_own_space_and_may_collide_with_node_ids(): void
    {
        // D-164: the two halves do not share a number space, and nothing goes wrong when the
        // numbers happen to be the same — the branch decides what a reference resolves to.
        $record = $this->data->create($this->part->id);

        self::assertNotNull($this->nodes->find($record->id) ?? $this->data->find($record->id));
        self::assertSame($record->id, $this->data->find($record->id)->id);
    }

    #[Test]
    public function several_values_of_one_attribute_are_several_paths_in_one_record(): void
    {
        // ⚠️ D-232: multiplicity plays no part in storage. Five integers are five paths in one
        // record, not five records.
        $record = $this->data->create($this->part->id);

        $this->records->putValue(new RecordValue(
            $record->id,
            $this->description->id . '.1',
            $this->description->id,
            '',
            TypedValue::ofText('second occurrence')
        ));
        $this->data->put($record->id, $this->description->id, TypedValue::ofText('first occurrence'));

        self::assertCount(2, $this->data->valuesOf($record->id));
        self::assertCount(1, $this->data->recordsOf($this->part->id));
    }
}
