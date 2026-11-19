<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Service\PageTimes;

final class PageTimesTest extends TestCase
{
    #[Test]
    public function the_ring_keeps_the_newest_calls_and_forgets_the_oldest(): void
    {
        $entries = [];

        for ($i = 1; $i <= PageTimes::KEEP + 5; $i++) {
            $entries = PageTimes::with($entries, ['p' => 'p', 'ms' => $i]);
        }

        self::assertCount(PageTimes::KEEP, $entries);
        self::assertSame(6, $entries[0]['ms']);
        self::assertSame(PageTimes::KEEP + 5, $entries[PageTimes::KEEP - 1]['ms']);
    }

    #[Test]
    public function the_summary_gives_middle_and_longest_per_page_slowest_first(): void
    {
        $call    = static fn (string $page, int $ms, int $queries, int $at): array => ['p' => $page, 'ms' => $ms, 'q' => $queries, 't' => $at];
        $summary = PageTimes::summary([
            $call('fast', 100, 40, 1000),
            $call('slow', 900, 70, 1060),
            $call('slow', 3000, 72, 1180),
            $call('slow', 1100, 74, 1120),
            $call('fast', 300, 44, 1240),
        ]);

        self::assertSame(['slow', 'fast'], array_keys($summary));
        self::assertSame(['calls' => 3, 'median_ms' => 1100, 'max_ms' => 3000, 'median_queries' => 72, 'last' => 1180], $summary['slow']);
        self::assertSame(200, $summary['fast']['median_ms']);
    }

    #[Test]
    public function no_calls_give_no_summary(): void
    {
        self::assertSame([], PageTimes::summary([]));
    }
}
