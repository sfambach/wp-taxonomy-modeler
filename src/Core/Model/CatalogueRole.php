<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * What a node is to the Feldkatalog: a base type, a data element, a composite data type, or none of these
 * ([D-1553](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Derived from the tree, never stored: where a node hangs and what its relations are says it, so there is no second
 * place that could disagree (CLAUDE.md «Duplicating a fact»).*
 *
 * @see docs/NewConcept/90-decision-log.md D-1553 (Feldkatalog); Herleitung konzept.md §0, §10, §11 (Projektablage)
 */
enum CatalogueRole: string
{
    /** A seeded type (Integer, Text …) or the seeded unit value — the generic form a relation may not choose directly. */
    case BaseType = 'base_type';

    /** A node below a base type or below a composite: it narrows its parent («Integer › Jahr», «Einheitenwert › Länge»). */
    case DataElement = 'data_element';

    /** A node whose own fields are parts held by composition (dimension, address …): only it sets settings for its parts. */
    case Composite = 'composite';

    /** Everything else: an ordinary node of the model. */
    case Outside = 'outside';

    /** Whether a relation may point at a node of this role (D-1553: «a base type is not chosen directly»). */
    public function isChoosableTarget(): bool
    {
        return $this === self::DataElement || $this === self::Composite;
    }
}
