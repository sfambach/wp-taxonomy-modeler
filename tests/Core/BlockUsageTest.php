<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Service\BlockUsage;

final class BlockUsageTest extends TestCase
{
    #[Test]
    public function every_record_block_becomes_one_row_also_when_nested(): void
    {
        $usage = new BlockUsage();
        $rows  = $usage->rows([[
            'id' => 14256, 'title' => 'Backplane, "8 slots"', 'status' => 'publish', 'type' => 'post',
            'blocks' => [
                ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => []],
                ['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [
                    ['blockName' => 'taxmod/record', 'attrs' => ['record' => 39064, 'rowFields' => '1,2'], 'innerBlocks' => []],
                ]],
                ['blockName' => 'taxmod/record', 'attrs' => ['display' => 'comparison', 'node' => 7, 'records' => '1, 2'], 'innerBlocks' => []],
            ],
        ]]);

        self::assertCount(2, $rows);
        self::assertSame(39064, $rows[0]['record']);
        self::assertSame(0, $rows[0]['node']);
        self::assertSame('comparison', $rows[1]['display']);
        self::assertSame([39064], $usage->recordsWithoutNode($rows));

        $rows = $usage->withNodes($rows, [39064 => 99]);
        self::assertSame(99, $rows[0]['node']);
        self::assertStringContainsString('14256,"Backplane, ""8 slots""",publish,post,record,99,39064,,0,0,"1,2"', $usage->csv($rows));
    }
}
