<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

use Taxmod\Core\Model\Record;
use Taxmod\Core\Model\RecordValue;
use Taxmod\Core\Model\TypedValue;

/**
 * Storage for the data half.
 *
 * ⚠️ **Its own identity space** (D-164), so `AUTO_INCREMENT` serves it. Model tables run in the
 * hundreds and data tables in the millions; keeping the two apart is also what stops anybody
 * merging them later.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
interface RecordRepository
{
    public function add(Record $record): int;

    public function find(int $id): ?Record;

    /** @return list<Record> Every record entered against one model node. */
    public function ofModel(int $modelId): array;

    /** @return list<RecordValue> Everything one record holds, in one statement. */
    public function valuesOf(int $recordId): array;

    public function putValue(RecordValue $value): void;

    public function forgetValue(int $recordId, string $path, string $locale): void;

    /**
     * Records whose value at one edge equals this one, wherever in the record it sits.
     *
     * ⚠️ **This is what `edge_id` is for** (D-134) — the question *which parts are 4k7* asked
     * once, over an index, rather than by unpacking every record. A range asks the same way with
     * a comparison instead of an equality; the shape is the same and only the operator differs.
     *
     * @return list<int> record ids
     */
    public function findByEdgeValue(int $edgeId, TypedValue $value): array;
}
