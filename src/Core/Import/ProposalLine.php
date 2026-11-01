<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * One item of an order as a source read it; the owner ticks it or not.
 *
 * @see docs/pakete/import/konzept.md §3.2
 */
final class ProposalLine
{
    /**
     * @param string|null $unitPrice decimal string, never float — money keeps its digits
     * @param float|null $quantity null when the source does not say (JLCPCB review mails)
     * @param string|null $articleNumber the platform's number for the item: Reichelt/Mouser article number, eBay item id
     * @param string|null $imageUrl the product picture the source shows, as an address from outside (D-865, D-1079)
     */
    public function __construct(
        public readonly string $title,
        public readonly ?float $quantity,
        public readonly ?string $unitPrice = null,
        public readonly ?string $articleNumber = null,
        public readonly ?string $imageUrl = null,
    ) {
    }

    /** @return array{title: string, quantity: ?float, unit_price: ?string, article_number: ?string, image_url?: string} */
    public function toArray(): array
    {
        return [
            'title'          => $this->title,
            'quantity'       => $this->quantity,
            'unit_price'     => $this->unitPrice,
            'article_number' => $this->articleNumber,
        ] + ($this->imageUrl === null ? [] : ['image_url' => $this->imageUrl]);
    }

    /** @param array{title: string, quantity?: float|int|null, unit_price?: ?string, article_number?: ?string, image_url?: ?string} $row */
    public static function fromArray(array $row): self
    {
        return new self($row['title'], isset($row['quantity']) ? (float) $row['quantity'] : null, $row['unit_price'] ?? null, $row['article_number'] ?? null,
            $row['image_url'] ?? null);
    }
}
