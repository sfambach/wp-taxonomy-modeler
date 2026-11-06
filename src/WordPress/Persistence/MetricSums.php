<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Addon\ChosenAddon;
use Taxmod\Core\Addon\MetricMeasure;
use Taxmod\Core\Model\Node;

/**
 * A tile's figure per step — counted or summed in the database, moved here from the tile screen so it reads no table itself
 * (D-1149, D-1186).
 *
 * @see docs/gestaltung-und-architektur.md R1
 */
final class MetricSums
{
    /**
     * One query (`CD-7`, MySQL 5.7, no `WITH RECURSIVE`): the records of the node and below it, the filter as `EXISTS`, per
     * record its date and value as the **last** row of a single-valued field (D-1022, 0.1.93), a reference followed one step.
     *
     * @param string      $step  the SQL `DATE_FORMAT` of a step: «%Y-%m» per month, «%Y-%m-%d» per day
     * @param string      $from  first day counted, «YYYY-MM-DD»
     * @param string|null $until first day no longer counted, null for open
     * @return array<string, float> step => count or sum
     */
    public static function perStep(Node $node, ChosenAddon $metric, string $step, string $from, ?string $until): array
    {
        global $wpdb;

        $records = Schema::table('node_records');
        $values  = Schema::table('relation_records');
        $field   = static fn (string $key): int => (int) (($metric->settings[$key] ?? null)?->reference ?? 0);
        $date    = $field('date_field');
        $sumOf   = $field('value_field');
        $filter  = $field('filter_field');
        $sum     = MetricMeasure::tryFrom((string) (($metric->settings['measure'] ?? null)?->text ?? '')) === MetricMeasure::Sum;
        $format  = $step === '%Y-%m-%d' ? '%%Y-%%m-%%d' : '%%Y-%%m';

        $at = $date === 0 ? 'r.created_at' : "COALESCE(
                (SELECT d.value_date FROM {$values} d WHERE d.node_record_id = r.id AND d.relation_id = {$date} AND d.locale = '' AND d.value_date IS NOT NULL ORDER BY d.id DESC LIMIT 1),
                (SELECT MIN(e.value_date) FROM {$values} d JOIN {$values} e ON e.node_record_id = d.value_ref
                  WHERE d.node_record_id = r.id AND d.relation_id = {$date} AND d.value_ref_kind = 'record' AND e.value_date IS NOT NULL))";

        $value = ! $sum || $sumOf === 0 ? '1' : "COALESCE(
                (SELECT COALESCE(s.value_decimal, s.value_int) FROM {$values} s WHERE s.node_record_id = r.id AND s.relation_id = {$sumOf} AND s.locale = ''
                  AND COALESCE(s.value_decimal, s.value_int) IS NOT NULL ORDER BY s.id DESC LIMIT 1),
                (SELECT COALESCE(e.value_decimal, e.value_int) FROM {$values} s JOIN {$values} e ON e.node_record_id = s.value_ref
                  WHERE s.node_record_id = r.id AND s.relation_id = {$sumOf} AND s.value_ref_kind = 'record' AND COALESCE(e.value_decimal, e.value_int) IS NOT NULL
                  ORDER BY s.id DESC, e.position, e.id LIMIT 1),
                0)";

        $where  = 'r.node_id IN (' . Ancestry::descendants([$node->id]) . ") AND r.relation_id = 0 AND r.record_type <> 'settings'";
        $params = [];

        if ($filter !== 0) {
            $wanted  = trim((string) (($metric->settings['filter_value'] ?? null)?->text ?? ''));
            $where  .= " AND EXISTS (SELECT 1 FROM {$values} f WHERE f.node_record_id = r.id AND f.relation_id = {$filter}
                AND (f.value_text = %s OR CAST(f.value_int AS CHAR) = %s OR CAST(f.value_ref AS CHAR) = %s))";
            $params  = [$wanted, $wanted, $wanted];
        }

        $params[] = $from . ' 00:00:00';
        $end      = '';

        if ($until !== null) {
            $params[] = $until . ' 00:00:00';
            $end      = ' AND x.at < %s';
        }

        $sql = "SELECT DATE_FORMAT(x.at, '{$format}') AS step, SUM(x.v) AS total FROM (
                SELECT {$at} AS at, {$value} AS v FROM {$records} r WHERE {$where}
            ) x WHERE x.at >= %s{$end} GROUP BY step";

        $out = [];

        foreach (Query::rows('Kennzahl je Zeitraum zaehlen', $wpdb->prepare($sql, ...$params)) as $row) {
            $out[(string) $row['step']] = (float) $row['total'];
        }

        return $out;
    }
}
