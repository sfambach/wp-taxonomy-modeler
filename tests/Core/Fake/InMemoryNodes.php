<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Exception\ConcurrentChange;
use Taxmod\Core\Exception\NodeNotFound;
use Taxmod\Core\Model\Node;
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

    /** Children are ordered by the edge, so the fake needs to see the edges too. */
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

    public function add(Node $node): void
    {
        $this->rows[$node->id] = $node;
    }

    public function save(Node $node, int $expectedVersion): void
    {
        $current = $this->find($node->id) ?? throw NodeNotFound::withId($node->id);

        if ($current->version !== $expectedVersion) {
            throw ConcurrentChange::on($node->id, $expectedVersion, $current->version);
        }

        $this->rows[$node->id] = $node;
    }

    public function childrenOf(Node $parent): array
    {
        // Order lives on the edge (position), because it is per parent. Without edges — a
        // repository used on its own in a test — name order keeps the result stable.
        if ($this->relations !== null) {
            $children = [];

            foreach ($this->relations->childEdgesOf($parent->id) as $edge) {
                $child = $this->find($edge->toId);

                if ($child !== null) {
                    $children[] = $child;
                }
            }

            return $children;
        }

        $prefix   = $parent->path . '.';
        $children = [];

        foreach ($this->rows as $node) {
            if (str_starts_with($node->path, $prefix) && ! str_contains(substr($node->path, strlen($prefix)), '.')) {
                $children[] = $node;
            }
        }

        usort($children, static fn (Node $a, Node $b): int => strcmp($a->name, $b->name));

        return $children;
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
                    $newPath . substr($node->path, strlen($oldPath))
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

    public function count(): int
    {
        return count($this->rows);
    }
}
