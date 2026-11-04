<?php declare(strict_types=1);

namespace Taxmod\Core\Tile;

/**
 * The one look of every tile in the modeller and its modules (D-1149): a row that wraps, each tile a link with its figure,
 * lines, bars and actions. Returns a string (CD-8); the style travels once per page with the first grid.
 *
 * ```mermaid
 * flowchart TB
 *   G["grid"] --> T1["tile · label"] --> F["figure · trend"] --> L["lines"] --> B["bars"] --> A["actions"]
 * ```
 *
 * @see docs/pakete/kacheln/konzept.md
 */
final class TileGrid
{
    private static bool $styled = false;

    /** @param list<Tile> $tiles */
    public static function of(array $tiles, string $heading = ''): string
    {
        if ($tiles === []) {
            return '';
        }

        $html = self::style() . ($heading === '' ? '' : '<h2 class="taxmod-tiles-heading">' . self::text($heading) . '</h2>') . '<div class="taxmod-tiles">';

        foreach ($tiles as $tile) {
            $html .= self::tile($tile);
        }

        return $html . '</div>';
    }

    /** Only for tests: the next grid writes its style again. */
    public static function forgetStyle(): void
    {
        self::$styled = false;
    }

    private static function tile(Tile $tile): string
    {
        $tone  = $tile->tone === TileTone::Normal ? '' : ' is-' . $tile->tone->value;
        $tag   = $tile->url === '' || $tile->actions !== '' ? 'div' : 'a';
        $open  = $tag === 'a' ? '<a class="taxmod-tile' . $tone . '" href="' . self::text($tile->url) . '">' : '<div class="taxmod-tile' . $tone . '">';
        $label = $tag === 'div' && $tile->url !== '' ? '<a class="taxmod-tile-label" href="' . self::text($tile->url) . '">' . self::text($tile->label) . '</a>'
            : '<span class="taxmod-tile-label">' . self::text($tile->label) . '</span>';
        $html  = $open . $label;

        if ($tile->figure !== null) {
            $html .= '<span class="taxmod-tile-figure">' . self::text($tile->figure)
                . ($tile->trend === '' ? '' : ' <span class="taxmod-tile-trend">' . self::text($tile->trend) . '</span>') . '</span>';
        }

        foreach ($tile->lines as $line) {
            $html .= '<span class="taxmod-tile-line">' . self::text($line) . '</span>';
        }

        return $html . self::bars($tile) . ($tile->actions === '' ? '' : '<span class="taxmod-tile-actions">' . $tile->actions . '</span>') . '</' . $tag . '>';
    }

    /** Bars as plain boxes scaled to the largest value — no script, no chart library, readable in both themes. */
    private static function bars(Tile $tile): string
    {
        if ($tile->bars === []) {
            return '';
        }

        $max  = max(array_map('abs', $tile->bars)) ?: 1.0;
        $last = count($tile->bars) - 1;
        $html = '<span class="taxmod-tile-bars" aria-hidden="true">';

        foreach ($tile->bars as $i => $value) {
            $height = $value == 0 ? 0 : max(4, (int) round(abs($value) / $max * 100));
            $title  = $tile->barLabels[$i] ?? '';
            $html  .= '<span class="taxmod-tile-bar' . ($i === $last ? ' is-current' : '') . '" style="height:' . $height . '%"'
                . ($title === '' ? '' : ' title="' . self::text($title) . '"') . '></span>';
        }

        return $html . '</span>';
    }

    private static function text(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function style(): string
    {
        if (self::$styled) {
            return '';
        }

        self::$styled = true;

        return '<style>.taxmod-tiles-heading{margin:24px 0 0;font-size:1.2em}'
            . '.taxmod-tiles{display:flex;flex-wrap:wrap;gap:12px;margin-top:16px}'
            . '.taxmod-tile{display:flex;flex-direction:column;gap:2px;min-width:170px;max-width:280px;padding:12px 16px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;text-decoration:none;color:#1d2327;box-sizing:border-box}'
            . 'a.taxmod-tile:hover,a.taxmod-tile:focus{box-shadow:0 0 0 2px #2271b1;outline:none}'
            . '.taxmod-tile-label{font-weight:600;color:inherit;text-decoration:none}'
            . '.taxmod-tile-figure{font-size:28px;font-weight:600;line-height:1.2}'
            . '.taxmod-tile-trend{font-size:13px;font-weight:400;color:#646970}'
            . '.taxmod-tile-line{font-size:12px;color:#646970}'
            . '.taxmod-tile-bars{display:flex;align-items:flex-end;gap:2px;height:32px;margin-top:6px}'
            . '.taxmod-tile-bar{flex:1;min-width:4px;background:#c5d9ed}.taxmod-tile-bar.is-current{background:#2271b1}'
            . '.taxmod-tile-actions{display:flex;flex-wrap:wrap;gap:6px;justify-content:flex-end;margin-top:8px}'
            . '.taxmod-tile.is-empty{border-left-color:#c3c4c7;color:#646970;min-width:120px}.taxmod-tile.is-empty .taxmod-tile-figure{font-size:20px}'
            . '.taxmod-tile.is-alert{border-left-color:#d63638}.taxmod-tile.is-alert .taxmod-tile-figure{color:#d63638}</style>';
    }
}
