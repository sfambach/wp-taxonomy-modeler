<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * The three kinds of edge — and the kind is never chosen.
 *
 * It is **read off** the branch the target sits in (sentence 5 of the core on one page), which
 * is why this enum has no factory taking user input: nothing outside the branch rule may decide
 * a kind.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
enum RelationKind: string
{
    /** Parent to child in the tree. The tree is inheritance and only inheritance (D-041). */
    case Inheritance = 'inheritance';

    /** The target belongs to the whole and is deleted with it. */
    case Composition = 'composition';

    /** The target is independent and always another node. */
    case Aggregation = 'aggregation';
}
