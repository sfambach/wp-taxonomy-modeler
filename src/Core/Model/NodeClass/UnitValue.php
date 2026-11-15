<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

/**
 * **Einheitenwert** — Zahl + Einheit + Präfix, der Knoten, der «Basiseinheit» wählt.
 *
 * ⚠️ *K3 auf der Protokollseite, von ihm bestätigt ([D-719](../../../../docs/NewConcept/90-decision-log.md)):
 * «Einheitenwert — eigene Klasse: Zahl + Einheit + Präfix; die Klasse, die ‹Basiseinheit› wählt.»
 * Der Knoten selbst ist gesät ({@see \Taxmod\WordPress\Persistence\UnitScaffold::unitValueId()},
 * [D-510](../../../../docs/NewConcept/90-decision-log.md)); hier bekommt er seine Klasse.*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class UnitValue implements NodeClass
{
    use NodeAttributes;

    /**
     * Welche Einheiten ein Feld dieses Typs anbietet — leer heisst alle; genau eine heisst vorgewählt und nicht änderbar.
     *
     * ⚠️ **Sein Wort** ([D-783](../../../../docs/NewConcept/90-decision-log.md)): *«shrink the available base units for these
     * fields in the settings so that we say only Ohms allowed, and so this is preselected and not changeable … das ist doch
     * das Gleiche wie bei den Präfixes».* *Gesetzt am Feld (der Verwendungsstelle), gelesen, wenn der Teil gezeichnet wird.*
     *
     * @var list<int> Knoten der Klasse Einheit
     */
    #[Attribut(listOf: 'node', refersTo: Unit::class, from: Anchor::Units)]
    public array $erlaubte_einheiten = [];

    public const ERLAUBTE_EINHEITEN = 'erlaubte_einheiten';

    /**
     * Welcher Präfix in der Maske vorgewählt steht, solange der Teil keinen trägt — änderbar, nicht gespeichert, bevor eine Zahl
     * dasteht ([D-1389](../../../../docs/NewConcept/90-decision-log.md)). *Sein Wort: «raster mass sollte default bei präfix milli
     * haben also mm».* Gesetzt am Feld (der Verwendungsstelle), wie {@see self::$erlaubte_einheiten}.
     */
    #[Attribut(refersTo: Constant::class, from: Anchor::Prefixes)]
    public ?int $vorgabe_praefix = null;

    public const VORGABE_PRAEFIX = 'vorgabe_praefix';

    // ⚠️ *Sein Wort zum Displaynamen «20 Zeichen · 4 Zeichen» ([D-1189](../../../../docs/NewConcept/90-decision-log.md)): «4x20 würde mir
    // reichen». Zwei Zahlen sind sonst ein Bereich «4 … 20»; ein Knoten wie «Raster» sagt hier «×».*
    #[Attribut]
    public string $zahlen_verbinder = '';

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
        return 'editor-ol';
    }

    public static function classKey(): string
    {
        return 'unit value';
    }

    public static function allowedMultiplicities(): array
    {
        return [];
    }
}
