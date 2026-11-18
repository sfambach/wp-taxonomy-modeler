<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\ValueInput;
use Taxmod\Core\Description\ValueKind;
use Taxmod\Core\Model\SimpleType;

/**
 * A colour, with the patch beside the characters.
 *
 * ⚠️ **The picker is offered only where the stored value is one it can hold.** `<input
 * type="color">` accepts exactly `#rrggbb`; handed anything else every browser silently reports
 * `#000000`, and the next save would write black over a value nobody touched. **A control that
 * can lose a value on the way past is worse than a plain field**, so anything the picker cannot
 * represent is edited as text.
 *
 * ⚠️ **That is a fallback within one renderer, not a second renderer** — the same distinction
 * R15 draws for read-only. What is *not* built is [D-226](90-decision-log.md)'s coupled pair, the
 * hex field standing **beside** the picker with both live: two controls writing one field need
 * JavaScript to stay in step, and a renderer produces markup (D-021).
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ColorRenderer extends TypedFieldRenderer
{
    public const NAME = 'color';

    public function handles(): array
    {
        return [SimpleType::Color];
    }

    protected function display(RenderContext $context): ShownValue
    {
        return new ShownValue($this->outputValue($context), ValueKind::Colour);
    }

    protected function input(RenderContext $context): ValueInput
    {
        return new ValueInput(InputKind::Colour, $context->fieldName, $this->outputValue($context), $context->surroundings->formId);
    }
}
