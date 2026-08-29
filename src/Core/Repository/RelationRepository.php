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

    /** One past the last position among a node's **attributes** — where a new one goes. */
    public function nextFieldPositionUnder(int $ownerId): int;

    /**
     * Hang every child of one parent under another, in one statement.
     *
     * ⚠️ **Exists so that [U4](../../../docs/NewConcept/20-interaction.md) is not a loop.**
     * Deleting only a node promotes its children to their grandparent; done one edge at a time
     * that is a write per child, which `CD-7` forbids.
     *
     * @param int $startPosition Where the promoted children are placed among their new
     *                           siblings — their relative order is kept.
     */
    public function reparentChildEdges(int $fromParentId, int $toParentId, int $startPosition): void;

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

    /**
     * The attribute edges owned by any of these nodes — everything that is not inheritance.
     *
     * ⚠️ **Several owners in one call, because attributes are inherited.** A node's attributes
     * are its own plus every ancestor's, and asking per ancestor would be one query per level —
     * the walk `CD-7` forbids. The ancestors come out of the path, so the caller already has
     * the list.
     *
     * @param list<int> $ownerIds
     *
     * @return list<Relation>
     */
    public function fieldEdgesOf(array $ownerIds): array;

    /** Remove the edges belonging to a purge. The only place edges are deleted outright. */
    /**
     * The removed attributes of these owners — D-128's *show deleted*.
     *
     * ⚠️ **A separate question, not a flag on the ordinary one.** A parked attribute is *hidden by
     * default in its owning node* ([D-128](../../../docs/NewConcept/90-decision-log.md)), because a
     * model full of ghost attributes is unreadable — so the live read leaves them out and whoever
     * wants them asks for them.
     *
     * @param  list<int>      $ownerIds
     * @return list<Relation>
     */
    public function parkedFieldEdgesOf(array $ownerIds): array;

    /**
     * The mirror of {@see self::fieldEdgesOf()} — every attribute **pointing at** these nodes.
     *
     * ⚠️ **This is the one direction that appears nowhere else** ([D-199](../../../docs/NewConcept/90-decision-log.md)):
     * *«everything going out of the current node is in the attributes»* — outgoing non-inheritance
     * edges **are** the attributes table, the parent edge is a chip in the head and the children are
     * the tree. **Incoming is what was left, and it had no query.**
     *
     * ⚠️ *Inheritance is excluded here for the same reason it is excluded there: an incoming
     * inheritance edge is a **child**, and the tree already draws every one of them.*
     *
     * ⚠️ **Parked attributes are left out**, as in {@see self::fieldEdgesOf()} — a parked attribute
     * is hidden in its owning node ([D-128](../../../docs/NewConcept/90-decision-log.md)), so listing
     * it as a *use* of this node would show a dependency its own node does not show.
     *
     * @param  list<int>      $targetIds
     * @return list<Relation>
     */
    public function fieldEdgesTo(array $targetIds): array;

    /**
     * Every edge with one end on any of these nodes — **both** ends, and every kind.
     *
     * ⚠️ **Both ends and every kind, because a purge has to reach what hangs off an edge.** An edge is
     * an identity ([D-080](../../../docs/NewConcept/90-decision-log.md)) and may carry settings and
     * labels of its own, so the tidy-up needs its **id** and not only its deletion — *which is why this
     * exists beside {@see self::purgeEdgesTouching()} rather than instead of it.*
     *
     * @param  list<int>      $nodeIds
     * @return list<Relation>
     */
    public function edgesTouching(array $nodeIds): array;

    public function purgeEdgesTouching(int $nodeId): void;
}
