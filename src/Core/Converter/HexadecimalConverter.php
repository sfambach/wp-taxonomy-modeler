<?php declare(strict_types=1);

namespace Taxmod\Core\Converter;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * A whole number written base sixteen — [D-076](../../../docs/NewConcept/90-decision-log.md)'s own
 * example of an invertible mapping.
 *
 * ⚠️ **Chosen as one of the first two converters because it needs nothing that is not built.** *The
 * concept's headline example is `2k7`, and that one is **blocked**: [D-220](../../../docs/NewConcept/90-decision-log.md)
 * says `2k7` lands in **two members of one composed value** — `number` and `prefix` — so it needs
 * composed-value rendering, which is S7. `decimal ↔ hex` and `decimal ↔ Roman` are the two mappings
 * [R36](../../../docs/NewConcept/30-renderer.md) lists beside it, and both are scalar to scalar.*
 *
 * ⚠️ **Invertible, so it serves input and search as well as display**
 * ([R36](../../../docs/NewConcept/30-renderer.md)) — *which is the point of starting here: it exercises
 * both directions of the contract, and a display-only converter would have left half of it untested.*
 *
 * ⚠️ **No `0x`, and that is a choice worth stating.** The characters are the number and nothing else, so
 * what a person reads is what a person writes. *A prefix on the way out that is optional on the way in
 * is two forms for one value, and the round trip stops being one.*
 *
 * ⚠️ **Der Gang liegt seit [D-523](../../../docs/NewConcept/90-decision-log.md) in
 * {@see PositionalNotation} — und dabei fielen zwei Fehler auf, die vorher nichts gemessen hatte.**
 * *`shown(PHP_INT_MIN)` warf einen `TypeError` (`abs(PHP_INT_MIN)` ist ein `float`), und
 * `written('FFFFFFFFFFFFFFFF')` gab **`0`** zurück — die stille Null, gegen die der Kommentar in
 * {@see self::written()} geschrieben war. Beide sind dort behoben, für alle drei Basen auf einmal.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class HexadecimalConverter implements Converter
{
    public const NAME = 'hexadecimal';

    private const BASE = 16;

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * ⚠️ *A base is a **pattern**, not a table: nothing is looked up and nothing is bounded. The four
     * forms are [R33a](../../../docs/NewConcept/30-renderer.md#r33a--there-are-a-few-kinds-of-converter-parameterised-by-data)'s
     * and the engine branches on the form ([D-148](../../../docs/NewConcept/90-decision-log.md)).*
     */
    public function kind(): ConverterKind
    {
        return ConverterKind::Format;
    }

    public function isInvertible(): bool
    {
        return true;
    }

    /**
     * ⚠️ *Whole numbers only. A hexadecimal fraction is a real notation and nobody asked for one, so it
     * is left out rather than guessed at (`PR-4`).*
     */
    public function handles(): array
    {
        return [SimpleType::Int];
    }

    /**
     * ⚠️ **Upper case, and negatives keep their sign in front.** *`-1` becoming `FFFFFFFF` would be
     * two's complement — a different mapping that depends on a width nobody stated, and it would not
     * round-trip through a `bigint`.*
     */
    public function shown(TypedValue $value): string
    {
        return PositionalNotation::digits($value->int ?? 0, self::BASE);
    }

    /**
     * ⚠️ **Refused rather than coerced** — `hexdec('zz')` is `0`, and a zero that arrived that way is
     * indistinguishable afterwards from a zero somebody meant. Same call as
     * [D-071](../../../docs/NewConcept/90-decision-log.md).
     */
    public function written(string $characters, ?SimpleType $type = null): TypedValue
    {
        return PositionalNotation::number($characters, self::BASE, self::NAME);
    }
}
