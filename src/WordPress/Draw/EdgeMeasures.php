<?php declare(strict_types=1);

namespace Taxmod\WordPress\Draw;

use Taxmod\Core\Description\Describes;
use Taxmod\Core\Description\Drawer;
use Taxmod\Core\Description\Measures;

/**
 * The edge's answer to «does this show anything»: it draws the piece and looks (A20). Once per piece, because the core asks the
 * same part from several places (columns, cells, words).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class EdgeMeasures implements Measures
{
    /** @var \WeakMap<Describes, bool> */
    private \WeakMap $known;

    public function __construct(private readonly Drawer $drawer)
    {
        $this->known = new \WeakMap();
    }

    public function shows(Describes $piece): bool
    {
        return $this->known[$piece] ??= $this->drawer->draw($piece) !== '';
    }
}
