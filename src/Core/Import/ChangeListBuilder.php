<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

use Taxmod\Core\Exception\WriteRefused;
use Taxmod\Core\Port\ExchangeRates;


/**
 * Turns a reviewed proposal into the change list `taxmod/write-data` takes (D-936): an order record with the picked
 * items as purchase records, all in one act. Same shape the Python importer sent.
 *
 * ```mermaid
 * flowchart LR
 *   O["create order @o"] --> V["values of @o"]
 *   P["create purchase @p0 … per picked item"] --> W["values of @pN"]
 *   W --> L["@o.lines = [@p0 …]"]
 * ```
 *
 * Shipping and fees are spread over all items of the order by their price, picked or not — leaving an item out does not
 * make the others dearer. Each purchase gets its two shares; the landed price per piece is calculated from them in the
 * model (D-1022), not written here.
 *
 * An amount in a foreign currency carries the ECB rate of the order date beside it (D-064, D-966); the euro figure is
 * derived on display, never stored.
 *
 * @see docs/pakete/import/konzept.md §3.6
 */
final class ChangeListBuilder
{
    private const TITLE_LENGTH = 45;

    public function __construct(private readonly TargetModel $target, private readonly ?ExchangeRates $rates = null)
    {
    }

    /**
     * @param list<int> $pickedLines indexes into $proposal->lines
     * @param string $originNote one sentence on where the data came from
     * @param string|null $receivedDate Y-m-d, when a delivery mail of the same order is taken over with it
     * @return list<array<string, mixed>> `mixed` because values nest by field kind
     */
    public function newOrder(OrderProposal $proposal, array $pickedLines, string $originNote, ?string $receivedDate = null): array
    {
        if ($pickedLines === []) {
            throw WriteRefused::because('no item picked');
        }

        $currency = $this->target->currency($proposal->currency);
        $rate = $this->frozenRate($proposal);
        $orderValues = $this->onOrder([
            'title'         => $this->orderTitle($proposal, $pickedLines),
            'platform'      => $proposal->platform,
            'order_number'  => $proposal->orderNumber,
            'merchant'      => $proposal->counterparty,
            'ordered_at'    => $proposal->orderDate,
            'origin'        => ['values' => [(string) $this->target->originDocumented]],
            'origin_note'   => $originNote,
            'shipping_cost' => $this->amount('shipping_cost', $proposal->shippingCost, $currency, $rate, true),
            'fees'          => $this->amount('fees', $proposal->fees, $currency, $rate, true),
            'total'         => $this->amount('total', $proposal->total, $currency, $rate, true),
            'tracking'      => $proposal->trackingNumbers === [] ? null : ['values' => $proposal->trackingNumbers],
            'received_at'   => $receivedDate,
            'shipped_at'    => $proposal->shippedDate,
        ]);

        $changes = [['op' => 'create_record', 'node' => $this->target->ordersNode, 'ref' => 'o']];
        $shares = self::costShares($proposal);
        $landed = $this->sharesKnown() ? [] : self::landedUnitPrices($proposal);
        $refs = [];

        foreach ($pickedLines as $index) {
            $line = $proposal->lines[$index] ?? throw WriteRefused::because('no item ' . $index);
            $ref = 'p' . $index;
            $refs[] = '@' . $ref;
            $changes[] = ['op' => 'create_record', 'node' => $this->target->purchasesNode, 'ref' => $ref];
            $changes[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => $this->purchaseValues($line, $currency, $rate, $shares[$index] ?? [], $landed[$index] ?? null, $originNote)];
        }

        $orderValues[(string) $this->target->orderField('lines')] = ['values' => $refs];
        $changes[] = ['op' => 'set_values', 'record' => '@o', 'values' => $orderValues];

        return $changes;
    }

    /** @return list<array<string, mixed>> */
    public function delivered(ExistingOrder $order, string $date): array
    {
        // A sale has no «received» date — the buyer's delivery is not his to record.
        if (! $this->target->hasOrderField('received_at')) {
            return [];
        }

        return [['op' => 'set_values', 'record' => (string) $order->recordId, 'values' => [
            (string) $this->target->orderField('received_at') => $date,
        ]]];
    }

