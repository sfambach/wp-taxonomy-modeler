<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

use Taxmod\Core\Model\Relation;

/**
 * Storage for edges.
 *
 * ⚠️ **The inheritance edges are the tree.** `nodes.path` is a **materialised** ancestor path
 * (D-014) — derived, rebuildable, and never a second truth. What is asked here is the truth;
 * `path` is what makes asking it cheap.
 *
 * ```mermaid
 * flowchart LR
 *   R["relations · kind = inheritance"] -->|derives| P["nodes.path"]
 *   P -->|answers| Q["who is below whom, in one statement"]
 * ```
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
interface RelationRepository
{
    public function add(Relation $relation): void;

    /**
     * @throws \Taxmod\Core\Exception\ConcurrentChange
     */
    public function save(Relation $relation, int $expectedVersion): void;

    /** The inheritance edge that puts this node where it is, or null for the root. */
    public function inheritanceEdgeTo(int $childId): ?Relation;

    /**
     * The inheritance edges under a parent, in `position` order.
     *
     * @return list<Relation>
     */
    public function childEdgesOf(int $parentId): array;

    /** One past the last position among a parent's children — where a new child goes. */
    public function nextPositionUnder(int $parentId): int;

    /**
     * Every inheritance edge in the model.
     *
     * ⚠️ **Deliberately unbounded, because the model is small by design.** This is a modeller:
     * thousands of *records* are unremarkable, but the model itself stays in the hundreds
     * (D-308). Asking per parent instead would be one query per level.
     *
     * @return list<Relation>
     */
    public function allInheritanceEdges(): array;

    /** Remove the edges belonging to a purge. The only place edges are deleted outright. */
    public function purgeEdgesTouching(int $nodeId): void;
}
