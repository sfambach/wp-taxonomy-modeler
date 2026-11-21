<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Rules\RuleOrder;

final class RuleOrderTest extends TestCase
{
    public function testNeighboursWithDifferentPositionsSwapThem(): void
    {
        $rules = [1 => 10, 2 => 9990, 3 => 9991];

        self::assertSame([3 => 9990, 2 => 9991], RuleOrder::move($rules, 3, -1), 'his rule 9991 moves before the catch-all');
        self::assertSame([1 => 9990, 2 => 10], RuleOrder::move($rules, 1, 1));
    }

    public function testEqualPositionsAreNumberedAnewInTheNewOrder(): void
    {
        self::assertSame([1 => 10, 3 => 20, 2 => 30], RuleOrder::move([1 => 5, 2 => 9990, 3 => 9990], 3, -1));
    }

    public function testTheFirstCannotMoveUpNorTheLastDown(): void
    {
        $this->expectException(\DomainException::class);
        RuleOrder::move([1 => 10, 2 => 20], 1, -1);
    }

    public function testAnUnknownRuleIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        RuleOrder::move([1 => 10, 2 => 20], 7, 1);
    }
}