    /** @return list<array<string, mixed>> empty where the target has no «shipped on» — an order has none, a sale has */
    public function shippedOn(ExistingOrder $order, string $date): array
    {
        if (! $this->target->hasOrderField('shipped_at')) {
            return [];
        }

        return [['op' => 'set_values', 'record' => (string) $order->recordId, 'values' => [
            (string) $this->target->orderField('shipped_at') => $date,
        ]]];
    }

    /**
     * @param list<string> $trackingNumbers
     * @return list<array<string, mixed>> empty when every number is already there
     */
    public function shipped(ExistingOrder $order, array $trackingNumbers): array
    {
        $merged = array_values(array_unique([...$order->trackingNumbers, ...$trackingNumbers]));

        if ($merged === $order->trackingNumbers) {
            return [];
        }

        return [['op' => 'set_values', 'record' => (string) $order->recordId, 'values' => [
            (string) $this->target->orderField('tracking') => ['values' => $merged],
        ]]];
    }

    /**
     * The order fields taken from the source (D-964): what the model leaves empty, and the conflicts he picked.
     *
     * @param list<string> $roles from {@see OrderComparison::ROLES}
     * @return list<array<string, mixed>> empty when nothing is taken
     */
    public function completed(ExistingOrder $order, OrderProposal $proposal, array $roles): array
    {
        $differences = OrderComparison::between($proposal, $order);
        $currency = $this->target->currency($proposal->currency);
        $rate = $this->frozenRate($proposal);
        $values = [];

        foreach (array_intersect($roles, array_keys($differences)) as $role) {
            $field = (string) $this->target->orderField($role);
            $values[$field] = match ($role) {
                'merchant'      => $proposal->counterparty,
                'ordered_at'    => $proposal->orderDate,
                'shipping_cost' => $this->amount($role, $proposal->shippingCost, $currency, $rate, true, $differences[$role]['model'] !== null),
                'fees'          => $this->amount($role, $proposal->fees, $currency, $rate, true, $differences[$role]['model'] !== null),
                'total'         => $this->amount($role, $proposal->total, $currency, $rate, true, $differences[$role]['model'] !== null),
            };
        }

        $values = $this->withoutEmpty($values);
        $changes = $values === [] ? [] : [['op' => 'set_values', 'record' => (string) $order->recordId, 'values' => $values]];

        return [...$changes, ...$this->linePrices($order, $proposal, $currency, $rate)];
    }

    /**
     * The empty prices of the order's purchases, from the source (D-986). Not a choice like the order fields: nothing
     * the model holds is overwritten, so there is nothing to decide.
     *
     * @return list<array<string, mixed>>
     */
    private function linePrices(ExistingOrder $order, OrderProposal $proposal, int $currency, ?string $rate): array
    {
        $fills = OrderComparison::lineFills($proposal, $order);

        if ($fills === []) {
            return [];
        }

        $shares = self::costShares($proposal);
        $landed = $this->sharesKnown() ? [] : self::landedUnitPrices($proposal);
        $byId = [];

        foreach ($order->lines as $line) {
            $byId[$line->recordId] = $line;
        }

        $changes = [];

        foreach ($fills as $recordId => $index) {
            $line = $byId[$recordId];
            $source = $proposal->lines[$index];
            $unit = Money::toUnits($source->unitPrice);
            $values = [];

            foreach ([
                'unit_price'        => $source->unitPrice,
                'total_price'       => $unit === null || $source->quantity === null ? null : Money::format((int) round($unit * $source->quantity)),
                'shipping_share'    => $shares[$index]['shipping'] ?? null,
                'fees_share'        => $shares[$index]['fees'] ?? null,
                'landed_unit_price' => $landed[$index] ?? null,
            ] as $role => $amount) {
                if (in_array($role, $line->filled, true) || ! $this->target->hasPurchaseField($role)) {
                    continue;
                }

                // ⚠️ *Shares only beside a price written from the same mail: an older price may already hold the shipping (D-933),
                // and a share beside it would count it twice (D-1022).*
                if (str_ends_with($role, '_share') && in_array('unit_price', $line->filled, true)) {
                    continue;
                }

                $values[$this->target->purchaseField($role)] = $this->amount($role, $amount, $currency, $rate, false, isset($line->parts[$role]));
            }

            $values = $this->withoutEmpty($values);

            if ($values !== []) {
                $changes[] = ['op' => 'set_values', 'record' => (string) $recordId, 'values' => $values];
            }
        }

        return $changes;
    }

