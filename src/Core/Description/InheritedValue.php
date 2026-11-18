<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * An inherited setting value: its control held shut, and beside it where it comes from — or, where the inherited entry was not
 * allowed here, that it was replaced automatically and the row stands open (D-688, D-689).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class InheritedValue implements Describes
{
    public function __construct(
        public readonly Describes $control,
        public readonly bool $automatic,
        public readonly string $sentence,
    ) {
    }

    public function toArray(): array
    {
        return ['inherited_value' => $this->control->toArray(), 'automatic' => $this->automatic, 'sentence' => $this->sentence];
    }
}
