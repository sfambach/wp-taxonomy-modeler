<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

/**
 * Eine Zusatzfunktion: ein benanntes Verhalten, das an einem Knoten oder einer Kante gewählt wird und eigene Felder mitbringt
 * ([D-845](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Sein Wort: «relation hat zusatz funktion filter/sort. das bedingt dass …». Jede Funktion erklärt darum drei Dinge selbst: wo sie
 * hängen darf ({@see self::sites()}), was sie an anderen Knoten bedingt ({@see self::requirements()}) und — über die Schnittstelle, die
 * sie zusätzlich trägt — wo sie eingreift: {@see ShapesOffer} beim Anbieten, {@see PicksRows} beim Anlegen von Zeilen,
 * {@see ChecksOnSave} beim Speichern.*
 *
 * *Ihre eigenen Felder sind die öffentlichen Eigenschaften mit `#[Attribut]`, gelesen wie die eines Renderers.*
 *
 * @see docs/NewConcept/90-decision-log.md D-845
 */
interface Addon
{
    /** Der Name in der Registratur — ein Schlüssel, kein Wort (`AR-2`). */
    public function name(): string;

    /** @return list<AddonSite> Wo die Funktion gewählt werden darf. */
    public function sites(): array;

    /** @return list<AddonRequirement> Was sie an anderen Knoten bedingt; leer, wo nichts. */
    public function requirements(): array;
}
