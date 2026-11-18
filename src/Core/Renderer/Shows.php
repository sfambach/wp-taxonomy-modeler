<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\Describes;
use Taxmod\Core\Description\Measures;

/**
 * Asks whether a piece shows anything ({@see Measures}, A20). The edge hands its answer over once, at autoload.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class Shows
{
    private static ?Measures $measures = null;

    public static function measureWith(Measures $measures): void
    {
        self::$measures = $measures;
    }

    /** `null` shows nothing; a string is what the edge handed in already drawn (A21). */
    public static function anything(Describes|string|null $piece): bool
    {
        if ($piece === null || is_string($piece)) {
            return $piece !== null && $piece !== '';
        }

        return (self::$measures ?? throw new \LogicException('Nothing measures: the edge registers it with the autoloader.'))->shows($piece);
    }
}
