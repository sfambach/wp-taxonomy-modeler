<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

/**
 * The inbox table of the import modules: proposals, not model data (D-953, S3) — a proposal becomes data only when he
 * takes it. Its own schema version beside the model's, so an inbox change never walks the model's migrations (CD-6).
 * Version 2 adds `received_at`: when the source received it, for the same mail under another id (§3.8). Version 3
 * removes the duty option the «Import» tab wrote, since the table is code (D-961).
 *
 * @see docs/pakete/import/konzept.md §3.3
 */
final class InboxSchema
{
    public const VERSION = 3;
    public const VERSION_OPTION = 'taxmod_inbox_schema_version';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'taxmod_inbox';
    }

    public static function upgrade(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) === self::VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // utf8mb4 index prefixes stay under MySQL 5.7's 767-byte limit (fambach.net runs 5.7).
        dbDelta(sprintf(
            "CREATE TABLE %s (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(32) NOT NULL,
  external_id varchar(191) NOT NULL DEFAULT '',
  platform varchar(64) NOT NULL DEFAULT '',
  order_number varchar(64) NOT NULL DEFAULT '',
  kind varchar(20) NOT NULL,
  status varchar(20) NOT NULL,
  proposal longtext NOT NULL,
  evidence longtext NULL,
  received_at datetime NULL,
  written longtext NULL,
  error text NULL,
  created_at datetime NOT NULL,
  handled_at datetime NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY source_external (source, external_id),
  KEY platform_number (platform, order_number)
) %s;",
            self::table(),
            $wpdb->get_charset_collate()
        ));

        delete_option(Duties::RETIRED_OPTION);

        update_option(self::VERSION_OPTION, self::VERSION, false);
    }
}
