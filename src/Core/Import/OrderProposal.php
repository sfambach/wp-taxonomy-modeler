<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * What one source says about one order — the same shape for every source, so one review page shows them all
 * (D-952, D-953). Taken over from tax_mail; `source` and `externalId` are new and optional, so an array tax_mail or
 * tax_ebay stored before still reads.
 *
 * @see docs/pakete/import/konzept.md §3.2
 */
final class OrderProposal
{
    /**
     * @param list<ProposalLine> $lines
     * @param string|null $shippingCost decimal string
     * @param string|null $total decimal string
     * @param string|null $fees decimal string — platform fees on a sale
     * @param list<string> $trackingNumbers
     * @param string|null $source key of the source that made it: 'mail', 'ebay', 'jlcpcb'
     * @param string|null $externalId the source's own id: Message-ID, eBay orderId, JLC batch number
     * @param bool $numberMissing the source names no order number (a won auction, an invoice); $orderNumber is then
     *        only the source's stand-in key, and the order is found by seller and title (LooseMatch)
     */
    public function __construct(
        public readonly string $platform,
        public readonly string $orderNumber,
        public readonly ProposalKind $kind,
        public readonly ?string $orderDate = null,
        public readonly string $currency = 'EUR',
        public readonly array $lines = [],
        public readonly ?string $shippingCost = null,
        public readonly ?string $total = null,
        public readonly ?string $counterparty = null,
        public readonly ?string $fees = null,
        public readonly ?string $shippedDate = null,
        public readonly array $trackingNumbers = [],
        public readonly ?string $source = null,
        public readonly ?string $externalId = null,
        public readonly bool $numberMissing = false,
    ) {
    }

    /** Duplicates are found by platform and order number (D-953); case and spaces of the platform do not count. */
    public function duplicateKey(): string
    {
        return mb_strtolower(trim($this->platform)) . '|' . trim($this->orderNumber);
    }

    /** @return array<string, mixed> JSON-ready; `mixed` because the lines nest */
    public function toArray(): array
    {
        return [
            'platform'      => $this->platform,
            'order_number'  => $this->orderNumber,
            'kind'          => $this->kind->value,
            'order_date'    => $this->orderDate,
            'currency'      => $this->currency,
            'lines'         => array_map(static fn (ProposalLine $line): array => $line->toArray(), $this->lines),
            'shipping_cost' => $this->shippingCost,
            'total'         => $this->total,
            'counterparty'  => $this->counterparty,
            'fees'          => $this->fees,
            'shipped_date'  => $this->shippedDate,
            'tracking'      => $this->trackingNumbers,
            'source'        => $this->source,
            'external_id'   => $this->externalId,
            'number_missing' => $this->numberMissing,
        ];
    }

    /** @param array<string, mixed> $row what toArray() produced, back from JSON */
    public static function fromArray(array $row): self
    {
        $text = static fn (string $key): ?string => isset($row[$key]) && $row[$key] !== '' ? (string) $row[$key] : null;

        return new self(
            (string) ($row['platform'] ?? ''),
            (string) ($row['order_number'] ?? ''),
            ProposalKind::from((string) ($row['kind'] ?? ProposalKind::Ordered->value)),
            $text('order_date'),
            (string) ($row['currency'] ?? 'EUR'),
            array_map(static fn (array $line): ProposalLine => ProposalLine::fromArray($line), array_values((array) ($row['lines'] ?? []))),
            $text('shipping_cost'),
            $text('total'),
            $text('counterparty'),
            $text('fees'),
            $text('shipped_date'),
            array_values(array_map('strval', (array) ($row['tracking'] ?? []))),
            $text('source'),
            $text('external_id'),
            (bool) ($row['number_missing'] ?? false),
        );
    }
}
