<?php declare(strict_types=1);

namespace Taxmod\Core\Tile;

/** How a tile stands out (design 6.3, 7.5): nothing due shrinks, something to act on is red. */
enum TileTone: string
{
    case Normal = 'normal';
    case Empty = 'empty';
    case Alert = 'alert';
}
