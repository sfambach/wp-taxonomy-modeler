<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;

/**
 * Kurze Zeichenketten und lange gleichermassen, Quelltext eingeschlossen
 * ([D-316](../../../../docs/NewConcept/90-decision-log.md)).
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class TextType extends SpecialisedType
{
    public function type(): SimpleType
    {
        return SimpleType::Text;
    }

    public function nodeName(): string
    {
        return 'Text';
    }

    public function column(): string
    {
        return 'value_text';
    }
}
