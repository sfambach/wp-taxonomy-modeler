<?php declare(strict_types=1);

namespace Taxmod\Tests\Support;

use Taxmod\Core\Description\Describes;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\Words;
use Taxmod\Core\Renderer\RenderResult;

/**
 * A stand-in for what a part shows, for tests of the containers: since renderer step 6c a result carries a description, not markup.
 * Empty is nothing at all (hidden, R11); `<span></span>` is a piece that draws only an empty element (D-954); anything else is words.
 */
final class Fixture
{
    public static function piece(string $shown): ?Describes
    {
        return match ($shown) {
            ''              => null,
            '<span></span>' => new ShownValue(''),
            default         => new Words($shown),
        };
    }

    public static function result(string $shown, int ...$usedRelations): RenderResult
    {
        return new RenderResult(self::piece($shown), array_values($usedRelations));
    }
}
