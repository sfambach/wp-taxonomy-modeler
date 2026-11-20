<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Rules\RuleBook;

final class RuleBookTest extends TestCase
{
    public function testTheFirstRuleByPositionWhoseConditionsHoldWins(): void
    {
        // 7 = Widerstände above 70 (a resistor); 9 = Bauform THT.
        $book = new RuleBook([
            ['id' => 30, 'position' => 2, 'conditions' => [1 => 0, 2 => 0]],
            ['id' => 20, 'position' => 1, 'conditions' => [1 => 7, 2 => 0]],
            ['id' => 10, 'position' => 1, 'conditions' => [1 => 7, 2 => 9]],
        ]);

        self::assertSame([10, 20, 30], $book->order(), 'position first, then id');
        self::assertSame(10, $book->first([1 => [70, 7, 1], 2 => [9]]));
        self::assertSame(20, $book->first([1 => [70, 7, 1], 2 => [8]]), 'a condition holds with the nodes above the thing');
        self::assertSame(30, $book->first([1 => [55, 5]]), 'an empty condition always holds');
        self::assertSame(30, $book->first([1 => [70, 7]], [], static fn (int $id): bool => $id === 30), 'the caller may refuse a rule');
    }

    public function testAFilterNarrowsTheNodeByAFieldValue(): void
    {
        // Widerstände (7) are tested only when Bauform (field 40) is «SMD»; the default is «no test».
        $book = new RuleBook([['id' => 5, 'position' => 0, 'conditions' => [1 => 7], 'filters' => [['field' => 40, 'value' => 'SMD']]]]);

        self::assertSame(5, $book->first([1 => [70, 7]], [40 => ['SMD']]));
        self::assertNull($book->first([1 => [70, 7]], [40 => ['THT']]), 'a filter that does not hold drops the rule');
        self::assertNull($book->first([1 => [70, 7]]), 'a thing without the field fails the filter');

        $later = new RuleBook([['id' => 6, 'position' => 0, 'conditions' => [], 'filters' => [['field' => 40, 'value' => '5', 'operator' => 'at_least']]]]);
        self::assertNull($later->first([], [40 => ['5']]), 'an operator not built yet never holds');
    }

    public function testNoRuleHoldsGivesNullForTheCallersDefault(): void
    {
        self::assertNull((new RuleBook([['id' => 1, 'position' => 0, 'conditions' => [1 => 7]]]))->first([1 => [5]]));
        self::assertNull((new RuleBook([['id' => 1, 'position' => 0, 'conditions' => [1 => 7]]]))->first([]), 'a key left out knows nothing');
        self::assertNull((new RuleBook([]))->first([1 => [7]]));
    }
}
