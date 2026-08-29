<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\RecordKind;
use Taxmod\Core\Model\EdgeRecord;
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
    public function add(NodeRecord $record): int
    {
        global $wpdb;

        $wpdb->insert(
            Schema::table('records'),
            [
                'node_id'      => $record->nodeId,
                'node_version' => $record->nodeVersion,
                'created_at'   => $record->createdAt,
                // ⚠️ *Die Spalte ist `NOT NULL` mit Vorgabe, also gibt es hier keine Null-Falle —
                // anders als bei `nodes.kind`, wo `$wpdb->prepare('%s', null)` eine leere
                // Zeichenkette schrieb ([D-519](../../../docs/NewConcept/90-decision-log.md)).*
                'kind'         => $record->kind->value,
            ],
            ['%d', '%d', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    public function find(int $id): ?NodeRecord
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, node_id, node_version, created_at, kind FROM ' . Schema::table('records')
                    . ' WHERE id = %d',
                $id
            ),
            ARRAY_A
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function ofNode(int $nodeId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, node_id, node_version, created_at, kind FROM ' . Schema::table('records') . '
                 WHERE node_id = %d ORDER BY id ASC',
                $nodeId
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
            static fn (array $r): EdgeRecord => new EdgeRecord(
                (int) $r['record_id'],
                (string) $r['path'],
                (int) $r['edge_id'],
                (string) $r['locale'],
                TypedValue::fromStorage(
                    $r['value_int'] === null ? null : (int) $r['value_int'],
                    // ⚠️ The padding `decimal(30,10)` adds on read is taken off here and nowhere else.
                    StoredDecimal::read($r['value_decimal']),
                    $r['value_text'] === null ? null : (string) $r['value_text'],
                    $r['value_date'] === null ? null : (string) $r['value_date'],
                    $r['value_ref'] === null ? null : (int) $r['value_ref'],
                )
            ),
            $rows ?: []
        );
    }

    public function putValue(EdgeRecord $value): void
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
    private function hydrate(array $row): NodeRecord
    {
        return new NodeRecord(
            (int) $row['id'],
            (int) $row['node_id'],
            (int) $row['node_version'],
            (string) $row['created_at'],
            // ⚠️ *`?? null` und nicht `$row['kind']` allein: `hydrate()` bekommt auch Zeilen aus
            // Abfragen, die die Spalte nicht auswaehlen — ein fehlender Schluessel waere eine Warnung
            // und danach stillschweigend die Vorgabe. **So ist es dieselbe Vorgabe, aber ausgesprochen.**
            // *
            RecordKind::fromStorage(isset($row['kind']) ? (string) $row['kind'] : null),
        );
    }

    /**
     * ⚠️ **Die Werte zuerst, die Records danach** — sonst findet die zweite Anweisung nicht mehr,
     * welche Records es waren. *Derselbe Reihenfolgefehler, den `clearTrash()` schon einmal gemacht
     * hat: die Kanten vor den Knoten zu löschen nahm den Weg mit, auf dem die Knoten gefunden wurden,
     * und 53 blieben liegen.*
     *
     * ⚠️ *`$wpdb->last_error` wird nach **jeder** Anweisung gelesen: eine kaputte Abfrage antwortet mit
     * einem leeren Ergebnis und sieht aus wie «nichts zu löschen».*
     */
    public function forgetNodes(array $nodeIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_map(intval(...), $nodeIds)));

        if ($ids === []) {
            return ['records' => 0, 'values' => 0];
        }

        $places  = implode(',', array_fill(0, count($ids), '%d'));
        $records = Schema::table('records');
        $values  = Schema::table('record_values');

        $goneValues = (int) $wpdb->query($wpdb->prepare(
            "DELETE v FROM {$values} v
             INNER JOIN {$records} r ON r.id = v.record_id
             WHERE r.node_id IN ({$places})",
            ...$ids
        ));

        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Die Werte der Datensätze liessen sich nicht löschen: ' . $wpdb->last_error);
        }

        $goneRecords = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$records} WHERE node_id IN ({$places})",
            ...$ids
        ));

        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Die Datensätze liessen sich nicht löschen: ' . $wpdb->last_error);
        }

        return ['records' => $goneRecords, 'values' => $goneValues];
    }
}
