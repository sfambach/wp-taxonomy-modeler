<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Model\ValuePrecision;

/** D-1489: the raw value straight from the typed columns is the raw value of the value built from them — one rule, two doors. */
final class RawValueTest extends TestCase
{
    /** @return iterable<string, array{?int, ?string, ?string, ?string, ?int, ?ValuePrecision, string}> */
    public static function columns(): iterable
    {
        yield 'nothing'        => [null, null, null, null, null, null, ''];
        yield 'a count'        => [7, null, null, null, null, null, '7'];
        yield 'zero'           => [0, null, null, null, null, null, '0'];
        yield 'a decimal'      => [null, '2.50', null, null, null, null, '2.50'];
        yield 'a text'         => [null, null, 'OLED 0,91', null, null, null, 'OLED 0,91'];
        yield 'an empty text'  => [null, null, '', null, null, null, ''];
        yield 'a day'          => [null, null, null, '2026-11-19 00:00:00', null, null, '2026-11-19 00:00:00'];
        yield 'a month'        => [null, null, null, '2026-11-01 00:00:00', null, ValuePrecision::Month, '2026-11'];
        yield 'a year'         => [null, null, null, '2026-01-01 00:00:00', null, ValuePrecision::Year, '2026'];
        yield 'a reference'    => [null, null, null, null, 4711, null, '4711'];
    }

    #[Test]
    #[DataProvider('columns')]
    public function the_columns_answer_as_the_value_built_from_them(?int $int, ?string $decimal, ?string $text, ?string $date, ?int $reference, ?ValuePrecision $precision, string $raw): void
    {
        self::assertSame($raw, TypedValue::rawOf($int, $decimal, $text, $date, $reference, $precision));
        self::assertSame($raw, TypedValue::fromStorage($int, $decimal, $text, $date, $reference, null, $precision)->rawValue());
    }
}
