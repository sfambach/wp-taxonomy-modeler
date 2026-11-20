<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * The one way a number becomes text: stored with a point and without trailing zeros, shown with the decimal sign of the
 * site's language (D-1175). Modules call this instead of their own `rtrim(number_format(…))` (review 2026-11-17, R-2).
 *
 * @see docs/gestaltung-und-architektur.md W3
 */
final class DecimalText
{
    /**
     * The stored form: a point, at most `$places` decimals, no trailing zeros, never «-0».
     *
     * @param int<0, 12> $places
     */
    public static function stored(float $value, int $places = 6): string
    {
        $text = number_format($value, $places, '.', '');
        $text = str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;

        return $text === '-0' ? '0' : $text;
    }

    /** A stored decimal as a reader sees it: only the decimal sign changes. */
    public static function shown(string $stored, string $separator): string
    {
        return $separator === '.' ? $stored : str_replace('.', $separator, $stored);
    }

    /**
     * D-1501: what a person typed, back to the stored form — the one place for German and stored input alike. With both
     * signs the last one is the decimal sign (1.234,5 → 1234.5); a lone comma is the decimal sign (1234,5); a lone point
     * is the stored form (1234.5). Spaces are ignored. Empty or not a number is null — never a guessed 0.
     */
    public static function typed(string $text): ?string
    {
        $text = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($text));

        if ($text === '') {
            return null;
        }

        $comma = strrpos($text, ',');
        $point = strrpos($text, '.');

        if ($comma !== false && $point !== false) {
            $text = $comma > $point ? str_replace(',', '.', str_replace('.', '', $text)) : str_replace(',', '', $text);
        } elseif ($comma !== false) {
            $text = substr_count($text, ',') === 1 ? str_replace(',', '.', $text) : str_replace(',', '', $text);
        }

        return is_numeric($text) ? self::stored((float) $text, 12) : null;
    }
}
