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
}
