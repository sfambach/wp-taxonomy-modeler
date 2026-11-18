<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Renderer\Choice;

/**
 * The «+» beside a choice that adds the chosen entry to a list; usable only where the choice is (D-380, D-370).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class AddChosen implements Describes
{
    public function __construct(
        public readonly string $label,
        public readonly bool $operable,
        public readonly ChoicePurpose $purpose = ChoicePurpose::Candidate,
    ) {
    }

    /** @param array<int|string, string> $options */
    public static function beside(array $options, string $label, ChoicePurpose $purpose = ChoicePurpose::Candidate): self
    {
        return new self($label, Choice::forSetting($options)->isOperable(), $purpose);
    }

    public function toArray(): array
    {
        return ['add_chosen' => $this->purpose->value, 'label' => $this->label, 'operable' => $this->operable];
    }
}
