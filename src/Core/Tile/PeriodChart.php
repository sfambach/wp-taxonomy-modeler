<?php declare(strict_types=1);

namespace Taxmod\Core\Tile;

/**
 * The chart of the period view (D-1167): per step one bar for each series, side by side, scaled to the largest value, as
 * inline SVG so it needs no script. Its look is the style of {@see TileGrid}, written once per page.
 *
 * @see docs/pakete/kacheln/konzept.md §6
 */
final class PeriodChart
{
    private const WIDTH  = 720;
    private const HEIGHT = 160;
    private const BOTTOM = 18;

    /**
     * @param list<string>               $steps  label of each step
     * @param array<string, list<float>> $series name → one value per step; at most two are drawn
     * @param int|null                   $mark   the step to emphasise (the chosen day), null for none
     */
    public static function of(array $steps, array $series, ?int $mark = null): string
    {
        $count = count($steps);

        if ($count === 0 || $series === []) {
            return '';
        }

        $series = array_slice($series, 0, 2, true);
        $max    = max(1.0, ...array_map(static fn (array $v): float => $v === [] ? 0.0 : (float) max($v), array_values($series)));
        $slot   = self::WIDTH / $count;
        $bar    = max(1.0, $slot * 0.8 / count($series));
        $room   = self::HEIGHT - self::BOTTOM;
        $every  = (int) ceil($count / 16);
        $svg    = '';

        foreach ($steps as $i => $label) {
            $x = $i * $slot + $slot * 0.1;

            if ($mark === $i) {
                $svg .= sprintf('<rect class="is-mark" x="%.1F" y="0" width="%.1F" height="%d"></rect>', $i * $slot, $slot, $room);
            }

            $n = 0;

            foreach ($series as $name => $values) {
                $value = (float) ($values[$i] ?? 0.0);
                $h     = $room * $value / $max;
                $svg  .= sprintf(
                    '<rect class="s%d" x="%.1F" y="%.1F" width="%.1F" height="%.1F"><title>%s</title></rect>',
                    $n,
                    $x + $n * $bar,
                    $room - $h,
                    $bar,
                    $h,
                    self::e($label . ' · ' . $name . ': ' . self::number($value))
                );
                $n++;
            }

            if ($i % $every === 0) {
                $svg .= sprintf('<text x="%.1F" y="%d">%s</text>', $i * $slot + $slot / 2, self::HEIGHT - 4, self::e($label));
            }
        }

        $legend = '';
        $n      = 0;

        foreach (array_keys($series) as $name) {
            $legend .= '<span class="taxmod-chart-key s' . $n++ . '">' . self::e($name) . '</span>';
        }

        return TileGrid::style() . '<figure class="taxmod-chart"><svg viewBox="0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '" preserveAspectRatio="none" role="img">'
            . $svg . '</svg><figcaption>' . $legend . '</figcaption></figure>';
    }

    private static function number(float $v): string
    {
        return fmod($v, 1.0) === 0.0 ? (string) (int) $v : rtrim(rtrim(sprintf('%.2F', $v), '0'), '.');
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
