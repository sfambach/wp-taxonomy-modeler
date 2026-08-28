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
        return '<input type="number"'
            . $this->createHtmlAttribute('name', $context->fieldName)
            . $this->createHtmlAttribute('value', $this->outputValue($context))
            . $this->createHtmlAttribute('min', $this->numberSetting($context, SettingKey::RangeMin->value))
            . $this->createHtmlAttribute('max', $this->numberSetting($context, SettingKey::RangeMax->value))
            . $this->createHtmlAttribute('step', $this->step($context))
            . '>';
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
        return $this->numberSetting($context, SettingKey::RangeStep->value)
            ?? ($context->type === SimpleType::Decimal ? 'any' : '1');
    }
}
