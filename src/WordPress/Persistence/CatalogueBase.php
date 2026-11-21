<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeClass\CompositeType;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Repository\TypeNodes;
use Taxmod\Core\Service\FieldCatalogue;

/**
 * The Feldkatalog of this installation: the seeded type nodes and the seeded unit value as base types, read once
 * ([D-1553](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *The unit value counts as a base type: it is seeded and generic like Integer, and «Länge» hangs below it as «Jahr»
 * hangs below Integer (konzept.md §10). `INFERRED` (Feldkatalog build), named in the PR.*
 *
 * @see docs/NewConcept/90-decision-log.md D-1553 (Feldkatalog); Herleitung konzept.md §10 (Projektablage)
 */
final class CatalogueBase
{
    /** @return list<int> */
    public static function baseTypeIds(TypeNodes $types): array
    {
        $ids = [];

        foreach (SimpleType::cases() as $type) {
            $id = $types->nodeId($type);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        $unitValue = UnitScaffold::unitValueId();

        if ($unitValue !== null) {
            $ids[] = $unitValue;
        }

        return $ids;
    }

    /** Base types and the nodes of the class «composite data type», each read once. */
    public static function catalogue(TypeNodes $types): FieldCatalogue
    {
        $composites = array_map(static fn (Node $n): int => $n->id, (new WpdbNodeRepository())->ofClass(CompositeType::class));

        return new FieldCatalogue(self::baseTypeIds($types), $composites);
    }
}
