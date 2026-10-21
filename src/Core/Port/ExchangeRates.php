<?php declare(strict_types=1);

namespace Taxmod\Core\Port;

/**
 * The daily reference rates the boundary has fetched (D-069): the core reads them, it never calls out.
 *
 * A quote is written the way the ECB publishes it: how many units of the currency one unit of the reference currency
 * buys (1 EUR = 1.1634 USD). A person can check it against the ECB page; the reference amount is amount ÷ quote.
 *
 * @see docs/NewConcept/90-decision-log.md D-064, D-067, D-069, D-966
 */
interface ExchangeRates
{
    /**
     * The quote on that day, or on the last published day before it (weekends, holidays); `null` when none is known.
     *
     * @param string $isoCode ISO 4217, e.g. `USD`
     * @param string $date    `Y-m-d`
     */
    public function quoteOn(string $isoCode, string $date): ?string;
}
