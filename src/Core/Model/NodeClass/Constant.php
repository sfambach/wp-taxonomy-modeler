<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

/**
 * **Konstante** — ein wählbarer Wert unter einer Auswahl: `kilo`, `milli`, eine Rolle.
 *
 * ⚠️ **Sein Wort** ([D-716](../../../../docs/NewConcept/90-decision-log.md)): *«kilo: klasse
 * Konstante mit Umrechnung»* — und die Umrechnung ist ein Einstellungsobjekt an ihr
 * ([D-712](../../../../docs/NewConcept/90-decision-log.md), K2a: *«umrechnungssatz hört sich gut an»*).
 * *Das Attribut `umrechnung` kommt mit Schritt 4 des Bauplans; hier steht erst die Klasse.*
 *
 * ⚠️ *Kinder: keine Vorgabe aus dem Konzept — eine Konstante ist ein Blatt. Erlaubt ist alles
 * (`INFERRED`, wie bei der Kategorie), Vorwahl Kategorie; der Bauplan nennt das unter «was der Plan
 * annimmt».*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class Constant implements NodeClass
{
    use NodeAttributes;

    /** Die Umrechnung des Präfixes — `kilo`: Faktor 1000 (K2a: *«umrechnungssatz hört sich gut an»*). */
    #[Attribut]
    public ?\Taxmod\Core\Model\Setting\Conversion $umrechnung = null;

    public static function allowedChildClasses(): array
    {
        return [];
    }

    public static function defaultChildClass(): string
    {
        return Category::class;
    }

    public static function classIcon(): string
    {
        return 'marker';
    }

    public static function classKey(): string
    {
        return 'constant';
    }

    public static function allowedMultiplicities(): array
    {
        return [];
    }
}
