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
     * 4 — the changelog gains `change_group_id`, the bracket around one act (D-348).
     * 5 — the three branches are seeded as framework nodes; no table changed, but the version
     *     moves so that an already-installed copy gets them (D-161).
     * 6 — the label roles are seeded as nodes under their own container (D-151); again no
     *     table changed, and again the version is what carries them to an installed copy.
     * 7 — `relations.parked_by_group_id`, so an attribute can be removed at all (D-371).
     * 8 — `settings.path`, the address a setting needs to say **which** place it answers for
     *     (OQ-092). The unique key becomes `(owner_id, setting_key, path)`; an empty path means
     *     the owner itself, so every existing row keeps its meaning untouched. **Four decisions
     *     had assumed this column existed** — D-236, D-158, C30 and D-378 — and the last of them
     *     was measured on 2026-08-26 to be written and not functioning because of it.
     * 9 — `records.model_id` becomes `node_id` and `model_version` becomes `node_version`
     *     (D-441). **A rename, not a change**: no row moves. The index comes with the column,
     *     because `dbDelta` has no notion of a rename and would build a second one beside it.
     *     *The old name was measured to mislead: it pointed into `Compositions` for 21 of 24
     *     records while `Model` is also the name of a branch in the tree.*
     * 10 — `nodes.hide` and `relations.hide`, and the `hide` **setting** goes away entirely
     *      (D-426, D-457). *A column is **not in the chain**, which is the whole point: as a
     *      setting, `hide` on a type blanked every field of that type, because an attribute's
     *      chain contains its target node. Measured twice — by experiment on 2026-08-26 and
     *      again on 2026-08-27.* **Both node and edge, because the owner asked for both**:
     *      «edge and node both having an attribute `hide`».
     */
    public const VERSION = 10;

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

        // ⚠️ **Before `dbDelta`, and that order is the whole trick.** *`dbDelta` compares a table
        // against a `CREATE TABLE` and **adds** what is missing — it has no notion of a rename. Run
        // afterwards, it would create `node_id` beside `model_id` and leave both, with the data in
        // the one nothing reads any more.*
        self::renameRecordColumns();

        foreach (self::statements() as $sql) {
            dbDelta($sql);
        }

        self::backfillIdentities();
        self::backfillInheritanceEdges();
        self::dropRetiredColumns();
        self::widenSettingUniqueKey();
        self::moveHideOutOfSettings();
        self::ensureForeignKeys();
    }

    /**
     * `hide` moves from the settings table into two columns — schema 10.
     *
     * ⚠️ **The point is that a column is *not in the chain*.** *[OQ-101](../../../docs/NewConcept/91-open-questions.md)
     * established it by experiment and 2026-08-27 reproduced it: `hide` as a **setting** on a type
     * blanked **every field of that type**, because an attribute's chain contains its target node.
     * [D-426](../../../docs/NewConcept/90-decision-log.md): «a column is not in the chain, so the two can
     * no longer reach each other **by construction** rather than by a rule somebody has to remember.»*
     *
     * ⚠️ **Both tables, because the owner asked for both** ([D-457](../../../docs/NewConcept/90-decision-log.md)):
     * *«edge and node both having an attribute `hide`»* — a node hides itself, a placement hides what
     * hangs there.
     *
     * ⚠️ **After `dbDelta`, unlike the rename in schema 9** — this one needs the columns to exist before
     * it can write into them, and `dbDelta` is what creates them. *The opposite order to
     * {@see self::renameRecordColumns()}, and for the opposite reason.*
     *
     * ⚠️ *Only `value_int = 1` travels. A `hide = 0` row says «not hidden», which is what the column
     * already defaults to — writing it would be copying a default into 109 rows.*
     */
    private static function moveHideOutOfSettings(): void
    {
        global $wpdb;

        $settings = self::table('settings');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $settings)) !== $settings) {
            return;
        }

        // Nothing to do on a fresh install, and a no-op on every activation after the first.
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$settings} WHERE setting_key = 'hide'") === 0) {
            return;
        }

        foreach (['nodes', 'relations'] as $name) {
            $table = self::table($name);

            $wpdb->query(
                "UPDATE {$table} t
                 JOIN {$settings} s ON s.owner_id = t.id AND s.setting_key = 'hide' AND s.value_int = 1
                 SET t.hide = 1"
            );
        }

        // ⚠️ *Deleted, not kept «just in case». The changelog is the record of what happened
        // ([D-061](../../../docs/NewConcept/90-decision-log.md)); a second copy in a table nothing
        // reads is the duplicated fact the standard forbids.*
        $wpdb->query("DELETE FROM {$settings} WHERE setting_key = 'hide'");
    }

    /**
     * `records.model_id` becomes `node_id`, `model_version` becomes `node_version` — schema 9.
     *
     * ⚠️ **A rename and nothing else: no row changes, no value moves.** [D-441](../../../docs/NewConcept/90-decision-log.md)
     * asked for it *because the old name lied* — measured, `model_id` pointed into `Compositions` for
     * **21 of 24** records and into `Model` for 3, while `Model` is simultaneously the name of a branch
     * in the tree. *A reader who knew the tree read it as «points into Model» and was wrong four times
     * out of five.*
     *
     * ⚠️ **The index has to come with the column, or `dbDelta` adds a second one.** *A renamed column
     * keeps its index, but the index keeps the **old name** — and `dbDelta`, comparing against a
     * definition that says `KEY node_id`, would helpfully create it. Two indexes over one column,
     * neither wrong, both there forever.*
     *
     * ⚠️ *Every step asks first. This runs on every activation, so it has to be a no-op the second
     * time — and on a fresh install the table does not exist at all yet, which is the first thing
     * checked.*
     */
    private static function renameRecordColumns(): void
    {
        global $wpdb;

        $table = self::table('records');

        // A fresh install: `dbDelta` will create the table with the new names in a moment.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        $renames = [
            ['model_id', 'node_id', 'bigint(20) unsigned NOT NULL'],
            ['model_version', 'node_version', 'int(10) unsigned NOT NULL'],
        ];

        foreach ($renames as [$from, $to, $type]) {
            $present = $wpdb->get_col($wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                $from
            ));

            if ($present === []) {
                continue;
            }

            // ⚠️ `CHANGE` and not `RENAME COLUMN`: the latter wants MySQL 8, and this plugin does not
            // get to choose the server it lands on.
            $wpdb->query("ALTER TABLE {$table} CHANGE {$from} {$to} {$type}");
        }

        $index = $wpdb->get_col($wpdb->prepare(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            $table,
            'model_id'
        ));

        if ($index !== []) {
            // Drop and recreate rather than `RENAME INDEX`, for the same portability reason.
            $wpdb->query("ALTER TABLE {$table} DROP INDEX model_id, ADD KEY node_id (node_id)");
        }
    }

    /**
     * Take `path` into the settings unique key — because `dbDelta` never touches an index it has
     * already created.
     *
     * ⚠️ **This is the half a schema bump does not do for you.** Adding the column worked on the
     * first run; the key stayed `(owner_id, setting_key)`, so a second row for the same key at a
     * different path would have been refused by a constraint nobody had noticed was still there.
     * *Measured before writing this: the column existed and `SHOW INDEX` still listed two columns.*
     *
     * ⚠️ **Safe in this direction and only in this direction.** The old key is **stricter** than the
     * new one, so no existing row can collide — widening a unique key can never fail on data that a
     * narrower one already accepted. *Narrowing one would be the opposite and would need the
     * duplicates found first.*
     */
    private static function widenSettingUniqueKey(): void
    {
        global $wpdb;

        $table = self::table('settings');

        $columns = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
             ORDER BY SEQ_IN_INDEX',
            $table,
            'owner_key'
        ));

        // Already three columns, or the index is not there at all on a fresh install where dbDelta
        // built it from the current definition.
        if ($columns === [] || in_array('path', $columns, true)) {
            return;
        }

        // ⚠️ **The new index goes in before the old one comes out, and that order is the whole
        // difference between working and silently doing nothing.** `settings.owner_id` carries a
        // foreign key to `identities` ([D-339]), and `owner_key` is an index MySQL can use to enforce
        // it — so `DROP INDEX` alone is **refused**, without an exception a caller would see.
        //
        // ⚠️ *Adding `owner_key_path` first gives the constraint a second index that also begins with
        // `owner_id`. The drop then succeeds, and the temporary name is renamed into place — which is
        // three statements to do one thing, and the reason is written here so nobody tidies it back
        // into one.*
        $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY owner_key_path (owner_id,setting_key,path)");

        if ($wpdb->last_error !== '') {
            return;
        }

        $wpdb->query("ALTER TABLE {$table} DROP INDEX owner_key");
        $wpdb->query("ALTER TABLE {$table} RENAME INDEX owner_key_path TO owner_key");
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
                hide tinyint(1) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY path (path),
                KEY name (name)
            ) {$charset};",

            // ⚠️ `parked_by_group_id` is the one place an edge can be parked (D-371). A node needs
            // no such column, because its **position** is the mark — it sits under the trash
            // (Package 1) — and an edge has no position in the tree, so there is no first truth to
            // duplicate. It holds the **change group** that parked it rather than a bare flag,
            // because D-128 wants a parked attribute labelled *deleted with «X»* and the group is
            // where that act is described (D-348). One column, two facts, neither of them a copy.
            "CREATE TABLE {$t('relations')} (
                id bigint(20) unsigned NOT NULL,
                version int(10) unsigned NOT NULL DEFAULT 1,
                from_id bigint(20) unsigned NOT NULL,
                to_id bigint(20) unsigned NOT NULL,
                kind varchar(20) NOT NULL,
                name varchar(191) NOT NULL DEFAULT '',
                position int(10) unsigned NOT NULL DEFAULT 0,
                parked_by_group_id bigint(20) unsigned DEFAULT NULL,
                hide tinyint(1) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY from_id (from_id),
                KEY to_id (to_id),
                KEY parked_by_group_id (parked_by_group_id)
            ) {$charset};",

            // Typed value columns, never one stringly value cast in and out (D-071, D-074).
            // No floating point anywhere: a price and a tolerance are exact (D-057).
            // ⚠️ **`path` is an address, not a multiplicity** ([D-409](../../../docs/NewConcept/90-decision-log.md),
            // [OQ-092](../../../docs/NewConcept/91-open-questions.md)). One key still holds one answer
            // at one place; `path` says **which place** — which attribute of this node, which member of
            // a composed value. *Several rows for one key at one place would be a multiplicity, and a
            // setting has none.*
            //
            // ⚠️ **Four decisions had already assumed it existed**, which is the argument for building
            // it before anything else: several renderers ([D-236](../../../docs/NewConcept/90-decision-log.md)),
            // several validators ([D-158](../../../docs/NewConcept/90-decision-log.md)), several defaults
            // (C30), and the prefix exponent ([D-378](../../../docs/NewConcept/90-decision-log.md)) —
            // which was measured on 2026-08-26 to be **written and not connected** for exactly this
            // reason: `kilo`'s value had nowhere to sit that the `exponent` attribute could read.
            //
            // ⚠️ **Empty means «the owner itself»**, so every row written before this version keeps its
            // meaning without being touched — the same choice `labels.path` made, and the same default.
            "CREATE TABLE {$t('settings')} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                owner_id bigint(20) unsigned NOT NULL,
                setting_key varchar(191) NOT NULL,
                path varchar(255) NOT NULL DEFAULT '',
                value_int bigint(20) DEFAULT NULL,
                value_decimal decimal(30,10) DEFAULT NULL,
                value_text mediumtext DEFAULT NULL,
                value_date datetime DEFAULT NULL,
                value_ref bigint(20) unsigned DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY owner_key (owner_id,setting_key,path),
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
                node_id bigint(20) unsigned NOT NULL,
                node_version int(10) unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY node_id (node_id)
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
