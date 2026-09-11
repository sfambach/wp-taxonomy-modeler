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
