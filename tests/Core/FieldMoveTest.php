<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\ImpossibleMove;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Tests\Core\Fake\CountingIdentities;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemoryRecords;
use Taxmod\Tests\Core\Fake\InMemoryRelations;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/**
 * Moving a field to any node and merging two fields, without hiding a value (D-941).
 *
 * @see docs/pakete/aufraeumen/baum.md
 */
final class FieldMoveTest extends TestCase
{
    private InMemoryNodes $nodes;
    private InMemoryRelations $relations;
    private InMemoryRecords $records;
    private ModelEditor $editor;
    private Node $model;
    private ?Node $text = null;

    protected function setUp(): void
    {
        $this->relations = new InMemoryRelations();
        $this->nodes     = new InMemoryNodes($this->relations);
        $this->records   = new InMemoryRecords();
        $identities      = new CountingIdentities();

        $root  = Node::create($identities->next(), 'Root', null);
        $trash = Node::create($identities->next(), 'Trash', $root->path, $root->id, 0);
        $this->nodes->add($root);
        $this->nodes->add($trash);

        $vorab = new ModelEditor($this->nodes, $this->relations, new FixedFramework($root, $trash), new RecordedChanges());
        $this->model = $vorab->createNode('Model', $root->id);

        $this->editor = new ModelEditor(
            $this->nodes,
            $this->relations,
            new FixedFramework($root, $trash, [Branch::Model->value => $this->model]),
            new RecordedChanges(),
            null,
            $this->records
        );
    }

    #[Test]
    public function a_moved_field_keeps_its_id_and_its_values(): void
    {
        $alt  = $this->editor->createNode('Alt', $this->model->id);
        $neu  = $this->editor->createNode('Neu', $this->model->id);
        $chip = $this->editor->createNode('Chip', $alt->id);
        $feld = $this->editor->addField($alt->id, $this->text()->id, 'Teilenummer');
        $satz = $this->satzMit($chip, $feld, 'Z80');

        $this->editor->move($chip->id, $neu->id);
        $bewegt = $this->editor->moveFieldTo($alt->id, $feld->id, $neu->id);

        self::assertSame($feld->id, $bewegt->id);
        self::assertSame($neu->id, $bewegt->fromNodeId);
        self::assertSame('Z80', $this->records->valuesOf($satz)[0]->value->text);
    }

    #[Test]
    public function a_move_that_would_hide_a_value_is_refused(): void
    {
        $alt  = $this->editor->createNode('Alt', $this->model->id);
        $neu  = $this->editor->createNode('Neu', $this->model->id);
        $chip = $this->editor->createNode('Chip', $alt->id);
        $feld = $this->editor->addField($alt->id, $this->text()->id, 'Teilenummer');
        $this->satzMit($chip, $feld, 'Z80');

        try {
            $this->editor->moveFieldTo($alt->id, $feld->id, $neu->id);
            self::fail('a record under «Alt» would have lost its field');
        } catch (ImpossibleMove) {
            self::assertSame($alt->id, $this->relations->byId($feld->id)?->fromNodeId);
        }
    }

    #[Test]
    public function merged_values_move_to_the_other_field_and_the_old_one_is_parked(): void
    {
        $hardware = $this->editor->createNode('Hardware', $this->model->id);
        $bauteile = $this->editor->createNode('Bauteile', $this->model->id);
        $chip     = $this->editor->createNode('Chip', $hardware->id);
        $alt      = $this->editor->addField($hardware->id, $this->text()->id, 'Teilenummer');
        $neu      = $this->editor->addField($bauteile->id, $this->text()->id, 'Teilenummer');
        $satz     = $this->satzMit($chip, $alt, 'Z80');

        $this->editor->move($chip->id, $bauteile->id);
        $this->editor->mergeField($hardware->id, $alt->id, $neu->id);

        $werte = $this->records->valuesOf($satz);
        self::assertSame($neu->id, $werte[0]->relationId);
        self::assertSame('Z80', $werte[0]->value->text);
        self::assertSame([], $this->relations->fieldRelationsOf([$hardware->id]), 'das alte Feld ist geparkt');
    }

    #[Test]
    public function a_record_holding_values_in_both_fields_stops_the_merge(): void
    {
        $oben  = $this->editor->createNode('Oben', $this->model->id);
        $unten = $this->editor->createNode('Unten', $oben->id);
        $alt   = $this->editor->addField($unten->id, $this->text()->id, 'a');
        $neu   = $this->editor->addField($oben->id, $this->text()->id, 'b');
        $satz  = $this->satzMit($unten, $alt, 'eins');
        $this->records->putValue(new RelationRecord($satz, $neu->id, '', TypedValue::ofText('zwei')));

        $this->expectException(ImpossibleMove::class);

        $this->editor->mergeField($unten->id, $alt->id, $neu->id);
    }

    #[Test]
    public function merging_only_under_a_node_keeps_the_old_field_for_the_rest(): void
    {
        $hardware = $this->editor->createNode('Hardware', $this->model->id);
        $chips    = $this->editor->createNode('Chips', $hardware->id);
        $karten   = $this->editor->createNode('Karten', $hardware->id);
        $alt      = $this->editor->addField($hardware->id, $this->text()->id, 'Teilenummer');
        $neu      = $this->editor->addField($chips->id, $this->text()->id, 'Teilenummer');
        $chip     = $this->satzMit($chips, $alt, 'Z80');
        $karte    = $this->satzMit($karten, $alt, 'SB16');

        $this->editor->mergeField($hardware->id, $alt->id, $neu->id, $chips->id);

        self::assertSame($neu->id, $this->records->valuesOf($chip)[0]->relationId);
        self::assertSame($alt->id, $this->records->valuesOf($karte)[0]->relationId);
        self::assertCount(1, $this->relations->fieldRelationsOf([$hardware->id]));
    }

    #[Test]
    public function merging_under_a_node_keeps_the_old_field_even_when_nothing_else_holds_a_value(): void
    {
        $modelle  = $this->editor->createNode('Modelle', $this->model->id);
        $software = $this->editor->createNode('Software', $modelle->id);
        $this->editor->createNode('Hardware', $modelle->id);
        $alt      = $this->editor->addField($modelle->id, $this->text()->id, 'Nachfolger');
        $neu      = $this->editor->addField($software->id, $this->text()->id, 'Nachfolger');
        $this->satzMit($software, $alt, 'Windows 95');

        $this->editor->mergeField($modelle->id, $alt->id, $neu->id, $software->id);

        self::assertCount(1, $this->relations->fieldRelationsOf([$modelle->id]), 'die Hardware braucht das Feld weiter');
    }

    #[Test]
    public function fields_with_different_targets_are_not_merged(): void
    {
        $knoten = $this->editor->createNode('Knoten', $this->model->id);
        $anders = $this->editor->createNode('Anders', $this->model->id);
        $alt    = $this->editor->addField($knoten->id, $this->text()->id, 'a');
        $neu    = $this->editor->addField($knoten->id, $anders->id, 'b');

        $this->expectException(ImpossibleMove::class);

        $this->editor->mergeField($knoten->id, $alt->id, $neu->id);
    }

    private function text(): Node
    {
        return $this->text ??= $this->editor->createNode('Wert', $this->model->id);
    }

    private function satzMit(Node $knoten, Relation $feld, string $wert): int
    {
        $satz = $this->records->add(new NodeRecord(0, $knoten->id, 1, '2026-10-20 12:00:00', RecordType::User));
        $this->records->putValue(new RelationRecord($satz, $feld->id, '', TypedValue::ofText($wert)));

        return $satz;
    }
}
