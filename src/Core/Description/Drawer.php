<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * What turns a description into a page: the core declares it, the edge fulfils it (`CD-1`, D-170).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
interface Drawer
{
    public function draw(Describes $piece): string;
}
