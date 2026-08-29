<?php declare(strict_types=1);

namespace Taxmod\Core\Converter;

/**
 * The converters that come in the box.
 *
 * ⚠️ **No default per type, unlike {@see \Taxmod\Core\Renderer\ShippedRenderers}.** *A renderer must
 * always be resolved, because something has to draw the field
 * ([R33c](../../../docs/NewConcept/30-renderer.md#r33c--automatic-is-a-default-never-a-fact)). **No
 * converter is a complete answer**: the value is shown as it is stored. So there is nothing to mark
 * here, and marking one would map values nobody asked to map.*
 *
 * ⚠️ **Vier seit [D-523](../../../docs/NewConcept/90-decision-log.md), und die Reihenfolge ist die
 * von [R34](../../../docs/NewConcept/30-renderer.md):** *«show a number as **binary, hexadecimal,
 * octal or in Roman numerals**».* **Sie ist auch die, in der die Auswahl sie anbietet** —
 * {@see ConverterRegistry::eligibleFor()} antwortet in Anmeldereihenfolge.
 *
 * ⚠️ **Und die eine, die das Konzept am häufigsten nennt, fehlt weiterhin absichtlich.** *`2k7` is the
 * headline example everywhere in the concept — and [D-220](../../../docs/NewConcept/90-decision-log.md)
 * says it lands in **two members of one composed value**, `number` and `prefix`. That needs
 * composed-value rendering, which is S7. Building a scalar `2k7` that wrote one number would look right
 * on screen and be the wrong model, which is worse than not having it.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ShippedConverters
{
    public static function registry(): ConverterRegistry
    {
        $registry = new ConverterRegistry();

        // Alle vier sind invertierbar, also wird von jeder beide Richtungen des Vertrags geübt —
        // *decimal ↔ Roman · decimal ↔ hex* sind [R36](../../../docs/NewConcept/30-renderer.md)s
        // eigene Beispiele, `binary` und `octal` stehen in [R34](../../../docs/NewConcept/30-renderer.md).
        $registry->add(new BinaryConverter());
        $registry->add(new HexadecimalConverter());
        $registry->add(new OctalConverter());
        $registry->add(new RomanNumeralConverter());

        return $registry;
    }
}
