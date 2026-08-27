<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;

/**
 * One node as a **pickable** row — the chooser's cell.
 *
 * ⚠️ **This is what [D-367](../../../docs/NewConcept/90-decision-log.md) was built for and it had
 * never been used.** *One walker, several cells*: the modelling tree, the chooser and the trash walk
 * the same hierarchy and want the node drawn differently — *a chooser row has no delete button.* Until
 * now the tree had exactly one cell, so the split was an argument rather than a fact.
 *
 * ```mermaid
 * flowchart LR
 *   W["the tree walker · nests"] --> C["this · one node, pickable"]
 *   R["radio · the field name comes in"] --> C
 *   P["pickable? · the boundary decides"] --> C
 * ```
 *
 * ⚠️ **Not every row can be picked, and that is not a style.** The owner's own example is the flat
 * select the toolbar had: `Model`, `Primitives`, `Label roles` and every branch root appeared in it,
 * and none of them is a sensible parent for a subject area. **The boundary knows which are impossible
 * — its own subtree, a protected node — so it says so** ([D-159](../../../docs/NewConcept/90-decision-log.md)),
 * and a row that cannot be picked is drawn as **text without a radio** rather than left out: leaving it
 * out breaks the tree, because a child of an unpickable node may well be pickable.
 *
 * ⚠️ **The name is the node's own, as in the tree** ([D-369](../../../docs/NewConcept/90-decision-log.md)).
 * *The chooser is part of modelling, and the names one models with are the node names — which is also
 * why this cell needs nothing handed in to say what a row is called.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ChooserCellRenderer implements Renderer
{
    public const NAME = 'chooser-node';

    /**
     * How a row says it cannot be picked.
     *
     * ⚠️ *Carried in `actions` as a word rather than as a flag, because {@see Surroundings} has no
     * per-row boolean and inventing one for this would be a field used once. A control named
     * `unpickable` with no value is the smallest honest way to say it.*
     */
    public const UNPICKABLE = 'unpickable';

    public function name(): string
    {
        return self::NAME;
    }

    /** ⚠️ **Edit only.** A row that shows a node and cannot be chosen is the tree's cell, not this. */
    public function supports(): array
    {
        return [Purpose::Edit];
    }

    /** @return list<SimpleType> Empty: structural, chosen for what a subject **is**. */
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
        if (! $subject instanceof Node) {
            return RenderResult::of('');
        }

        $icon = $context->setting(SettingKey::Icon->value)?->text ?? '';
        $glyph = $icon === ''
            ? ''
            : '<span class="dashicons dashicons-' . RenderResult::escape($icon) . '"></span> ';

        $name = $glyph . '<span class="taxmod-chooser-name">' . RenderResult::escape($subject->name) . '</span>';

        if ($this->cannotBePicked($context)) {
            // ⚠️ Shown and not offered: the tree has to stay whole, because a child of an impossible
            // parent may be a perfectly good one.
            return RenderResult::of('<span class="taxmod-chooser-row taxmod-chooser-fixed">' . $name . '</span>');
        }

        $chosen = $context->value->reference === $subject->id;

        return RenderResult::of(
            '<label class="taxmod-chooser-row">'
            . '<input type="radio" name="' . RenderResult::escape($context->fieldName) . '"'
            . ' value="' . (int) $subject->id . '"' . ($chosen ? ' checked' : '') . '> '
            . $name
            . '</label>'
        );
    }

    private function cannotBePicked(RenderContext $context): bool
    {
        foreach ($context->surroundings->actions as $control) {
            if ($control->name === self::UNPICKABLE) {
                return true;
            }
        }

        return false;
    }
}
