<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SeededRole;
/**
 * The few nodes the machinery stands on — the root, and the node parked things go under.
 *
 * ⚠️ **Framework is protection, not provenance** (D-194). These are not *nodes that came from
 * the seed pack*; they are the handful without which the engine cannot run, and they may not be
 * deleted or moved.
 *
 * The core asks for them by role rather than by id, because an id is meaningless (sentence 2)
 * and hard-coding one would be exactly the special-casing the code standard forbids.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
interface FrameworkNodes
{
    /** The one node with no parent (V4). */
    public function root(): Node;

    /** Where deleted things are parked before they can be purged (C101, D-123). */
    public function trash(): Node;

    public function isProtected(Node $node): bool;

    /**
     * The identity installation-wide settings hang on — the first link of the chain.
     *
     * ⚠️ **An identity, not a node** (OQ-039): it is reserved and **does not appear in the
     * modeller**. That is what lets *a configured default plus a choice in the moment* fall out
     * of the ordinary walk with no second mechanism — it is simply the top of it (D-032).
     *
     * ⚠️ Genuinely WordPress-shaped settings — which admin page, which capability — stay
     * WordPress options at the boundary. They are not model settings and are not in this walk.
     */
    public function installationId(): int;

    /**
     * The node one of the seeded label roles lives in.
     *
     * ⚠️ **Roles are nodes** (D-151), so `labels.role_id` is a real reference: a picker instead
     * of free text, no typo roles pointing nowhere, and a role that can carry properties of its
     * own — the length hints of D-152 are settings **on the role node**.
     */
    public function roleId(SeededRole $role): int;

    /**
     * The node a branch hangs from — `Model`, `Compositions`, `Data Types`, `Constants`.
     *
     * ⚠️ **Asked for by role, never by id.** An id is meaningless (sentence 2), so hard-coding
     * one would be the special-casing the code standard forbids outright.
     */
    public function rootOf(Branch $branch): Node;

    /**
     * Which branch a node sits in, or null for the few that sit outside them all — the root
     * itself, and the trash.
     */
    public function branchOf(Node $node): ?Branch;
}
