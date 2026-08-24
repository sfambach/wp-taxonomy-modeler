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

    public function add(Relation $relation): void
    {
        $this->rows[$relation->id] = $relation;
    }

    public function save(Relation $relation, int $expectedVersion): void
    {
        $current = $this->rows[$relation->id] ?? null;

        if ($current !== null && $current->version !== $expectedVersion) {
            throw ConcurrentChange::on($relation->id, $expectedVersion, $current->version);
        }

        $this->rows[$relation->id] = $relation;
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
}
