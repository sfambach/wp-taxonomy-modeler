<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\ValueInput;
use Taxmod\Core\Description\ValueKind;
use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;

/**
 * The fallback — what draws a value when nothing better has been chosen.
 *
 * ⚠️ **A fallback is not a *default*.** The default renderer of a type is a setting somebody
 * chose; this is what answers when the whole chain is silent (D-091, D-168). It must therefore
 * fit **everything** and be dull on purpose: the moment it starts making decisions it becomes a
 * second way to draw a field, which is exactly what [R20a] warns about.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class PlainRenderer extends RendererNode
{
    public const NAME = 'plain';

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit, Purpose::Search];
    }

    /**
     * ⚠️ **Every type, and it is the default for none of them.** Being able to draw anything is
     * what a fallback is for; being *chosen* is what a default is, and a default is somebody's
     * decision (D-091). Keeping the two apart is what makes an unrendered field visible.
     */
    public function handles(): array
    {
        return SimpleType::cases();
    }

    public function fits(Renderable $subject): bool
    {
        // It is the fallback. Refusing anything would leave something undrawable.
        return true;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        // ⚠️ *The `hide` check that stood here is gone — see {@see TypedFieldRenderer::render()} for
        // why. `hide` is a column and an abort ([D-457](../../../docs/NewConcept/90-decision-log.md)),
        // so nothing asks a renderer for a hidden thing.*

        // ⚠️ **A reference is never drawn here, and reaching this line means a renderer is
        // mis-set.** This is the fallback — *nothing draws this yet* ([R14b](../../../docs/NewConcept/30-renderer.md)) —
        // and a reference belongs to {@see ReferenceRenderer}, which has to be *handed* its
        // target's label ([D-105](../../../docs/NewConcept/90-decision-log.md), [D-159](../../../docs/NewConcept/90-decision-log.md)).
        //
        // ⚠️ *It used to call `describe()` and print `→ 285` — a **bare id on screen**, which
        // [D-363](../../../docs/NewConcept/90-decision-log.md) forbids. The owner found it by
        // setting `renderer = chooser-inline` on a constant: a chooser is for **picking**, so it is
        // registered for surfaces only, the descent could not use it, and this ran instead. **A
        // silent fallback that invents a value is worse than one that says it cannot draw.***
        if ($context->value->isAReference()) {
            // ⚠️ *A fault that looks different reads as a different kind of problem: it is shown as every missing renderer is.*
            return RenderResult::described(new ShownValue($this->cannotDraw($context), ValueKind::Unchosen));
        }

        $shown = $context->value->isNothing() ? '' : $context->value->describe();

        // ⚠️ **Marked as a fault, because it is one** (R14b). Reaching the fallback means the
        // chain named no renderer and the type has no default — and covering that with a quiet
        // grey field is exactly how such an omission survives three weeks unnoticed. The class
        // says so in the markup; the words belong to the boundary, since the core may not reach
        // for the text domain (`CD-1`, and OQ-087).
        if (! $context->mayEdit()) {
            // ⚠️ Nothing is drawn as nothing, not as a dash or a zero. A missing value means
            // *not answered*, and inventing a placeholder here would hide that from the reader.
            return RenderResult::described(new ShownValue($shown, ValueKind::Unchosen));
        }

        // ⚠️ *[R32](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete), auf sein Wort: ein Eingabefeld muss sich
        // immer gleich verhalten, und bei `1..1` muss ein Wert gesetzt sein.*
        return RenderResult::described(
            new ValueInput(InputKind::Unchosen, $context->fieldName, $shown, $context->surroundings->formId, ! $context->surroundings->mayBeNothing)
        );
    }
    /**
     * What a renderer says when it has been handed something it may not draw.
     *
     * ⚠️ **It names the cause rather than the symptom.** *«No renderer» sends a person looking at
     * the renderer control, which is exactly where the answer is — the stored name is one no field
     * can use.*
     *
     * ⚠️ **And there are two causes, which is why the context is asked.** *Measured 2026-08-27 on a
     * `resistance` attribute pointing at `Einheitenwert`: both cases said «the one set for this
     * cannot draw a reference», and for a record reference that is **false** — nothing is mis-set,
     * the summary renderer ([D-106](../../../docs/NewConcept/90-decision-log.md)) is not built. A
     * fault that names the wrong cause sends a person to a control where there is nothing to fix,
     * which is worse than one that admits what is missing.*
     */
    private function cannotDraw(RenderContext $context): string
    {
        if ($context->surroundings->refersToARecord) {
            return 'not drawn yet — showing a record needs the summary renderer, which is not built';
        }

        return 'no renderer here — the one set for this cannot draw a reference';
    }

}
