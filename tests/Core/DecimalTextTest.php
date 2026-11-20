<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Renderer\DecimalText;

final class DecimalTextTest extends TestCase
{
    public function testTheStoredFormHasAPointAndNoTrailingZeros(): void
    {
        self::assertSame('2.5', DecimalText::stored(2.5));
        self::assertSame('3', DecimalText::stored(3.0));
        self::assertSame('1000', DecimalText::stored(1000.0));
        self::assertSame('0.333', DecimalText::stored(1 / 3, 3));
        self::assertSame('0', DecimalText::stored(-0.0000001));
        self::assertSame('-1.25', DecimalText::stored(-1.25));
    }

    public function testShownWithTheSiteDecimalSign(): void
    {
        self::assertSame('0,91', DecimalText::shown('0.91', ','));
        self::assertSame('0.91', DecimalText::shown('0.91', '.'));
        self::assertSame('12', DecimalText::shown('12', ','));
    }

    /** D-1501: what a person types comes back in the stored form, German and stored alike; nothing guessed. */
    public function testTypedInputBecomesTheStoredForm(): void
    {
        self::assertSame('1234.5', DecimalText::typed('1.234,5'));
        self::assertSame('1234.5', DecimalText::typed('1234,5'));
        self::assertSame('1234.5', DecimalText::typed('1234.5'));
        self::assertSame('1234.5', DecimalText::typed('1,234.5'));
        self::assertSame('1234', DecimalText::typed(' 1 234 '));
        self::assertSame('-0.25', DecimalText::typed('-0,25'));
        self::assertNull(DecimalText::typed(''));
        self::assertNull(DecimalText::typed('   '));
        self::assertNull(DecimalText::typed('zwei'));
    }
}

