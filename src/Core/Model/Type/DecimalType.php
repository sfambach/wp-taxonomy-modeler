<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Exakte Dezimalzahlen, aus demselben Grund wie {@see IntType}
 * ([D-057](../../../../docs/NewConcept/90-decision-log.md)).
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class DecimalType extends SpecialisedType
{
    // ⚠️ **Dieselben Namen wie am Integer, im eigenen Typ** (Anforderung 3.3.3, sein Wort:
    // *«klassenname + attributname ist dann wieder eindeutig, beispiel int/double min/max»*).
    #[\Taxmod\Core\Model\NodeClass\Attribut(decimal: true)]
    public ?string $min = null;

    #[\Taxmod\Core\Model\NodeClass\Attribut(decimal: true)]
    public ?string $max = null;

    #[\Taxmod\Core\Model\NodeClass\Attribut(decimal: true)]
    public string $step = '1';

    public function type(): SimpleType
    {
        return SimpleType::Decimal;
    }

    public function nodeName(): string
    {
        return 'Decimal';
    }

    public function humanName(): string
    {
        return 'double';
    }

    public function column(): string
    {
        return 'value_decimal';
    }

    public function pattern(): ?string
    {
        return '-?\d+(\.\d+)?';
    }

    public function inputMode(): ?string
    {
        return 'decimal';
    }

    public function hasBounds(): bool
    {
        return true;
    }

    /** Bleibt als die Zeichen, als die er ankam — eine Dezimalzahl wird nie ein Float (D-057). */
    public function valueFrom(string $characters): TypedValue
    {
        $this->mustMatchItsShape($characters);

        return TypedValue::ofDecimal($characters);
    }
}
