<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\ValueInput;
use Taxmod\Core\Description\ValueKind;
use Taxmod\Core\Model\SimpleType;

/**
 * Several lines — for the long end of `text`, which is one type covering *short strings and long
 * ones alike, source code included* ([D-316](90-decision-log.md)).
 *
 * ⚠️ **Not the default.** A text is one line until somebody says otherwise; guessing from the
 * length of what happens to be stored would make the control jump about as the content grows.
 *
 * ⚠️ **`cols` × `rows` are free settings, and they live on the node like any other.** A setting
 * only one renderer reads is still a setting on the node — the chain does not ask who will read
 * it, which is exactly how `range_min` works. *There is no such thing as a homeless setting here,
 * and the premise that a renderer needs to own one in order to have it was simply wrong.* What
 * keeps these two free rather than reserved is that no decision names them: [R17](../../../docs/NewConcept/30-renderer.md#r12r17)
 * names `min`, `max` and `step` for numbers and nothing names a text area's shape.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class TextareaRenderer extends TypedFieldRenderer
{
    /** Spalten und Zeilen des Textfelds — ohne Vorgabe nimmt die Breite `display_size` des Knotens. */
    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public ?int $cols = null;

    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public ?int $rows = null;

    public const NAME = 'textarea';

    public function handles(): array
    {
        return [SimpleType::Text];
    }

    protected function display(RenderContext $context): ShownValue
    {
        return new ShownValue($this->outputValue($context), ValueKind::LongText);
    }

    protected function input(RenderContext $context): ValueInput
    {
        return new ValueInput(
            InputKind::LongText,
            $context->fieldName,
            $this->outputValue($context),
            $context->surroundings->formId,
            $this->required($context),
            rows: $this->numberSetting($context, 'rows'),
            columns: $this->numberSetting($context, 'cols') ?? $this->numberSetting($context, 'display_size'),
        );
    }
}
