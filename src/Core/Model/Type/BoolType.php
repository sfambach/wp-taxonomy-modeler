<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Wahr oder falsch, als `0` oder `1` in der Zahlenspalte
 * ([D-315](../../../../docs/NewConcept/90-decision-log.md)).
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class BoolType extends SpecialisedType
{
    public function type(): SimpleType
    {
        return SimpleType::Bool;
    }

    public function nodeName(): string
    {
        return 'Boolean';
    }

    public function humanName(): string
    {
        return 'yes or no';
    }

    public function column(): string
    {
        return 'value_int';
    }

    public function valueFrom(string $characters): TypedValue
    {
        return match (strtolower($characters)) {
            '1', 'true', 'on', 'yes'  => TypedValue::ofBool(true),
            '0', 'false', 'off', 'no' => TypedValue::ofBool(false),
            default                   => throw $this->refuse($characters),
        };
    }
}
