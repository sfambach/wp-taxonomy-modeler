<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Exception\ConcurrentChange;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Repository\RelationRepository;

/** Edges in an array, ordered the way the SQL one orders them. */
final class InMemoryRelations implements RelationRepository
{
    /** @var array<int,Relation> */
    private array $rows = [];

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

    public function byId(int $edgeId): ?Relation
    {
        foreach ($this->rows as $edge) {
            if ($edge->id === $edgeId) {
                return $edge;
            }
        }

        return null;
    }

    public function inheritanceEdgeTo(int $childId): ?Relation
    {
        foreach ($this->rows as $edge) {
            if ($edge->toId === $childId && $edge->kind === RelationKind::Inheritance) {
                return $edge;
            }
        }

        return null;
    }

    public function childEdgesOf(int $parentId): array
    {
        $edges = [];

        foreach ($this->rows as $edge) {
            if ($edge->fromId === $parentId && $edge->kind === RelationKind::Inheritance) {
                $edges[] = $edge;
            }
        }

        usort($edges, static fn (Relation $a, Relation $b): int => $a->position <=> $b->position ?: $a->id <=> $b->id);

        return $edges;
    }

    public function nextPositionUnder(int $parentId): int
    {
        $edges = $this->childEdgesOf($parentId);

        return $edges === [] ? 0 : end($edges)->position + 1;
    }

    public function allInheritanceEdges(): array
    {
        $edges = [];

        foreach ($this->rows as $edge) {
            if ($edge->kind === RelationKind::Inheritance) {
                $edges[] = $edge;
            }
        }

        usort($edges, static fn (Relation $a, Relation $b): int =>
            [$a->fromId, $a->position, $a->id] <=> [$b->fromId, $b->position, $b->id]);

        return $edges;
    }


    public function reparentChildEdges(int $fromParentId, int $toParentId, int $startPosition): void
    {
        foreach ($this->rows as $id => $edge) {
            if ($edge->fromId === $fromParentId && $edge->kind === RelationKind::Inheritance) {
                $this->rows[$id] = $edge->reparentedTo($toParentId, $edge->position + $startPosition);
            }
        }
    }



    public function nextFieldPositionUnder(int $ownerId): int
    {
        $edges = $this->fieldEdgesOf([$ownerId]);

        return $edges === [] ? 0 : end($edges)->position + 1;
    }

    public function fieldEdgesOf(array $ownerIds): array
    {
        // Parked ones are left out here, as in the real repository: a parked attribute is hidden by
        // default in its owning node (D-128).
        return $this->fieldsOf($ownerIds, false);
    }

    public function parkedFieldEdgesOf(array $ownerIds): array
    {
        return $this->fieldsOf($ownerIds, true);
    }

    public function fieldEdgesTo(array $targetIds): array
    {
        $edges = [];

        foreach ($this->rows as $edge) {
            if ($edge->kind === RelationKind::Inheritance || ! in_array($edge->toId, $targetIds, true)) {
                continue;
            }

            if (! $edge->isParked()) {
                $edges[] = $edge;
            }
        }

        usort($edges, static fn (Relation $a, Relation $b): int => [$a->fromId, $a->position, $a->id] <=> [$b->fromId, $b->position, $b->id]);

        return $edges;
    }

    /**
     * @param  list<int>      $ownerIds
     * @return list<Relation>
     */
    private function fieldsOf(array $ownerIds, bool $parked): array
    {
        $edges = [];

        foreach ($this->rows as $edge) {
            if ($edge->kind === RelationKind::Inheritance || ! in_array($edge->fromId, $ownerIds, true)) {
                continue;
            }

            if ($edge->isParked() === $parked) {
                $edges[] = $edge;
            }
        }

        usort($edges, static fn (Relation $a, Relation $b): int => [$a->position, $a->id] <=> [$b->position, $b->id]);

        return $edges;
    }

    public function purgeEdgesTouching(int $nodeId): void
    {
        foreach ($this->rows as $id => $edge) {
            if ($edge->fromId === $nodeId || $edge->toId === $nodeId) {
                unset($this->rows[$id]);
            }
        }
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function edgesTouching(array $nodeIds): array
    {
        $found = [];

        foreach ($this->rows as $edge) {
            if (in_array($edge->fromId, $nodeIds, true) || in_array($edge->toId, $nodeIds, true)) {
                $found[] = $edge;
            }
        }

        return $found;
    }
}