    /**
     * @param  array{shipping?: ?string, fees?: ?string} $share
     * @return array<string, mixed>
     */
    private function purchaseValues(ProposalLine $line, int $currency, ?string $rate, array $share, ?string $landedUnitPrice, string $originNote): array
    {
        $unit = Money::toUnits($line->unitPrice);
        $values = [];

        // ⚠️ *A role the target does not name is left out, not refused: a sold item has no total and no cost shares
        // (D-977). The landed price of a sale would be the selling price plus shipping — not his cost.*
        foreach ([
            'title'             => $line->title,
            'quantity'          => $line->quantity === null ? null : self::quantity($line->quantity),
            'article_number'    => $line->articleNumber,
            'unit_price'        => $this->amount('unit_price', $line->unitPrice, $currency, $rate),
            'total_price'       => $unit === null || $line->quantity === null ? null : $this->amount('total_price', Money::format((int) round($unit * $line->quantity)), $currency, $rate),
            'shipping_share'    => $this->amount('shipping_share', $share['shipping'] ?? null, $currency, $rate),
            'fees_share'        => $this->amount('fees_share', $share['fees'] ?? null, $currency, $rate),
            'landed_unit_price' => $this->amount('landed_unit_price', $landedUnitPrice, $currency, $rate),
            'origin'            => ['values' => [(string) $this->target->originDocumented]],
            'origin_note'       => $originNote,
        ] as $role => $value) {
            if ($this->target->hasPurchaseField($role)) {
                $values[$this->target->purchaseField($role)] = $value;
            }
        }

        return $this->withoutEmpty($values);
    }

    /**
     * Whether the target names the cost shares. Without them the landed price is written as before (D-933); with them the
     * model calculates it (D-1022) and it is not written.
     */
    private function sharesKnown(): bool
    {
        return $this->target->hasPurchaseField('shipping_share') || $this->target->hasPurchaseField('fees_share');
    }

    /**
     * Each line's share of shipping and of fees, spread by item value (D-933, D-1022); a share of nothing is left out,
     * since an empty share counts as zero in the landed price.
     *
     * @return array<int, array{shipping: ?string, fees: ?string}> line index => shares of the whole line
     */
    public static function costShares(OrderProposal $proposal): array
    {
        $lineTotals = [];

        foreach ($proposal->lines as $index => $line) {
            $unit = Money::toUnits($line->unitPrice);

            if ($unit === null || $line->quantity === null || $line->quantity <= 0) {
                return [];
            }

            $lineTotals[$index] = $unit * $line->quantity;
        }

        $sum = array_sum($lineTotals);
        $spread = static function (int $extra, int|float $lineTotal) use ($sum, $lineTotals): ?string {
            $share = (int) round($sum == 0 ? $extra / count($lineTotals) : $extra * $lineTotal / $sum);

            return $share === 0 ? null : Money::format($share);
        };
        $shipping = Money::toUnits($proposal->shippingCost) ?? 0;
        $fees = Money::toUnits($proposal->fees) ?? 0;
        $shares = [];

        foreach ($lineTotals as $index => $lineTotal) {
            $shares[$index] = ['shipping' => $spread($shipping, $lineTotal), 'fees' => $spread($fees, $lineTotal)];
        }

        return $shares;
    }

    /**
     * Price per piece plus its share of shipping and fees, spread by item value (D-933) — what the inbox shows; the model
     * calculates the same from the shares (D-1022).
     *
     * @return array<int, string> line index => landed price per piece
     */
    public static function landedUnitPrices(OrderProposal $proposal): array
    {
        $lineTotals = [];

        foreach ($proposal->lines as $index => $line) {
            $unit = Money::toUnits($line->unitPrice);

            if ($unit === null || $line->quantity === null || $line->quantity <= 0) {
                return [];
            }

            $lineTotals[$index] = $unit * $line->quantity;
        }

        $sum = array_sum($lineTotals);
        $extra = (Money::toUnits($proposal->shippingCost) ?? 0) + (Money::toUnits($proposal->fees) ?? 0);
        $landed = [];

        foreach ($lineTotals as $index => $lineTotal) {
            $share = $sum == 0 ? $extra / count($lineTotals) : $extra * $lineTotal / $sum;
            $landed[$index] = Money::format((int) round(($lineTotal + $share) / $proposal->lines[$index]->quantity));
        }

        return $landed;
    }

