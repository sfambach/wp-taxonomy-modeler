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

    public function byId(int $id): Node
    {
        return $this->find($id) ?? throw NodeNotFound::withId($id);
    }

    public function find(int $id): ?Node
    {
        return $this->rows[$id] ?? null;
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

    public function moveSubtree(string $oldPath, string $newPath): void
    {
        foreach ($this->rows as $id => $node) {
            if (str_starts_with($node->path, $oldPath . '.')) {
                $this->rows[$id] = Node::fromStorage(
                    $node->id,
                    $node->version,
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
