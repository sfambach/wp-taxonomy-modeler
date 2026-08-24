<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Record;
use Taxmod\Core\Model\RecordValue;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\RecordRepository;

/**
 * The data half, in its own two tables and its own id space (D-164).
 *
 * ⚠️ **`AUTO_INCREMENT` serves records** precisely because they do **not** share the model's
 * space. The model's had to be a table of its own so that nodes and edges could draw from one
 * number; here there is no such ambiguity, and a hand-built allocator on the hottest write path
 * in the system would be paid for nothing.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class WpdbRecordRepository implements RecordRepository
{
    public function add(Record $record): int
    {
        global $wpdb;

        $wpdb->insert(
            Schema::table('records'),
            [
                'model_id'      => $record->modelId,
                'model_version' => $record->modelVersion,
                'created_at'    => $record->createdAt,
            ],
            ['%d', '%d', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    public function find(int $id): ?Record
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, model_id, model_version, created_at FROM ' . Schema::table('records') . ' WHERE id = %d',
                $id
            ),
            ARRAY_A
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function ofModel(int $modelId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, model_id, model_version, created_at FROM ' . Schema::table('records') . '
                 WHERE model_id = %d ORDER BY id ASC',
                $modelId
            ),
            ARRAY_A
        );

        return array_map($this->hydrate(...), $rows ?: []);
    }

    public function valuesOf(int $recordId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT record_id, path, edge_id, locale, value_int, value_decimal, value_text, value_date, value_ref
                 FROM ' . Schema::table('record_values') . '
                 WHERE record_id = %d ORDER BY path ASC',
                $recordId
            ),
            ARRAY_A
        );

        return array_map(
            static fn (array $r): RecordValue => new RecordValue(
                (int) $r['record_id'],
                (string) $r['path'],
                (int) $r['edge_id'],
                (string) $r['locale'],
                TypedValue::fromStorage(
                    $r['value_int'] === null ? null : (int) $r['value_int'],
                    $r['value_decimal'] === null ? null : (string) $r['value_decimal'],
                    $r['value_text'] === null ? null : (string) $r['value_text'],
                    $r['value_date'] === null ? null : (string) $r['value_date'],
                    $r['value_ref'] === null ? null : (int) $r['value_ref'],
                )
            ),
            $rows ?: []
        );
    }

    public function putValue(RecordValue $value): void
    {
        global $wpdb;

        $wpdb->replace(
            Schema::table('record_values'),
            [
                'record_id'     => $value->recordId,
                'path'          => $value->path,
                'edge_id'       => $value->edgeId,
                'locale'        => $value->locale,
                'value_int'     => $value->value->int,
                'value_decimal' => $value->value->decimal,
                'value_text'    => $value->value->text,
                'value_date'    => $value->value->date,
                'value_ref'     => $value->value->reference,
            ],
            ['%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d']
        );
    }

    public function forgetValue(int $recordId, string $path, string $locale): void
    {
        global $wpdb;

        // ⚠️ The row disappears, and the attribute is **unanswered** — which is a third state
        // beside a value and an explicit nothing, and collapsing it would lose it for good.
        $wpdb->delete(
            Schema::table('record_values'),
            ['record_id' => $recordId, 'path' => $path, 'locale' => $locale],
            ['%d', '%s', '%s']
        );
    }

    public function findByEdgeValue(int $edgeId, TypedValue $value): array
    {
        global $wpdb;

        // ⚠️ This is the query D-134 was designed for: the edge is indexed, so *which parts are
        // 4k7* is one lookup rather than a walk through every record.
        [$column, $bound] = match (true) {
            $value->int !== null       => ['value_int', $value->int],
            $value->decimal !== null   => ['value_decimal', $value->decimal],
            $value->date !== null      => ['value_date', $value->date],
            $value->reference !== null => ['value_ref', $value->reference],
            default                    => ['value_text', (string) $value->text],
        };

        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT record_id FROM ' . Schema::table('record_values') . "
             WHERE edge_id = %d AND {$column} = %s",
            $edgeId,
            $bound
        ));

        return array_map(intval(...), $ids ?: []);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Record
    {
        return new Record(
            (int) $row['id'],
            (int) $row['model_id'],
            (int) $row['model_version'],
            (string) $row['created_at'],
        );
    }
}
