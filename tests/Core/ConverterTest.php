<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Converter\BinaryConverter;
use Taxmod\Core\Converter\Converter;
use Taxmod\Core\Converter\ConverterKind;
use Taxmod\Core\Converter\HexadecimalConverter;
use Taxmod\Core\Converter\OctalConverter;
use Taxmod\Core\Converter\RomanNumeralConverter;
use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Exception\NotAPossibleTarget;
use Taxmod\Core\Exception\NotAValueOfThatType;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * The converters, and the two rules that are easy to get wrong.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ConverterTest extends TestCase
{
    // ------------------------------------------------------------ hexadecimal

    #[Test]
    public function a_whole_number_goes_out_and_comes_back(): void
    {
        $converter = new HexadecimalConverter();

        foreach ([0, 1, 15, 16, 255, 4095, 65_535, 1_000_000] as $number) {
            self::assertSame(
                $number,
                $converter->written($converter->shown(TypedValue::ofInt($number)))->int,
                'the round trip is what makes it invertible (D-076)'
            );
        }
    }

    #[Test]
    public function it_is_upper_case_and_carries_no_prefix(): void
    {
        // ⚠️ A `0x` on the way out that is optional on the way in would be two forms for one value,
        // and the round trip would stop being one.
        self::assertSame('FF', (new HexadecimalConverter())->shown(TypedValue::ofInt(255)));
    }

    #[Test]
    public function a_negative_keeps_its_sign_in_front(): void
    {
        // Two's complement depends on a width nobody stated, and it would not round-trip a bigint.
        $converter = new HexadecimalConverter();

        self::assertSame('-1A', $converter->shown(TypedValue::ofInt(-26)));
        self::assertSame(-26, $converter->written('-1A')->int);
    }

    #[Test]
    public function characters_that_are_not_hexadecimal_are_refused_and_not_read_as_zero(): void
    {
        // ⚠️ `hexdec('zz')` is `0`, and a zero that arrived that way is indistinguishable afterwards
        // from a zero somebody meant — the same call D-071 makes about typed columns.
        $this->expectException(NotAValueOfThatType::class);

        (new HexadecimalConverter())->written('zz');
    }

    #[Test]
    public function an_empty_string_is_refused_rather_than_read_as_zero(): void
    {
        $this->expectException(NotAValueOfThatType::class);

        (new HexadecimalConverter())->written('');
    }

    // ------------------------------------------------------- binary and octal

    #[Test]
    public function binary_and_octal_write_the_digits_and_read_them_back(): void
    {
        // ⚠️ **R34 named all three bases on 2026-08-22** — *«show a number as binary, hexadecimal,
        // octal or in Roman numerals»* — so this is an old sentence being kept, not a new idea.
        $binary = new BinaryConverter();
        $octal  = new OctalConverter();

        self::assertSame('1100', $binary->shown(TypedValue::ofInt(12)));
        self::assertSame('11111111', $binary->shown(TypedValue::ofInt(255)));
        self::assertSame('755', $octal->shown(TypedValue::ofInt(493)));
        self::assertSame('10', $octal->shown(TypedValue::ofInt(8)));

        foreach ([$binary, $octal] as $converter) {
            foreach ([0, 1, 7, 8, 15, 16, 255, 4095, 65_535, 1_000_000] as $number) {
                self::assertSame(
                    $number,
                    $converter->written($converter->shown(TypedValue::ofInt($number)))->int,
                    $converter->name() . ': the round trip is what makes it invertible (D-076)'
                );
            }
        }
    }

    #[Test]
    public function a_leading_zero_does_not_switch_bases(): void
    {
        // ⚠️ **The one trap this base carries.** In C, in PHP and in a file mode `0755` *means*
        // octal — here it means nothing at all: it is the same number as `755`, and `755` is the
        // only form ever written. *A zero that changed the base would be the second form for one
        // value that breaks the round trip.*
        $octal = new OctalConverter();

        self::assertSame(493, $octal->written('0755')->int);
        self::assertSame(493, $octal->written('755')->int);
        self::assertSame('755', $octal->shown(TypedValue::ofInt(493)));
    }

    #[Test]
    public function a_negative_keeps_its_sign_in_front_in_every_base(): void
    {
        // Two's complement depends on a width nobody stated, and it would not round-trip a bigint.
        self::assertSame('-1100', (new BinaryConverter())->shown(TypedValue::ofInt(-12)));
        self::assertSame('-755', (new OctalConverter())->shown(TypedValue::ofInt(-493)));

        self::assertSame(-12, (new BinaryConverter())->written('-1100')->int);
        self::assertSame(-493, (new OctalConverter())->written('-755')->int);
    }

    #[Test]
    public function a_digit_the_base_does_not_have_is_refused(): void
    {
        // ⚠️ `bindec('2')` is `0` and `octdec('9')` is `0` — the same quiet zero `D-071` refuses.
        $refused = [
            [new BinaryConverter(), ['2', '102', 'zz', '0b11', '']],
            [new OctalConverter(), ['8', '9', '75x', '0o7', '']],
        ];

        foreach ($refused as [$converter, $inputs]) {
            foreach ($inputs as $written) {
                try {
                    $converter->written($written);
                    self::fail(sprintf('%s: «%s» should have been refused', $converter->name(), $written));
                } catch (NotAValueOfThatType) {
                    self::assertTrue(true);
                }
            }
        }
    }

    // ------------------------------------------- what every base has to survive

    #[Test]
    public function the_smallest_whole_number_is_drawn_instead_of_crashing(): void
    {
        // ⚠️ **`abs(PHP_INT_MIN)` is a `float`, and `dechex()` refuses one.** *Measured 2026-08-29:
        // the hexadecimal converter threw a `TypeError` here — a crash while **drawing** a value the
        // `bigint` column is allowed to hold, which is not a domain error at all.*
        foreach ($this->numeralSystems() as $converter) {
            $shown = $converter->shown(TypedValue::ofInt(PHP_INT_MIN));

            self::assertStringStartsWith('-', $shown, $converter->name());
            self::assertSame(
                PHP_INT_MIN,
                $converter->written($shown)->int,
                $converter->name() . ': and it comes back'
            );
        }

        self::assertSame('-8000000000000000', (new HexadecimalConverter())->shown(TypedValue::ofInt(PHP_INT_MIN)));
        self::assertSame('-1000000000000000000000', (new OctalConverter())->shown(TypedValue::ofInt(PHP_INT_MIN)));
    }

    #[Test]
    public function a_number_too_large_for_a_whole_number_is_refused_and_not_read_as_zero(): void
    {
        // ⚠️ **Measured 2026-08-29: `written('FFFFFFFFFFFFFFFF')` returned `0`** — `(int)` on a
        // `float` that is out of range. *That is exactly the quiet zero the converter's own comment
        // was written against, arriving through the other door.*
        $tooLarge = [
            'binary'      => str_repeat('1', 64),
            'hexadecimal' => 'FFFFFFFFFFFFFFFF',
            'octal'       => '2000000000000000000000',
        ];

        foreach ($this->numeralSystems() as $converter) {
            try {
                $converter->written($tooLarge[$converter->name()]);
                self::fail($converter->name() . ': a number no `int` can hold should have been refused');
            } catch (NotAValueOfThatType) {
                self::assertTrue(true);
            }
        }

        // ⚠️ **The one that is not caught by the size of the result but by the step before it.**
        // *`2^63 + 512` is the crafted case: once the multiplication has overflowed, the value is a
        // `float`, and the **nearest** `float` to it is `PHP_INT_MIN` itself — so every test made
        // afterwards says «still in range» and a `float` walks out through a signature that says
        // `int`. **Measured: without the guard before the multiplication this is a `TypeError` and
        // not a refusal.***
        try {
            (new BinaryConverter())->written('1' . str_repeat('0', 53) . '1' . str_repeat('0', 9));
            self::fail('binary: 2^63 + 512 should have been refused');
        } catch (NotAValueOfThatType) {
            self::assertTrue(true);
        }

        // ⚠️ *And the largest one that **does** fit is not refused with it — a guard that refuses one
        // too many is as wrong as one that refuses none.*
        self::assertSame(PHP_INT_MAX, (new HexadecimalConverter())->written('7FFFFFFFFFFFFFFF')->int);
        self::assertSame(PHP_INT_MAX, (new BinaryConverter())->written(str_repeat('1', 63))->int);
        self::assertSame(PHP_INT_MAX, (new OctalConverter())->written('777777777777777777777')->int);
    }

    #[Test]
    public function zero_is_a_single_zero_and_never_an_empty_string(): void
    {
        // ⚠️ *A blank would read as **not answered** (D-232) for a value that is answered.*
        foreach ([new BinaryConverter(), new OctalConverter(), new HexadecimalConverter()] as $converter) {
            self::assertSame('0', $converter->shown(TypedValue::ofInt(0)), $converter->name());
            self::assertSame(0, $converter->written('0')->int, $converter->name());
        }
    }

    /** @return list<Converter> */
    private function numeralSystems(): array
    {
        return [new BinaryConverter(), new HexadecimalConverter(), new OctalConverter()];
    }

    // ------------------------------------------------------------------ roman

    #[Test]
    public function roman_uses_the_subtractive_pairs(): void
    {
        $converter = new RomanNumeralConverter();

        self::assertSame('IV', $converter->shown(TypedValue::ofInt(4)), 'IV, never IIII');
        self::assertSame('IX', $converter->shown(TypedValue::ofInt(9)));
        self::assertSame('XII', $converter->shown(TypedValue::ofInt(12)), "R36's own example");
        self::assertSame('MCMXCIV', $converter->shown(TypedValue::ofInt(1994)));
        self::assertSame('MMMCMXCIX', $converter->shown(TypedValue::ofInt(3999)), 'the largest it writes');
    }

    #[Test]
    public function roman_reads_back_what_it_writes_across_the_whole_range(): void
    {
        // ⚠️ **The whole range, because a greedy writer and a walking reader can disagree in one
        // place and look fine in ten.** R36 turns on this direction working: `> XII` in a search box
        // has to parse before the query touches the database.
        $converter = new RomanNumeralConverter();

        for ($number = 1; $number <= 3999; ++$number) {
            self::assertSame($number, $converter->written($converter->shown(TypedValue::ofInt($number)))->int);
        }
    }

    #[Test]
    public function roman_refuses_a_form_it_would_never_write(): void
    {
        // ⚠️ `IIII` parses to four and `IC` to ninety-nine — and neither is how this notation writes
        // them. Accepting them would make the mapping non-invertible while claiming otherwise.
        $converter = new RomanNumeralConverter();

        foreach (['IIII', 'IC', 'VX', 'MMMM'] as $written) {
            try {
                $converter->written($written);
                self::fail(sprintf('«%s» should have been refused', $written));
            } catch (NotAValueOfThatType) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function roman_marks_a_number_it_cannot_write_instead_of_drawing_nothing(): void
    {
        // ⚠️ **Not an empty string.** A blank would read as *not answered* (D-232) for a value that
        // is answered — the one thing a fallback must never do.
        $converter = new RomanNumeralConverter();

        self::assertSame('—', $converter->shown(TypedValue::ofInt(0)));
        self::assertSame('—', $converter->shown(TypedValue::ofInt(-3)));
        self::assertSame('—', $converter->shown(TypedValue::ofInt(4000)));
    }

    #[Test]
    public function it_is_read_case_insensitively_and_written_in_capitals(): void
    {
        self::assertSame(12, (new RomanNumeralConverter())->written('xii')->int);
    }

    // --------------------------------------------------------------- registry

    #[Test]
    public function the_registry_answers_by_type_and_refuses_a_name_nobody_registered(): void
    {
        $registry = ShippedConverters::registry();

        $names = array_map(static fn ($one): string => $one->name(), $registry->eligibleFor(SimpleType::Int));

        // ⚠️ **Registration order, and it is R34's own sentence** — *binary, hexadecimal, octal or
        // in Roman numerals.* The choice control offers them in exactly this order.
        self::assertSame(['binary', 'hexadecimal', 'octal', 'roman'], $names);
        self::assertSame([], $registry->eligibleFor(SimpleType::Text), 'none of them maps characters');
        self::assertSame([], $registry->eligibleFor(null), 'and a subject with no simple type has none');

        $this->expectException(NotAPossibleTarget::class);

        $registry->byName('kein-konverter');
    }

    #[Test]
    public function every_shipped_converter_is_invertible_so_both_directions_are_exercised(): void
    {
        // ⚠️ *Deliberate: a display-only first converter would have left `written()` untested, and
        // R36's search case is the reason that half of the contract exists.*
        foreach (ShippedConverters::registry()->eligibleFor(SimpleType::Int) as $one) {
            self::assertTrue($one->isInvertible(), $one->name() . ' must serve input and search');
            self::assertSame(ConverterKind::Format, $one->kind());
        }

        self::assertCount(4, ShippedConverters::registry()->invertibleFor(SimpleType::Int));
    }

    #[Test]
    public function the_seed_plants_the_names_the_code_knows_and_no_others(): void
    {
        // ⚠️ **This is what `RenderingScaffold` sows** — it never enumerates the names itself
        // (D-511). *A converter that is registered and not planted is one the choice never offers,
        // and nothing would go red.* The boundary run measures the nodes; this measures the list.
        self::assertSame(
            ['binary', 'hexadecimal', 'octal', 'roman'],
            ShippedConverters::registry()->namesForNodes(),
            'sorted, so two runs sow in the same order'
        );
    }

    #[Test]
    public function no_converter_is_marked_default_for_a_type(): void
    {
        // ⚠️ **The difference from the renderer registry, and it is R33b.** A renderer must always be
        // resolved because something has to draw the field; *no converter* is a complete answer — the
        // value is shown as it is stored. There is deliberately no `defaultFor()` here to call.
        self::assertFalse(
            method_exists(ShippedConverters::registry(), 'defaultFor'),
            'a default converter would map every number in the installation the moment one was registered'
        );
    }
}
