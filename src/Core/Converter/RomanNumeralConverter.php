<?php declare(strict_types=1);

namespace Taxmod\Core\Converter;

use Taxmod\Core\Exception\NotAValueOfThatType;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * A whole number written the Roman way — the mapping
 * [R36](../../../docs/NewConcept/30-renderer.md) uses to make its point about search.
 *
 * ⚠️ **R36 asks for `> XII` in a search box to find everything above twelve, and it says why that
 * works**: the converter runs **on the way in as well**, parsing `XII` to `12` *before the query
 * touches the database*. So this class is the reason {@see Converter::written()} exists — a
 * display-only mapping could not answer that question at all.
 *
 * ⚠️ **A closed range, and it is refused outside it rather than approximated.** *The classical
 * notation has no zero, no negatives, and nothing above 3999 without bars over the letters. A
 * converter that quietly returned an empty string for `0` would put a *not answered* on screen for a
 * value that is answered ([D-232](../../../docs/NewConcept/90-decision-log.md)), which is the one thing
 * a fallback must never do.*
 *
 * ```mermaid
 * flowchart LR
 *   V["12"] -->|shown| C["XII"]
 *   C -->|written| V
 * ```
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RomanNumeralConverter implements Converter
{
    public const NAME = 'roman';

    /**
     * @var array<string, int> Largest first, subtractive pairs included — which is what makes the
     *                        greedy walk in {@see self::shown()} produce `IX` and never `VIIII`.
     */
    private const LETTERS = [
        'M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400,
        'C' => 100,  'XC' => 90,  'L' => 50,  'XL' => 40,
        'X' => 10,   'IX' => 9,   'V' => 5,   'IV' => 4,
        'I' => 1,
    ];

    private const SMALLEST = 1;
    private const LARGEST  = 3999;

    public function name(): string
    {
        return self::NAME;
    }

    public function kind(): ConverterKind
    {
        return ConverterKind::Format;
    }

    public function isInvertible(): bool
    {
        return true;
    }

    public function handles(): array
    {
        return [SimpleType::Int];
    }

    /**
     * ⚠️ **Out of range is marked, not silently dropped.** *A number this notation cannot write is a
     * mis-set converter, not a missing value — so it says so where a reader can see it, the same call
     * [R14b](../../../docs/NewConcept/30-renderer.md) makes for a renderer that cannot draw.*
     */
    public function shown(TypedValue $value): string
    {
        $number = $value->int ?? 0;

        if ($number < self::SMALLEST || $number > self::LARGEST) {
            return '—';
        }

        $written = '';

        foreach (self::LETTERS as $letter => $worth) {
            while ($number >= $worth) {
                $written .= $letter;
                $number  -= $worth;
            }
        }

        return $written;
    }

    /**
     * ⚠️ **Read by walking the same table, then checked by writing it back.** *That round trip is what
     * refuses `IIII` and `IC` — both parse to a number, and neither is how this notation writes it. A
     * parser that accepted them would make the mapping non-invertible while claiming otherwise.*
     */
    public function written(string $characters, ?SimpleType $type = null): TypedValue
    {
        $written = strtoupper(trim($characters));

        if ($written === '' || preg_match('/^[MDCLXVI]+$/', $written) !== 1) {
            throw NotAValueOfThatType::submitted($characters, self::NAME);
        }

        $number = 0;
        $at     = 0;

        while ($at < strlen($written)) {
            $pair = substr($written, $at, 2);
            $one  = substr($written, $at, 1);

            if (isset(self::LETTERS[$pair])) {
                $number += self::LETTERS[$pair];
                $at     += 2;

                continue;
            }

            $number += self::LETTERS[$one];
            ++$at;
        }

        if ($this->shown(TypedValue::ofInt($number)) !== $written) {
            throw NotAValueOfThatType::submitted($characters, self::NAME);
        }

        return TypedValue::ofInt($number);
    }
}
