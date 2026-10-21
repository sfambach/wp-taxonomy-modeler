<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

use Taxmod\Core\Exception\WriteRefused;

/**
 * Where in the modeller an order goes: node ids and relation ids, never field names (no special-casing by label).
 * Relation ids survive the tree rebuild — move_field keeps them (D-941).
 *
 * @see docs/pakete/import/konzept.md §3.6
 */
final class TargetModel
{
    /** The reference currency of D-067: the ECB publishes its rates against the euro (D-966). */
    public const REFERENCE_CURRENCY = 'EUR';

    /**
     * @param array<string, int> $orderFields role => relation id: title, origin, origin_note, order_number, platform,
     *        merchant, ordered_at, received_at, shipping_cost, fees, total, tracking, lines
     * @param array<string, int> $purchaseFields role => relation id: title, origin, origin_note, quantity,
     *        article_number, unit_price, total_price, landed_unit_price
     * @param array<string, int> $currencies ISO code => unit node id
     * @param list<int> $requiredParts unit-value fields with multiplicity 1..1 — their part exists on a new record
     * @param int|null $rateField the unit value's frozen rate (D-966); `null` freezes nothing
     */
    public function __construct(
        public readonly int $ordersNode,
        public readonly int $purchasesNode,
        public readonly int $originDocumented,
        public readonly int $amountField,
        public readonly int $unitField,
        public readonly array $orderFields,
        public readonly array $purchaseFields,
        public readonly array $currencies,
        public readonly array $requiredParts = [],
        public readonly ?int $rateField = null,
    ) {
    }

    public function currency(string $isoCode): int
    {
        if (! isset($this->currencies[strtoupper($isoCode)])) {
            throw WriteRefused::because('no unit node for currency ' . $isoCode);
        }

        return $this->currencies[strtoupper($isoCode)];
    }

    public function orderField(string $role): int
    {
        return $this->orderFields[$role] ?? throw WriteRefused::because('order field not configured: ' . $role);
    }

    public function purchaseField(string $role): int
    {
        return $this->purchaseFields[$role] ?? throw WriteRefused::because('purchase field not configured: ' . $role);
    }
}
