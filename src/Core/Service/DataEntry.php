<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Record;
use Taxmod\Core\Model\RecordValue;
use Taxmod\Core\Model\Storage;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\Clock;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RecordRepository;
use Taxmod\Core\Repository\RelationRepository;

/**
 * Entering something against a model, and finding it again.
 *
 * ⚠️ **Where a value goes is not a choice either** (D-232). The attribute's target sits in a
 * branch, the branch says where the value is kept, and the author never picks:
 *
 * ```mermaid
 * flowchart LR
 *   T["the target's branch"] --> D["Data Types → inside the record, by path"]
 *   T --> K["Constants → a reference to a node"]
 *   T --> M["Model → a reference to a record"]
 *   T --> C["Compositions → records of its own"]
 * ```
 *
 * ⚠️ **Multiplicity plays no part in it.** Five integers are five **paths** in one record, not
 * five records (D-232).
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class DataEntry
{
    public function __construct(
        private readonly RecordRepository $records,
        private readonly RelationRepository $relations,
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Start a record against a model node.
     *
     * ⚠️ **Only a branch that has instances can have one** (D-183). A data type has no
     * instances of its own — a `Text` node is not a thing somebody owns three of.
     */
    public function create(int $modelId): Record
    {
        $model  = $this->nodes->byId($modelId);
        $branch = $this->framework->branchOf($model);

        if ($branch === null || ! $branch->holdsData()) {
            throw NotYetStorable::thatBranchHasNoRecords($model->name);
        }

        $record = new Record(
            0,
            $model->id,
            $model->version,
            $this->clock->now()->format('Y-m-d H:i:s')
        );

        $id = $this->records->add($record);

        return new Record($id, $record->modelId, $record->modelVersion, $record->createdAt);
    }

    /**
     * Put a value in at one attribute of the record's model.
     *
     * @param string $locale Only ever non-empty for an attribute declared translatable (D-317).
     */
    public function put(int $recordId, int $edgeId, TypedValue $value, string $locale = ''): void
    {
        $record = $this->records->find($recordId) ?? throw NotYetStorable::noSuchRecord($recordId);
        $edge   = $this->edgeOf($record, $edgeId);
        $target = $this->nodes->byId($edge->toId);
        $branch = $this->framework->branchOf($target);

        if ($branch === null) {
            throw NotYetStorable::thatBranchHasNoRecords($target->name);
        }

        // ⚠️ Refused rather than guessed: a composed part is a record of its own, and nothing
        // here creates one yet. Storing it inline would put the value in the wrong place and
        // look right until somebody tried to share it.
        if ($branch->storage() === Storage::OwnRecords) {
            throw NotYetStorable::compositionsNeedTheirOwnRecords($edge->name);
        }

        $this->records->putValue(RecordValue::direct($recordId, $edgeId, $value, $locale));
    }

    /** Take a value out again, so the attribute is simply unanswered (D-232's three states). */
    public function clear(int $recordId, int $edgeId, string $locale = ''): void
    {
        $this->records->forgetValue($recordId, (string) $edgeId, $locale);
    }

    /** @return list<RecordValue> */
    public function valuesOf(int $recordId): array
    {
        return $this->records->valuesOf($recordId);
    }

    /** @return list<Record> */
    public function recordsOf(int $modelId): array
    {
        return $this->records->ofModel($modelId);
    }

    public function find(int $recordId): ?Record
    {
        return $this->records->find($recordId);
    }

    /**
     * Every record holding this value at this attribute.
     *
     * @return list<Record>
     */
    public function findByValue(int $edgeId, TypedValue $value): array
    {
        $found = [];

        foreach ($this->records->findByEdgeValue($edgeId, $value) as $id) {
            $record = $this->records->find($id);

            if ($record !== null) {
                $found[] = $record;
            }
        }

        return $found;
    }

    /**
     * The attribute must belong to the record's model — its own or an inherited one.
     *
     * ⚠️ **Checked rather than trusted.** An edge id arriving from a form is input, and a value
     * written against an attribute the model does not have is a value nothing will ever read.
     */
    private function edgeOf(Record $record, int $edgeId): \Taxmod\Core\Model\Relation
    {
        $model = $this->nodes->byId($record->modelId);
        $owned = $this->relations->attributeEdgesOf([...$model->ancestorIds(), $model->id]);

        foreach ($owned as $edge) {
            if ($edge->id === $edgeId) {
                return $edge;
            }
        }

        throw NotYetStorable::notAnAttributeOfThisModel($edgeId, $model->name);
    }
}
