<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;

/**
 * A number as a track — the *slider* of [D-018](90-decision-log.md), confirmed independently by
 * the legacy `Range` choice over the same type.
 *
 * ⚠️ **The figure is drawn beside the track, always.** A slider on its own says *somewhere around
 * here*, and every value in this model is exact (D-057) — a tolerance a person cannot read off is
 * a tolerance they will type into a spreadsheet instead.
 *
 * ⚠️ **A slider without bounds is a misconfigured model, and it does not currently look like
 * one.** `range_min`/`range_max` are what give a track meaning; where the chain is silent the
 * attributes are simply omitted and the browser invents 0–100. Making that **visible** needs a
 * sentence a person reads, and a core renderer cannot produce one — see
 * [OQ-087](91-open-questions.md).
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class SliderRenderer extends TypedFieldRenderer
{
    public const NAME = 'slider';

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
        $outputValue = $this->outputValue($context);

        return '<input type="range"'
            . $this->createHtmlAttribute('name', $context->fieldName)
            . $this->createHtmlAttribute('value', $outputValue)
            . $this->createHtmlAttribute('min', $this->numberSetting($context, SettingKey::RangeMin->value))
            . $this->createHtmlAttribute('max', $this->numberSetting($context, SettingKey::RangeMax->value))
            . $this->createHtmlAttribute(
                'step',
                $this->numberSetting($context, SettingKey::RangeStep->value)
                    ?? ($context->type === SimpleType::Decimal ? 'any' : '1')
            )
            . '>'
            . $this->createHtmlValueSpan(RenderResult::escape($outputValue));
    }
}
