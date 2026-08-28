<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\ChangeSummary;
use Taxmod\Core\Model\FrozenState;
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
    /**
     * How many brackets are open, and the number the outermost one adopted.
     *
     * ⚠️ *Two fields rather than one, because the number only exists once a row has been written:
     * `$depth` says «an act is running», `$openAct` says «and it is this one». Between `beginAct()`
     * and the act's first `record()` the first is set and the second is not — which is correct rather
     * than a gap.*
     */
    private int $depth = 0;

    private ?int $openAct = null;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function beginAct(): void
    {
        ++$this->depth;
    }

    public function endAct(): void
    {
        if ($this->depth > 0) {
            --$this->depth;
        }

        if ($this->depth === 0) {
            $this->openAct = null;
        }
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

        // ⚠️ **An open act adopts the row, and an explicit id still wins.** *The thirteen callers that
        // pass a group on purpose keep working exactly as before; the bracket only answers for the
        // ones that passed nothing and therefore each opened an act of their own.*
        $changeGroupId ??= $this->openAct;

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

        // ⚠️ *And if a bracket is open, this row is the one that gave it its number.*
        if ($this->depth > 0) {
            $this->openAct ??= $opened;
        }

        return $opened;
    }


    public function recordMany(array $rows, ?int $changeGroupId = null): int
    {
        global $wpdb;

        if ($rows === []) {
            return $changeGroupId ?? 0;
        }

        // ⚠️ *Same adoption as {@see record()} — a batch written inside a bracket is part of that act
        // and not an act of its own.*
        $changeGroupId ??= $this->openAct;

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

        if ($this->depth > 0) {
            $this->openAct ??= $opened;
        }

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

        // ⚠️ **Read through {@see FrozenState} and no longer with a search from the right.** *The old
        // line was `strrpos(' path=')`, and the **same line stood a second time** in the test double —
        // two readers for one format, which is how a format comes to disagree with itself. It also
        // could not have been kept: the node state now writes `path` **before** the name
        // ([D-427](../../../docs/NewConcept/90-decision-log.md)), so «the last `path=`» stopped
        // meaning «the address».*
        //
        // ⚠️ *Both orders parse to the same address, and that is measured rather than argued: the
        // boundary check reads **every** row in the table with both the old rule and this one and
        // asserts they agree — 0 of 10745 rows carry ` path=` twice, which is the only case where they
        // could differ.*
        return FrozenState::parse($before === null ? null : (string) $before)?->field('path');
    }

    private function human(): ?int
    {
        if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return null;
        }

        $id = get_current_user_id();

        return $id > 0 ? $id : null;
    }

    public function summaryOf(int $ownerId): ChangeSummary
    {
        global $wpdb;

        $table = Schema::table('changelog');

        // ⚠️ **Ordered by `id` and not by `at`.** Two acts in the same second are ordinary — a save
        // writes several rows — and a timestamp cannot order them. The id is the sequence.
        $first = $wpdb->get_row($wpdb->prepare(
            'SELECT at, by_user_id, what FROM ' . $table . ' WHERE owner_id = %d ORDER BY id ASC LIMIT 1',
            $ownerId
        ));

        if ($first === null) {
            return new ChangeSummary();
        }

        $last = $wpdb->get_row($wpdb->prepare(
            'SELECT at, by_user_id, what FROM ' . $table . ' WHERE owner_id = %d ORDER BY id DESC LIMIT 1',
            $ownerId
        ));

        // ⚠️ *The first row is only a **creation** where it says so. A node seeded before the
        // changelog existed has a first row that is a rename or a move, and calling that its birthday
        // would be a guess presented as a fact.*
        $created = $first->what === 'created';

        // ⚠️ **`(int) null` is `0`, and that undid the whole of [D-296](../../../docs/NewConcept/90-decision-log.md)
        // on the way out.** *The column stores null correctly — measured, **7696 rows** of it against
        // 1262 human ones — and this cast turned every one of them into «user 0» for a reader. The
        // screen then drew `#0`, which is neither a person nor the machine.*
        //
        // ⚠️ *Written as a closure rather than twice, because the second copy is where the next
        // reader's cast will go back in.*
        $who = static fn (?string $id): ?int => $id === null ? null : (int) $id;

        return new ChangeSummary(
            $created ? (string) $first->at : null,
            $created ? $who($first->by_user_id) : null,
            (string) $last->at,
            $who($last->by_user_id),
            (string) $last->what
        );
    }
}
