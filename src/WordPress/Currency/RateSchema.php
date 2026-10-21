<?php declare(strict_types=1);

namespace Taxmod\WordPress\Currency;

/**
 * The rate table of D-069: one ECB reference rate per currency and working day. Machinery, not model data, so it has
 * its own schema version beside the model's (CD-6).
 *
 * @see docs/NewConcept/90-decision-log.md D-069, D-966
 */
final class RateSchema
{
    public const VERSION = 1;
    public const VERSION_OPTION = 'taxmod_rate_schema_version';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'taxmod_exchange_rates';
    }

    public static function upgrade(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) === self::VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // `quote` as the ECB writes it: units of the currency for one euro; six places per D-069, ten for headroom.
        dbDelta(sprintf(
            "CREATE TABLE %s (
  currency char(3) NOT NULL,
  day date NOT NULL,
  quote decimal(20,10) NOT NULL,
  fetched_at datetime NOT NULL,
  PRIMARY KEY  (currency, day)
) %s;",
            self::table(),
            $wpdb->get_charset_collate()
        ));

        update_option(self::VERSION_OPTION, self::VERSION, false);
    }
}
