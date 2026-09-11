<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

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
        // ⚠️ **Zwei Werte, und vorher war es einer.** *In der Bahn steht der **gespeicherte** Wert, denn
        // der Browser deutet ihn; neben der Bahn steht, was ein Mensch **liest** — mit Konverter also
        // `XII` statt `12`. Vorher stand die Notation in `value`, und `<input type="range" value="XII">`
        // ist kein Wert: der Griff sprang in die Mitte, und das nächste Speichern hätte die Mitte
        // geschrieben. {@see TypedFieldRenderer::controlValue()} trägt die Messung.*
        return RenderResult::htmlTag('input', [
            'type'  => 'range',
            'name'  => $context->fieldName,
            // ⚠️ *Ohne dies schickt die Eingabe nichts, wenn sie ausserhalb ihres Formulars steht.*
            'form'  => $context->surroundings->formId,
            'value' => $this->controlValue($context),
            'min'   => $this->numberSetting($context, 'min'),
            'max'   => $this->numberSetting($context, 'max'),
            // ⚠️ *`any` for a decimal, because a slider with an integer step cannot reach 2.5.*
            'step'  => $this->numberSetting($context, 'step')
                ?? ($context->type === SimpleType::Decimal ? 'any' : '1'),
        ])
            . $this->createHtmlValueSpan(RenderResult::escape($this->outputValue($context)));
    }
}
