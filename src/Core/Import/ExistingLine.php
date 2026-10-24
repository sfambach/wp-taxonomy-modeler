<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * A purchase already in the model under an order, as far as filling its empty prices from the source needs it (D-986).
 *
 * @see docs/pakete/import/konzept.md §3.4
 */
final class ExistingLine
{
    /**
     * @param array<string, int> $parts price role => the part record the purchase already holds, filled or not
     * @param list<string> $filled price roles that already carry an amount
     */
    public function __construct(
        public readonly int $recordId,
        public readonly string $title,
        public readonly array $parts = [],
        public readonly array $filled = [],
    ) {
    }
}
