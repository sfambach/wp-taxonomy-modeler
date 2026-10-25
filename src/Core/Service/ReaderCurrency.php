<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

/**
 * The currency a reader of the site sees amounts in, chosen by the language of the page (D-992): its ISO code, its sign,
 * its unit node in the model, and the ECB quote (1 EUR = quote units; null for the euro itself).
 *
 * @see docs/NewConcept/90-decision-log.md D-992
 */
final class ReaderCurrency
{
    public function __construct(
        public readonly string $code,
        public readonly string $sign,
        public readonly ?int $unitNodeId,
        public readonly ?string $quote,
    ) {
    }

    /** A euro amount in this currency, two places. */
    public function fromEuro(string $euro): ?string
    {
        if (! is_numeric($euro)) {
            return null;
        }

        if ($this->quote === null) {
            return number_format((float) $euro, 2, '.', '');
        }

        return is_numeric($this->quote) && (float) $this->quote > 0.0 ? number_format((float) $euro * (float) $this->quote, 2, '.', '') : null;
    }
}
