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

    /**
     * The data elements below the same base type whose name is like the new one — what creating one first shows
     * ([D-1555](../../../docs/NewConcept/90-decision-log.md): «Creating a node or a relation first shows similar data elements … and asks
     * whether to use one»). Same name rule as the MCP `similar-fields` ({@see SimilarFields::nameSimilarity()}).
     *
     * @param  list<Node> $nodes every live node (the tree)
     * @return list<Node> best first
     */
    public function similarElements(string $name, Node $parent, array $nodes): array
    {
        $base = $this->baseTypeOf($parent);

        if ($base === null) {
            return [];
        }

        $wanted = SimilarFields::folded($name);
        $found  = [];

        foreach ($nodes as $node) {
            if ($wanted === '' || $this->baseTypeOf($node) !== $base || isset($this->baseTypes[$node->id])) {
                continue;
            }

            $score = SimilarFields::nameSimilarity($wanted, SimilarFields::folded($node->name));

            if ($score >= SimilarFields::NAME_THRESHOLD) {
                $found[] = [$score, $node];
            }
        }

        usort($found, static fn (array $a, array $b): int => [$b[0], $a[1]->id] <=> [$a[0], $b[1]->id]);

        return array_map(static fn (array $f): Node => $f[1], $found);
    }

    /** The base type a node is or hangs below, or null outside the catalogue's base types. */
    public function baseTypeOf(Node $node): ?int
    {
        if (isset($this->baseTypes[$node->id])) {
            return $node->id;
        }

        foreach ($node->ancestorIds() as $ancestor) {
            if (isset($this->baseTypes[$ancestor])) {
                return $ancestor;
            }
        }

        return null;
    }
}
