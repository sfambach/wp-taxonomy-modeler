<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

use Taxmod\Core\Model\TypedValue;

/**
 * **Liest die Attribute einer Klasse per Reflection** — genau einmal je Klasse, für den Vertrag
 * (Anforderung 2.4.1, 2.4.5).
 *
 * ```mermaid
 * flowchart LR
 *   K["Klasse · Eigenschaften mit #[Attribut]"] -->|"Reflection, einmal"| A["AttributeDeclaration je Eigenschaft"]
 *   A --> V["Vertrag · gehalten"]
 * ```
 *
 * ⚠️ **Was PHP sagt, wird gelesen; was es nicht sagt, steht in {@see Attribut}.** *Der Typ kommt aus
 * der Eigenschaft — `bool`, `int`, `string`, eine Enum, eine Wertklasse —, das Listenelement, das
 * Verweisziel und «Dezimalzahl» aus der Angabe. Die Vorgabe ist der Vorgabewert der Eigenschaft.*
 *
 * ⚠️ **Die erklärende Klasse eines Trait-Attributs ist der Trait** — sonst hiesse `display_size`
 * an jeder Knotenklasse anders, und die Adresse (3.3) wäre keine. *PHP nennt als `declaringClass`
 * die Klasse, die den Trait benutzt; hier wird nachgesehen, ob ein Trait in der Kette die Eigenschaft
 * mitbringt.*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class AttributeReader
{
    /**
     * @param class-string $class
     * @return array<string, AttributeDeclaration> nach Attributname, in Erklärungsreihenfolge
     */
    public static function read(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $aus        = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $angaben = $property->getAttributes(Attribut::class);

            if ($angaben === []) {
                continue;
            }

            /** @var Attribut $angabe */
            $angabe = $angaben[0]->newInstance();

            $aus[$property->getName()] = self::declare($property, $angabe);
        }

        return $aus;
    }

    private static function declare(\ReflectionProperty $property, Attribut $angabe): AttributeDeclaration
    {
        $typ      = $property->getType();
        $typName  = $typ instanceof \ReflectionNamedType ? $typ->getName() : 'mixed';
        $list     = $typName === 'array';
        $vorgabe  = $property->hasDefaultValue() ? $property->getDefaultValue() : null;

        [$type, $objectClass, $enumClass, $refersTo] = $list
            ? self::elementType($angabe)
            : self::scalarType($typName, $angabe);

        return new AttributeDeclaration(
            self::declaredBy($property),
            $property->getName(),
            $type,
            $list,
            $objectClass,
            $enumClass,
            $refersTo,
            $list ? null : self::defaultOf($vorgabe, $type),
            $angabe->allowDuplicates,
            $angabe->from,
            $angabe->band,
        );
    }

    /**
     * @return array{0: AttributeType, 1: ?string, 2: ?string, 3: ?string}
     */
    private static function scalarType(string $typName, Attribut $angabe): array
    {
        return match (true) {
            $typName === 'bool'   => [AttributeType::Bool, null, null, null],
            $typName === 'int'    => $angabe->refersTo !== null
                ? [AttributeType::NodeRef, null, null, $angabe->refersTo]
                : [AttributeType::Int, null, null, null],
            $typName === 'string' => $angabe->decimal
                ? [AttributeType::Decimal, null, null, null]
                : [AttributeType::Text, null, null, null],
            is_subclass_of($typName, \UnitEnum::class) => [AttributeType::Enum, null, $typName, null],
            class_exists($typName) || interface_exists($typName) => [AttributeType::Object, $typName, null, null],
            default => throw new \LogicException("Eine Eigenschaft vom Typ «{$typName}» ist kein Attribut (Anforderung 3.1.1)."),
        };
    }

    /**
     * @return array{0: AttributeType, 1: ?string, 2: ?string, 3: ?string}
     */
    private static function elementType(Attribut $angabe): array
    {
        $element = $angabe->listOf ?? throw new \LogicException('Eine Liste braucht die Angabe listOf (Anforderung 2.4.5).');

        return match (true) {
            $element === 'node'    => [AttributeType::NodeRef, null, null, $angabe->refersTo],
            $element === 'bool'    => [AttributeType::Bool, null, null, null],
            $element === 'int'     => [AttributeType::Int, null, null, null],
            $element === 'decimal' => [AttributeType::Decimal, null, null, null],
            $element === 'string', $element === 'text' => [AttributeType::Text, null, null, null],
            is_subclass_of($element, \UnitEnum::class) => [AttributeType::Enum, null, $element, null],
            class_exists($element) || interface_exists($element) => [AttributeType::Object, $element, null, null],
            default => throw new \LogicException("«{$element}» ist kein Listenelement, das der Vertrag kennt."),
        };
    }

    private static function defaultOf(mixed $vorgabe, AttributeType $type): ?TypedValue
    {
        if ($vorgabe === null) {
            return null;
        }

        return match ($type) {
            AttributeType::Bool    => TypedValue::ofBool((bool) $vorgabe),
            AttributeType::Int     => TypedValue::ofInt((int) $vorgabe),
            AttributeType::Decimal => TypedValue::ofDecimal((string) $vorgabe),
            AttributeType::Text    => TypedValue::ofText((string) $vorgabe),
            AttributeType::Enum    => TypedValue::ofText($vorgabe instanceof \BackedEnum ? (string) $vorgabe->value : (string) $vorgabe->name),
            AttributeType::NodeRef => TypedValue::ofReference((int) $vorgabe),
            AttributeType::Object  => null,
        };
    }

    /** @return class-string Die Klasse — oder der Trait —, die das Attribut erklärt. */
    private static function declaredBy(\ReflectionProperty $property): string
    {
        $klasse = $property->getDeclaringClass();

        foreach (self::traitsOf($klasse) as $trait) {
            if ($trait->hasProperty($property->getName())) {
                return $trait->getName();
            }
        }

        return $klasse->getName();
    }

    /** @return list<\ReflectionClass<object>> Alle Traits der Klasse, auch die verschachtelten. */
    private static function traitsOf(\ReflectionClass $klasse): array
    {
        $aus = [];

        foreach ($klasse->getTraits() as $trait) {
            $aus[] = $trait;
            $aus   = [...$aus, ...self::traitsOf($trait)];
        }

        return $aus;
    }
}
