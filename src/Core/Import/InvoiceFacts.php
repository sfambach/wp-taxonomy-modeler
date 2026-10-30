<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * What an invoice says beyond its order (D-1024): its own number and date, whether it is paid and how, and the PDF in
 * the document store (D-1023). It becomes a record «Rechnung» linked to the order.
 *
 * @see docs/pakete/import/konzept.md §3.2
 */
final class InvoiceFacts
{
    /**
     * @param string|null $date Y-m-d
     * @param bool|null $paid null: the invoice does not say
     * @param string|null $document `vault:<key>` (D-1023)
     */
    public function __construct(
        public readonly string $number,
        public readonly ?string $date = null,
        public readonly ?bool $paid = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $document = null,
    ) {
    }

    /** @return array<string, string|bool|null> */
    public function toArray(): array
    {
        return ['number' => $this->number, 'date' => $this->date, 'paid' => $this->paid, 'payment_method' => $this->paymentMethod, 'document' => $this->document];
    }

    /** @param array<string, mixed> $row `mixed` because it comes back from JSON */
    public static function fromArray(array $row): ?self
    {
        $number = trim((string) ($row['number'] ?? ''));
        $text = static fn (string $key): ?string => isset($row[$key]) && $row[$key] !== '' ? (string) $row[$key] : null;

        return $number === '' ? null : new self($number, $text('date'), isset($row['paid']) ? (bool) $row['paid'] : null, $text('payment_method'), $text('document'));
    }
}
