<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Eine Farbe aus der einen Auswahl «Farben»: ihr Name mit einem Kästchen in ihrer Farbe.
 *
 * ⚠️ *Sein Wort ([D-1080](../../../docs/NewConcept/90-decision-log.md)): «farbe sollte einen eigenen render haben der die
 * farbe benennt und im kästchen anzeigt». Der Farbwert steht an der Konstante (`color`), der Rand reicht ihn je Angebot als
 * {@see Surroundings::$swatches} herein — kein Lesen hier ([D-445](../../../docs/NewConcept/90-decision-log.md)).*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ColorChoiceRenderer extends RendererNode
{
    public const NAME = 'color-choice';

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    public function handles(): array
    {
        return [\Taxmod\Core\Model\SimpleType::NodeRef];
    }

    public function needsSomethingToChooseFrom(): bool
    {
        return true;
    }

    public function fits(Renderable $subject): bool
    {
        return true;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $offered = $context->surroundings->options;
        $now     = $context->value->reference === null ? ($context->value->text ?? '') : (string) $context->value->reference;

        if ($context->purpose === Purpose::Display || ! $context->editable) {
            if ($now === '') {
                return RenderResult::of('<span class="taxmod-nothing">—</span>');
            }

            $name = $offered[$now] ?? $context->surroundings->refersTo ?? ('#' . $now);

            return RenderResult::of(
                '<span class="taxmod-choice taxmod-color-choice">' . $this->patch((int) $now, $context) . RenderResult::escape($name) . '</span>'
            );
        }

        $merkmale = ['data-taxmod-swatches' => (string) json_encode($context->surroundings->swatches)];

        return RenderResult::of(
            '<span class="taxmod-color-choice">' . $this->patch((int) $now, $context)
            . SelectMarkup::of(
                $context->fieldName,
                $offered,
                $now === '' ? null : $now,
                $context->surroundings->mayBeNothing,
                $context->surroundings->formId,
                $merkmale,
                $context->editable
            ) . '</span>'
        );
    }

    /** Das Kästchen — leer und gestrichelt, wo die Farbe keinen Wert trägt, damit es nicht wie Weiss aussieht. */
    private function patch(int $nodeId, RenderContext $context): string
    {
        $hex = $context->surroundings->swatches[$nodeId] ?? '';
        $ok  = preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1;

        return '<span class="taxmod-swatch" style="display:inline-block;width:1em;height:1em;vertical-align:middle;margin-right:.3em;'
            . ($ok ? 'border:1px solid #8c8f94;background:' . RenderResult::escape($hex) : 'border:1px dashed #8c8f94')
            . '"></span>';
    }
}
