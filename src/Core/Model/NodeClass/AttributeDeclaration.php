<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

use Taxmod\Core\Model\TypedValue;

/**
 * **Ein Attribut, wie der Vertrag es gelesen hat** — Name, Typ, Liste, Vorgabe (Anforderung 2.4.4).
 *
 * ⚠️ *Die Adresse ist Klasse + Name (3.3): `$declaredBy` ist die Klasse, die das Attribut erklärt
 * hat — bei einem geerbten Attribut die Oberklasse, nicht die Klasse des Knotens. So heisst
 * `display_size` an jedem Knoten `NodeAttributes.display_size` und nicht dreizehnmal anders.*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class AttributeDeclaration
{
    /**
     * @param class-string             $declaredBy   Die Klasse, die das Attribut erklärt (Adresse, Teil 1).
     * @param class-string|null        $objectClass  Bei `Object` (auch als Listenelement): die Wertklasse
     *                                               oder Oberklasse, die der Wert haben darf (3.1.5).
     * @param class-string|null        $enumClass    Bei `Enum`: die Enum-Klasse; ihre Fälle sind die Wahl (3.1.3).
     * @param class-string<NodeClass>|null $refersTo Bei `NodeRef`: welche Knotenklasse das Ziel haben darf (3.1.4).
     * @param TypedValue|null          $default      Die Vorgabe des Vertrags, oder `null` für «nichts».
     */
    public function __construct(
        public readonly string $declaredBy,
        public readonly string $name,
        public readonly AttributeType $type,
        public readonly bool $list = false,
        public readonly ?string $objectClass = null,
        public readonly ?string $enumClass = null,
        public readonly ?string $refersTo = null,
        public readonly ?TypedValue $default = null,
        public readonly bool $allowDuplicates = true,
    ) {
    }

    /** Die Adresse — Klasse + Name (3.3): `Taxmod\…\IntType.min`. */
    public function address(): string
    {
        return $this->declaredBy . '.' . $this->name;
    }

    /**
     * Die Fälle einer Enum-Wahl, als Text (3.2.2).
     *
     * @return list<string>
     */
    public function enumCases(): array
    {
        if ($this->enumClass === null) {
            return [];
        }

        return array_map(
            static fn (\UnitEnum $case): string => $case instanceof \BackedEnum ? (string) $case->value : $case->name,
            $this->enumClass::cases()
        );
    }
}
