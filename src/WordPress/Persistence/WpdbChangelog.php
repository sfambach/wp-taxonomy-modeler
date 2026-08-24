<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\Clock;

/**
 * Frozen history, one row per change, bracketed by act.
 *
 * ⚠️ **A machine change is recorded as the machine** (D-296): when no human is behind the
 * request — cron, WP-CLI, an import — `by_user_id` stays null rather than borrowing whichever
 * administrator was logged in. A wrong name in the history is worse than no name.
 *
 * ⚠️ **The change group is the id of the act's first row** (D-348). No second counter and no
 * extra table: the row that opens an act is stamped with its own number, and every later row of
 * the same act carries it too. A borrowed number would not do — see D-348 for why neither
 * version number can serve.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class WpdbChangelog implements Changelog
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function record(
        int $ownerId,
        string $ownerKind,
        string $what,
        ?string $before,
        ?string $after,
        ?int $changeGroupId = null,
    ): int {
        global $wpdb;

        $table = Schema::table('changelog');

        $wpdb->insert(
            $table,
            [
                'change_group_id' => $changeGroupId,
                'owner_id'        => $ownerId,
                'owner_kind'      => $ownerKind,
                'at'              => $this->clock->now()->format('Y-m-d H:i:s'),
                'by_user_id'      => $this->human(),
                'what'            => $what,
                'before_state'    => $before,
                'after_state'     => $after,
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        if ($changeGroupId !== null) {
            return $changeGroupId;
        }

        // The row that opens an act becomes its own group. One extra statement, and only for
        // the first row — every later row of the act is told the number.
        $opened = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET change_group_id = %d WHERE id = %d",
            $opened,
            $opened
        ));

        return $opened;
    }


    public function recordMany(array $rows, ?int $changeGroupId = null): int
    {
        global $wpdb;

        if ($rows === []) {
            return $changeGroupId ?? 0;
        }

        $table = Schema::table('changelog');
        $at    = $this->clock->now()->format('Y-m-d H:i:s');
        $by    = $this->human();

        $values       = [];
        $placeholders = [];

        // ⚠️ **A nullable column cannot take `%d`.** `prepare()` casts null to `0`, so the rows
        // would land in group zero and the act would silently fall apart — which is exactly
        // what happened, and what the boundary run caught while the in-memory fake could not.
        // Null is written as a literal instead; the same for `by_user_id`, which is null for a
        // machine change (D-296).
        $group = $changeGroupId === null ? 'NULL' : '%d';
        $user  = $by === null ? 'NULL' : '%d';

        foreach ($rows as $row) {
            $placeholders[] = "({$group}, %d, %s, %s, {$user}, %s, %s, %s)";

            if ($changeGroupId !== null) {
                $values[] = $changeGroupId;
            }

            array_push($values, $row['ownerId'], $row['ownerKind'], $at);

            if ($by !== null) {
                $values[] = $by;
            }

            array_push($values, $row['what'], $row['before'], $row['after']);
        }

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
             (change_group_id, owner_id, owner_kind, at, by_user_id, what, before_state, after_state)
             VALUES " . implode(',', $placeholders),
            $values
        ));

        if ($changeGroupId !== null) {
            return $changeGroupId;
        }

        // The first row of the batch opens the act; the rest of the batch joins it.
        $opened = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET change_group_id = %d WHERE id >= %d AND change_group_id IS NULL",
            $opened,
            $opened
        ));

        return $opened;
    }

    public function actAround(int $ownerId, string $what): array
    {
        global $wpdb;

        $table = Schema::table('changelog');

        $group = $wpdb->get_var($wpdb->prepare(
            "SELECT change_group_id FROM {$table}
             WHERE owner_id = %d AND what = %s
             ORDER BY id DESC LIMIT 1",
            $ownerId,
            $what
        ));

        if ($group === null) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT owner_id, what, before_state, after_state FROM {$table}
             WHERE change_group_id = %d ORDER BY id ASC",
            (int) $group
        ), ARRAY_A);

        return array_map(
            static fn (array $r): array => [
                'ownerId' => (int) $r['owner_id'],
                'what'    => (string) $r['what'],
                'before'  => $r['before_state'],
                'after'   => $r['after_state'],
            ],
            $rows ?: []
        );
    }

    public function pathBeforeLastParking(int $ownerId): ?string
    {
        global $wpdb;

        $before = $wpdb->get_var($wpdb->prepare(
            'SELECT before_state FROM ' . Schema::table('changelog') . '
             WHERE owner_id = %d AND what = %s
             ORDER BY id DESC LIMIT 1',
            $ownerId,
            'parked'
        ));

        if ($before === null) {
            return null;
        }

        // ⚠️ Read from the **last** `path=`, not the first: a node may legitimately be called
        // something containing ` path=`, and the path is written last.
        $at = strrpos((string) $before, ' path=');

        return $at === false ? null : substr((string) $before, $at + 6);
    }

    private function human(): ?int
    {
        if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return null;
        }

        $id = get_current_user_id();

        return $id > 0 ? $id : null;
    }
}
