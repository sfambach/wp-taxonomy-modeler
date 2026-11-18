<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A value to read, not to change.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class ShownValue implements Describes
{
    public function __construct(
        public readonly string $text,
        public readonly ValueKind $kind = ValueKind::Text,
        /** Where a jump leads, or the language of code. */
        public readonly string $target = '',
    ) {
    }

    public function toArray(): array
    {
        return ['shown' => $this->kind->value, 'text' => $this->text, 'target' => $this->target];
    }
}
