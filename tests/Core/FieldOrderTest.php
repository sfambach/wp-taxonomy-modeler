<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\FieldOrder;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Tests\Core\Fake\CountingIdentities;
use Taxmod\Tests\Core\Fake\FixedClock;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemoryRecords;
use Taxmod\Tests\Core\Fake\InMemoryRelations;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/**
 * Ein Kind ordnet geerbte Felder an — [D-698](../../docs/NewConcept/90-decision-log.md), sein Wort:
 * *«würde sagen kind darf felder neu anordnen».*
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class FieldOrderTest extends TestCase
{
    private InMemoryRecords $records;
    private ModelEditor $editor;
    private DataEntry $data;
    private FieldOrder $order;
    private Node $owner;
    private Node $child;
    private Node $grandchild;
    private Relation $f1;
    private Relation $f2;
    private Relation $g;

    protected function setUp(): void
    {
        $relations     = new InMemoryRelations();
        $nodes         = new InMemoryNodes($relations);
        $this->records = new InMemoryRecords();
        $identities    = new CountingIdentities();

        $make = function (string $name, ?Node $parent) use ($identities, $nodes): Node {
            $node = Node::create(
                $identities->next(),
                $name,
                $parent?->path,
                $parent?->id,
                $parent === null ? 0 : $nodes->nextPositionUnder($parent->id)
            );
            $nodes->add($node);

            return $node;
        };

        $root       = $make('Root', null);
        $trash      = $make('Trash', $root);
        $branches   = ['model' => $make('Model', $root), 'compositions' => $make('Compositions', $root)];
        $primitives = $make('Primitives', $root);
        $branches['data-types'] = $make('Data Types', $primitives);
        $branches['constants']  = $make('Constants', $primitives);

        $framework    = new FixedFramework($root, $trash, $branches);
        $this->editor = new ModelEditor($nodes, $relations, $framework, new RecordedChanges(), records: $this->records);
        $this->data   = new DataEntry($this->records, $relations, $nodes, $framework, new FixedClock(), new RecordedChanges());
        $this->order  = new FieldOrder($this->records, $relations, $nodes, $framework);

        $text    = $this->editor->createNode('Text', $branches['data-types']->id);
        $integer = $this->editor->createNode('Integer', $branches['data-types']->id);

        // Fassung 45: die Einstellung `position` an der Wurzel, `0..1` auf `Integer`.
        $position = $this->editor->addField($root->id, $integer->id, SettingKey::Position->value, RelationKind::Setting);
        $this->editor->setMultiplicity($root->id, $position->id, Multiplicity::ZeroToOne);

        $this->owner      = $this->editor->createNode('Owner', $branches['model']->id);
        $this->f1         = $this->editor->addField($this->owner->id, $text->id, 'f1');
        $this->f2         = $this->editor->addField($this->owner->id, $text->id, 'f2');
        $this->child      = $this->editor->createNode('Child', $this->owner->id);
        $this->g          = $this->editor->addField($this->child->id, $text->id, 'g');
        $this->grandchild = $this->editor->createNode('Grandchild', $this->child->id);
    }

    /** @return list<string> */
    private function fieldNamesAt(Node $node): array
    {
        return array_map(
            static fn (Relation $r): string => $r->name,
            $this->order->fieldRowsOf($this->editor->fieldsOf($node->id))
        );
    }

    #[Test]
    public function without_an_arrangement_the_owners_order_holds(): void
    {
        self::assertSame(['f1', 'f2', 'g'], $this->fieldNamesAt($this->child));
        self::assertFalse($this->order->isArrangedAt($this->child->id, $this->editor->fieldsOf($this->child->id)));
    }

    #[Test]
    public function a_child_moves_an_inherited_field_and_writes_the_whole_list_at_its_own_address(): void
    {
        self::assertTrue($this->data->moveFieldAt($this->child->id, $this->editor->fieldsOf($this->child->id), $this->g->id, -1));
        self::assertSame(['f1', 'g', 'f2'], $this->fieldNamesAt($this->child));

        self::assertTrue($this->data->moveFieldAt($this->child->id, $this->editor->fieldsOf($this->child->id), $this->g->id, -1));
        self::assertSame(['g', 'f1', 'f2'], $this->fieldNamesAt($this->child));

        $satz = $this->records->ofRelationAt($this->child->id, $this->f1->id);
        self::assertNotNull($satz);
        self::assertSame(RecordType::Settings, $satz->recordType);

        $positionen = $this->order->positionsAt($this->child->id, $this->editor->fieldsOf($this->child->id));
        ksort($positionen);
        $erwartet = [$this->g->id => 0, $this->f1->id => 1, $this->f2->id => 2];
        ksort($erwartet);
        self::assertSame($erwartet, $positionen);
    }

    #[Test]
    public function the_owner_is_untouched(): void
    {
        $this->data->moveFieldAt($this->child->id, $this->editor->fieldsOf($this->child->id), $this->g->id, -1);

        self::assertSame(['f1', 'f2'], $this->fieldNamesAt($this->owner));
        self::assertNull($this->records->ofRelationAt($this->owner->id, $this->f1->id));
    }

    #[Test]
    public function a_grandchild_inherits_the_arrangement_and_may_rearrange_without_changing_the_child(): void
    {
        $this->data->moveFieldAt($this->child->id, $this->editor->fieldsOf($this->child->id), $this->g->id, -1);
        $this->data->moveFieldAt($this->child->id, $this->editor->fieldsOf($this->child->id), $this->g->id, -1);

        self::assertSame(['g', 'f1', 'f2'], $this->fieldNamesAt($this->grandchild));

        $this->data->moveFieldAt($this->grandchild->id, $this->editor->fieldsOf($this->grandchild->id), $this->f2->id, -1);

        self::assertSame(['g', 'f2', 'f1'], $this->fieldNamesAt($this->grandchild));
        self::assertSame(['g', 'f1', 'f2'], $this->fieldNamesAt($this->child));
    }

    #[Test]
    public function a_step_off_the_end_moves_nothing(): void
    {
        self::assertFalse($this->data->moveFieldAt($this->child->id, $this->editor->fieldsOf($this->child->id), $this->f1->id, -1));
        self::assertSame(['f1', 'f2', 'g'], $this->fieldNamesAt($this->child));
        self::assertNull($this->records->ofRelationAt($this->child->id, $this->f1->id));
    }
}
