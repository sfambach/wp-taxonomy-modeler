<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

/** Die Zusatzfunktionen, die mitkommen ([D-845](../../../docs/NewConcept/90-decision-log.md)). */
final class ShippedAddons
{
    public static function registry(): AddonRegistry
    {
        $registry = new AddonRegistry();
        $registry->add(new PresetAddon());
        $registry->add(new PickRowsAddon());
        $registry->add(new RangeValidator());
        $registry->add(new ShapeValidator());

        return $registry;
    }
}
