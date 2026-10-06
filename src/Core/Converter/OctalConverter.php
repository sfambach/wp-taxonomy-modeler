<?php declare(strict_types=1);

namespace Taxmod\Core\Converter;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Eine ganze Zahl zur Basis acht geschrieben.
 *
 * ⚠️ **Auf seine Bitte, und [R34](../../../docs/NewConcept/30-renderer.md) hatte sie längst
 * aufgezählt** — *«show a number as binary, hexadecimal, **octal** or in Roman numerals»*
 * ([D-523](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **Die eine Falle dieser Basis ist die führende Null, und sie wird nicht gestellt.** *In C, in
 * PHP und in einem Dateirecht bedeutet `0755` oktal — hier bedeutet es nichts: `0755` und `755`
 * lesen dieselbe Zahl, und geschrieben wird immer `755`. **Eine Null, die die Basis wechselt, wäre
 * genau die zweite Form für einen Wert, die den Rundgang zerbricht.***
 *
 * ⚠️ *Invertierbar wie die anderen beiden Zahlensysteme, also für Anzeige, Eingabe und Suche
 * ([R36](../../../docs/NewConcept/30-renderer.md)).*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class OctalConverter implements Converter
{
    public const NAME = 'octal';

    private const BASE = 8;

    public function name(): string
    {
        return self::NAME;
    }

    public function kind(): ConverterKind
    {
        return ConverterKind::Format;
    }

    public function isInvertible(): bool
    {
        return true;
    }

    public function handles(): array
    {
        return [SimpleType::Int];
    }

    public function shown(TypedValue $value): string
    {
        return PositionalNotation::digits($value->int ?? 0, self::BASE);
    }

    public function written(string $characters, ?SimpleType $type = null): TypedValue
    {
        return PositionalNotation::number($characters, self::BASE, self::NAME);
    }
}