    /**
     * A unit value: amount, currency and, in a foreign currency, the frozen rate. A required part (1..1) already stands
     * empty on a new record, so «0» fills it; an optional one does not exist yet, so a name («neu») creates it — same
     * rule as the Python importer. A part the record already holds is overwritten through «0».
     *
     * @return array<string, array<string, string>>|null
     */
    private function amount(string $role, ?string $amount, int $currency, ?string $rate, bool $onOrder = false, bool $replacesPart = false): ?array
    {
        $units = Money::toUnits($amount);

        if ($units === null) {
            return null;
        }

        if (! ($onOrder ? $this->target->hasOrderField($role) : $this->target->hasPurchaseField($role))) {
            return null;
        }

        $field = $onOrder ? $this->target->orderField($role) : $this->target->purchaseField($role);

        $part = [
            (string) $this->target->amountField => Money::format($units),
            (string) $this->target->unitField   => (string) $currency,
        ];

        if ($rate !== null && $this->target->rateField !== null) {
            $part[(string) $this->target->rateField] = $rate;
        }

        // ⚠️ *Overwriting what a record already holds is key «0» too (D-964), whether the part is required or not.*
        return [$replacesPart || in_array($field, $this->target->requiredParts, true) ? '0' : 'neu' => $part];
    }

    /**
     * The ECB quote of the order date; none in the reference currency (D-067: the rate is 1 and nothing is stored), none
     * without a date, and none when no rate is known — the amount is then written without one rather than refused.
     */
    private function frozenRate(OrderProposal $proposal): ?string
    {
        $isoCode = strtoupper($proposal->currency);
        $date = substr((string) $proposal->orderDate, 0, 10);

        if ($isoCode === TargetModel::REFERENCE_CURRENCY || $this->rates === null || $this->target->rateField === null || $date === '') {
            return null;
        }

        return $this->rates->quoteOn($isoCode, $date);
    }

    /**
     * «Reichelt I-123456 · LM317 Spannungsregler (+2)», as the Python importer named orders.
     *
     * @param list<int> $pickedLines
     */
    private function orderTitle(OrderProposal $proposal, array $pickedLines): string
    {
        $first = self::shorten($proposal->lines[$pickedLines[0]]->title ?? '');
        $more = count($pickedLines) > 1 ? sprintf(' (+%d)', count($pickedLines) - 1) : '';

        return trim($proposal->platform . ' ' . $proposal->orderNumber . ($first === '' ? '' : ' · ' . $first . $more));
    }

    /**
     * The order's values by relation id. Title, platform and number are what the order is found by, so a target without
     * them is refused; any other role the target does not name is left out.
     *
     * @param array<string, mixed> $byRole
     * @return array<string, mixed>
     */
    private function onOrder(array $byRole): array
    {
        $values = [];

        foreach ($byRole as $role => $value) {
            if (in_array($role, ['title', 'platform', 'order_number'], true) || $this->target->hasOrderField($role)) {
                $values[$this->target->orderField($role)] = $value;
            }
        }

        return $this->withoutEmpty($values);
    }

    private static function shorten(string $text): string
    {
        if (mb_strlen($text) <= self::TITLE_LENGTH) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::TITLE_LENGTH);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space === false ? $cut : mb_substr($cut, 0, $space), ' -–,/') . ' …';
    }

    private static function quantity(float $quantity): string
    {
        return $quantity == floor($quantity) ? (string) (int) $quantity : (string) $quantity;
    }

    /**
     * @param array<int, mixed> $values
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $values): array
    {
        $kept = [];

        foreach ($values as $relation => $value) {
            if ($value !== null && $value !== '') {
                $kept[(string) $relation] = $value;
            }
        }

        return $kept;
    }
}
