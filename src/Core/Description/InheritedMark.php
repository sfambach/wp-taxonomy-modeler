<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * The arrow saying a row is borrowed from above, its word as the title — so an inherited value does not look like one set here.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class InheritedMark implements Describes
{
    public function __construct(public readonly string $word)
    {
    }

    public function toArray(): array
    {
        return ['inherited_mark' => $this->word];
    }
}
