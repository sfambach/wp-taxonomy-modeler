<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\ValueInput;
use Taxmod\Core\Description\ValueKind;
use Taxmod\Core\Model\SimpleType;

/**
 * A boolean as a switch.
 *
 * ⚠️ **Three states, two of which look alike and are not.** A `bool` is stored as `0` or `1`
 * (D-315), and a value that is **not there** means *not answered* (D-232) — which a checkbox
 * cannot express, because it is either ticked or it is not. So the control writes a hidden `0`
 * ahead of itself: unticked means **false**, deliberately, and the third state is reachable only
 * by clearing the attribute. *An unticked box that silently meant «nobody has said» would make
 * every mandatory check unanswerable.*
 *
 * ⚠️ **No words.** *Yes* and *no* are software strings and would have to go through the text
 * domain (`AR-2`), which the core may not touch (`CD-1`) — so a read boolean is drawn as the same
 * box, closed. See [OQ-087](91-open-questions.md); a labelled switch waits for its answer.
 *
 * ⚠️ **The layout is not this renderer's business.** [D-118](90-decision-log.md) collects
 * booleans into one wrapping, column-aligned row — that is R75's ordering, done by whatever draws
 * the node, not by the box itself.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class CheckboxRenderer extends TypedFieldRenderer
{
    public const NAME = 'checkbox';

    public function handles(): array
    {
        return [SimpleType::Bool];
    }

    protected function display(RenderContext $context): ShownValue
    {
        return new ShownValue($context->value->isNothing() ? '' : ($context->value->asBool() ? '1' : '0'), ValueKind::Flag);
    }

    protected function input(RenderContext $context): ValueInput
    {
        return new ValueInput(
            InputKind::Flag,
            $context->fieldName,
            ! $context->value->isNothing() && $context->value->asBool() ? '1' : '0',
            $context->surroundings->formId,
        );
    }
}
