<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\RelationKind;

/**
 * The seven tables (D-083) plus the identity base they draw their numbers from (D-339),
 * created on activation and guarded by a stored schema version.
 *
 * ⚠️ **Seven, and no table per model** — the model *is* the schema (D-066). A per-model
 * projection may exist later, but only as a rebuildable cache (D-228), never as a place where
 * anything is kept.
 *
 * ```mermaid
 * flowchart TD
 *   I[(identities)] --> N[(nodes)]
 *   I --> R[(relations)]
 *   I --> S[(settings)]
 *   I --> L[(labels)]
 *   I --> C[(changelog)]
 *   RC[(records)] --> RV[(record_values)]
 * ```
 *
 * `settings`, `labels` and `changelog` all hang off **an identity**, not off a node — which is
 * what lets a *relation* carry settings too (C8). That is why `owner_id` is one column rather
 * than a kind plus an id, and since D-339 it is a real foreign key rather than a promise.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class Schema
{
    /**
     * Raise this whenever a table definition below changes. The stored value is compared on
     * every load, so an upgrade is deterministic rather than a matter of when somebody last
     * deactivated the plugin (`CD-6`).
     *
     * 1 — the seven tables, ids from a counter in `wp_options`.
     * 2 — `identities` takes over id allocation and `owner_id` becomes a foreign key (D-339);
     *     `settings.key` becomes `setting_key`, because `KEY` is reserved and dbDelta cannot
     *     parse an index over a backticked column — the same reason `before` became
     *     `before_state`.
     * 3 — inheritance edges become the tree and `nodes.path` is derived from them (D-014);
     *     `relations.from_id` and `to_id` join the foreign keys.
     */
    public const VERSION = 4;

    public const VERSION_OPTION = 'taxmod_schema_version';

    /** The counter version 1 allocated from. Read once during the upgrade, then removed. */
    private const RETIRED_COUNTER_OPTION = 'taxmod_model_last_id';

    /** Every column that holds a model identity, and the table it sits in. */
    private const IDENTITY_REFERENCES = [
        ['nodes', 'id'],
        ['relations', 'id'],
        ['relations', 'from_id'],
        ['relations', 'to_id'],
        ['settings', 'owner_id'],
        ['labels', 'owner_id'],
        ['changelog', 'owner_id'],
    ];

    /** @return list<string> The table names, without the WordPress prefix. */
    public static function tableNames(): array
    {
        return ['identities', 'nodes', 'relations', 'settings', 'labels', 'changelog', 'records', 'record_values'];
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

        self::backfillIdentities();
        self::backfillInheritanceEdges();
        self::dropRetiredColumns();
        self::ensureForeignKeys();
    }

    /**
     * Give every node that has a parent the inheritance edge it should always have had.
     *
     * ⚠️ **Version 1 and 2 stored the tree only as `nodes.path`** — but D-014 calls the path
     * *derived, rebuildable, never a second truth*, and the truth it should derive from is the
     * inheritance edge. Until this ran, the derived value **was** the only truth, which is the
     * relation the concept forbids, the wrong way round.
     *
     * The parent is read back out of the path, which is exactly what the path is good for. It
     * is a one-time pass over the existing nodes, so the row-by-row insert is not the N+1 that
     * `CD-7` forbids — that rule is about the paths a person walks every day.
     */
    private static function backfillInheritanceEdges(): void
    {
        global $wpdb;

        $nodes     = self::table('nodes');
        $relations = self::table('relations');
        $allocator = new TableIdentityAllocator();

        $orphans = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT n.id, n.path FROM {$nodes} n
                 LEFT JOIN {$relations} r ON r.to_id = n.id AND r.kind = %s
                 WHERE n.path LIKE %s AND r.id IS NULL
                 ORDER BY LENGTH(n.path) ASC, n.id ASC",
                RelationKind::Inheritance->value,
                '%.%'
            ),
            ARRAY_A
        );

        foreach ($orphans ?: [] as $row) {
            $segments = explode('.', (string) $row['path']);
            array_pop($segments);
            $parentId = (int) end($segments);

            $position = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(position) FROM {$relations} WHERE from_id = %d AND kind = %s",
                $parentId,
                RelationKind::Inheritance->value
            ));

            $wpdb->insert(
                $relations,
                [
                    'id'       => $allocator->next(),
                    'version'  => 1,
                    'from_id'  => $parentId,
                    'to_id'    => (int) $row['id'],
                    'kind'     => RelationKind::Inheritance->value,
                    'name'     => '',
                    'position' => $position === null ? 0 : (int) $position + 1,
                ],
                ['%d', '%d', '%d', '%d', '%s', '%s', '%d']
            );
        }
    }

    /**
     * Remove columns a later version replaced. `dbDelta` never drops anything, so without this
     * an upgraded installation would carry both the old column and the new one, and nobody
     * reading the table could tell which one is true.
     *
     * ⚠️ **Dropping a column destroys what is in it.** That is only defensible here because
     * `settings.key` never shipped with data in it — version 1 created the table and nothing
     * ever wrote a row. **A future retirement that holds data must copy first and drop after.**
     */
    private static function dropRetiredColumns(): void
    {
        global $wpdb;

        $settings = self::table('settings');

        $hasOld = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $settings,
            'key'
        ));

        if ($hasOld === 1) {
            $wpdb->query("ALTER TABLE {$settings} DROP COLUMN `key`");
        }
    }

    /**
     * Give every id that already exists a row in `identities`, then push the counter past every
     * id that was ever handed out.
     *
     * ⚠️ **The second half is the one that matters.** Version 1 allocated from a counter in
     * `wp_options`, and ids belonging to purged objects leave no trace in any table. Seeding
     * `AUTO_INCREMENT` from the highest *surviving* id would reissue exactly those numbers —
     * the reuse D-339 exists to prevent, introduced by the migration meant to prevent it.
     */
    private static function backfillIdentities(): void
    {
        global $wpdb;

        $identities = self::table('identities');
        $highest    = (int) get_option(self::RETIRED_COUNTER_OPTION, 0);

        foreach (self::IDENTITY_REFERENCES as [$table, $column]) {
            $source = self::table($table);

            $wpdb->query(
                "INSERT IGNORE INTO {$identities} (id)
                 SELECT DISTINCT s.{$column} FROM {$source} s
                 WHERE s.{$column} > 0"
            );

            $highest = max($highest, (int) $wpdb->get_var("SELECT MAX({$column}) FROM {$source}"));
        }

        if ($highest > 0) {
            $wpdb->query(
                $wpdb->prepare("ALTER TABLE {$identities} AUTO_INCREMENT = %d", $highest + 1)
            );
        }

        // One source, not two. Leaving the old counter behind would invite somebody to trust it.
        delete_option(self::RETIRED_COUNTER_OPTION);
    }

    /**
     * Add the foreign keys `dbDelta` cannot express, once, and only if they are missing.
     *
     * ⚠️ **`RESTRICT` is deliberate and is not laziness.** An identity row is never deleted
     * (D-339), so the database refusing to delete one is the rule being enforced rather than a
     * case left unhandled.
     */
    private static function ensureForeignKeys(): void
    {
        global $wpdb;

        $identities = self::table('identities');

        foreach (self::IDENTITY_REFERENCES as [$table, $column]) {
            $source     = self::table($table);
            $constraint = 'fk_taxmod_' . $table . '_' . $column;

            $exists = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = %s',
                $constraint
            ));

            if ($exists > 0) {
                continue;
            }

            $wpdb->query(
                "ALTER TABLE {$source}
                 ADD CONSTRAINT {$constraint} FOREIGN KEY ({$column})
                 REFERENCES {$identities} (id) ON DELETE RESTRICT ON UPDATE RESTRICT"
            );
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
            // The model identity space, shared by nodes and relations (C11). One column, and
            // that is the whole point: it exists so that a number is allocated in exactly one
            // place and never a second time. Rows are added, never removed (D-339).
            "CREATE TABLE {$t('identities')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                PRIMARY KEY  (id)
            ) {$charset};",

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
                setting_key varchar(191) NOT NULL,
                value_int bigint(20) DEFAULT NULL,
                value_decimal decimal(30,10) DEFAULT NULL,
                value_text mediumtext DEFAULT NULL,
                value_date datetime DEFAULT NULL,
                value_ref bigint(20) unsigned DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY owner_key (owner_id,setting_key),
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
            // change_group_id brackets the rows written by one act (D-348). It is a number and
            // nothing more — no table, because a second place where history lives is a second
            // place that can disagree with the changelog (D-061).
            "CREATE TABLE {$t('changelog')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                change_group_id bigint(20) unsigned DEFAULT NULL,
                owner_id bigint(20) unsigned NOT NULL,
                owner_kind varchar(20) NOT NULL,
                at datetime NOT NULL,
                by_user_id bigint(20) unsigned DEFAULT NULL,
                what varchar(40) NOT NULL,
                before_state mediumtext DEFAULT NULL,
                after_state mediumtext DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY change_group_id (change_group_id),
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
