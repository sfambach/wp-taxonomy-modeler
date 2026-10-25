<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

/**
 * Where a unit value keeps its frozen rate and which unit node is the reference currency (D-067, D-966) — handed in by
 * the boundary, so the display can derive the reference amount without knowing names.
 *
 * @see docs/NewConcept/90-decision-log.md D-966
 */
final class ReferenceCurrency
{
    public function __construct(
        public readonly int $rateFieldId,
        public readonly int $unitNodeId,
        /** ⚠️ *The reader's currency (D-992); set only where readers look, so the admin keeps amount and euro side by side.* */
        public readonly ?ReaderCurrency $reader = null,
    ) {
    }

    /**
     * The reference amount, rounded to two places: amount ÷ ECB quote (1 EUR = quote units).
     *
     * ⚠️ *Two places is the euro's minor unit (D-073); the reference currency is the euro until D-067's setting exists.*
     */
    public static function converted(string $amount, string $quote): ?string
    {
        if (! is_numeric($amount) || ! is_numeric($quote) || (float) $quote <= 0.0) {
            return null;
        }

        return number_format((float) $amount / (float) $quote, 2, '.', '');
    }
}
