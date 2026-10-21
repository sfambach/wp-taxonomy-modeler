<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\InboxGrouping;
use Taxmod\Core\Import\IncomingProposal;
use Taxmod\Core\Import\InboxStatus;
use Taxmod\Core\Import\OrderProposal;

/**
 * Reads and writes the inbox rows. Every query is prepared (CD-6).
 *
 * @see docs/pakete/import/konzept.md §3.3
 */
final class InboxTable
{
    /** True when this source already handed in this external id — a second run brings it again. */
    public function has(string $source, string $externalId): bool
    {
        global $wpdb;

        return $externalId !== '' && (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . InboxSchema::table() . ' WHERE source = %s AND external_id = %s LIMIT 1',
            $source,
            mb_substr($externalId, 0, 191)
        ));
    }

    /** The same message under another id — a forwarded copy: same platform, order number, kind and moment received. */
    public function hasSame(OrderProposal $proposal, string $receivedAt): bool
    {
        global $wpdb;

        return trim($proposal->orderNumber) !== '' && (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . InboxSchema::table() . ' WHERE platform = %s AND order_number = %s AND kind = %s AND received_at = %s LIMIT 1',
            mb_substr($proposal->platform, 0, 64),
            mb_substr($proposal->orderNumber, 0, 64),
            $proposal->kind->value,
            $receivedAt
        ));
    }

    /** Forgets every open proposal of a source; returns how many went. */
    public function forgetOpen(string $source): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . InboxSchema::table() . ' WHERE source = %s AND status = %s',
            $source,
            InboxStatus::Open->value
        ));
    }

    public function add(string $source, IncomingProposal $incoming, ?string $receivedAt = null): int
    {
        global $wpdb;
        $proposal = $incoming->proposal;

        $wpdb->insert(InboxSchema::table(), [
            'source'       => $source,
            'external_id'  => mb_substr((string) $proposal->externalId, 0, 191),
            'platform'     => mb_substr($proposal->platform, 0, 64),
            'order_number' => mb_substr($proposal->orderNumber, 0, 64),
            'kind'         => $proposal->kind->value,
            'status'       => InboxStatus::Open->value,
            'proposal'     => (string) wp_json_encode($proposal->toArray()),
            'evidence'     => $incoming->evidence === [] ? null : wp_json_encode($incoming->evidence),
            'received_at'  => $receivedAt,
            'created_at'   => current_time('mysql'),
        ]);

        return (int) $wpdb->insert_id;
    }

    public function replaceProposal(int $id, OrderProposal $proposal): void
    {
        global $wpdb;

        $wpdb->update(InboxSchema::table(), ['proposal' => (string) wp_json_encode($proposal->toArray())], ['id' => $id]);
    }

    /**
     * Open rows grouped by order (InboxGrouping), so the review page shows one order once.
     *
     * @return list<non-empty-list<array<string, mixed>>> `mixed` because these are database rows
     */
    public function openGroups(): array
    {
        global $wpdb;
        $rows = [];

        foreach ($wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . InboxSchema::table() . ' WHERE status = %s ORDER BY id ASC',
            InboxStatus::Open->value
        ), ARRAY_A) ?: [] as $row) {
            $rows[(int) $row['id']] = $row;
        }

        $proposals = array_map(static fn (array $row): OrderProposal => OrderProposal::fromArray((array) json_decode((string) $row['proposal'], true)), $rows);
        $days = array_map(static fn (array $row): ?string => $row['received_at'] === null ? null : substr((string) $row['received_at'], 0, 10), $rows);

        return array_map(
            static fn (array $ids): array => array_map(static fn (int $id): array => $rows[$id], $ids),
            InboxGrouping::group($proposals, $days)
        );
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>> the open rows among $ids
     */
    public function openRows(array $ids): array
    {
        global $wpdb;

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . InboxSchema::table() . " WHERE status = %s AND id IN ($placeholders) ORDER BY id ASC",
            InboxStatus::Open->value,
            ...$ids
        ), ARRAY_A) ?: [];
    }

    /** @param array<string, int> $written ref => record id, as write-data answered */
    public function close(int $id, InboxStatus $status, array $written = []): void
    {
        global $wpdb;

        $wpdb->update(InboxSchema::table(), [
            'status'     => $status->value,
            'written'    => $written === [] ? null : wp_json_encode($written),
            'handled_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    /** @return array<string, int> status => count */
    public function counts(): array
    {
        global $wpdb;
        $counts = [];

        foreach ($wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . InboxSchema::table() . ' GROUP BY status', ARRAY_A) ?: [] as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }
}
