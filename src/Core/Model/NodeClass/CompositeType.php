<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

/**
 * **Zusammengesetzter Datentyp** — ein Eintrag des Feldkatalogs, dessen Teile auf Datenelemente zeigen (Abmessung = Länge ·
 * Breite · Höhe), und der einzige Halter, an dessen Relationen Einstellungen für die Teile stehen dürfen
 * ([D-1553](../../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Sein Wort 21.11.2026 07:16: «eine neue Klasse … zusammengesetzter Datentyp … der erlaubt auch Einstellungen dann für die
 * gelinkten Elemente» (konzept.md §6). Ein Kind erbt die Klasse und schränkt nur ein (§11a).*
 *
 * ⚠️ *`summary_fields` und `mass_felder` wie bei {@see Category} und {@see Choice}: wer einen vorhandenen Knoten (Abmessung) auf
 * diese Klasse stellt, verliert sonst beide beim Klassenwechsel (D-733). `INFERRED` (Feldkatalog-Bau).*
 *
 * @see docs/NewConcept/90-decision-log.md D-1553 (Feldkatalog); Herleitung konzept.md §6, §11 (Projektablage)
 */
final class CompositeType implements NodeClass
{
    use NodeAttributes;

    /** @var list<int> Kanten-Ids ([D-753](../../../../docs/NewConcept/90-decision-log.md)) */
    #[Attribut(listOf: 'relation')]
    public array $summary_fields = [];

    /** @var list<int> Kanten-Ids, die der Name als ein Maß schreibt ([D-1399](../../../../docs/NewConcept/90-decision-log.md)) */
    #[Attribut(listOf: 'relation')]
    public array $mass_felder = [];

    public static function allowedChildClasses(): array
    {
        return [self::class];
    }

    public static function defaultChildClass(): string
    {
        return self::class;
    }

    public static function classIcon(): string
    {
        return 'screenoptions';
    }

    public static function classKey(): string
    {
        return 'composite type';
    }

    public static function allowedMultiplicities(): array
    {
        return [];
    }
}
