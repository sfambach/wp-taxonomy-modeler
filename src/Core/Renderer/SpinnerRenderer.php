<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;

/**
 * A number with its bounds attached — the *spinner* of [D-018](90-decision-log.md).
 *
 * ⚠️ **The bounds are read, never enforced here.** `range_min` and `range_max` travel down the
 * chain and may only ever narrow (D-312); a renderer that clamped a value it was handed would be
 * writing, which no renderer does (D-159). What it does is stop the control from *offering*
 * what the model forbids — which is R28's rule, one level up: a control offers only real choices.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class SpinnerRenderer extends TypedFieldRenderer
{
    public const NAME = 'spinner';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return [SimpleType::Int, SimpleType::Decimal];
    }

    protected function display(RenderContext $context): string
    {
        return $this->createHtmlValueSpan(RenderResult::escape($this->outputValue($context)));
    }

    protected function input(RenderContext $context): string
    {
        // ⚠️ *One call where there were six pieces of string* ([D-463](../../../docs/NewConcept/90-decision-log.md)):
        // `'<input type="number"'` was written by hand and **went past the escaping**, while the rest
        // came through the helper. {@see RenderResult::htmlTag()} is now the one place that knows how an
        // element is spelled.
        return RenderResult::htmlTag('input', [
            'type'  => 'number',
            'name'  => $context->fieldName,
            // ⚠️ *Ohne dies schickt die Eingabe nichts, wenn sie ausserhalb ihres Formulars steht.*
            'form'  => $context->surroundings->formId,
            // ⚠️ *[R32](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete), auf sein Wort: ein
            // Eingabefeld muss sich immer gleich verhalten, und bei `1..1` muss ein Wert gesetzt sein.*
            'aria-required' => $context->surroundings->mayBeNothing ? null : 'true',
            'value' => $this->outputValue($context),
            'min'   => $this->numberSetting($context, SettingKey::Min->value),
            'max'   => $this->numberSetting($context, SettingKey::Max->value),
            'step'  => $this->step($context),
        ]);
    }

    /**
     * ⚠️ **An engine setting, `range_step` — the third of R17's triple**, which names `min`, `max`
     * and `step` in one breath as settings a numeric **node** has. It was briefly a free key here,
     * which made one of three siblings an outsider; corrected once R17 was read properly.
     *
     * Silence means `any` for a decimal and one for an integer — read off the **type**, which the
     * context is told (R14a), not guessed from whatever value happens to be in the field.
     */
    private function step(RenderContext $context): string
    {
        return $this->numberSetting($context, SettingKey::Step->value)
            ?? ($context->type === SimpleType::Decimal ? 'any' : '1');
    }
}
