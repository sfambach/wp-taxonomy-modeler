<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Exception\ConcurrentChange;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Repository\RelationRepository;

/** Relations in an array, ordered the way the SQL one orders them. */
final class InMemoryRelations implements RelationRepository
{
    /** @var array<int,Relation> */
    private array $rows = [];

    /**
     * Der Schatten — geparkte Kanten stehen hier und **nicht** mehr bei den lebenden.
     *
     * ⚠️ *Dieselbe Form wie in der Datenbank seit [D-619](../../../docs/NewConcept/90-decision-log.md):
     * geparkt ist kein Merkmal einer lebenden Zeile, sondern ein anderer Ort. **Ein Doppel wäre die
     * Sorte Fälschung, die eine Zusage grün hält, die in Wahrheit rot ist.***
     *
     * @var array<int,Relation>
     */
    private array $geparkt = [];

    /** ⚠️ *Eigener Id-Raum, genau wie {@see InMemoryNodes::add()} — `0` heisst «vergib eine».* */
    public function add(Relation $relation): Relation
    {
        if ($relation->id === 0) {
            $relation = $relation->withAssignedId($this->rows === [] ? 1 : max(array_keys($this->rows)) + 1);
        }

        $this->rows[$relation->id] = $relation;

        return $relation;
    }

    public function save(Relation $relation, int $expectedVersion): void
    {
        $current = $this->rows[$relation->id] ?? null;

        if ($current !== null && $current->version !== $expectedVersion) {
            throw ConcurrentChange::on($relation->id, $expectedVersion, $current->version);
        }

        $this->rows[$relation->id] = $relation;
    }

    public function byId(int $relationId): ?Relation
    {
        foreach ($this->rows as $relation) {
            if ($relation->id === $relationId) {
                return $relation;
            }
        }

        return null;
    }

    // ⚠️ *Die fünf Baumleser sind mit TASK-018 gefallen* ([D-581](../../../docs/NewConcept/90-decision-log.md))
    // *— ihre Ablösung steht in {@see InMemoryNodes}.*

    public function nextFieldPositionUnder(int $ownerId): int
    {
        $relations = $this->fieldRelationsOf([$ownerId]);

        return $relations === [] ? 0 : end($relations)->sortOrder + 1;
    }

    public function fieldRelationsOf(array $ownerIds): array
    {
        // Parked ones are left out here, as in the real repository: a parked attribute is hidden by
        // default in its owning node (D-128).
        return $this->fieldsOf($ownerIds, false);
    }

    public function parkedFieldRelationsOf(array $ownerIds): array
    {
        $relations = [];

        foreach ($this->geparkt as $relation) {
            if (in_array($relation->fromNodeId, $ownerIds, true)) {
                $relations[] = $relation;
            }
        }

        usort($relations, static fn (Relation $a, Relation $b): int => [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id]);

        return $relations;
    }

    public function park(int $relationId, int $changeGroupId): void
    {
        $relation = $this->rows[$relationId] ?? null;

        if ($relation === null) {
            return;
        }

        $this->geparkt[$relationId] = $relation->parkedBy($changeGroupId);

        unset($this->rows[$relationId]);
    }

    public function unpark(int $relationId): ?Relation
    {
        $relation = $this->geparkt[$relationId] ?? null;

        if ($relation === null) {
            return $this->rows[$relationId] ?? null;
        }

        $revived = $relation->revived();

        $this->rows[$relationId] = $revived;

        unset($this->geparkt[$relationId]);

        return $revived;
    }

    public function fieldRelationsTo(array $targetIds): array
    {
        $relations = [];

        foreach ($this->rows as $relation) {
            if (! in_array($relation->toNodeId, $targetIds, true)) {
                continue;
            }

            if (! $relation->isParked()) {
                $relations[] = $relation;
            }
        }

        usort($relations, static fn (Relation $a, Relation $b): int => [$a->fromNodeId, $a->sortOrder, $a->id] <=> [$b->fromNodeId, $b->sortOrder, $b->id]);

        return $relations;
    }

    /**
     * @param  list<int>      $ownerIds
     * @return list<Relation>
     */
    private function fieldsOf(array $ownerIds, bool $parked = false): array
    {
        $relations = [];

        foreach ($this->rows as $relation) {
            if (! in_array($relation->fromNodeId, $ownerIds, true)) {
                continue;
            }

            if ($relation->isParked() === $parked) {
                $relations[] = $relation;
            }
        }

        usort($relations, static fn (Relation $a, Relation $b): int => [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id]);

        return $relations;
    }

    public function purgeRelationsTouching(int $nodeId): void
    {
        foreach ($this->rows as $id => $relation) {
            if ($relation->fromNodeId === $nodeId || $relation->toNodeId === $nodeId) {
                unset($this->rows[$id]);
            }
        }
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function relationsTouching(array $nodeIds): array
    {
        $found = [];

        foreach ($this->rows as $relation) {
            if (in_array($relation->fromNodeId, $nodeIds, true) || in_array($relation->toNodeId, $nodeIds, true)) {
                $found[] = $relation;
            }
        }

        return $found;
    }

    /** @var array<int,int> Kanten-Id => Satz-Id */
    private array $settingsRecords = [];

    public function settingsRecordIdsOfRelations(array $relationIds): array
    {
        $aus = [];

        foreach ($relationIds as $id) {
            if (isset($this->rows[(int) $id])) {
                $aus[(int) $id] = [
                    'own'    => $this->settingsRecords[(int) $id] ?? 0,
                    'target' => 0,
                ];
            }
        }

        return $aus;
    }

    public function rememberSettingsRecord(int $relationId, int $recordId): void
    {
        if ($recordId === 0) {
            unset($this->settingsRecords[$relationId]);

            return;
        }

        $this->settingsRecords[$relationId] = $recordId;
    }
}
