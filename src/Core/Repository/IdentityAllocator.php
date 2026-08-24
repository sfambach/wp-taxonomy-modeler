<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

/**
 * Hands out ids from the **model** identity space, shared by nodes and relations (C11).
 *
 * ⚠️ **Why this is an interface and not `AUTO_INCREMENT`.** Nodes and edges draw from *one*
 * space, because a setting or a label hangs on a node **or** on an edge and the model does not
 * say in advance which — so `owner_id` must be one column over both (D-090). Two tables cannot
 * share one `AUTO_INCREMENT`, and MySQL has no sequences.
 *
 * ⚠️ **The concept does not say how this space is allocated** — the seven tables of D-083
 * contain no identity table. The mechanism is therefore an implementation choice at the
 * boundary, deliberately kept behind this interface so it can be replaced without the core
 * noticing. See the assumptions listed for Package 1.
 *
 * Records are **not** in this space (D-164); they have their own and `AUTO_INCREMENT` serves it.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
interface IdentityAllocator
{
    /** The next unused id in the model identity space. Never returns the same value twice. */
    public function next(): int;
}
