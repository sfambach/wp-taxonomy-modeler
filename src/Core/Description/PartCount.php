<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * How many parts a record holds, in place of the parts themselves, and where they are listed (D-825).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class PartCount implements Describes
{
    public function __construct(
        public readonly int $count,
        /** Empty: nowhere to go. */
        public readonly string $href = '',
    ) {
    }

    public function toArray(): array
    {
        return ['part_count' => $this->count, 'href' => $this->href];
    }
}
