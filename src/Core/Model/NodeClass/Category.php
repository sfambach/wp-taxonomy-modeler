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
     * Die Vorbelegung des Filters, wenn ein Satz dieses Knotens gewählt wird ([D-791](../../../../docs/NewConcept/90-decision-log.md)
     * Schritt 3) — *sein Wort: «smd könnte schon mit übergeben werden, aber … generisch … weil wir das auch noch an anderer stelle
     * brauchen».* Wie `summary_fields`: Vorgabe am Knoten, an der Kante überschreibbar — gesetzt wird sie meist an der Kante, weil
     * die Quelle von dort aus gelesen wird.
     *
     * ⚠️ *Angenommen, nicht von ihm gesagt: das erste aktive Glied gilt; die Quelle ist ein Weg über Felder, der im Satz des Feldes
     * beginnt oder im Satz, der ihn hält (die Stückliste über ihrer Position), und Satzverweisen folgt.*
     *
     * @var list<int> Kanten-Ids — das Feld **am Ziel**, das verglichen wird
     */
    #[Attribut(listOf: 'relation')]
    public array $preset_field = [];

    public const PRESET_FIELD = 'preset_field';

    /** @var list<int> Kanten-Ids — der Weg zum Wert **hier**, Glied für Glied */
    #[Attribut(listOf: 'relation')]
    public array $preset_source = [];

    public const PRESET_SOURCE = 'preset_source';

    #[Attribut]
    public PresetMode $preset_mode = PresetMode::First;

    public const PRESET_MODE = 'preset_mode';

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
