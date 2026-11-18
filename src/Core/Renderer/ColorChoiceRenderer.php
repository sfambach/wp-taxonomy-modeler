<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\ChoicePurpose;
use Taxmod\Core\Description\Choosing;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\ValueKind;

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
            return RenderResult::described($now === ''
                ? new ShownValue('', ValueKind::ChosenColour)
                : new ShownValue($offered[$now] ?? $context->surroundings->refersTo ?? ('#' . $now), ValueKind::ChosenColour, $context->surroundings->swatches[(int) $now] ?? ''));
        }

        return RenderResult::described(Choosing::offer(
            $context->fieldName,
            $offered,
            $now === '' ? null : $now,
            $context->surroundings->mayBeNothing,
            $context->surroundings->formId,
            ChoicePurpose::Colour,
            $context->editable,
            swatches: $context->surroundings->swatches,
        ));
    }
}
