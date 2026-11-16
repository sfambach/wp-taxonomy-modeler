<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

/**
 * **Kategorie** — ein Knoten ohne besondere Funktion: er ordnet, und er trägt Felder.
 *
 * ⚠️ **Sein Wort** ([D-716](../../../../docs/NewConcept/90-decision-log.md)): *«alle anderen knoten,
 * die keine spezielle funktion haben, sind Kategorieknoten — sie dienen zur strukturierung und lassen
 * grundsätzlich alle unterklassen zu.»* Und: *«somit vielleicht der standard, bis der benutzer etwas
 * anderes auswählt.»*
 *
 * *Darum ist sie die Vorwahl, wo keine Klasse etwas anderes sagt, und erlaubt alles als Kind
 * (Anforderung 2.2.4).*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class Category implements NodeClass
{
    use NodeAttributes;

    /**
     * Welche Felder diesen Knoten in einer Zusammenfassung ausmachen ([D-753](../../../../docs/NewConcept/90-decision-log.md)) —
     * Vorgabe am Knoten, an der Kante überschreibbar. *Sein Wort: «aber wenn wirs am Knoten haben, können wirs an der
     * Kante so übernehmen».*
     *
     * @var list<int> Kanten-Ids
     */
    #[Attribut(listOf: 'relation')]
    public array $summary_fields = [];

    /**
     * Welche Felder des verwiesenen Satzes eine Tabelle als eigene Spalten neben dem Verweis zeigt ([D-955](../../../../docs/NewConcept/90-decision-log.md)) —
     * gesetzt am Ziel des Verweises, an der Kante überschreibbar. *Sein Wort: «in der bom links zu ihnen» (den Datenblättern des Bauteils).*
     *
     * @var list<int> Kanten-Ids
     */
    #[Attribut(listOf: 'relation')]
    public array $target_columns = [];

    /**
     * Zwei Felder, die im Namen als Raster stehen: das erste die Reihen, das zweite die Gesamtzahl — der Name liest
     * «Reihen×(Gesamt/Reihen)» statt der Gesamtzahl, ohne Reihen «1×Gesamt» ([D-1390](../../../../docs/NewConcept/90-decision-log.md),
     * [D-1327](../../../../docs/NewConcept/90-decision-log.md)). *Sein Wort: «reihen sieht man nicht und zeilen gibts auch nur die polzahl».*
     * Gesetzt am Knoten, geerbt nach unten; der nächste Vorfahr mit einer Angabe gewinnt.
     *
     * @var list<int> Kanten-Ids: [Reihen, Gesamt]
     */
    #[Attribut(listOf: 'relation')]
    public array $raster_felder = [];

    public const RASTER_FELDER = 'raster_felder';

    /**
     * D-1399: fields that a record's name shows as one measure — their numbers joined by « × » and the shared unit once:
     * «6 × 6 × 13 mm» instead of «6 mm · 6 mm · 13 mm». An empty field drops out; parts with different units keep their own.
     * Set at the node, inherited down; the nearest owner naming fields wins.
     *
     * @var list<int> Kanten-Ids in the order they are written, e.g. [Länge, Breite, Höhe]
     */
    #[Attribut(listOf: 'relation')]
    public array $mass_felder = [];

    public const MASS_FELDER = 'mass_felder';

    /**
     * D-1379: at the use site (this node, a field), which branches a choice offers — the nodes named and everything below them; empty: the
     * whole target. Inherited down, the nearest owner naming it wins. *His case: a resistor in «THT» chooses only THT packages.*
     *
     * @var list<int> Knoten
     */
    #[Attribut(listOf: 'node')]
    public array $wahl_unter = [];

    public const WAHL_UNTER = 'wahl_unter';

    /**
     * D-1379: at the use site, the choice that stands chosen while the record holds none — saved with the record, changeable.
     *
     * @var list<int> one node; the first counts
     */
    #[Attribut(listOf: 'node')]
    public array $wahl_vorgabe = [];

    public const WAHL_VORGABE = 'wahl_vorgabe';

    public static function allowedChildClasses(): array
    {
        return [];
    }

    public static function defaultChildClass(): string
    {
        return self::class;
    }

    public static function classIcon(): string
    {
        return 'category';
    }

    public static function classKey(): string
    {
        return 'category';
    }

    public static function allowedMultiplicities(): array
    {
        return [];
    }
}
