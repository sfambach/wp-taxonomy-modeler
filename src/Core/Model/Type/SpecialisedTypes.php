<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;

/**
 * **Das Inventar: wie viele spezialisierte Typen es gibt, steht hier und sonst nirgends.**
 *
 * ⚠️ **Das ist der Grund, aus dem der Eigentümer die Klassen wollte**
 * ([D-484](../../../../docs/NewConcept/90-decision-log.md)): *«ich hätte gerne spezialisierte
 * Klassen, weil dann auch klar ist, wie viele spezialisierte Typen wir haben.»* **Vorher stand die
 * Antwort an drei Stellen und die stimmten nicht überein** — *gemessen am 2026-08-28: die Aufzählung
 * nannte elf, gesät waren elf, und die Registratur band einen Renderer an zehn.* {@see UserRefType}
 * war der elfte, den niemand zeichnet, und **eine Liste an einer Stelle hätte das gezeigt**.
 *
 * ```mermaid
 * flowchart LR
 *   L["diese Liste"] --> E["SimpleType · elf Faelle"]
 *   L --> S["elf gesaete Knoten"]
 *   L --> V["Validatoren lesen ab, was ein Typ kann"]
 * ```
 *
 * **Und der Wächter misst genau das:** `simple-type-check.php` hält Klassenzahl, Aufzählungsfälle und
 * gesäte Typknoten auf **einer** Zahl. *Die Auseinanderentwicklung, die D-484 gemessen hat, kann
 * damit nicht zurückkommen.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class SpecialisedTypes
{
    /**
     * ⚠️ **Von Hand aufgezählt und nicht aus dem Ordner gelesen.** *Eine Liste, die sich selbst aus
     * dem Dateisystem füllt, kann nicht falsch sein — und darum sagt sie auch nichts. Das Inventar
     * soll eine Zusage sein, die jemand liest und die ein Wächter gegen die Welt hält.*
     *
     * @var list<class-string<SpecialisedType>>
     */
    public const CLASSES = [
        IntType::class,
        DecimalType::class,
        TextType::class,
        CharType::class,
        BoolType::class,
        EmailType::class,
        DateTimeType::class,
        ColorType::class,
        VersionType::class,
        PathType::class,
        JumpType::class,
        NodeRefType::class,
        UserRefType::class,
    ];

    /** @var array<string, SpecialisedType>|null Nach {@see SimpleType::$value}, einmal gebaut. */
    private static ?array $byCase = null;

    /** @return list<SpecialisedType> In der Reihenfolge, in der die Typen gesät werden. */
    public static function all(): array
    {
        return array_values(self::byCase());
    }

    /** Die Klasse, die diesen Aufzählungsfall ausmacht. */
    public static function for(SimpleType $type): SpecialisedType
    {
        return self::byCase()[$type->value]
            ?? throw new \LogicException("Kein spezialisierter Typ für «{$type->value}».");
    }

    /**
     * Der Typ, den diese Klasse ausmacht, oder `null`.
     *
     * ⚠️ *Der Rückweg für `nodes.implemented_by` (TASK-008/TASK-009): in der Spalte steht ein
     * Klassenname, und das ist die Frage, welchen Typ er meint.*
     */
    public static function ofClass(string $className): ?SimpleType
    {
        foreach (self::byCase() as $one) {
            if ($one::class === $className) {
                return $one->type();
            }
        }

        return null;
    }

    /** @return list<SimpleType> Die Typen, für die `min` und `max` einen Sinn ergeben. */
    public static function withBounds(): array
    {
        return array_values(array_map(
            static fn (SpecialisedType $one): SimpleType => $one->type(),
            array_filter(self::all(), static fn (SpecialisedType $one): bool => $one->hasBounds())
        ));
    }

    /** @return list<SimpleType> Die Typen, die eine Form versprechen, ohne sie beim Lesen zu erzwingen. */
    public static function withAShape(): array
    {
        return array_values(array_map(
            static fn (SpecialisedType $one): SimpleType => $one->type(),
            array_filter(self::all(), static fn (SpecialisedType $one): bool => $one->wellFormedShape() !== null)
        ));
    }

    /** @return array<string, SpecialisedType> */
    private static function byCase(): array
    {
        if (self::$byCase !== null) {
            return self::$byCase;
        }

        $byCase = [];

        foreach (self::CLASSES as $className) {
            /** @var SpecialisedType $one */
            $one                        = new $className();
            $byCase[$one->type()->value] = $one;
        }

        return self::$byCase = $byCase;
    }
}
