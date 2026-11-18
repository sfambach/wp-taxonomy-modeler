<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * One line of a tree: what stands in it, how deep, and how it folds (D-345, D-367).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class TreeLine implements Describes
{
    public function __construct(
        public readonly int $depth,
        public readonly Describes $cell,
        /** Where its fold leads: an address, `#` for «the script folds», `null`: nothing to fold. */
        public readonly ?string $fold = null,
        public readonly bool $collapsed = false,
        /** Under a closed branch, so not shown at first. */
        public readonly bool $shut = false,
        public readonly bool $highlighted = false,
    ) {
    }

    public function with(Describes $cell): self
    {
        return new self($this->depth, $cell, $this->fold, $this->collapsed, $this->shut, $this->highlighted);
    }

    public function toArray(): array
    {
        return [
            'line'        => $this->cell->toArray(),
            'depth'       => $this->depth,
            'fold'        => $this->fold,
            'collapsed'   => $this->collapsed,
            'shut'        => $this->shut,
            'highlighted' => $this->highlighted,
        ];
    }
}
