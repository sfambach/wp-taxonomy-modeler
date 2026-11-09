<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

/**
 * Which records still need a review (D-1255): a record created with its name only, from «New here …» in a record dialog,
 * until someone saves it once in the full form. The one place that touches the table.
 *
 * @see docs/NewConcept/90-decision-log.md D-1255
 */
final class RecordReviews
{
    public static function mark(int $record): void
    {
        global $wpdb;

        if ($record > 0) {
            $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . Schema::table('record_reviews') . ' (node_record_id, since) VALUES (%d, %s)', $record, current_time('mysql', true)));
        }
    }

    public static function clear(int $record): void
    {
        global $wpdb;

        if ($record > 0) {
            $wpdb->delete(Schema::table('record_reviews'), ['node_record_id' => $record], ['%d']);
        }
    }

    /**
     * The records among these that need a review, in one query (CD-7).
     *
     * @param list<int> $records
     * @return list<int>
     */
    public static function among(array $records): array
    {
        global $wpdb;

        $records = array_values(array_unique(array_filter(array_map('intval', $records), static fn (int $id): bool => $id > 0)));

        if ($records === []) {
            return [];
        }

        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT node_record_id FROM ' . Schema::table('record_reviews') . ' WHERE node_record_id IN (' . implode(',', array_fill(0, count($records), '%d')) . ')',
            ...$records
        ));

        return array_map('intval', $ids ?: []);
    }
}
