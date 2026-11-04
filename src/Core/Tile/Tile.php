<?php declare(strict_types=1);

namespace Taxmod\Core\Tile;

/**
 * One tile of a cockpit or an overview (D-1048, D-1128, D-1149): a heading, an optional big figure, short lines below it,
 * optional bars of a series, and where a click leads. Every text is already in the reader's words and number format; the
 * grid only escapes and arranges.
 *
 * @see docs/pakete/kacheln/konzept.md
 */
final class Tile
{
    /**
     * @param list<string>     $lines  short lines under the figure («Vormonat 3», a last created name …)
     * @param list<float>      $bars   a series, oldest first; empty means no bars
     * @param list<string>     $barLabels one label per bar (its month), shown as the bar's title
     * @param string           $actions HTML of buttons or forms at the tile's foot — the caller's, escaped by the caller
     */
    public function __construct(
        public readonly string $label,
        public readonly string $url = '',
        public readonly ?string $figure = null,
        public readonly array $lines = [],
        public readonly TileTone $tone = TileTone::Normal,
        public readonly array $bars = [],
        public readonly array $barLabels = [],
        public readonly string $trend = '',
        public readonly string $actions = '',
    ) {
    }
}
