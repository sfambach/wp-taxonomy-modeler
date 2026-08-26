<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
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
final class PlainRenderer implements Renderer
{
    public const NAME = 'plain';

    public function name(): string
    {
        return self::NAME;
    }

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

    public function fits(Node|Relation $subject): bool
    {
        // It is the fallback. Refusing anything would leave something undrawable.
        return true;
    }

    public function render(Node|Relation $subject, RenderContext $context): RenderResult
    {
        if ($context->setting(SettingKey::Hide->value)?->asBool() ?? SettingKey::Hide->defaultSwitch()) {
            return RenderResult::of('');
        }

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
            return RenderResult::of(
                // ⚠️ **The same class the rest of this renderer uses.** A test caught me inventing a
                // second one: *`taxmod-no-renderer`* is how a missing renderer already reads on this
                // screen, and a fault that styles itself differently reads as a different kind of
                // problem. *The class is the vocabulary; a new word needs a reason.*
                '<span class="taxmod-value taxmod-no-renderer">'
                . RenderResult::escape($this->cannotDraw()) . '</span>'
            );
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
            return RenderResult::of(
                '<span class="taxmod-value taxmod-no-renderer">'
                . RenderResult::escape($shown) . '</span>'
            );
        }

        return RenderResult::of(
            '<input type="text" class="taxmod-no-renderer"'
            . ' name="' . RenderResult::escape($context->fieldName) . '"'
            . ' value="' . RenderResult::escape($shown) . '">'
        );
    }
    /**
     * What a renderer says when it has been handed something it may not draw.
     *
     * ⚠️ **It names the cause rather than the symptom.** *«No renderer» sends a person looking at
     * the renderer control, which is exactly where the answer is — the stored name is one no field
     * can use.*
     */
    private function cannotDraw(): string
    {
        return 'no renderer here — the one set for this cannot draw a reference';
    }

}
