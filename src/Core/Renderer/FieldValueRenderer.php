<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\Choosing;
use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\ValueInput;
use Taxmod\Core\Description\ValueKind;
use Taxmod\Core\Model\SimpleType;

/**
 * The value of a rule filter, entered as the field chosen in the same row takes it (D-1552): a choice among that field's
 * values where it chooses from a list (Zustand → neu, gebraucht …), else one line. Stored as text — the stored form of the
 * chosen field's value, which is what the rule book compares.
 *
 * His word 21.11.2026: «Und Wert müsste sich ja auch entsprechend umschalten, was ich da jetzt auswähle.»
 *
 * ```mermaid
 * flowchart LR
 *   F["feld_feld → the field chosen in the row"] --> O{"does it choose from a list?"}
 *   O -->|yes| C["choice of its values"]
 *   O -->|no| L["one line"]
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1552
 */
final class FieldValueRenderer extends RendererNode
{
    public const NAME = 'field-value';

    public const FIELD_FIELD = 'feld_feld';

    /** The field of the same row whose chosen field says how the value is entered — a relation id. */
    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public int $feld_feld = 0;

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    public function handles(): array
    {
        return [SimpleType::Text];
    }

    public function fits(Renderable $subject): bool
    {
        return true;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $offered = $context->surroundings->options;
        $now     = (string) ($context->value->text ?? '');
        $follows = ($context->settings[self::FIELD_FIELD] ?? null)?->value->int ?? 0;

        if ($context->purpose === Purpose::Display || ! $context->editable) {
            return RenderResult::described(new ShownValue((string) ($offered[$now] ?? $now), ValueKind::Chosen));
        }

        if ($offered !== []) {
            return RenderResult::described(Choosing::offer(
                $context->fieldName,
                $now !== '' && ! array_key_exists($now, $offered) ? [$now => $now] + $offered : $offered,
                $now === '' ? null : $now,
                true,
                $context->surroundings->formId,
                editable: $context->editable,
                valueOf: $follows,
            ));
        }

        // The id marks the line as the value of `feld_feld`, so the page can turn it into a choice when another field is chosen.
        return RenderResult::described(new ValueInput(InputKind::Line, $context->fieldName, $now, $context->surroundings->formId,
            id: $follows > 0 && $context->fieldName !== '' ? 'taxmod-value-of-' . $follows . '-' . substr(md5($context->fieldName), 0, 10) : ''));
    }
}
