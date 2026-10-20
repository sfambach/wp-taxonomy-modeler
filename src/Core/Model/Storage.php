<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * Where a value given through an relation is kept — read off the edge and its target, never chosen per value.
 *
 * ⚠️ **The edge decides whether a value is a part of its own, not the target's branch**
 * ([D-938](../../../docs/NewConcept/90-decision-log.md)). *His word: «ja bitte die kante entscheidet».
 * Until then `Branch::storage()` answered, so a composition was only a part when its target lay under
 * `Compositions` — a place deciding what [D-890](../../../docs/NewConcept/90-decision-log.md) had already
 * called «nur noch aus historischen gründen».*
 *
 * ⚠️ **Multiplicity plays no part in this** (D-232). Five integers are five **paths** inside one
 * record, not five records; the branch decides the *kind* of place, and how many there are of
 * them is a different question entirely.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
enum Storage: string
{
    /** The target has records of its own and this is a reference to one — an aggregation. */
    case ExternalReference = 'external-reference';

    /** The target's records belong to the holder and die with it — a composition. */
    case OwnRecords = 'own-records';

    /** No instances: the value sits in the holder's record, addressed by path — `Data Types`. */
    case InsideTheRecord = 'inside-the-record';

    /** A fixed value a person may extend, so the value is a reference to a node — a constant or a choice. */
    case NodeRef = 'node-ref';

    /**
     * ⚠️ *A simple type stays inside the record and a choice stays a node reference whatever the edge
     * says — those two are what the target **is** ([D-540](../../../docs/NewConcept/90-decision-log.md),
     * [D-790](../../../docs/NewConcept/90-decision-log.md)). Everything else is the edge's answer.*
     */
    public static function forField(RelationKind $kind, ?Branch $targetBranch, bool $targetIsChoice): self
    {
        if ($targetBranch === Branch::DataTypes) {
            return self::InsideTheRecord;
        }

        if ($targetBranch === Branch::Constants || $targetIsChoice) {
            return self::NodeRef;
        }

        return $kind === RelationKind::Composition ? self::OwnRecords : self::ExternalReference;
    }
}
