<?php declare(strict_types=1);

namespace Taxmod\WordPress\Printing;

/**
 * The table of print jobs for the print service at home (D-1032, stage 2 of D-1100). Jobs are work in transit, not model
 * data; its own schema version, so a change here never walks the model's migrations (CD-6).
 *
 * @see docs/NewConcept/90-decision-log.md D-1113
 */
final class PrintJobSchema
{
    /**
     * 2: `document`, a label drawn from a template (D-1153). 3: `copies`, one job prints them together (D-1162). 4: `pdf` and
     * `delivery`, a job that arrives finished from the module that prints (D-1294). 5: `queue`, the CUPS queue the job is
     * for, so each print service fetches only the jobs for its own printers, and `claimed_by`, the service that fetched it
     * (D-1336).
     */
    public const VERSION = 5;
    public const VERSION_OPTION = 'taxmod_print_schema_version';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'taxmod_print_jobs';
    }

    public static function upgrade(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) === self::VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(sprintf(
            "CREATE TABLE %s (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  format varchar(32) NOT NULL,
  code varchar(64) NOT NULL,
  title varchar(255) NOT NULL,
  subtitle varchar(255) NOT NULL DEFAULT '',
  document longtext NULL,
  pdf longblob NULL,
  delivery longtext NULL,
  copies smallint(5) unsigned NOT NULL DEFAULT 1,
  is_test tinyint(1) NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL,
  claim varchar(32) NOT NULL DEFAULT '',
  message text NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  claimed_at datetime NULL,
  finished_at datetime NULL,
  queue varchar(127) NOT NULL DEFAULT '',
  claimed_by varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY status (status),
  KEY claim (claim),
  KEY queue (queue)
) %s;",
            self::table(),
            $wpdb->get_charset_collate()
        ));

        update_option(self::VERSION_OPTION, self::VERSION, false);
    }
}
