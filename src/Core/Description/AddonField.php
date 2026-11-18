<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * One own field of a chosen extra function: a choice (field reference, fixed cases), a tick or a short text.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class AddonField implements Describes
{
    public function __construct(
        public readonly string $label,
        public readonly string $name,
        /** `Choosing`: offered values; `bool`: a tick; `string`: a short text. */
        public readonly Choosing|bool|string $value,
    ) {
    }

    public function toArray(): array
    {
        return [
            'addon_field' => $this->label,
            'name'        => $this->name,
            'value'       => $this->value instanceof Choosing ? $this->value->toArray() : $this->value,
        ];
    }
}
