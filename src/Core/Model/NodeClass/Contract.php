<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

/**
 * Der **Vertrag** einer Knotenklasse — was sie erklärt, einmal gelesen und dann gehalten.
 *
 * ⚠️ **Sein Vermerk** ([D-712](../../../../docs/NewConcept/90-decision-log.md), D4): *«per reflektion
 * — das kostet, das überall zu lesen. der knoten sollte einen vertrag haben, der einmal bestimmt wird,
 * und es wird nur der vertrag gelesen.»* Und D4a: *«einmal abgeleitet — manuell kann das nur eine
 * KI 😉».*
 *
 * ⚠️ **Was er heute trägt, ist Schritt 1 des Bauplans:** *erlaubte Kindklassen, Vorwahl, Icon,
 * Schlüssel. Die Attribute (Anforderung 2.4.4: Name, Typ, Liste, Vorgabe) kommen mit Schritt 4 —
 * dann liest {@see Contracts} sie per Reflection aus den Eigenschaften der Klasse.*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class Contract
{
    /**
     * @param class-string<NodeClass>       $class
     * @param list<class-string<NodeClass>> $allowedChildClasses Leer heisst: alle.
     * @param class-string<NodeClass>       $defaultChildClass
     */
    public function __construct(
        public readonly string $class,
        public readonly string $key,
        public readonly string $icon,
        public readonly array $allowedChildClasses,
        public readonly string $defaultChildClass,
        /** @var list<\Taxmod\Core\Model\Multiplicity> Leer heisst: alle vier (Modell 1.2.3). */
        public readonly array $allowedMultiplicities = [],
    ) {
    }

    /** @param class-string<NodeClass> $class */
    public static function of(string $class): self
    {
        return new self(
            $class,
            $class::classKey(),
            $class::classIcon(),
            $class::allowedChildClasses(),
            $class::defaultChildClass(),
            $class::allowedMultiplicities(),
        );
    }

    /** Ob ein Feld auf einen Knoten dieser Klasse diese Multiplizität tragen darf (Modell 1.2.3). */
    public function allowsMultiplicity(\Taxmod\Core\Model\Multiplicity $multiplicity): bool
    {
        return $this->allowedMultiplicities === [] || in_array($multiplicity, $this->allowedMultiplicities, true);
    }

    /** Ob ein Kind dieser Klasse die genannte Klasse tragen darf (Anforderung 2.2.2). */
    public function allowsChild(string $childClass): bool
    {
        return $this->allowedChildClasses === [] || in_array($childClass, $this->allowedChildClasses, true);
    }
}
