<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

use Taxmod\Core\Model\SimpleType;

/**
 * Which node each simple data type was seeded as — **by id, never by name**.
 *
 * ⚠️ **A name is a beschriftung and may change** ([D-510](../../../docs/NewConcept/90-decision-log.md)).
 * *Binding a type to a node name broke and was measured breaking: a check looked for a node called
 * `int` — it is called `Integer` — so it never ran and preserved a contradiction for three days.
 * [D-022](../../../docs/NewConcept/90-decision-log.md) is the reason it can never be safe:* **node
 * names are deliberately not unique.**
 *
 * ⚠️ **The same technique the framework nodes already use** — an id written down at the moment the
 * node is created ({@see FrameworkNodes}), not a second mechanism. A rename breaks nothing there,
 * and now it breaks nothing here either.
 *
 * ```mermaid
 * flowchart LR
 *   A["ask for a type"] --> B["the id the seed wrote down"]
 *   B -->|nothing| C["the node's name · Notnagel"]
 *   C --> D["write the id down"]
 * ```
 *
 * The order is **id first, name as the last resort**, and the last resort writes the id down so it
 * is not needed twice. *Without that fallback an upgrade would be a loss: an installation seeded
 * before this decision has no ids written anywhere.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
interface TypeNodes
{
    /** The node this type was seeded as, or null when nothing has been written down for it. */
    public function nodeId(SimpleType $type): ?int;

    /**
     * The type this node **is**, or null when it is not one of the seeded ones.
     *
     * ⚠️ *Null for a node that merely bears a type's name, and that is the point of the decision
     * rather than a gap in it: a second node called `Integer` is somebody's own thing.*
     */
    public function typeOf(int $nodeId): ?SimpleType;

    /**
     * What the seed writes down when it creates or renames the node.
     *
     * ⚠️ *On the interface and not only on the implementation, because it is the other half of the
     * same fact: the binding is worth nothing if the only thing that can establish it lives at the
     * boundary by accident.*
     */
    public function remember(SimpleType $type, int $nodeId): void;
}
