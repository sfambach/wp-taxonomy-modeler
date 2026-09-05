<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Exception\ConcurrentChange;
use Taxmod\Core\Exception\NodeNotFound;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\FieldType;
use Taxmod\Core\Repository\NodeRepository;

/**
 * Nodes in an array, behaving the way the SQL one does.
 *
 * ⚠️ **A fake, not a mock.** It really stores and really moves subtrees, so a test can assert
 * an outcome rather than that a method was called — and the day the two diverge, the boundary
 * check against a real database is what catches it.
 */
final class InMemoryNodes implements NodeRepository
{
    /** @var array<int,Node> */
    private array $rows = [];

    /**
     * ⚠️ *Der Kantenspeicher wird für den Baum nicht mehr gebraucht (TASK-018,
     * [D-581](../../../docs/NewConcept/90-decision-log.md)) — **die Einordnung steht am Knoten**. Der
     * Parameter bleibt, weil ihn die Tests der Reihe nach übergeben.*
     */
    public function __construct(private readonly ?InMemoryRelations $relations = null)
    {
    }

    public function byId(int $id): Node
    {
        return $this->find($id) ?? throw NodeNotFound::withId($id);
    }

    public function find(int $id): ?Node
    {
        return $this->rows[$id] ?? null;
    }

    public function byIds(array $ids): array
    {
        $found = [];

        foreach ($ids as $id) {
            if (isset($this->rows[$id])) {
                $found[$id] = $this->rows[$id];
            }
        }

        return $found;
    }

    /**
     * ⚠️ *Wie die SQL-Fassung seit TASK-004: **Id `0` heisst «vergib eine»**, und der eigene
     * Id-Raum dieser Tabelle ist hier schlicht das höchste, was schon dasteht, plus eins.*
     */
    public function add(Node $node): Node
    {
        if ($node->id === 0) {
            $node = $node->withAssignedId($this->rows === [] ? 1 : max(array_keys($this->rows)) + 1);
        }

        $this->rows[$node->id] = $node;

        return $node;
    }

    public function save(Node $node, int $expectedVersion): void
    {
        $current = $this->find($node->id) ?? throw NodeNotFound::withId($node->id);

        if ($current->version !== $expectedVersion) {
            throw ConcurrentChange::on($node->id, $expectedVersion, $current->version);
        }

        $this->rows[$node->id] = $node;
    }

    /**
     * @param  list<int>              $parentIds
     * @return array<int, list<Node>>
     */
    public function visibleChildrenOf(array $parentIds): array
    {
        $kinder = [];

        foreach ($parentIds as $id) {
            $kinder[$id] = [];

            foreach ($this->kinderVon((int) $id) as $child) {
                if (! $child->hide) {
                    $kinder[$id][] = $child;
                }
            }
        }

        return $kinder;
    }

    public function childrenOf(Node $parent): array
    {
        return $this->kinderVon($parent->id);
    }

    /**
     * Die Kinder eines Knotens, in ihrer Reihenfolge — wie die SQL-Fassung sie liest.
     *
     * ⚠️ *`parent_node_id` und **nicht** der Pfad: der Pfad ist abgeleitet
     * ([D-014](../../../docs/NewConcept/90-decision-log.md)), und ein Fake, der die abgeleitete
     * Angabe befragt, hielte einen Fehler in der führenden grün.*
     *
     * @return list<Node>
     */
    private function kinderVon(int $parentId): array
    {
        $children = [];

        foreach ($this->rows as $node) {
            if ($node->parentNodeId === $parentId) {
                $children[] = $node;
            }
        }

        usort($children, static fn (Node $a, Node $b): int => [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id]);

        return $children;
    }

    public function nextPositionUnder(int $parentId): int
    {
        $kinder = $this->kinderVon($parentId);

        return $kinder === [] ? 0 : end($kinder)->sortOrder + 1;
    }

