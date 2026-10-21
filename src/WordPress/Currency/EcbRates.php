<?php declare(strict_types=1);

namespace Taxmod\WordPress\Currency;

use Taxmod\Core\Port\ExchangeRates;

/**
 * ECB reference rates, read from the rate table and fetched into it when the table has none near the day (D-069, D-966).
 *
 * ```mermaid
 * flowchart LR
 *   Q["quoteOn(USD, day)"] --> T{"row on or up to 4 days before?"}
 *   T -- yes --> R["quote"]
 *   T -- no --> F["fetch ECB CSV, 10 days up to the day"] --> S["store rows"] --> T2["read again"]
 * ```
 *
 * Four days cover the longest gap without a published rate (Good Friday to Easter Monday). An unreachable ECB leaves
 * the table as it is and answers from what it has, or `null`.
 *
 * @see docs/NewConcept/90-decision-log.md D-069
 */
final class EcbRates implements ExchangeRates
{
    public const SOURCE = 'https://data-api.ecb.europa.eu/service/data/EXR/D.%s.EUR.SP00.A?startPeriod=%s&endPeriod=%s&format=csvdata';
    private const LONGEST_GAP_DAYS = 4;
    private const FETCH_WINDOW_DAYS = 10;

    /** @var array<string, true> windows already fetched in this request, so a failing source is asked once */
    private array $fetched = [];

    public function quoteOn(string $isoCode, string $date): ?string
    {
        $isoCode = strtoupper($isoCode);

        if (! preg_match('/^[A-Z]{3}$/', $isoCode) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $row = $this->stored($isoCode, $date);

        if ($row === null || $this->daysBetween($row['day'], $date) > self::LONGEST_GAP_DAYS) {
            $this->fetch($isoCode, $date);
            $row = $this->stored($isoCode, $date);
        }

        return $row === null ? null : self::trimmed($row['quote']);
    }

    /** @return array{day: string, quote: string}|null */
    private function stored(string $isoCode, string $date): ?array
    {
        global $wpdb;

        RateSchema::upgrade();
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT day, quote FROM ' . RateSchema::table() . ' WHERE currency = %s AND day <= %s ORDER BY day DESC LIMIT 1',
            $isoCode,
            $date
        ), ARRAY_A);

        return is_array($row) ? ['day' => (string) $row['day'], 'quote' => (string) $row['quote']] : null;
    }

    private function fetch(string $isoCode, string $date): void
    {
        $key = $isoCode . '|' . $date;

        if (isset($this->fetched[$key])) {
            return;
        }

        $this->fetched[$key] = true;
        $start = gmdate('Y-m-d', (int) strtotime($date . ' -' . self::FETCH_WINDOW_DAYS . ' days'));
        $response = wp_remote_get(sprintf(self::SOURCE, $isoCode, $start, $date), ['timeout' => 15]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return;
        }

        $this->store(self::parse((string) wp_remote_retrieve_body($response)));
    }

    /** @param list<array{currency: string, day: string, quote: string}> $rows */
    private function store(array $rows): void
    {
        global $wpdb;

        foreach ($rows as $row) {
            $wpdb->replace(RateSchema::table(), [
                'currency'   => $row['currency'],
                'day'        => $row['day'],
                'quote'      => $row['quote'],
                'fetched_at' => current_time('mysql', true),
            ], ['%s', '%s', '%s', '%s']);
        }
    }

    /**
     * The rows of the ECB `csvdata` answer: columns found by their header, so an added column moves nothing.
     *
     * @return list<array{currency: string, day: string, quote: string}>
     */
    public static function parse(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        $header = str_getcsv((string) array_shift($lines), ',', '"', '\\');
        $currency = array_search('CURRENCY', $header, true);
        $day = array_search('TIME_PERIOD', $header, true);
        $quote = array_search('OBS_VALUE', $header, true);

        if ($currency === false || $day === false || $quote === false) {
            return [];
        }

        $rows = [];

        foreach ($lines as $line) {
            $cells = str_getcsv($line, ',', '"', '\\');
            $value = (string) ($cells[$quote] ?? '');

            if (! is_numeric($value) || (float) $value <= 0 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($cells[$day] ?? ''))) {
                continue;
            }

            $rows[] = ['currency' => strtoupper((string) ($cells[$currency] ?? '')), 'day' => (string) $cells[$day], 'quote' => $value];
        }

        return $rows;
    }

    private function daysBetween(string $from, string $to): int
    {
        return (int) round(((int) strtotime($to) - (int) strtotime($from)) / 86400);
    }

    private static function trimmed(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }
}
