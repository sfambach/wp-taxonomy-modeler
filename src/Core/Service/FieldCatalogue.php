<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\CatalogueRole;
use Taxmod\Core\Model\Node;

/**
 * Which role a node plays in the Feldkatalog, read from the tree in one pass ([D-1553](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart TD
 *   N["node"] --> B{"a base type?"}
 *   B -- yes --> BT["BaseType"]
 *   B -- no --> A{"below a base type<br>or a composite?"}
 *   A -- yes --> DE["DataElement"]
 *   A -- no --> C{"class «composite data type»?"}
 *   C -- yes --> CO["Composite"]
 *   C -- no --> O["Outside"]
 * ```
 *
 * ⚠️ *A composite is a node of the class {@see \Taxmod\Core\Model\NodeClass\CompositeType}, set on purpose — not every node held
 * by composition (Konzept-Thread 21.11.2026 to PR #147: «Lager-Positionen … zählen nicht, bloß weil sie per Komposition gehalten
 * werden»).*
 *
 * @see docs/NewConcept/90-decision-log.md D-1553 (Feldkatalog); Herleitung konzept.md §0, §10, §11 (Projektablage)
 */
final class FieldCatalogue
{
    /** @var array<int, true> */
    private readonly array $baseTypes;

    /** @var array<int, true> */
    private readonly array $composites;

    /**
     * @param list<int> $baseTypeIds  the seeded type nodes and the seeded unit value
     * @param list<int> $compositeIds the nodes of the class «composite data type»
     */
    public function __construct(array $baseTypeIds, array $compositeIds)
    {
        $this->baseTypes  = array_fill_keys($baseTypeIds, true);
        $this->composites = array_fill_keys($compositeIds, true);
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
