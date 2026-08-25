<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;

/**
 * One node as the tree draws it — the **cell** of [D-367](../../../docs/NewConcept/90-decision-log.md)'s
 * split.
 *
 * The owner: *a renderer for the tree node is justified, so that **every node is rendered the same
 * way**. The tree renderer only sees to it that the tree is walked and builds the hierarchy, and the
 * node is then rendered by the node renderer. Perhaps we want to render the nodes differently for
 * the **tree chooser** — then we only need to swap the node renderer.*
 *
 * ```mermaid
 * flowchart LR
 *   W["tree renderer<br/>walks · nests"] --> C["this · draws one node"]
 *   I["icon · a setting"] --> C
 *   L["label · a role and a locale"] --> C
 *   A["actions · built at the boundary"] --> C
 * ```
 *
 * ⚠️ **Swapping the cell is the argument, not a nicety.** The modelling tree, the tree chooser
 * ([D-244](../../../docs/NewConcept/90-decision-log.md)) and the trash all walk the same hierarchy
 * and want the node drawn differently — a chooser row has no delete button. **One walker, several
 * cells** is the only arrangement in which those three cannot drift apart, which is the fault
 * [D-346](../../../docs/NewConcept/90-decision-log.md) demonstrated: *collapsing worked in the tree
 * and not in the trash, the same function, two call sites, one of them forgotten.*
 *
 * ⚠️ **The icon is not the `symbol` role**
 * ([D-252](../../../docs/NewConcept/90-decision-log.md)): an icon is a language-neutral glyph, a
 * symbol is a short **translated** text. They sit next to each other and are different mechanisms.
 *
 * ⚠️ **The actions arrive finished.** A control carries a URL and a nonce — boundary facts (`CD-1`)
 * — and *what may be done to this node* depends on things a renderer must not fetch. So the
 * boundary builds them and this places them.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class TreeNodeRenderer implements Renderer
{
    public const NAME = 'tree-node';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * ⚠️ **Display, and it is not a hedge.** A row **shows** a node; nothing in it is a value being
     * edited. The buttons are **acts** — renaming goes through `ModelEditor::rename()` — and an act
     * is not the edit purpose, which is about offering a control for a **value** (R9a).
     */
    public function supports(): array
    {
        return [Purpose::Display];
    }

    /** @return list<SimpleType> Empty: structural, chosen for what a subject **is**. */
    public function handles(): array
    {
        return [];
    }

    public function fits(Node|Relation $subject): bool
    {
        return $subject instanceof Node;
    }

    public function render(Node|Relation $subject, RenderContext $context): RenderResult
    {
        $icon = $context->setting(SettingKey::Icon->value)?->text ?? '';

        // ⚠️ **The label, with the node's own name behind it.** The chain ends on the name and never
        // on nothing (D-020, D-022), so a row always reads as something — an empty cell where a name
        // belongs is impossible by construction rather than by care.
        $shown = $context->subjectLabel !== null && $context->subjectLabel !== ''
            ? $context->subjectLabel
            : ($subject instanceof Node ? $subject->name : '');

        $markup = '<span class="taxmod-tree-node">'
            . ($icon === '' ? '' : '<span class="taxmod-icon">' . RenderResult::escape($icon) . '</span>')
            . '<span class="taxmod-tree-label">' . RenderResult::escape($shown) . '</span>';

        if ($context->actions !== []) {
            // Already finished markup from the boundary — escaping it again would print the buttons
            // instead of offering them.
            $markup .= '<span class="taxmod-tree-actions">' . implode('', $context->actions) . '</span>';
        }

        return RenderResult::of($markup . '</span>');
    }
}
