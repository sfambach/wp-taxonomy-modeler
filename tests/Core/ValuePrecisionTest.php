<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Type\DateTimeType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Model\ValuePrecision;

/**
 * Ein Datum merkt sich je Wert, wie genau es bekannt ist (D-932).
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class ValuePrecisionTest extends TestCase
{
    #[Test]
    public function aYearIsStoredAsItsFirstDayAndReadBackAsTheYear(): void
    {
        $jahr = TypedValue::ofKnownDate('1994');

        self::assertSame('1994-01-01 00:00:00', $jahr->date);
        self::assertSame(ValuePrecision::Year, $jahr->precision);
        self::assertSame('1994', $jahr->rawValue());
    }

    #[Test]
    public function aMonthIsStoredAsItsFirstDayAndReadBackAsTheMonth(): void
    {
        $monat = TypedValue::ofKnownDate('1997-03');

        self::assertSame('1997-03-01 00:00:00', $monat->date);
        self::assertSame('1997-03', $monat->rawValue());
    }

    #[Test]
    public function aWholeDateCarriesNoPrecision(): void
    {
        self::assertNull((new DateTimeType())->valueFrom('1997-03-07')->precision);
    }

    #[Test]
    public function theJournalRoundTripKeepsThePrecision(): void
    {
        $jahr = TypedValue::ofKnownDate('1994');

        self::assertTrue(TypedValue::ofTypeName($jahr->typeName(), $jahr->rawValue())->equals($jahr));
    }

    #[Test]
    public function theSameMomentWithAnotherPrecisionIsAnotherValue(): void
    {
        self::assertFalse(TypedValue::ofKnownDate('1994')->equals(TypedValue::ofDate('1994-01-01 00:00:00')));
    }
}
