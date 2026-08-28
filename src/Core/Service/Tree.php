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
     * @var array<int,true> Which nodes hang on a hidden inheritance edge — filled by
     *                     {@see self::rowsUnder()} so that a row can carry the fact.
     *
     * ⚠️ *Only meaningful while `showHidden` is on: with it off those rows do not exist. It is a
     * field rather than a parameter because {@see self::collect()} recurses and would have to
     * thread it through every level.*
     */
    private array $hiddenTargets = [];

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
     * @return list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool, hidden: bool}>
     */
    public function rowsUnder(Node $root, array $skip = [], array $collapsed = [], bool $showHidden = false): array
    {
        $byId = [];

        foreach ($this->nodes->subtreeOf($root) as $node) {
            $byId[$node->id] = $node;
        }

        $childIdsByParent = [];
        $hidden           = [];

        foreach ($this->relations->allInheritanceEdges() as $edge) {
            if (! isset($byId[$edge->toId])) {
                continue;
            }

            $childIdsByParent[$edge->fromId][] = $edge->toId;

            if ($edge->hide) {
                $hidden[$edge->toId] = true;
            }
        }

        // ⚠️ **The abort, and it costs nothing because `$skip` already is one**
        // ([D-450](../../../docs/NewConcept/90-decision-log.md), [D-452](../../../docs/NewConcept/90-decision-log.md)).
        // *A skipped id is not listed **and** `collect()` never descends into it, so a hidden placement
        // takes its subtree with it by construction. The owner's words: «it stops before rendering
        // itself and does not look at the children either.»*
        //
        // ⚠️ **`hide` sits on the **inheritance edge**, which is what puts a node in the tree at all**
        // ([D-014](../../../docs/NewConcept/90-decision-log.md), [D-467](../../../docs/NewConcept/90-decision-log.md)).
        // *So the walk already has the answer in the edges it just loaded — no second query, and no
        // filter afterwards. **The path-based filter this replaced lived in the screen** and had to
        // reconstruct from `path` what the walk knew all along.*
        if (! $showHidden) {
            $skip = [...$skip, ...array_keys($hidden)];
        }

        $this->hiddenTargets = $hidden;

        $rows = [];
        $this->collect($root->id, 0, $byId, $childIdsByParent, array_flip($skip), array_flip($collapsed), $rows);

        return $rows;
    }

    /**
     * The folded set a surface starts from when nobody has folded anything yet.
     *
     * ⚠️ **The owner, 2026-08-28: *«wenn ich die Seite neu aufmach, dann sollte Kolleps sein — das
     * bitte die beste Übersicht.»*** So *everything folded* is the starting point, and it is a
     * question about the tree like collapsing itself is — answered here once, not on the screen
     * ([D-345](../../../docs/NewConcept/90-decision-log.md)). *It is a **surface** question and not
     * a loading one, which is the split [D-455](../../../docs/NewConcept/90-decision-log.md) makes
     * explicitly: «his other half — the tree should not start fully expanded — is a change to a
     * surface and not to loading».*
     *
     * ⚠️ **The path to `$reveal` is opened, and that is not a preference but consistency.** *A
     * surface that shows a selected node beside the tree would otherwise show a node the tree next
     * to it does not contain. **The node itself stays folded** — its ancestors are what make it
     * visible, and unfolding it as well would open a branch nobody asked to see.*
     *
     * ⚠️ **One query and no walk.** *Who has children is what the inheritance edges say, and the
     * ancestors are already in the node's own `path` — ids separated by `.`, own id last
     * ([D-014](../../../docs/NewConcept/90-decision-log.md)). So this costs the edges and nothing
     * else; a second descent to find the same answer would be the loop `CD-7` forbids.*
     *
     * ```mermaid
     * flowchart LR
     *   E["inheritance edges"] --> P["every node that has children"]
     *   S["the selected node's path"] --> A["its ancestors"]
     *   P --> M["fold everything"]
     *   A -->|"minus"| M
     * ```
     *
     * @return list<int> Ids to fold — for the whole model, so one answer serves every walk in a
     *                   request, the trash's included.
     */
    public function collapsedByDefault(?Node $reveal = null): array
    {
        $fold = [];

        foreach ($this->relations->allInheritanceEdges() as $edge) {
            $fold[$edge->fromId] = true;
        }

        // ⚠️ *`ancestorIds()` und nicht die Scheibe aus dem `path` von Hand — dieselbe Tatsache, und
        // sie hat schon eine Stelle ({@see \Taxmod\Core\Model\Node::ancestorIds()}). Die Handarbeit
        // stand hier eine Stunde und war die zweite Kopie einer Zerlegung, die genau einmal richtig
        // sein muss.*
        if ($reveal !== null) {
            foreach ($reveal->ancestorIds() as $ancestor) {
                unset($fold[$ancestor]);
            }
        }

        return array_values(array_keys($fold));
    }

    /**
     * @param array<int,Node>       $byId
     * @param array<int,list<int>>  $childIdsByParent
     * @param array<int,int>        $skip
     * @param array<int,int>        $collapsed
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool, hidden: bool}> $rows
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
                // ⚠️ *Only ever true while «show hidden» is on — otherwise the row is not here at all.*
                'hidden'      => isset($this->hiddenTargets[$childId]),
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
