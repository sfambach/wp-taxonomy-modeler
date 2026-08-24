<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

use Taxmod\Core\Model\Node;

/**
 * Storage for nodes, stated as the core needs it rather than as a database offers it.
 *
 * ⚠️ **`moveSubtree` exists so that `CD-7` can be kept.** Reparenting rewrites the `path` of
 * every descendant; done node by node that is N+1, and the concept forbids it. The interface
 * therefore asks for the whole subtree in one call and lets the boundary do it in one statement.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
interface NodeRepository
{
    /** @throws \Taxmod\Core\Exception\NodeNotFound */
    public function byId(int $id): Node;

    public function find(int $id): ?Node;

    /**
     * Several nodes at once, so a caller with a list does not query in a loop (`CD-7`).
     *
     * @param  list<int>        $ids
     * @return array<int, Node> Keyed by id. Ids that no longer exist are simply absent.
     */
    public function byIds(array $ids): array;

    public function add(Node $node): void;

    /**
     * @param int $expectedVersion The version the caller read. Guards against a concurrent
     *                             change (P4c).
     *
     * @throws \Taxmod\Core\Exception\ConcurrentChange
     * @throws \Taxmod\Core\Exception\NodeNotFound
     */
    public function save(Node $node, int $expectedVersion): void;

    /**
     * Direct children, in `position` order once relations carry one.
     *
     * @return list<Node>
     */
    public function childrenOf(Node $parent): array;

    /**
     * Rewrite the paths of everything below a node that has just moved, in one statement.
     *
     * @param string $oldPath The subtree's path before the move.
     * @param string $newPath The same subtree's path after it.
     */
    public function moveSubtree(string $oldPath, string $newPath): void;

    /**
     * Everything below a node, in one statement, at any depth.
     *
     * ⚠️ **This is what the materialised path is for.** Reading a tree level by level is the
     * recursive query per level `CD-7` forbids outright; one `LIKE` on the path answers it
     * whole, and the caller assembles the shape in memory.
     *
     * @return list<Node>
     */
    public function subtreeOf(Node $root): array;

    /** Remove a node and everything under it, for good. The irreversible half of D-123. */
    public function purgeSubtree(Node $node): void;
}
