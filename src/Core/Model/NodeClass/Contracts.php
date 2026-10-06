<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

use Taxmod\Core\Exception\UnknownNodeClass;
use Taxmod\Core\Model\Type\SpecialisedTypes;

/**
 * **Das Inventar der Knotenklassen, und die Verträge dazu — je Klasse genau einer.**
 *
 * ⚠️ **Von Hand aufgezählt, wie {@see SpecialisedTypes::CLASSES}**: *eine Liste, die sich aus dem
 * Ordner füllt, kann nicht falsch sein — und sagt darum nichts. Das Inventar ist eine Zusage, die
 * `klasse-check` gegen die Datenbank hält: jede Zeile nennt eine Klasse, die hier steht.*
 *
 * ⚠️ **Der Vertrag wird einmal je Klasse gebaut und gehalten** (Anforderung 2.4.1). *Gehalten heisst
 * heute: je Anfrage im Speicher. Die Bindung an die Plugin-Version ([D-720](../../../../docs/NewConcept/90-decision-log.md))
 * wird nötig, sobald ein Vertrag eine Anfrage überlebt — mit Schritt 4, wenn Reflection die Attribute
 * liest und das Ergebnis in den Objektcache wandert.*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class Contracts
{
    /**
     * Die Knotenklassen ausser den elf Typklassen — die kommen aus {@see SpecialisedTypes::CLASSES}.
     *
     * @var list<class-string<NodeClass>>
     */
    public const CLASSES = [
        Category::class,
        Choice::class,
        Constant::class,
        Unit::class,
        UnitValue::class,
    ];

    /** @var array<class-string<NodeClass>, Contract> */
    private static array $held = [];

    /** @return list<class-string<NodeClass>> Alle Knotenklassen, die Typklassen eingeschlossen. */
    public static function all(): array
    {
        return [...self::CLASSES, ...SpecialisedTypes::CLASSES];
    }

    public static function isKnown(string $class): bool
    {
        return in_array($class, self::all(), true);
    }

    /**
     * Der Vertrag dieser Klasse — beim ersten Mal gebaut, danach derselbe.
     *
     * @throws UnknownNodeClass wenn die Klasse nicht im Inventar steht — ein Klassenname in den Daten
     *                          bindet die Zeile an den Code, und diese Frage ist die Gegenprobe.
     */
    public static function of(string $class): Contract
    {
        if (isset(self::$held[$class])) {
            return self::$held[$class];
        }

        if (! self::isKnown($class)) {
            throw UnknownNodeClass::named($class);
        }

        /** @var class-string<NodeClass> $class */
        return self::$held[$class] = Contract::of($class);
    }

    /** @var array<class-string, Contract> Verträge der Wertklassen — Renderer, Konverter, Validatoren, Umrechnung. */
    private static array $heldValues = [];

    /**
     * Der Vertrag einer Wertklasse — beim ersten Mal gelesen, danach derselbe.
     *
     * ⚠️ *Kein Inventar wie bei den Knotenklassen: welche Renderer, Konverter und Validatoren es gibt,
     * sagen ihre Registraturen; hier zählt nur, dass die Klasse existiert.*
     *
     * @param class-string $class
     */
    public static function ofValueClass(string $class): Contract
    {
        if (isset(self::$heldValues[$class])) {
            return self::$heldValues[$class];
        }

        if (! class_exists($class)) {
            throw UnknownNodeClass::named($class);
        }

        return self::$heldValues[$class] = Contract::ofValueClass($class);
    }

    /**
     * Welche Klassen ein Kind unter einem Knoten dieser Klasse haben darf — als Liste, die eine Maske
     * anbieten kann (Anforderung 6.3). Leer im Vertrag heisst alle; hier steht dann das ganze Inventar.
     *
     * @return list<class-string<NodeClass>>
     */
    public static function childClassesUnder(string $parentClass): array
    {
        $contract = self::of($parentClass);

        return $contract->allowedChildClasses === [] ? self::all() : $contract->allowedChildClasses;
    }

    /** Nur für Tests: vergisst die gehaltenen Verträge. */
    /**
     * Der Name einer festen Wertklasse ohne Registratur — der Umrechnungssatz heisst `conversion`, wie
     * seine Klasse, klein geschrieben. *Renderer und Konverter heissen, wie ihre Registratur sie nennt.*
     */
    public static function shortName(string $class): string
    {
        $kurz = strrchr($class, '\\');

        return strtolower($kurz === false ? $class : substr($kurz, 1));
    }

    public static function forget(): void
    {
        self::$held       = [];
        self::$heldValues = [];
    }
}
