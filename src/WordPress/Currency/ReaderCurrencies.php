<?php declare(strict_types=1);

namespace Taxmod\WordPress\Currency;

use Taxmod\Core\Service\ReaderCurrency;
use Taxmod\WordPress\Import\Targets;

/**
 * Which currency a reader sees, from the language of the page (D-992): the region of the locale decides, the euro where
 * none is known. A filter may change the table.
 *
 * @see docs/NewConcept/90-decision-log.md D-992
 */
final class ReaderCurrencies
{
    public const FILTER = 'taxmod_reader_currencies';

    /** @var array<string, array{0: string, 1: string}> region => ISO code, sign */
    private const BY_REGION = [
        'GB' => ['GBP', '£'],
        'US' => ['USD', '$'],
        'CH' => ['CHF', 'CHF'],
    ];

    public static function forLocale(string $locale): ReaderCurrency
    {
        $table = (array) apply_filters(self::FILTER, self::BY_REGION);
        $region = strtoupper((string) (explode('_', str_replace('-', '_', $locale))[1] ?? ''));
        [$code, $sign] = isset($table[$region]) && is_array($table[$region]) ? array_values($table[$region]) + ['EUR', '€'] : ['EUR', '€'];
        $unit = Targets::load()->currencies[$code] ?? null;
        $euro = Targets::load()->currencies['EUR'] ?? null;

        if ($code === 'EUR') {
            return new ReaderCurrency('EUR', '€', $euro === null ? null : (int) $euro, null);
        }

        // ⚠️ *Today's ECB quote: the reader's amount is a guide, the frozen rate of the purchase stays the euro value (D-966).
        // No quote, no guessing — the reader gets the euro.*
        try {
            $quote = (new EcbRates())->quoteOn($code, wp_date('Y-m-d'));
        } catch (\Throwable) {
            $quote = null;
        }

        return $quote === null
            ? new ReaderCurrency('EUR', '€', $euro === null ? null : (int) $euro, null)
            : new ReaderCurrency($code, (string) $sign, $unit === null ? null : (int) $unit, $quote);
    }
}
