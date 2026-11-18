<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * An input shown as it would be, but not to be used: the field may be edited somewhere, not by this person here.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class Locked implements Describes
{
    public function __construct(public readonly Describes $inner)
    {
    }

    public function toArray(): array
    {
        return ['locked' => $this->inner->toArray()];
    }
}
