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
 * The landed price per piece spreads shipping and fees over all items of the order by their price, picked or not —
 * leaving an item out does not make the others dearer.
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
        $orderValues = $this->withoutEmpty([
            $this->target->orderField('title')        => $this->orderTitle($proposal, $pickedLines),
            $this->target->orderField('platform')     => $proposal->platform,
            $this->target->orderField('order_number') => $proposal->orderNumber,
            $this->target->orderField('merchant')     => $proposal->counterparty,
            $this->target->orderField('ordered_at')   => $proposal->orderDate,
            $this->target->orderField('origin')       => ['values' => [(string) $this->target->originDocumented]],
            $this->target->orderField('origin_note')  => $originNote,
            $this->target->orderField('shipping_cost') => $this->amount('shipping_cost', $proposal->shippingCost, $currency, $rate, true),
            $this->target->orderField('fees')         => $this->amount('fees', $proposal->fees, $currency, $rate, true),
            $this->target->orderField('total')        => $this->amount('total', $proposal->total, $currency, $rate, true),
            $this->target->orderField('tracking')     => $proposal->trackingNumbers === [] ? null : ['values' => $proposal->trackingNumbers],
            $this->target->orderField('received_at')  => $receivedDate,
        ]);

        $changes = [['op' => 'create_record', 'node' => $this->target->ordersNode, 'ref' => 'o']];
        $landed = $this->landedUnitPrices($proposal);
        $refs = [];

        foreach ($pickedLines as $index) {
            $line = $proposal->lines[$index] ?? throw WriteRefused::because('no item ' . $index);
            $ref = 'p' . $index;
            $refs[] = '@' . $ref;
            $changes[] = ['op' => 'create_record', 'node' => $this->target->purchasesNode, 'ref' => $ref];
            $changes[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => $this->purchaseValues($line, $currency, $rate, $landed[$index] ?? null, $originNote)];
        }

        $orderValues[(string) $this->target->orderField('lines')] = ['values' => $refs];
        $changes[] = ['op' => 'set_values', 'record' => '@o', 'values' => $orderValues];

        return $changes;
    }

    /** @return list<array<string, mixed>> */
    public function delivered(ExistingOrder $order, string $date): array
    {
        return [['op' => 'set_values', 'record' => (string) $order->recordId, 'values' => [
            (string) $this->target->orderField('received_at') => $date,
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

        return $values === [] ? [] : [['op' => 'set_values', 'record' => (string) $order->recordId, 'values' => $values]];
    }

    /** @return array<string, mixed> */
    private function purchaseValues(ProposalLine $line, int $currency, ?string $rate, ?string $landedUnitPrice, string $originNote): array
    {
        $unit = Money::toUnits($line->unitPrice);

        return $this->withoutEmpty([
            $this->target->purchaseField('title')             => $line->title,
            $this->target->purchaseField('quantity')          => $line->quantity === null ? null : self::quantity($line->quantity),
            $this->target->purchaseField('article_number')    => $line->articleNumber,
            $this->target->purchaseField('unit_price')        => $this->amount('unit_price', $line->unitPrice, $currency, $rate),
            $this->target->purchaseField('total_price')       => $unit === null || $line->quantity === null ? null : $this->amount('total_price', Money::format((int) round($unit * $line->quantity)), $currency, $rate),
            $this->target->purchaseField('landed_unit_price') => $this->amount('landed_unit_price', $landedUnitPrice, $currency, $rate),
            $this->target->purchaseField('origin')            => ['values' => [(string) $this->target->originDocumented]],
            $this->target->purchaseField('origin_note')       => $originNote,
        ]);
    }

    /** @return array<int, string> line index => landed price per piece */
    private function landedUnitPrices(OrderProposal $proposal): array
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
