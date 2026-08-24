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
     * ⚠️ **Collapsing is answered here, not on the screen.** Which rows a person can see is a
     * question about the tree, so it is settled once and every surface asks the same way — the
     * provisional admin screen today, the tree renderer later ([R18](../../../docs/NewConcept/30-renderer.md)).
     * Each row says whether it **has** children, because a row with none must not offer a
     * control that would do nothing ([U8](../../../docs/NewConcept/20-interaction.md)).
     *
     * @param list<int> $skip      Ids whose subtrees are left out entirely — the trash, when
     *                             the screen shows it separately.
     * @param list<int> $collapsed Ids that are shown but whose children are not.
     *
     * @return list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}>
     */
    public function rowsUnder(Node $root, array $skip = [], array $collapsed = []): array
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
        $this->collect($root->id, 0, $byId, $childIdsByParent, array_flip($skip), array_flip($collapsed), $rows);

        return $rows;
    }

    /**
     * @param array<int,Node>       $byId
     * @param array<int,list<int>>  $childIdsByParent
     * @param array<int,int>        $skip
     * @param array<int,int>        $collapsed
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     */
    private function collect(
        int $parentId,
        int $depth,
        array $byId,
        array $childIdsByParent,
        array $skip,
        array $collapsed,
        array &$rows,
    ): void {
        $siblings = $this->visibleChildrenOf($parentId, $byId, $childIdsByParent, $skip);
        $last     = count($siblings) - 1;

        foreach ($siblings as $index => $childId) {
            $isCollapsed = isset($collapsed[$childId]);

            $rows[] = [
                'node'  => $byId[$childId],
                'depth' => $depth,
                // A node whose only children are skipped counts as having none — otherwise
                // the screen offers a control that opens an empty branch.
                'hasChildren' => $this->visibleChildrenOf($childId, $byId, $childIdsByParent, $skip) !== [],
                'collapsed'   => $isCollapsed,
                // ⚠️ Whether a node can move up or down is a fact about the tree, not about a
                // screen. Answered here so that no surface has to work it out again — and so
                // that U8 can be kept: a control that cannot act is **absent**, not greyed.
                'isFirst'     => $index === 0,
                'isLast'      => $index === $last,
            ];

            if (! $isCollapsed) {
                $this->collect($childId, $depth + 1, $byId, $childIdsByParent, $skip, $collapsed, $rows);
            }
        }
    }

    /**
     * @param array<int,Node>      $byId
     * @param array<int,list<int>> $childIdsByParent
     * @param array<int,int>       $skip
     *
     * @return list<int>
     */
    private function visibleChildrenOf(int $parentId, array $byId, array $childIdsByParent, array $skip): array
    {
        return array_values(array_filter(
            $childIdsByParent[$parentId] ?? [],
            static fn (int $id): bool => ! isset($skip[$id]) && isset($byId[$id])
        ));
    }
}
