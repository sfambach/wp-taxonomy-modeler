<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\RecordInTree;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\RecordQuery;
use Taxmod\Tests\Core\Fake\InMemoryRecords;

/**
 * Records found by their values instead of reading a whole node ([D-1209](../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *His trigger: «is anything stored under Alex?» meant 561 lots and every transaction. The answer must come from the
 * question, not from the node.*
 */
final class RecordQueryTest extends TestCase
{
    #[Test]
    public function aWrongQuestionIsRefusedWithItsReason(): void
    {
        foreach ([
            [[], 'Name a node'],
            [['node' => 5, 'where' => [['field' => 0]]], 'field must be'],
            [['node' => 5, 'where' => [['field' => 7, 'op' => 'like', 'value' => 'x']]], 'op must be'],
            [['node' => 5, 'where' => [['field' => 7, 'op' => 'eq']]], 'needs a value'],
            [['node' => 5, 'where' => [['field' => 7, 'op' => 'eq', 'values' => ['a', 'b']]]], 'use in'],
            [['node' => 5, 'where' => [['field' => 7, 'op' => 'within', 'value' => '12']]], 'parent_field'],
            [['node' => 5, 'limit' => 501], 'limit'],
        ] as [$input, $reason]) {
            try {
                RecordQuery::from($input);
                self::fail('Accepted: ' . json_encode($input));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($reason, $e->getMessage());
            }
        }
    }

    #[Test]
    public function withinMeansTheRecordAndEverythingBelowIt(): void
    {
        // Haus(1) › Alex(2) › Kiste(3) › Fach(4); Garage(5)
        $tree = [
            new RecordInTree(1, null, 'Haus'),
            new RecordInTree(2, 1, 'Alex'),
            new RecordInTree(3, 2, 'Kiste'),
            new RecordInTree(4, 3, 'Fach'),
            new RecordInTree(5, null, 'Garage'),
        ];

        $ids = RecordQuery::within($tree, 2);
        sort($ids);

        self::assertSame(['2', '3', '4'], $ids);
        self::assertSame(['9'], RecordQuery::within($tree, 9), 'a record the tree does not know stands for itself');

        $q = RecordQuery::from(['node' => 50, 'where' => [['field' => 77, 'op' => 'within', 'value' => 2, 'parent_field' => 70]], 'search' => 'NE555']);

        self::assertSame([
            ['field' => 77, 'op' => 'in', 'values' => ['2', '3', '4']],
            ['field' => 0, 'op' => 'contains', 'values' => ['NE555']],
        ], $q->forTable([0 => ['2', '3', '4']]));
    }

    #[Test]
    public function theTableAnswersOnePageAndTheTotal(): void
    {
        $records = new InMemoryRecords();
        $alex    = 900;
        $made    = [];

        foreach (['NE555', 'NE556', 'LM317', 'NE555 DIP'] as $i => $name) {
            $id     = $records->add(new NodeRecord(0, 50, 1, '2026-11-06'));
            $made[] = $id;
            $records->putValue(new RelationRecord($id, 10, '', TypedValue::ofText($name)));
            $records->putValue(new RelationRecord($id, 77, '', TypedValue::ofRecordReference($i < 2 ? $alex : 901)));
        }

        $records->add(new NodeRecord(0, 50, 1, '2026-11-06', RecordType::Settings));

        self::assertSame(['total' => 2, 'ids' => [$made[0], $made[1]]], $records->recordsWhere([50], [['field' => 77, 'op' => 'in', 'values' => [(string) $alex]]], 10, 0));
        self::assertSame(['total' => 2, 'ids' => [$made[3]]], $records->recordsWhere([50], [['field' => 0, 'op' => 'contains', 'values' => ['ne555']]], 10, 1));
        self::assertSame(['total' => 1, 'ids' => [$made[0]]], $records->recordsWhere([50], [['field' => 10, 'op' => 'eq', 'values' => ['NE555']]], 10, 0));
        self::assertSame(4, $records->recordsWhere([50], [['field' => 10, 'op' => 'not_empty', 'values' => []]], 10, 0)['total'], 'the settings record stays out');
        self::assertSame(0, $records->recordsWhere([50], [['field' => 11, 'op' => 'not_empty', 'values' => []]], 10, 0)['total']);
        self::assertSame(4, $records->recordsWhere([50], [['field' => 11, 'op' => 'empty', 'values' => []]], 10, 0)['total']);
    }
}
