<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\CatalogueRole;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Service\FieldCatalogue;

/**
 * Which role a node plays in the Feldkatalog — [D-1553](../../docs/NewConcept/90-decision-log.md): «a data element is a node
 * under its type … Composite data types (dimension, unit value, address …) are catalogue entries whose parts point at data
 * elements».
 *
 * @see /mnt/project-files/taxmod-felder/konzept.md §0
 */
final class FieldCatalogueTest extends TestCase
{
    // 1 Primitives › 2 Data Types › 3 Integer › 4 Jahr › 5 Baujahr; 2 › 6 Einheitenwert › 7 Länge
    // 10 Model › 11 Abmessung (parts) › 12 Platinenmaß; 10 › 13 Bauteile (fields, held by reference)
    private function node(int $id, string $path): Node
    {
        return Node::fromStorage($id, 1, 'n' . $id, $path);
    }

    private function catalogue(): FieldCatalogue
    {
        return new FieldCatalogue([3, 6], [
            Relation::fromStorage(100, 1, 11, 7, 'composition', 'Länge', 0),
            Relation::fromStorage(101, 1, 13, 11, 'composition', 'Abmessung', 0),
            Relation::fromStorage(102, 1, 13, 4, 'composition', 'Jahr', 1),
            Relation::fromStorage(103, 1, 10, 13, 'aggregation', 'Bauteil', 0),
            Relation::fromStorage(104, 1, 6, 3, 'composition', 'Betrag', 0),
        ]);
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
    public function a_node_with_own_fields_held_as_a_part_is_a_composite_and_its_child_narrows_it(): void
    {
        self::assertSame(CatalogueRole::Composite, $this->catalogue()->roleOf($this->node(11, '10.11')));
        self::assertSame(CatalogueRole::DataElement, $this->catalogue()->roleOf($this->node(12, '10.11.12')));
        self::assertTrue(CatalogueRole::Composite->isChoosableTarget());
    }

    #[Test]
    public function a_referenced_node_and_the_branch_roots_stay_outside(): void
    {
        self::assertSame(CatalogueRole::Outside, $this->catalogue()->roleOf($this->node(13, '10.13')));
        self::assertSame(CatalogueRole::Outside, $this->catalogue()->roleOf($this->node(2, '1.2')));
        self::assertSame(CatalogueRole::Outside, $this->catalogue()->roleOf($this->node(10, '10')));
    }

    #[Test]
    public function a_setting_edge_makes_nothing_a_composite(): void
    {
        $catalogue = new FieldCatalogue([], [
            Relation::fromStorage(1, 1, 20, 21, 'setting', 'x', 0),
            Relation::fromStorage(2, 1, 21, 22, 'aggregation', 'y', 0),
        ]);

        self::assertSame(CatalogueRole::Outside, $catalogue->roleOf($this->node(21, '21')));
        self::assertSame([20 => CatalogueRole::Outside, 21 => CatalogueRole::Outside], $catalogue->rolesOf([$this->node(20, '20'), $this->node(21, '21')]));
    }
}
