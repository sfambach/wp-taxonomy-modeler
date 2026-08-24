<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RelationRepository;

/**
 * The tree as something that can be drawn: every node under a root, in order, with its depth.
 *
 * ⚠️ **Two queries for the whole tree, whatever its shape.** The nodes come out in one
 * statement because the path answers *who is below whom*; the edges come out in one because
 * order lives on them. Everything after that is assembled in memory, which is what `CD-7`
 * asks for — the traversal is solved once, here, and every caller uses it.
 *
 * ```mermaid
 * flowchart LR
 *   N["nodes under a path"] --> A["assemble"]
 *   E["inheritance edges"] --> A
 *   A --> R["rows: node + depth, in order"]
 * ```
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class Tree
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly RelationRepository $relations,
    ) {
    }

    /**
     * Everything below a node, depth-first, in the order the edges give.
     *
     * @param list<int> $skip Ids whose subtrees are left out entirely — the trash, when the
     *                        screen shows it separately.
     *
     * @return list<array{node: Node, depth: int}>
     */
    public function rowsUnder(Node $root, array $skip = []): array
    {
        $byId = [];

        foreach ($this->nodes->subtreeOf($root) as $node) {
            $byId[$node->id] = $node;
        }

        $childIdsByParent = [];

        foreach ($this->relations->allInheritanceEdges() as $edge) {
            if (isset($byId[$edge->toId])) {
                $childIdsByParent[$edge->fromId][] = $edge->toId;
            }
        }

        $rows = [];
        $this->collect($root->id, 0, $byId, $childIdsByParent, array_flip($skip), $rows);

        return $rows;
    }

    /**
     * @param array<int,Node>       $byId
     * @param array<int,list<int>>  $childIdsByParent
     * @param array<int,int>        $skip
     * @param list<array{node: Node, depth: int}> $rows
     */
    private function collect(int $parentId, int $depth, array $byId, array $childIdsByParent, array $skip, array &$rows): void
    {
        foreach ($childIdsByParent[$parentId] ?? [] as $childId) {
            if (isset($skip[$childId]) || ! isset($byId[$childId])) {
                continue;
            }

            $rows[] = ['node' => $byId[$childId], 'depth' => $depth];

            $this->collect($childId, $depth + 1, $byId, $childIdsByParent, $skip, $rows);
        }
    }
}
