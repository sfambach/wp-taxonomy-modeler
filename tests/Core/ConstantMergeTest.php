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
 * Merging a doubled constant: every value that chose it chooses the other, nothing stands twice, the double goes to the trash (D-1458).
 *
 * @see docs/NewConcept/90-decision-log.md D-1458
 */
final class ConstantMergeTest extends TestCase
{
    private InMemoryNodes $nodes;
    private InMemoryRecords $records;
    private ModelEditor $editor;
    private Node $model;
    private Node $trash;

    protected function setUp(): void
    {
        $relations     = new InMemoryRelations();
        $this->nodes   = new InMemoryNodes($relations);
        $this->records = new InMemoryRecords();
        $identities    = new CountingIdentities();

        $root        = Node::create($identities->next(), 'Root', null);
        $this->trash = Node::create($identities->next(), 'Trash', $root->path, $root->id, 0);
        $this->nodes->add($root);
        $this->nodes->add($this->trash);

        $vorab       = new ModelEditor($this->nodes, $relations, new FixedFramework($root, $this->trash), new RecordedChanges());
        $this->model = $vorab->createNode('Model', $root->id);

        $this->editor = new ModelEditor(
            $this->nodes,
            $relations,
            new FixedFramework($root, $this->trash, [Branch::Model->value => $this->model]),
            new RecordedChanges(),
            null,
            $this->records
        );
    }

    #[Test]
    public function every_choice_moves_and_a_double_falls(): void
    {
        $liste = $this->editor->createNode('Bauform', $this->model->id);
        $tht   = $this->editor->createNode('THT', $liste->id);
        $dip   = $this->editor->createNode('DIP', $liste->id);
        $chip  = $this->editor->createNode('Chip', $this->model->id);
        $feld  = $this->editor->addField($chip->id, $liste->id, 'Bauform');

        $nur   = $this->satz($chip, $feld, [$dip]);
        $beide = $this->satz($chip, $feld, [$tht, $dip]);

        $gezaehlt = $this->editor->mergeConstant($dip->id, $tht->id);

        self::assertSame(['moved' => 1, 'dropped' => 1], $gezaehlt);
        self::assertSame([$tht->id], $this->gewaehlt($nur));
        self::assertSame([$tht->id], $this->gewaehlt($beide));
        self::assertSame([], $this->records->nodeReferrers($dip->id));
        self::assertTrue($this->nodes->byId($dip->id)->isDescendantOf($this->trash));
    }

    #[Test]
    public function a_constant_the_field_cannot_choose_is_refused(): void
    {
        $liste  = $this->editor->createNode('Bauform', $this->model->id);
        $andere = $this->editor->createNode('Farbe', $this->model->id);
        $dip    = $this->editor->createNode('DIP', $liste->id);
        $rot    = $this->editor->createNode('Rot', $andere->id);
        $chip   = $this->editor->createNode('Chip', $this->model->id);
        $feld   = $this->editor->addField($chip->id, $liste->id, 'Bauform');
        $satz   = $this->satz($chip, $feld, [$dip]);

        try {
            $this->editor->mergeConstant($dip->id, $rot->id);
            self::fail('«Bauform» would have chosen a colour');
        } catch (ImpossibleMove $e) {
            self::assertStringContainsString('Bauform', $e->getMessage());
        }

        self::assertSame([$dip->id], $this->gewaehlt($satz));
        self::assertFalse($this->nodes->byId($dip->id)->isDescendantOf($this->trash));
    }

    #[Test]
    public function a_constant_with_children_or_a_field_target_is_refused(): void
    {
        $liste = $this->editor->createNode('Bauform', $this->model->id);
        $tht   = $this->editor->createNode('THT', $liste->id);
        $dip   = $this->editor->createNode('DIP', $liste->id);
        $this->editor->createNode('DIP-8', $dip->id);

        $this->expectException(ImpossibleMove::class);
        $this->editor->mergeConstant($dip->id, $tht->id);
    }

    /** @param list<Node> $gewaehlt */
    private function satz(Node $knoten, Relation $feld, array $gewaehlt): int
    {
        $satz = $this->records->add(new NodeRecord(0, $knoten->id, 1, '2026-11-18 12:00:00', RecordType::User));

        foreach ($gewaehlt as $position => $wahl) {
            $this->records->putValue(new RelationRecord($satz, $feld->id, '', TypedValue::ofReference($wahl->id), null, $position));
        }

        return $satz;
    }

    /** @return list<int> */
    private function gewaehlt(int $satz): array
    {
        return array_map(static fn (RelationRecord $r): int => (int) $r->value->reference, $this->records->valuesOf($satz));
    }
}
