<?php declare(strict_types=1);

namespace Taxmod\Core\Listing;

/**
 * The one rule for what a column filter keeps (D-1462): a text column keeps what contains the term ({@see Wildcard}); a number
 * column also reads `2` as «exactly 2», `>2`, `<2`, `>=2`, `<=2` and `2-10` as «from 2 to 10». A term that is no number
 * expression is «contains» there too. `assets/list-head.js` repeats this rule for the rows a page drew; change both or neither —
 * `scripts/dev/list-head-check.php` runs the same cases through both.
 *
 * @see docs/NewConcept/90-decision-log.md D-1462
 */
final class FilterTerm
{
    private const NUMBER = '-?\d+(?:[.,]\d+)?';

    /** Whether a cell with this text passes the term; an empty term passes everything. */
    public static function matches(string $text, string $term, bool $number = false): bool
    {
        $term = trim($term);

        if ($term === '') {
            return true;
        }

        $range = $number ? self::range($term) : null;

        if ($range === null) {
            return Wildcard::matches($text, $term);
        }

        $value = self::number($text);

        if ($value === null) {
            return false;
        }

        [$operator, $from, $to] = $range;

        return match ($operator) {
            '>'     => $value > $from,
            '<'     => $value < $from,
            '>='    => $value >= $from,
            '<='    => $value <= $from,
            '-'     => $value >= min($from, $to) && $value <= max($from, $to),
            default => $value == $from,
        };
    }

    /**
     * A number expression as operator and bounds; null when the term is none.
     *
     * @return array{0: string, 1: float, 2: float}|null
     */
    public static function range(string $term): ?array
    {
        $term = trim($term);

        if (preg_match('/^(' . self::NUMBER . ')\s*(?:-|–|\.\.)\s*(' . self::NUMBER . ')$/u', $term, $m) === 1) {
            return ['-', self::decimal($m[1]), self::decimal($m[2])];
        }

        if (preg_match('/^(>=|<=|>|<|=)?\s*(' . self::NUMBER . ')$/', $term, $m) === 1) {
            return [$m[1] === '' ? '=' : $m[1], self::decimal($m[2]), self::decimal($m[2])];
        }

        return null;
    }

    /**
     * The number a cell starts with — `1.234,5`, `12 Stk`, `#42`, `0,5` — or null when it starts with none (empty, «—»).
     */
    public static function number(string $text): ?float
    {
        $text = (string) preg_replace('/^[#€$\s]+/u', '', trim($text));

        if (preg_match('/^-?\d{1,3}(?:\.\d{3})+(?:,\d+)?(?!\d)|^-?\d+(?:[,.]\d+)?/', $text, $m) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', (string) preg_replace('/\.(?=\d{3}(?!\d))/', '', $m[0]));
    }

    private static function decimal(string $number): float
    {
        return (float) str_replace(',', '.', $number);
    }
}
