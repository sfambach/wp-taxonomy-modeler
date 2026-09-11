<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

/**
 * **Auswahl** — ein Knoten, dessen Kinder zur Wahl stehen: `Prefixes`, `Base units`, die Rollen.
 *
 * ⚠️ **Sein Wort** ([D-716](../../../../docs/NewConcept/90-decision-log.md)): *«Präfix: klasse
 * Auswahlknoten; kilo: klasse Konstante mit Umrechnung»* — und *«das gleiche bei selection-typen: da
 * ist der default Konstante-class.»*
 *
 * ⚠️ *Erlaubt sind Konstanten, Einheiten und Kategorien: unter `Base units` liegen `With prefix` und
 * `Without prefix` als Kategorien, die nicht wählbar sind, und darunter die Einheiten (K2, K3 auf der
 * Protokollseite). **Was gewählt werden kann, sind die Blätter** — die Kategorie dazwischen ordnet nur.*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class Choice implements NodeClass
{
    use NodeAttributes;

    public static function allowedChildClasses(): array
    {
        return [Constant::class, Unit::class, Category::class];
    }

    public static function defaultChildClass(): string
    {
        return Constant::class;
    }

    public static function classIcon(): string
    {
        return 'list-view';
    }

    public static function classKey(): string
    {
        return 'choice';
    }

    public static function allowedMultiplicities(): array
    {
        return [];
    }
}
