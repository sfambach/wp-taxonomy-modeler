<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Ein einzelnes Zeichen.
 *
 * ⚠️ Kein `text` der Länge eins: es hat eine Zahl hinter sich und kann als Glyphe, als ASCII, als
 * Unicode oder in einem Zahlensystem gezeigt werden
 * ([D-329](../../../../docs/NewConcept/90-decision-log.md)).
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class CharType extends SpecialisedType
{
    public function type(): SimpleType
    {
        return SimpleType::Char;
    }

    public function nodeName(): string
    {
        return 'Character';
    }

    public function humanName(): string
    {
        return 'character';
    }

    public function column(): string
    {
        return 'value_text';
    }

    /**
     * ⚠️ **In Zeichen gezählt, nicht in Bytes.** *`mb_strlen` ist der Grund, dass `ä` ein `char` ist
     * und nicht zwei — ein `char` hat eine Zahl hinter sich (D-329), und das ist ein Codepunkt.*
     */
    public function valueFrom(string $characters): TypedValue
    {
        if (mb_strlen($characters, 'UTF-8') !== 1) {
            throw $this->refuse($characters);
        }

        return TypedValue::ofText($characters);
    }
}
