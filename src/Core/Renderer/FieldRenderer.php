<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SimpleType;

/**
 * One line — the *plain field* of [D-018](90-decision-log.md)'s three ways to draw a scalar.
 *
 * ⚠️ **It knows nothing about the number it may be holding**, and that is the point of the
 * decision: field, spinner and slider are **three renderers**, not one with three modes (R15).
 * A field that started reading `range_min` to bound itself would be a spinner without saying so.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class FieldRenderer extends TypedFieldRenderer
{
    public const NAME = 'field';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return [
            SimpleType::Text,
            SimpleType::Char,
            SimpleType::Version,
            SimpleType::Int,
            SimpleType::Decimal,
        ];
    }

    protected function display(RenderContext $context): string
    {
        return $this->createHtmlValueSpan(RenderResult::escape($this->outputValue($context)));
    }

    /**
     * ⚠️ **`type="text"` with a pattern, deliberately — not `type="number"`.** A number input
     * reports an **empty value** for content it cannot parse, and an empty value here means
     * *clear this attribute* — so a stray keystroke could delete a value rather than be refused.
     * The same trap the colour picker has, avoided the same way: never offer a control that can
     * lose a value on the way past.
     *
     * ⚠️ **The pattern is the type's own** ({@see SimpleType::pattern()}), which is what makes the
     * browser and the core check one rule rather than two that drift — and it is what stops a
     * plain integer field from accepting letters it will then refuse
     * ([R28](30-renderer.md#r28r32--the-rule-complete): *a control offers only real choices*).
     * **When converters arrive the pattern comes from the converter in effect** (D-356), because
     * `4k7` in a numeric field is a notation to be converted, not a typo to be blocked.
     */
    protected function input(RenderContext $context): string
    {
        return RenderResult::htmlTag('input', [
            'type'      => 'text',
            'name'      => $context->fieldName,
            // ⚠️ *Ohne dies schickt die Eingabe nichts, wenn sie ausserhalb ihres Formulars steht —
            // eine Tabellenzelle neben der Zelle mit dem `<form>`. Leer wird das Attribut weggelassen.*
            'form'      => $context->surroundings->formId,
            // ⚠️ **[R32](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete), auf sein
            // Wort:** *«damit ist gemeint, dass sich ein Eingabefeld immer gleich verhalten muss — und
            // ja, wenn `1..1` steht, muss ein Wert gesetzt sein».* *Die Regel steht seit dem 2026-08-22
            // im Konzept und galt bis heute nur für Auswahllisten
            // ([R29](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete)); **gemessen trug
            // keine einzige einfache Eingabe ein `required`**.*
            //
            // ⚠️ *`mayBeNothing` trägt die Angabe schon: sie kommt aus der Multiplizität der Kante. Die
            // Vorgabe ist `true`, also bleibt jede Zeichnung ohne diese Angabe unverändert.*
            'required'  => ! $context->surroundings->mayBeNothing,
            'value'     => $this->outputValue($context),
            'pattern'   => $context->type?->pattern(),
            'inputmode' => $context->type?->inputMode(),
        ]);
    }
}
