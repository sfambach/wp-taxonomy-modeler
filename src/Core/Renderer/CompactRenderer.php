<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SimpleType;

/**
 * A node's fields drawn as tightly together as they will go — **one** renderer with a switch, not
 * two ([D-471](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **Why one and not two.** [D-245](../../../docs/NewConcept/90-decision-log.md) carried *compact
 * horizontal* and *compact vertical* as two entries, from the owner's own words of 2026-08-23.
 * [D-471](../../../docs/NewConcept/90-decision-log.md) narrowed that: *«Zwei Renderer, die sich in
 * einer Achse unterscheiden, sind zwei Registrierungen, zwei Namen im `renderer`-Schlüssel und zwei
 * Stellen, an denen dieselbe Kompaktheit gepflegt wird. **Ein Umschalter ist dieselbe Aussage, an
 * einer Stelle.***
 *
 * ⚠️ **What it is for**, in the owner's words in [D-245](../../../docs/NewConcept/90-decision-log.md):
 * *«a node with several attributes that I want shown as compactly as possible together»* — so the
 * parts keep **the order they were handed in**. There is no regrouping here: {@see FormRenderer}
 * sorts by R75's four groups because a form is read top to bottom, and a compact line that
 * rearranged an author's fields would be a second, competing layout rule.
 *
 * ⚠️ **It lays out parts the descent drew; it does not draw them.** A renderer reaches out to
 * nothing ([D-159](../../../docs/NewConcept/90-decision-log.md)), so the container cannot be what
 * asks the registry — the members arrive in {@see Surroundings::$parts}, exactly as they do for
 * {@see FormRenderer}.
 *
 * ```mermaid
 * flowchart LR
 *   P["parts · already drawn"] --> O{"orientation"}
 *   O -->|horizontal| R["one row"]
 *   O -->|vertical| C["one column"]
 *   L["label"] -.->|on by default| R
 *   L -.-> C
 * ```
 *
 * ## The two properties, and the open question they hang on
 *
 * | Key | Values | Silence means |
 * |---|---|---|
 * | `orientation` | `horizontal`, `vertical` | `horizontal` |
 * | `label` | a switch | **on** |
 *
 * Both defaults are the owner's, verbatim in [D-471](../../../docs/NewConcept/90-decision-log.md):
 * *«**Standard ist Label an**, und bei horizontal/vertikal ist **Standard horizontal**.»*
 *
 * ⚠️ **They are read as *free* setting keys, and that choice is provisional.**
 * [OQ-120](../../../docs/NewConcept/91-open-questions.md) asks whether a renderer **declares** its
 * properties at all, and measures that the same thing is done two ways today: `SpinnerRenderer` and
 * `SliderRenderer` read **reserved** keys, `TextareaRenderer` reads the free keys `cols` and `rows`
 * that nobody declares. *This follows `TextareaRenderer`, because
 * [D-364](../../../docs/NewConcept/90-decision-log.md) already settled what a free key is for —
 * «`cols` and `rows` are free keys today and correctly so — no instance has *rows*; it is how one
 * renderer draws … **What changes is who writes them: whoever writes renderers, not whoever
 * models.**» An orientation is not a fact about a thing; no record answers it.* **If
 * [OQ-120](../../../docs/NewConcept/91-open-questions.md) answers that renderers declare their
 * properties, these two keys move and this class changes with them.**
 *
 * ⚠️ **The defaults live here, in the renderer, and that is also OQ-120's part 2.**
 * {@see SpinnerRenderer::step()} does the same thing today for `step`. *The alternative — seeding
 * the two keys on the model so silence never happens — would put a drawing instruction in the model
 * for every node that might ever be drawn compactly, and [OQ-120](../../../docs/NewConcept/91-open-questions.md)
 * is where that is being decided rather than here.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class CompactRenderer implements Renderer
{
    public const NAME = 'compact';

    /** The free setting key that flips the axis. See the class docblock on OQ-120. */
    public const ORIENTATION = 'orientation';

    /** The free setting key that switches the labels off. See the class docblock on OQ-120. */
    public const LABEL = 'label';

    public const HORIZONTAL = 'horizontal';
    public const VERTICAL   = 'vertical';

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /** @return list<SimpleType> Empty: it is chosen for what a subject **is**, like every container. */
    public function handles(): array
    {
        return [];
    }

    /**
     * ⚠️ **A node, for {@see FormRenderer::fits()}'s reason and no wider.** D-245 describes the
     * subject as *a node with several attributes*; what a compact rendering of an **edge** would
     * mean — the target's fields, or the edge's own — is not decided, and answering it here by
     * accident is how a concept acquires a rule nobody wrote.
     */
    public function fits(Renderable $subject): bool
    {
        return $subject instanceof Node;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $vertical  = $this->isVertical($context);
        $withLabel = $this->withLabel($context);

        $inner     = '';
        $usedEdges = [];

        foreach ($context->surroundings->parts as $part) {
            if ($part->isHidden()) {
                // R11: a hidden member takes no place at all — the same rule the form applies, and
                // the reason `isHidden()` lives on the part rather than being re-derived here.
                continue;
            }

            $usedEdges = [...$usedEdges, ...$part->result->usedEdges];
            $inner    .= $this->createHtmlPart($part, $withLabel);
        }

        return new RenderResult(
            $inner === '' ? '' : $this->createHtmlContainer($inner, $vertical),
            array_values(array_unique($usedEdges))
        );
    }

    /**
     * ⚠️ **Only the exact word `vertical` turns the axis**; everything else — silence, an empty
     * setting, a misspelling — is the default. *An unrecognised value is not a decided case
     * ([OQ-120](../../../docs/NewConcept/91-open-questions.md) owns the shape of these keys), and
     * falling back to the declared default is the only reading that cannot invent a third
     * orientation.*
     */
    private function isVertical(RenderContext $context): bool
    {
        return $context->setting(self::ORIENTATION)?->text === self::VERTICAL;
    }

    /**
     * ⚠️ **Silence is *on*, which is why this is not a plain `asBool()`.** The pattern is
     * {@see RenderContext::mayEdit()}'s — read the switch, and where the chain is silent use the
     * default that was decided rather than PHP's falsy zero. *`?->` guards a null object here, not
     * a missing key.*
     */
    private function withLabel(RenderContext $context): bool
    {
        $switch = $context->setting(self::LABEL);

        if ($switch === null || $switch->isNothing()) {
            return true;
        }

        return $switch->asBool();
    }

    /**
     * ⚠️ **The class is the hook and the inline axis is the behaviour.** *A class alone would make
     * the switch a name that only a stylesheet honours — and there is no rule for `taxmod-compact`
     * in `assets/admin.css`, so the two orientations would have rendered identically. The class
     * stays so a stylesheet can still take over.*
     */
    private function createHtmlContainer(string $inner, bool $vertical): string
    {
        // ⚠️ *The cross-axis alignment is not the same fact as the axis.* Along a row the parts share
        // a **text baseline**, which is what makes a compact line read as one line; down a column
        // baseline alignment would align them sideways instead, so the column starts them flush.
        $axis = $vertical
            ? 'flex-direction:column;align-items:flex-start'
            : 'flex-direction:row;align-items:baseline';

        return RenderResult::htmlTag('div', [
            'class' => 'taxmod-compact taxmod-compact-' . ($vertical ? self::VERTICAL : self::HORIZONTAL),
            'style' => 'display:flex;flex-wrap:wrap;gap:.5em;' . $axis,
        ]) . $inner . '</div>';
    }

    /**
     * ⚠️ **The label is the attribute's own name, and that is the same gap
     * {@see FormRenderer::row()} names rather than fills.** A field should read its label in the
     * **`form` role** ([D-196](../../../docs/NewConcept/90-decision-log.md) seeds one by that
     * name), which means the label has to arrive in the context the way a reference's does. *Until
     * it does, this shows the edge's internal name — the same honesty the chain itself ends on: a
     * node's own name, never nothing ([D-020](../../../docs/NewConcept/90-decision-log.md)).*
     */
    private function createHtmlPart(RenderedField $part, bool $withLabel): string
    {
        $label = $withLabel
            ? RenderResult::htmlTag('span', ['class' => 'taxmod-compact-label'])
                . RenderResult::escape($part->edge->name) . '</span>'
            : '';

        return RenderResult::htmlTag('span', ['class' => 'taxmod-compact-part'])
            . $label
            . RenderResult::htmlTag('span', ['class' => 'taxmod-compact-field'])
            . $part->result->markup
            . '</span></span>';
    }
}
