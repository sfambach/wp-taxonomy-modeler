<?php declare(strict_types=1);

namespace Taxmod\WordPress\Draw;

use Taxmod\Core\Description\Describes;
use Taxmod\Core\Renderer\RenderResult;

/**
 * The markup of what the core handed back — drawn here, at the edge, by the one drawer (D-1424).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class Markup
{
    private static ?EdgeDrawer $drawer = null;

    public static function of(RenderResult|Describes|null $what): string
    {
        $piece = $what instanceof RenderResult ? $what->piece : $what;

        return $piece === null ? '' : (self::$drawer ??= new EdgeDrawer())->draw($piece);
    }
}
