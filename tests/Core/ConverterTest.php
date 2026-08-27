<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Converter\ConverterKind;
use Taxmod\Core\Converter\HexadecimalConverter;
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

        self::assertSame(['hexadecimal', 'roman'], $names);
        self::assertSame([], $registry->eligibleFor(SimpleType::Text), 'neither maps characters');
        self::assertSame([], $registry->eligibleFor(null), 'and a subject with no simple type has none');

        $this->expectException(NotAPossibleTarget::class);

        $registry->byName('kein-konverter');
    }

    #[Test]
    public function both_shipped_converters_are_invertible_so_both_directions_are_exercised(): void
    {
        // ⚠️ *Deliberate: a display-only first converter would have left `written()` untested, and
        // R36's search case is the reason that half of the contract exists.*
        foreach (ShippedConverters::registry()->eligibleFor(SimpleType::Int) as $one) {
            self::assertTrue($one->isInvertible(), $one->name() . ' must serve input and search');
            self::assertSame(ConverterKind::Format, $one->kind());
        }

        self::assertCount(2, ShippedConverters::registry()->invertibleFor(SimpleType::Int));
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
