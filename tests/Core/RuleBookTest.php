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

    public function testLiesBelowHoldsForTheNodeAndEveryNodeBelowIt(): void
    {
        // D-1563: Bauform (field 40) «SMD» = 9; «0603» = 91 lies below it, «THT» = 8 does not. The caller hands in the nodes above.
        $book  = new RuleBook([['id' => 5, 'position' => 0, 'conditions' => [], 'filters' => [['field' => 40, 'value' => '9', 'operator' => RuleBook::BELOW]]]]);
        $above = ['91' => ['1', '9'], '8' => ['1'], '9' => ['1']];

        self::assertSame([40], $book->fieldsBelow(), 'only the fields of «liegt unter» need the nodes above');
        self::assertSame(5, $book->first([], [40 => ['91']], null, $above), 'a value below the node holds');
        self::assertSame(5, $book->first([], [40 => ['9']], null, $above), 'the node itself holds');
        self::assertNull($book->first([], [40 => ['8']], null, $above), 'a value beside it does not');
        self::assertNull($book->first([], [40 => ['91']]), 'without the nodes above, a value is only itself');
        self::assertNull($book->first([], [], null, $above), 'a thing without the field fails the filter');
        self::assertSame([], (new RuleBook([['id' => 1, 'position' => 0, 'conditions' => [], 'filters' => [['field' => 40, 'value' => '9']]]]))->fieldsBelow());
    }

    public function testNoRuleHoldsGivesNullForTheCallersDefault(): void
    {
        self::assertNull((new RuleBook([['id' => 1, 'position' => 0, 'conditions' => [1 => 7]]]))->first([1 => [5]]));
        self::assertNull((new RuleBook([['id' => 1, 'position' => 0, 'conditions' => [1 => 7]]]))->first([]), 'a key left out knows nothing');
        self::assertNull((new RuleBook([]))->first([1 => [7]]));
    }

    /** D-1552: one case per operator. */
    public function testEachOperator(): void
    {
        $rule = static fn (string $op, string $value): RuleBook => new RuleBook([['id' => 1, 'position' => 0, 'conditions' => [], 'filters' => [['field' => 9, 'value' => $value, 'operator' => $op]]]]);

        self::assertSame(1, $rule(RuleBook::EQUALS, 'gebraucht')->first([], [9 => ['gebraucht']]));
        self::assertSame(1, $rule(RuleBook::NOT_EQUALS, 'neu')->first([], [9 => ['gebraucht']]));
        self::assertNull($rule(RuleBook::NOT_EQUALS, 'neu')->first([], [9 => ['neu']]));
        self::assertNull($rule(RuleBook::NOT_EQUALS, 'neu')->first([], []), '«ungleich» needs the field');
        self::assertSame(1, $rule(RuleBook::GREATER, '10')->first([], [9 => ['10.5']]));
        self::assertNull($rule(RuleBook::GREATER, '10')->first([], [9 => ['10.00']]), 'equal is not greater');
        self::assertNull($rule(RuleBook::GREATER, '10')->first([], [9 => ['9']]));
        self::assertSame(1, $rule(RuleBook::LESS, '1,5')->first([], [9 => ['-3']]));
        self::assertNull($rule(RuleBook::LESS, '1.5')->first([], [9 => ['2']]));
        self::assertNull($rule(RuleBook::GREATER, '1')->first([], [9 => ['5 V']]), 'a value with a unit never holds yet (D-1555)');
        self::assertSame(1, $rule(RuleBook::CONTAINS, 'smd')->first([], [9 => ['0805 SMD']]), '«enthält» ignores case');
        self::assertNull($rule(RuleBook::CONTAINS, 'tht')->first([], [9 => ['0805 SMD']]));
        self::assertNull($rule('at_least', '1')->first([], [9 => ['5']]), 'an operator not built never holds');
    }

    public function testDecimalsCompareWithoutFloats(): void
    {
        self::assertSame(0, RuleBook::compareDecimal('0.1', '0.10'));
        self::assertSame(1, RuleBook::compareDecimal('100000000000000000001', '100000000000000000000'), 'past float precision');
        self::assertSame(-1, RuleBook::compareDecimal('-2', '-1'));
        self::assertSame(0, RuleBook::compareDecimal('-0', '0'));
        self::assertNull(RuleBook::compareDecimal('abc', '1'));
    }
}
