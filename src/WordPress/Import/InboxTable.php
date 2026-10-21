<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

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

    public function add(string $source, IncomingProposal $incoming): int
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
     * Open rows grouped by platform and order number, so the review page shows one order once.
     *
     * @return list<non-empty-list<array<string, mixed>>> `mixed` because these are database rows
     */
    public function openGroups(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . InboxSchema::table() . ' WHERE status = %s ORDER BY id ASC',
            InboxStatus::Open->value
        ), ARRAY_A) ?: [];
        $groups = [];

        foreach ($rows as $row) {
            $number = trim((string) $row['order_number']);
            $key = $number === '' ? 'row:' . $row['id'] : mb_strtolower(trim((string) $row['platform'])) . '|' . $number;
            $groups[$key][] = $row;
        }

        return array_values($groups);
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
