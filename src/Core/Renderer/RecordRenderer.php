<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\RecordView;
use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;

/**
 * One record as a block — **the fourth and last hand-built panel to go through `R1`**.
 *
 * The owner: *I assume a record is not a renderer yet either.* Half right, and the half is the
 * interesting part: the **fields** were already drawn by {@see FormRenderer} through
 * `nodeAsForm()` ([D-098](../../../docs/NewConcept/90-decision-log.md),
 * [R46](../../../docs/NewConcept/30-renderer.md)) — what was hand-built was everything **around**
 * them: the heading, the frame, the save form, the diagnostic.
 *
 * ```mermaid
 * flowchart LR
 *   H["what it is · id · model version"] --> R[this]
 *   F["sections · the form, already drawn"] --> R
 *   A["actions · save"] --> R
 *   D["sections · the diagnostic, in developer mode"] --> R
 * ```
 *
 * ⚠️ **The subject is the *model* node and not the record**, because a record is neither a node nor
 * an relation and {@see Renderer::fits()} takes those two ([C11](../../../docs/NewConcept/10-domain-core.md)).
 * *That is not a workaround: what this draws is «one record **of this model**», and which record it
 * happens to be is a fact that travels in the context like a value does.*
 *
 * ⚠️ **The form arrives drawn** — the descent draws the fields and this places them, exactly as the
 * attribute row places its settings panel ([D-381](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **~~A renderer cannot call another renderer — it has no registry~~ — never decided.** *The owner
 * asked who had said so and the answer was **nobody**: [D-159](../../../docs/NewConcept/90-decision-log.md)
 * forbids **fetching per relation** and **writing**, and a registry lookup is neither. See
 * {@see \Taxmod\Core\Service\Rendering::recordAsBlock()} for the full correction. **How it is built is
 * not the same as what is required**, and stating the second when you mean the first is how a
 * convention becomes a rule nobody agreed to (`PR-10`).*
 *
 * ⚠️ **The diagnostic sits beside the form and never inside it.** *Which renderer drew what* found
 * four faults in one afternoon and is scaffolding ([D-344](../../../docs/NewConcept/90-decision-log.md))
 * — so it goes in its own slot rather than being woven into the markup, where it would have to be
 * unpicked when the scaffolding goes.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RecordRenderer extends RendererNode
{
    public const NAME = 'record';

    /** Where the drawn form is looked for in {@see Surroundings::$sections}. */
    public const FORM = 'form';

    /** Where the *which renderer drew what* list is looked for. Absent outside developer mode. */
    public const DIAGNOSTIC = 'diagnostic';

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /** @return list<SimpleType> Empty: structural — it frames a record, it draws no value itself. */
    public function handles(): array
    {
        return [];
    }

    public function fits(Renderable $subject): bool
    {
        return $subject instanceof Node;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $form = $context->surroundings->sections[self::FORM] ?? null;

        if ($form === null || ! $form->shows()) {
            return RenderResult::nothing();
        }

        // ⚠️ *A table for readers has no title (D-916, D-955); an empty head drew a blank bold line above it in the post.*
        $diagnostic = $context->surroundings->sections[self::DIAGNOSTIC] ?? null;
        $submits    = $context->surroundings->submits;
        $edit       = $context->purpose === Purpose::Edit && $submits !== null;

        // ⚠️ **What a record *is* comes in as a finished sentence** (`AR-2`, [OQ-087](../../../docs/NewConcept/91-open-questions.md)):
        // *Record #12 · written against version 3* names an id and a version, and the core can make neither the word nor the number
        // formatting.
        return RenderResult::described(new RecordView(
            $form->title,
            $form->piece ?? $form->body,
            $context->developerMode && $diagnostic !== null && $diagnostic->shows() ? ($diagnostic->piece ?? $diagnostic->body) : null,
            $edit ? $submits->action : null,
            $edit ? $submits->hidden : [],
            array_values(array_filter($context->surroundings->actions, static fn (Control $c): bool => ! $c->isAWord()))
        ));
    }
}
