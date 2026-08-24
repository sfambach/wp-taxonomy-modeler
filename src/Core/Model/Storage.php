<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * Where a value given through an edge is kept — read off the target's branch, never chosen.
 *
 * ⚠️ **Multiplicity plays no part in this** (D-232). Five integers are five **paths** inside one
 * record, not five records; the branch decides the *kind* of place, and how many there are of
 * them is a different question entirely.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
enum Storage: string
{
    /** The target has records of its own and this is a reference to one — `Model`. */
    case ExternalReference = 'external-reference';

    /** The target's records belong to the holder and die with it — `Compositions`. */
    case OwnRecords = 'own-records';

    /** No instances: the value sits in the holder's record, addressed by path — `Data Types`. */
    case InsideTheRecord = 'inside-the-record';

    /** A fixed value a person may extend, so the value is a reference to a node — `Constants`. */
    case NodeReference = 'node-reference';
}
