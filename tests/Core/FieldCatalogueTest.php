<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\CatalogueRole;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeClass\CompositeType;
use Taxmod\Core\Model\NodeClass\Contracts;
use Taxmod\Core\Service\FieldCatalogue;

/**
 * Which role a node plays in the Feldkatalog — [D-1553](../../docs/NewConcept/90-decision-log.md): «a data element is a node
 * under its type … Composite data types (dimension, unit value, address …) are catalogue entries whose parts point at data
 * elements»; and what creating one first shows ([D-1555](../../docs/NewConcept/90-decision-log.md)).
 *
 * @see docs/NewConcept/90-decision-log.md D-1553 (Feldkatalog); Herleitung konzept.md §0 (Projektablage)
 */
final class FieldCatalogueTest extends TestCase
{
    // 1 Primitives › 2 Data Types › 3 Integer › 4 Jahr › 5 Baujahr; 2 › 6 Einheitenwert › 7 Länge
    // 10 Model › 11 Abmessung (composite) › 12 Platinenmaß; 10 › 13 Bauteile; 3 › 8 Polzahl
    private function node(int $id, string $path, string $name = ''): Node
    {
        return Node::fromStorage($id, 1, $name === '' ? 'n' . $id : $name, $path);
    }

    private function catalogue(): FieldCatalogue
    {
        return new FieldCatalogue([3, 6], [11]);
    }

    #[Test]
    public function a_seeded_type_is_a_base_type(): void
    {
        self::assertSame(CatalogueRole::BaseType, $this->catalogue()->roleOf($this->node(3, '1.2.3')));
        self::assertSame(CatalogueRole::BaseType, $this->catalogue()->roleOf($this->node(6, '1.2.6')));
        self::assertFalse(CatalogueRole::BaseType->isChoosableTarget());
    }

    #[Test]
    public function a_node_below_a_base_type_is_a_data_element_at_any_depth(): void
    {
        self::assertSame(CatalogueRole::DataElement, $this->catalogue()->roleOf($this->node(4, '1.2.3.4')));
        self::assertSame(CatalogueRole::DataElement, $this->catalogue()->roleOf($this->node(5, '1.2.3.4.5')));
        self::assertSame(CatalogueRole::DataElement, $this->catalogue()->roleOf($this->node(7, '1.2.6.7')));
        self::assertTrue(CatalogueRole::DataElement->isChoosableTarget());
    }

    #[Test]
    public function a_node_of_the_composite_class_is_a_composite_and_its_child_narrows_it(): void
    {
        self::assertSame(CatalogueRole::Composite, $this->catalogue()->roleOf($this->node(11, '10.11')));
        self::assertSame(CatalogueRole::DataElement, $this->catalogue()->roleOf($this->node(12, '10.11.12')));
        self::assertTrue(CatalogueRole::Composite->isChoosableTarget());
    }

    #[Test]
    public function other_nodes_and_the_branch_roots_stay_outside(): void
    {
        self::assertSame(CatalogueRole::Outside, $this->catalogue()->roleOf($this->node(13, '10.13')));
        self::assertSame(CatalogueRole::Outside, $this->catalogue()->roleOf($this->node(2, '1.2')));
        self::assertSame([10 => CatalogueRole::Outside], $this->catalogue()->rolesOf([$this->node(10, '10')]));
    }

    #[Test]
    public function the_composite_class_is_in_the_inventory_and_its_children_stay_composite(): void
    {
        self::assertTrue(Contracts::isKnown(CompositeType::class));
        self::assertSame(CompositeType::class, CompositeType::defaultChildClass());
        self::assertSame('composite type', Contracts::of(CompositeType::class)->key);
    }

    #[Test]
    public function creating_below_a_base_type_first_finds_the_similar_elements_of_that_type_only(): void
    {
        $nodes = [
            $this->node(3, '1.2.3', 'Integer'),
            $this->node(4, '1.2.3.4', 'Jahr'),
            $this->node(5, '1.2.3.4.5', 'Baujahr'),
            $this->node(8, '1.2.3.8', 'Polzahl'),
            $this->node(7, '1.2.6.7', 'Jahr'),
        ];
        $similar = $this->catalogue()->similarElements('jahr', $this->node(3, '1.2.3', 'Integer'), $nodes);

        self::assertSame([4, 5], array_map(static fn (Node $n): int => $n->id, $similar));
        self::assertSame([], $this->catalogue()->similarElements('Pinzahl Steckverbinder lang', $this->node(4, '1.2.3.4'), $nodes));
        self::assertSame([], $this->catalogue()->similarElements('Jahr', $this->node(13, '10.13'), $nodes));
        self::assertSame(3, $this->catalogue()->baseTypeOf($this->node(5, '1.2.3.4.5')));
        self::assertNull($this->catalogue()->baseTypeOf($this->node(13, '10.13')));
    }
}
