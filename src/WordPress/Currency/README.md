# Currency

ECB reference rates for amounts in a foreign currency ([D-069](../../../docs/NewConcept/90-decision-log.md), D-966).

- `RateSchema` — the rate table `taxmod_exchange_rates`, its own schema version.
- `EcbRates` — implements the core port `ExchangeRates`: reads the table, fetches a window from the ECB when the day is missing.

Depends on WordPress (`$wpdb`, `wp_remote_get`). The core only sees `Taxmod\Core\Port\ExchangeRates`.
