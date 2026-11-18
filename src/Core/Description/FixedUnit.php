<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * The one allowed unit, shown as its sign and sent along unseen — not as a locked choice (D-780, D-1140).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class FixedUnit implements Describes
{
    public function __construct(
        public readonly string $sign,
        public readonly string $name,
        public readonly int $unit,
        public readonly string $form,
    ) {
    }

    public function toArray(): array
    {
        return ['fixed_unit' => $this->unit, 'sign' => $this->sign, 'name' => $this->name, 'form' => $this->form];
    }
}
