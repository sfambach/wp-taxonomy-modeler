<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A small picture that says what a control does: a named symbol of the admin's set, or a character (`glyph`). With a label
 * it is read aloud, without one it is decoration.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class Icon implements Describes
{
    private function __construct(
        public readonly string $symbol,
        public readonly string $label,
        public readonly bool $character,
    ) {
    }

    public static function named(string $symbol, string $label = ''): self
    {
        return new self($symbol, $label, false);
    }

    public static function glyph(string $character, string $label = ''): self
    {
        return new self($character, $label, true);
    }

    public function toArray(): array
    {
        return ['icon' => $this->symbol, 'label' => $this->label, 'character' => $this->character];
    }
}
