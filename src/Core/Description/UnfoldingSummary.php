<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A part shown by its words, opening to the part itself (D-889, D-894).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class UnfoldingSummary implements Describes
{
    public function __construct(
        public readonly string $words,
        public readonly Describes $part,
    ) {
    }

    public function toArray(): array
    {
        return ['unfolding' => $this->words, 'part' => $this->part->toArray()];
    }
}
