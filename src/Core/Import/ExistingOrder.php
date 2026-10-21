<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * An order already in the model, as far as a follow-up (shipped, delivered) needs it.
 *
 * @see docs/pakete/import/konzept.md §3.4
 */
final class ExistingOrder
{
    /** @param list<string> $trackingNumbers */
    public function __construct(
        public readonly int $recordId,
        public readonly array $trackingNumbers = [],
        public readonly ?string $receivedAt = null,
        public readonly ?string $counterparty = null,
        public readonly ?string $orderDate = null,
        public readonly string $title = '',
    ) {
    }
}
