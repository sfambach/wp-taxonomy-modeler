<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * Which way a setting may move as the chain goes down.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
enum Narrowing
{
    /** A minimum: it may rise, never fall. More is required, never less. */
    case OnlyUp;

    /** A maximum: it may fall, never rise. Less is allowed, never more. */
    case OnlyDown;

    /** ⚠️ A switch that only ever closes — mandatory, hidden, read-only (D-311). */
    case OnceOnAlwaysOn;

    /**
     * ⚠️ **Containment, not arithmetic** — multiplicity's shape (D-351).
     *
     * Two of its four values are incomparable, so *narrower* is a question the value type
     * answers itself ({@see Multiplicity::narrows()}) rather than a direction a number can be
     * tested against.
     */
    case BySubset;

    /** A choice inside the bounds. It may become anything the bounds still allow. */
    case Free;
}
