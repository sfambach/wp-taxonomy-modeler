<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\FieldChoiceRenderer;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Rendering;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemoryRecords;
use Taxmod\Tests\Core\Fake\InMemoryRelations;
use Taxmod\Tests\Core\Fake\RememberedTypeNodes;

/** D-1552: a filter row's kind says whose fields its field choice offers; a kind naming none falls back to the rule's node. */
final class FieldChoiceKindTest extends TestCase
{
    private const RULE_NODE = 575;   // «Knoten» at the rule
    private const KIND      = 590;   // «Art» at the filter row
    private const KEY       = 601;   // «Filterart» at the list of kinds
    private const FROM      = 602;   // «Felder von» at the list of kinds
    private const STOCK     = 77;    // Bestandsposten
    private const PURCHASE  = 78;    // Kauf
    private const PROCESS   = 79;    // Vorgänge
    private const MATERIAL  = 70;    // the rule's node

    private function rendering(InMemoryRecords $records, ?InMemoryRelations $relations = null): Rendering
    {
        $relations ??= new InMemoryRelations();
        $nodes     = new InMemoryNodes($relations);
        $root      = Node::create(1, 'Root', null, null, 0);
        $trash     = Node::create(2, 'Trash', $root->path, 1, 0);
        $nodes->add($root);
        $nodes->add($trash);

        return new Rendering($nodes, new FixedFramework($root, $trash), ShippedRenderers::registry(), new RememberedTypeNodes(), relations: $relations, records: $records);
    }

    /** @return array<string, ResolvedSetting> */
    private function settings(): array
    {
        $one = static fn (string $key, int $value): ResolvedSetting => new ResolvedSetting($key, TypedValue::ofInt($value), 1, true);

        return [
            FieldChoiceRenderer::NODE_FIELD       => $one(FieldChoiceRenderer::NODE_FIELD, self::RULE_NODE),
            FieldChoiceRenderer::KIND_FIELD       => $one(FieldChoiceRenderer::KIND_FIELD, self::KIND),
            FieldChoiceRenderer::KIND_KEY         => $one(FieldChoiceRenderer::KIND_KEY, self::KEY),
            FieldChoiceRenderer::KIND_FIELDS_FROM => $one(FieldChoiceRenderer::KIND_FIELDS_FROM, self::FROM),
        ];
    }

    #[Test]
    public function each_kind_offers_the_fields_of_its_node_and_feld_those_of_the_rule(): void
    {
        $records = new InMemoryRecords();
        // The list «Filterarten»: kinds 11 Feld (no node), 12 Bestand, 13 Einkauf, 14 Prozess — one record each (as «Präfixe»).
        foreach ([12 => self::STOCK, 13 => self::PURCHASE, 14 => self::PROCESS] as $kind => $node) {
            $records->putValue(new RelationRecord(100 + $kind, self::KEY, '', TypedValue::ofReference($kind)));
            $records->putValue(new RelationRecord(100 + $kind, self::FROM, '', TypedValue::ofReference($node)));
        }
        $records->putValue(new RelationRecord(111, self::KEY, '', TypedValue::ofReference(11)));

        $rendering = $this->rendering($records);
        $row       = static fn (int $kind): array => [self::RULE_NODE => TypedValue::ofReference(self::MATERIAL)] + ($kind > 0 ? [self::KIND => TypedValue::ofReference($kind)] : []);

        self::assertSame(self::STOCK, $rendering->offeringNode($this->settings(), $row(12)), 'Bestand');
        self::assertSame(self::PURCHASE, $rendering->offeringNode($this->settings(), $row(13)), 'Einkauf');
        self::assertSame(self::PROCESS, $rendering->offeringNode($this->settings(), $row(14)), 'Prozess');
        self::assertSame(self::MATERIAL, $rendering->offeringNode($this->settings(), $row(11)), 'Feld: a kind naming no node offers the rule\'s node');
        self::assertSame(self::MATERIAL, $rendering->offeringNode($this->settings(), $row(0)), 'no kind chosen: the rule\'s node, as before D-1552');
        self::assertSame(self::MATERIAL, $rendering->offeringNode([FieldChoiceRenderer::NODE_FIELD => $this->settings()[FieldChoiceRenderer::NODE_FIELD]], $row(12)), 'without art_feld nothing changes');
    }

    #[Test]
    public function the_kept_field_is_named_even_where_no_node_offers_it(): void
    {
        $relations = new InMemoryRelations();
        $bauform   = $relations->add(\Taxmod\Core\Model\Relation::attribute(0, self::MATERIAL, 3, \Taxmod\Core\Model\RelationKind::Aggregation, 'Bauform', 1));
        $rendering = $this->rendering(new InMemoryRecords(), $relations);

        // Live check 21.11. B2: a print rule without a node showed its field as «#149000104307».
        self::assertSame([(string) $bauform->id => 'Bauform'], $rendering->withKeptField([], $bauform->id), 'no node: the kept field by its name');
        self::assertSame(['5' => 'Toleranz', (string) $bauform->id => 'Bauform'], $rendering->withKeptField(['5' => 'Toleranz'], $bauform->id), 'added to what is offered');
        self::assertSame(['5' => 'Toleranz'], $rendering->withKeptField(['5' => 'Toleranz'], 0), 'nothing kept, nothing added');
        self::assertSame([], $rendering->withKeptField([], 999999), 'a field that is gone is not invented — it stays «#id»');
    }
}
