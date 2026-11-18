<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * Markup a part of the core still draws itself, carried inside a description until that part describes too.
 *
 * ⚠️ *Scaffolding of renderer step 6c (A15), and the one place it is honest about it: a description that holds markup proves
 * nothing (D-636). `core-html-ratchet-check` counts the core files that still hand markup through; at the merge it is 0 and
 * this class goes.*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class Drawn implements Describes
{
    public function __construct(public readonly string $markup)
    {
    }

    public function toArray(): array
    {
        return ['drawn' => $this->markup];
    }
}
