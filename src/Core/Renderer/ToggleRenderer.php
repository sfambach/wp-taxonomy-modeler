<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SimpleType;

/**
 * A boolean as a **sliding switch** — the owner's *bool_schieberegler_renderer*.
 *
 * ⚠️ **A second renderer, not a mode on the first**, and that is [D-018](../../../docs/NewConcept/90-decision-log.md)'s
 * own pattern: *an integer node can be shown as a plain field, a spinner, or a slider: **three
 * renderers, not one renderer with three modes***. A box you tick and a switch you slide are two
 * presentation variants of one type, so they are two renderers ([R15](../../../docs/NewConcept/30-renderer.md#r15--variant-and-circumstance-are-different-axes)).
 *
 * ⚠️ **And it is the default**, because the owner asked for *bools always with a slider*. The
 * checkbox stays offered — a variant nobody can choose is a variant that need not exist.
 *
 * ```mermaid
 * flowchart LR
 *   B["bool"] --> T["toggle · the default"]
 *   B --> C["checkbox · offered"]
 * ```
 *
 * ⚠️ **Three states, two of which look alike and are not.** A `bool` is `0` or `1`
 * ([D-315](../../../docs/NewConcept/90-decision-log.md)) and a value that is **not there** means
 * *not answered* ([D-232](../../../docs/NewConcept/90-decision-log.md)) — which a switch cannot
 * express. So the control writes a hidden `0` ahead of itself: **off means false, deliberately**,
 * and the third state is reached by clearing the attribute. *An unset switch that silently meant
 * «nobody has said» would make every mandatory check unanswerable.*
 *
 * ⚠️ **The markup is the renderer's, the appearance is the surface's.** A sliding switch is a
 * checkbox plus two spans and a stylesheet; the core writes the elements and their classes, and the
 * boundary paints them (`CD-1`). *That is also why it degrades to a plain checkbox rather than to
 * nothing when no stylesheet is present.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ToggleRenderer extends TypedFieldRenderer
{
    public const NAME = 'toggle';

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

        return $this->createHtmlValueSpan($this->track($context->value->asBool(), false));
    }

    protected function input(RenderContext $context): string
    {
        $on   = ! $context->value->isNothing() && $context->value->asBool();
        $name = $this->createHtmlAttribute('name', $context->fieldName);

        // The hidden field is what makes *off* mean false rather than absent. PHP keeps the last of
        // two equal names, so the switch overrides it when it is on.
        return '<label class="taxmod-toggle">'
            . '<input type="hidden"' . $name . ' value="0">'
            . '<input type="checkbox" class="taxmod-toggle-input"' . $name . ' value="1"'
            . ($on ? ' checked' : '') . '>'
            . $this->track($on, true)
            . '</label>';
    }

    /** The switch itself: a rail and a knob, named so the surface can paint them. */
    private function track(bool $on, bool $live): string
    {
        return '<span class="taxmod-toggle-track' . ($on ? ' is-on' : '')
            . ($live ? '' : ' is-fixed') . '"><span class="taxmod-toggle-knob"></span></span>';
    }
}
