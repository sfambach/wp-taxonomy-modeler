<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A choice among the lines of a tree — open in the flow, the body of a dialog, or why there is nothing to choose (D-727).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class TreeChoice implements Describes
{
    private function __construct(
        public readonly Describes|string|null $tree,
        public readonly bool $inDialog,
        /** Characters (`display_size`); `null`: as wide as it is. */
        public readonly ?int $width,
        public readonly string $why,
    ) {
    }

    public static function open(Describes|string $tree, ?int $width): self
    {
        return new self($tree, false, $width, '');
    }

    public static function inDialog(Describes|string $tree): self
    {
        return new self($tree, true, null, '');
    }

    public static function nothingToChoose(string $why): self
    {
        return new self(null, false, null, $why);
    }

    public function toArray(): array
    {
        return [
            'choose_in' => $this->tree instanceof Describes ? $this->tree->toArray() : $this->tree,
            'dialog'    => $this->inDialog,
            'width'     => $this->width,
            'why'       => $this->why,
        ];
    }
}
