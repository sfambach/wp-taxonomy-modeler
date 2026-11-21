<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\CatalogueRole;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;

/**
 * Which role a node plays in the Feldkatalog, read from the tree in one pass ([D-1553](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart TD
 *   N["node"] --> B{"a base type?"}
 *   B -- yes --> BT["BaseType"]
 *   B -- no --> A{"below a base type<br>or a composite?"}
 *   A -- yes --> DE["DataElement"]
 *   A -- no --> C{"owns fields and is held<br>by composition?"}
 *   C -- yes --> CO["Composite"]
 *   C -- no --> O["Outside"]
 * ```
 *
 * ⚠️ *Composite is derived and not a class of its own: a node whose own fields exist and which some relation holds as a
 * part (composition, D-498) — the edge decides, not the place (D-938). konzept.md §0 U5 spoke of a new class; a class
 * would be a second record of the same fact and `set_class` would drop the settings of the old class (D-733).
 * `INFERRED` (Feldkatalog build), named in the PR.*
 *
 * @see /mnt/project-files/taxmod-felder/konzept.md §0, §10, §11
 */
final class FieldCatalogue
{
    /** @var array<int, true> */
    private readonly array $baseTypes;

    /** @var array<int, true> */
    private readonly array $composites;

    /**
     * @param list<int>      $baseTypeIds the seeded type nodes and the seeded unit value
     * @param list<Relation> $relations   every live field relation (settings left out by the caller or here)
     */
    public function __construct(array $baseTypeIds, array $relations)
    {
        $this->baseTypes = array_fill_keys($baseTypeIds, true);

        $owners = [];
        $parts  = [];

        foreach ($relations as $relation) {
            if ($relation->kind === RelationKind::Setting) {
                continue;
            }

            $owners[$relation->fromNodeId] = true;

            if ($relation->kind === RelationKind::Composition) {
                $parts[$relation->toNodeId] = true;
            }
        }

        $composites = [];

        foreach (array_keys($parts) as $id) {
            if (isset($owners[$id]) && ! isset($this->baseTypes[$id])) {
                $composites[$id] = true;
            }
        }

        $this->composites = $composites;
    }

    public function roleOf(Node $node): CatalogueRole
    {
        if (isset($this->baseTypes[$node->id])) {
            return CatalogueRole::BaseType;
        }

        foreach ($node->ancestorIds() as $ancestor) {
            if (isset($this->baseTypes[$ancestor]) || isset($this->composites[$ancestor])) {
                return CatalogueRole::DataElement;
            }
        }

        return isset($this->composites[$node->id]) ? CatalogueRole::Composite : CatalogueRole::Outside;
    }

    /**
     * The roles of many nodes at once.
     *
     * @param  iterable<Node>           $nodes
     * @return array<int, CatalogueRole> by node id
     */
    public function rolesOf(iterable $nodes): array
    {
        $roles = [];

        foreach ($nodes as $node) {
            $roles[$node->id] = $this->roleOf($node);
        }

        return $roles;
    }
}
