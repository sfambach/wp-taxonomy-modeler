<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Ein Verweis auf einen Knoten im Modell.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class NodeRefType extends SpecialisedType
{
    public function type(): SimpleType
    {
        return SimpleType::NodeRef;
    }

    public function nodeName(): string
    {
        return 'Node reference';
    }

    public function humanName(): string
    {
        return 'node reference';
    }

    public function column(): string
    {
        return 'value_ref';
    }

    public function valueFrom(string $characters): TypedValue
    {
        if (preg_match('/^\d+$/', $characters) !== 1) {
            throw $this->refuse($characters);
        }

        return TypedValue::ofReference((int) $characters);
    }
}
