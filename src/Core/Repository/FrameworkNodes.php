<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

use Taxmod\Core\Model\Node;

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
}