    public function reparentChildren(int $fromParentId, int $toParentId, int $startPosition): void
    {
        foreach ($this->kinderVon($fromParentId) as $child) {
            $this->rows[$child->id] = $child->movedUnder(
                $this->find($toParentId)?->path,
                $toParentId,
                $child->sortOrder + $startPosition
            );
        }
    }

    public function allPlacements(): array
    {
        $zeilen = $this->rows;

        uasort($zeilen, static fn (Node $a, Node $b): int =>
            [$a->parentNodeId ?? 0, $a->sortOrder, $a->id] <=> [$b->parentNodeId ?? 0, $b->sortOrder, $b->id]);

        $aus = [];

        foreach ($zeilen as $node) {
            $aus[$node->id] = [
                'parent'    => $node->parentNodeId,
                'sortOrder' => $node->sortOrder,
                'hide'      => $node->hide,
            ];
        }

        return $aus;
    }

    public function subtreeOf(Node $root): array
    {
        $under = [];

        foreach ($this->rows as $node) {
            if (str_starts_with($node->path, $root->path . '.')) {
                $under[] = $node;
            }
        }

        usort($under, static fn (Node $a, Node $b): int => strcmp($a->path, $b->path));

        return $under;
    }

    public function moveSubtree(string $oldPath, string $newPath): void
    {
        foreach ($this->rows as $id => $node) {
            if (str_starts_with($node->path, $oldPath . '.')) {
                // The counter rides along, exactly as the one UPDATE does (D-349).
                $this->rows[$id] = Node::fromStorage(
                    $node->id,
                    $node->version + 1,
                    $node->name,
                    $newPath . substr($node->path, strlen($oldPath)),
                    $node->implementedBy,
                    $node->parentNodeId,
                    $node->sortOrder,
                    $node->hide
                );
            }
        }
    }

    public function purgeSubtree(Node $node): void
    {
        foreach ($this->rows as $id => $row) {
            if ($row->id === $node->id || str_starts_with($row->path, $node->path . '.')) {
                unset($this->rows[$id]);
            }
        }
    }

    /** Dieselbe Antwort aus den Kanten wie am Rand ([D-621](../../../docs/NewConcept/90-decision-log.md)). */
    public function ownFieldTypes(array $ids): array
    {
        $sorten = [];

        foreach ($ids as $id) {
            $id      = (int) $id;
            $treffer = null;

            foreach ($this->relations?->fieldRelationsTo([$id]) ?? [] as $relation) {
                $treffer = $treffer === null || $treffer === FieldType::Setting
                    ? ($relation->isSetting() ? FieldType::Setting : FieldType::Model)
                    : FieldType::Model;
            }

            $sorten[$id] = $treffer;
        }

        return $sorten;
    }

    /** Derselbe Lauf, ohne Abfragen — der Pfad steht im Knoten. */
    public function resolvedFieldTypes(array $ids): array
    {
        $aufgeloest = [];

        foreach ($ids as $id) {
            $node = $this->rows[(int) $id] ?? null;
            $sorte = FieldType::standard();

            if ($node !== null) {
                $entlang = array_reverse(explode('.', $node->path));
                $eigene  = $this->ownFieldTypes(array_map(intval(...), $entlang));

                foreach ($entlang as $stufe) {
                    if (($eigene[(int) $stufe] ?? null) !== null) {
                        $sorte = $eigene[(int) $stufe];

                        break;
                    }
                }
            }

            $aufgeloest[(int) $id] = $sorte;
        }

        return $aufgeloest;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function byImplementations(array $classNames): array
    {
        $gesucht = array_flip($classNames);
        $aus     = [];
        $zeilen  = $this->rows;

        // ⚠️ *Dieselbe Zusage wie am Rand: die kleinste Id gewinnt.*
        ksort($zeilen);

        foreach ($zeilen as $node) {
            if ($node->implementedBy !== null && isset($gesucht[$node->implementedBy])) {
                $aus[$node->implementedBy] ??= $node;
            }
        }

        return $aus;
    }
}
