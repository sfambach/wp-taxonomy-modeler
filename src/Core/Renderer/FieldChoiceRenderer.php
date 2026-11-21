<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\Choosing;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\ValueKind;
use Taxmod\Core\Model\SimpleType;

/**
 * A field of a chosen node, chosen by its name and stored as its relation id — the field of a rule filter (D-1541).
 *
 * His card 21.11.2026: «Felder des gewählten Knotens» — the choice offers the fields of the node another field of the same
 * record (or of the record that holds it) points at, inherited ones included. Which field that is says `knoten_feld`; the
 * offer is handed in as {@see Surroundings::$options}, read once at the boundary of rendering, never here (D-445).
 *
 * ```mermaid
 * flowchart LR
 *   K["knoten_feld → the node chosen in the record"] --> F["its fields, inherited ones included"]
 *   F --> W["choice: field name, stored as relation id"]
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1541
 */
final class FieldChoiceRenderer extends RendererNode
{
    public const NAME = 'field-choice';

    public const NODE_FIELD = 'knoten_feld';

    /** The field of the record (or of the record holding it) whose chosen node offers its fields here — a relation id. */
    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public int $knoten_feld = 0;

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    public function handles(): array
    {
        return [SimpleType::Int];
    }

    public function fits(Renderable $subject): bool
    {
        return true;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $offered = $context->surroundings->options;
        $now     = $context->value->int === null ? '' : (string) $context->value->int;

        if ($context->purpose === Purpose::Display || ! $context->editable) {
            return RenderResult::described($now === ''
                ? new ShownValue('', ValueKind::Chosen)
                : (array_key_exists($now, $offered) ? new ShownValue($offered[$now], ValueKind::Chosen) : new ShownValue('#' . $now, ValueKind::NotOffered)));
        }

        return RenderResult::described(Choosing::offer(
            $context->fieldName,
            $now !== '' && ! array_key_exists($now, $offered) ? [$now => '#' . $now] + $offered : $offered,
            $now === '' ? null : $now,
            $context->surroundings->mayBeNothing,
            $context->surroundings->formId,
            editable: $context->editable,
        ));
    }
}
