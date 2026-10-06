<?php declare(strict_types=1);

namespace Taxmod\Core\Converter;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Eine ganze Zahl zur Basis zwei geschrieben.
 *
 * ⚠️ **Der Eigentümer hat sie benannt, und das Konzept auch:** *«kannst du im hintergrund bauen
 * converter binary, converter hex, convert oct für int»* — und
 * [R34](../../../docs/NewConcept/30-renderer.md) sagt seit dem 2026-08-22 dasselbe: *«show a number
 * as **binary, hexadecimal, octal or in Roman numerals**»*. **Hier wird nichts entschieden, hier wird
 * eingelöst** ([D-523](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **Invertierbar, also bedient sie Eingabe und Suche mit**
 * ([R36](../../../docs/NewConcept/30-renderer.md)) — *`> 1100` im Suchfeld wird zu `> 12`, bevor die
 * Abfrage die Datenbank berührt.*
 *
 * ⚠️ *Kein `0b` und keine feste Breite. Eine Breite gehört zu einer Maschine, nicht zu einer Zahl —
 * und `00001100` neben `1100` wären zwei Formen für einen Wert.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class BinaryConverter implements Converter
{
    public const NAME = 'binary';

    private const BASE = 2;

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * ⚠️ *Ein Muster, keine Tabelle: nichts wird nachgeschlagen und nichts ist begrenzt — dieselbe
     * der vier Formen aus [R33a](../../../docs/NewConcept/30-renderer.md#r33a--there-are-a-few-kinds-of-converter-parameterised-by-data),
     * die auch die anderen Zahlensysteme haben ([D-148](../../../docs/NewConcept/90-decision-log.md)).*
     */
    public function kind(): ConverterKind
    {
        return ConverterKind::Format;
    }

    public function isInvertible(): bool
    {
        return true;
    }

    /**
     * ⚠️ *Nur ganze Zahlen. Ein `char` hat laut [D-329](../../../docs/NewConcept/90-decision-log.md)
     * ebenfalls eine Zahl hinter sich und liesse sich so zeigen — **danach hat niemand gefragt**, und
     * geraten wird hier nichts (`PR-4`).*
     */
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
