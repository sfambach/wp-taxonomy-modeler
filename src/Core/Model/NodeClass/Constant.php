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

    // ⚠️ *Sein Wort am 2026-09-12: «constant sollte auch display size bekommen» — zu D-724, das es auf die einfachen
    // Typen beschränkt hatte. Eine Konstante wird als Wert gezeichnet, also hat auch sie eine Anzeigebreite.*
    #[Attribut]
    public int $display_size = 20;

    /**
     * Welches Label der Konstante gezeigt wird — eine Rolle. ⚠️ *Sein Wort am 2026-09-12: «bei constant müsste der
     * label typ wählbar sein der angezeigt wird» — «entweder im renderer oder im type, einfacher wäre im typ glaube
     * ich»* ([D-728](../../../../docs/NewConcept/90-decision-log.md)). Der Renderer an der Verwendungsstelle darf
     * es überstimmen; ohne beides gilt die Formularrolle.
     */
    #[Attribut(refersTo: Constant::class, from: Anchor::Roles)]
    public ?int $label_role = null;

    // ⚠️ *Sein Wort am 2026-09-12: «Constant class sollte with label haben und Standard ist aus» (D-747).*
    #[Attribut]
    public bool $with_label = false;

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
