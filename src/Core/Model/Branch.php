<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * The branch a node sits in — and with it three answers nobody sets by hand.
 *
 * ⚠️ **The relation kind is never asked.** The author picks a **target**; the kind follows from
 * where that target lives (sentence 5 of the core on one page, D-161). That removes the error
 * the whole storage rule exists to prevent — a supplier accidentally *composed* into an order,
 * so every order breeds its own supplier — **not by validating it afterwards but by never
 * offering it.**
 *
 * ⚠️ **Multiplicity plays no part in storage** (D-232). Five integers are five **paths** in one
 * record, not five records.
 *
 * ```mermaid
 * flowchart TB
 *   R["Root"] --> M["Model"]
 *   R --> C["Compositions"]
 *   R --> P["Primitives"]
 *   P --> DT["Data Types"]
 *   P --> K["Constants"]
 * ```
 *
 * @see docs/NewConcept/10-domain-core.md
 */
enum Branch: string
{
    /** Things that stand on their own — a supplier, a board. Reached by aggregation. */
    case Model = 'model';

    /** Things owned by whatever holds them, deleted with it. Reached by composition. */
    case Compositions = 'compositions';

    /** Integer, text, quantity — no instances of their own; the value lives in the record. */
    case DataTypes = 'data-types';

    /** Fixed values a person may extend — a unit, a colour. The value is a reference to a node. */
    case Constants = 'constants';

    /** Which kind of edge reaches a node in this branch (D-161). */
    public function relationKind(): RelationKind
    {
        return match ($this) {
            self::Model, self::Constants     => RelationKind::Aggregation,
            self::Compositions, self::DataTypes => RelationKind::Composition,
        };
    }

    /** Whether nodes in this branch have records of their own (D-183). */
    public function holdsData(): bool
    {
        return match ($this) {
            self::Model, self::Compositions   => true,
            self::DataTypes, self::Constants  => false,
        };
    }

    /** Where a value given through such an edge is kept (D-232). */
    public function storage(): Storage
    {
        return match ($this) {
            self::Model        => Storage::ExternalReference,
            self::Compositions => Storage::OwnRecords,
            self::DataTypes    => Storage::InsideTheRecord,
            self::Constants    => Storage::NodeReference,
        };
    }
}
