<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * An order already in the model, as far as a follow-up (shipped, delivered) and a completion (D-964) need it.
 *
 * @see docs/pakete/import/konzept.md §3.4
 */
final class ExistingOrder
{
    /**
     * @param list<string> $trackingNumbers
     * @param array<string, string> $values order role => what the model holds, as {@see OrderComparison} reads it
     *                                      (an amount as «4.5 EUR», a date as «Y-m-d»)
     * @param list<ExistingLine> $lines the purchases of the order (D-986)
     */
    public function __construct(
        public readonly int $recordId,
        public readonly array $trackingNumbers = [],
        public readonly ?string $receivedAt = null,
        public readonly ?string $counterparty = null,
        public readonly ?string $orderDate = null,
        public readonly string $title = '',
        public readonly array $values = [],
        public readonly array $lines = [],
    ) {
    }
}
