<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Ganze Zahlen. Nie Fliesskomma — ein Preis und eine Toleranz sind exakt
 * ([D-057](../../../../docs/NewConcept/90-decision-log.md)).
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class IntType extends SpecialisedType
{
    public function type(): SimpleType
    {
        return SimpleType::Int;
    }

    public function nodeName(): string
    {
        return 'Integer';
    }

    public function humanName(): string
    {
        return 'integer';
    }

    public function column(): string
    {
        return 'value_int';
    }

    public function pattern(): ?string
    {
        return '-?\d+';
    }

    public function inputMode(): ?string
    {
        return 'numeric';
    }

    /** ⚠️ *Die Frage des Eigentümers am 2026-09-05, und hier steht die Antwort: ja.* */
    public function hasBounds(): bool
    {
        return true;
    }

    public function valueFrom(string $characters): TypedValue
    {
        $this->mustMatchItsShape($characters);

        return TypedValue::ofInt((int) $characters);
    }
}
