<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\InboxBundles;

final class ImportBundlesTest extends TestCase
{
    public function testSubOrdersOfOneCartShareABoxInTheOrderTheirMailsCame(): void
    {
        // The AliExpress cart of 2026-10-20, split per seller; one new order of another day stays alone.
        $boxes = InboxBundles::of([
            0 => ['platform' => 'AliExpress', 'bundle' => null, 'orderDate' => '2026-10-20 00:00:00', 'first' => '2026-10-22 09:00:00'],
            1 => ['platform' => 'Reichelt', 'bundle' => null, 'orderDate' => null, 'first' => '2026-10-21 08:00:00'],
            2 => ['platform' => 'aliexpress', 'bundle' => null, 'orderDate' => '2026-10-20', 'first' => '2026-10-21 12:00:00'],
            3 => ['platform' => 'eBay', 'bundle' => 'cart-7', 'orderDate' => null, 'first' => '2026-10-22 10:00:00'],
            4 => ['platform' => 'eBay', 'bundle' => 'cart-7', 'orderDate' => '2026-10-01', 'first' => '2026-10-22 07:00:00'],
            5 => ['platform' => 'Mouser', 'bundle' => null, 'orderDate' => null, 'first' => '2026-10-21 08:00:00'],
        ]);

        self::assertSame([[1], [5], [2, 0], [4, 3]], $boxes);
    }
}
