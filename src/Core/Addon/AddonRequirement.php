<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

/**
 * «Das bedingt, dass …» — ein Feld, das eine Zusatzfunktion an **anderen** Knoten verlangt ([D-845](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Sein Beispiel: «an den konstanten sagen ist filter oder ist sort smd filter, tht filter, almost .. sort». Das Feld erscheint nur an
 * den Knoten, auf die das eigene Feld `$atValuesOf` einer gewählten Funktion zeigen kann — also am Ziel dieses Feldes und darunter —,
 * und nirgends sonst.*
 */
final class AddonRequirement
{
    public function __construct(
        /** Der Name des verlangten Feldes, etwa `preset_behaviour`. */
        public readonly string $attribut,
        /** @var class-string<\BackedEnum> Die Werte, die es annehmen kann. */
        public readonly string $enumClass,
        /** Das eigene Feld der Funktion, dessen Ziel (und alles darunter) das verlangte Feld trägt. */
        public readonly string $atValuesOf,
    ) {
    }
}
