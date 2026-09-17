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
