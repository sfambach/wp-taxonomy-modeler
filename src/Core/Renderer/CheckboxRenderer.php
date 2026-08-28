<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

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

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return [SimpleType::Bool];
    }

    protected function display(RenderContext $context): string
    {
        if ($context->value->isNothing()) {
            return $this->createHtmlValueSpan('');
        }

        return $this->createHtmlValueSpan(
            // ⚠️ *`true` schreibt das Attribut bar, `false` laesst es weg — genau wie `disabled`
            // und `checked` in HTML gemeint sind ([D-463]).*
            RenderResult::htmlTag('input', [
                'type'     => 'checkbox',
                'disabled' => true,
                'checked'  => $context->value->asBool(),
            ])
        );
    }

    protected function input(RenderContext $context): string
    {
        $name = $this->createHtmlAttribute('name', $context->fieldName);

        // The hidden field is what makes *unticked* mean false rather than absent. PHP keeps the
        // last of two equal names, so the box overrides it when it is ticked.
        return RenderResult::htmlTag('input', [
            'type'  => 'hidden',
            'name'  => $context->fieldName,
            'value' => '0',
        ]) . RenderResult::htmlTag('input', [
            'type'    => 'checkbox',
            'name'    => $context->fieldName,
            'value'   => '1',
            'checked' => ! $context->value->isNothing() && $context->value->asBool(),
        ]);
    }
}
