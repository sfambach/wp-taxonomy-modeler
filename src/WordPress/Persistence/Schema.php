<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

/**
 * The seven tables (D-083), created on activation and guarded by a stored schema version.
 *
 * ⚠️ **Seven, and no table per model** — the model *is* the schema (D-066). A per-model
 * projection may exist later, but only as a rebuildable cache (D-228), never as a place where
 * anything is kept.
 *
 * ```mermaid
 * flowchart TD
 *   N[(nodes)] --> S[(settings)]
 *   R[(relations)] --> S
 *   N --> L[(labels)]
 *   R --> L
 *   N --> C[(changelog)]
 *   R --> C
 *   RC[(records)] --> RV[(record_values)]
 * ```
 *
 * `settings`, `labels` and `changelog` all hang off **an identity**, not off a node — which is
 * what lets a *relation* carry settings too (C8). That is why `owner_id` is one column rather
 * than a kind plus an id.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class Schema
{
    /**
     * Raise this whenever a table definition below changes. The stored value is compared on
     * every load, so an upgrade is deterministic rather than a matter of when somebody last
     * deactivated the plugin (`CD-6`).
     */
    public const VERSION = 1;

    public const VERSION_OPTION = 'taxmod_schema_version';

    /** @return list<string> The seven table names, without the WordPress prefix. */
    public static function tableNames(): array
    {
        return ['nodes', 'relations', 'settings', 'labels', 'changelog', 'records', 'record_values'];
    }

    public static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . 'taxmod_' . $name;
    }

    /** Create or upgrade the tables, but only when the stored version is behind. */
    public static function ensureCurrent(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) === self::VERSION) {
            return;
        }

        self::install();
        update_option(self::VERSION_OPTION, self::VERSION, true);
    }

    public static function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach (self::statements() as $sql) {
            dbDelta($sql);
        }
    }

    /**
     * @return list<string>
     */
    private static function statements(): array
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $t       = static fn (string $n): string => self::table($n);

        return [
            // The model identity space is shared by nodes and relations (C11), so neither
            // table may own an AUTO_INCREMENT — see OptionIdentityAllocator.
            "CREATE TABLE {$t('nodes')} (
                id bigint(20) unsigned NOT NULL,
                version int(10) unsigned NOT NULL DEFAULT 1,
                name varchar(191) NOT NULL,
                path varchar(255) NOT NULL,
                PRIMARY KEY  (id),
                KEY path (path),
                KEY name (name)
            ) {$charset};",

            "CREATE TABLE {$t('relations')} (
                id bigint(20) unsigned NOT NULL,
                version int(10) unsigned NOT NULL DEFAULT 1,
                from_id bigint(20) unsigned NOT NULL,
                to_id bigint(20) unsigned NOT NULL,
                kind varchar(20) NOT NULL,
                name varchar(191) NOT NULL DEFAULT '',
                position int(10) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY from_id (from_id),
                KEY to_id (to_id)
            ) {$charset};",

            // Typed value columns, never one stringly value cast in and out (D-071, D-074).
            // No floating point anywhere: a price and a tolerance are exact (D-057).
            "CREATE TABLE {$t('settings')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                owner_id bigint(20) unsigned NOT NULL,
                `key` varchar(191) NOT NULL,
                value_int bigint(20) DEFAULT NULL,
                value_decimal decimal(30,10) DEFAULT NULL,
                value_text mediumtext DEFAULT NULL,
                value_date datetime DEFAULT NULL,
                value_ref bigint(20) unsigned DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY owner_key (owner_id,`key`),
                KEY value_ref (value_ref)
            ) {$charset};",

            "CREATE TABLE {$t('labels')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                owner_id bigint(20) unsigned NOT NULL,
                path varchar(255) NOT NULL DEFAULT '',
                role_id bigint(20) unsigned NOT NULL,
                number varchar(20) NOT NULL DEFAULT '',
                locale varchar(20) NOT NULL DEFAULT '',
                text mediumtext NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY one_text (owner_id,path,role_id,number,locale),
                KEY role_id (role_id)
            ) {$charset};",

            // owner_kind is stored alongside because the changelog outlives what it refers
            // to — frozen history rather than a duplicated fact (D-065).
            "CREATE TABLE {$t('changelog')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                owner_id bigint(20) unsigned NOT NULL,
                owner_kind varchar(20) NOT NULL,
                at datetime NOT NULL,
                by_user_id bigint(20) unsigned DEFAULT NULL,
                what varchar(40) NOT NULL,
                before_state mediumtext DEFAULT NULL,
                after_state mediumtext DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY owner_id (owner_id),
                KEY at (at)
            ) {$charset};",

            // The record identity space is its own (D-164), so AUTO_INCREMENT serves it.
            "CREATE TABLE {$t('records')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                model_id bigint(20) unsigned NOT NULL,
                model_version int(10) unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY model_id (model_id)
            ) {$charset};",

            // Keyed on a path with the last edge repeated in edge_id, so that
            // `WHERE edge_id = ... AND value_decimal > 1000` finds every occurrence
            // regardless of how deep it sits (D-134).
            "CREATE TABLE {$t('record_values')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                record_id bigint(20) unsigned NOT NULL,
                edge_id bigint(20) unsigned NOT NULL,
                path varchar(255) NOT NULL,
                locale varchar(20) NOT NULL DEFAULT '',
                value_int bigint(20) DEFAULT NULL,
                value_decimal decimal(30,10) DEFAULT NULL,
                value_text mediumtext DEFAULT NULL,
                value_date datetime DEFAULT NULL,
                value_ref bigint(20) unsigned DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY one_value (record_id,path,locale),
                KEY edge_id (edge_id),
                KEY value_ref (value_ref)
            ) {$charset};",
        ];
    }
}
