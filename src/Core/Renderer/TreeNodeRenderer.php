<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\RecordInTree;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Description\Carried;
use Taxmod\Core\Description\Icon;
use Taxmod\Core\Description\NodeInTree;

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
final class TreeNodeRenderer extends RendererNode
{
    public const NAME = 'tree-node';

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

    public function fits(Renderable $subject): bool
    {
        return $subject instanceof Node;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        return RenderResult::described($this->describe($subject, $context));
    }

    /** The row, described — the chooser's cell puts it inside its own (D-367). */
    public function describe(Renderable $subject, RenderContext $context): NodeInTree
    {
        $icon = $context->setting('icon')?->text ?? '';

        // ⚠️ **The node's own name, not a label** ([D-369](../../../docs/NewConcept/90-decision-log.md)).
        // The owner: *there I would take the node name.* The modelling tree is where the model is
        // worked on, and its names are what one works with — so the cell needs nothing handed in,
        // and it can never read as nothing, because a node cannot exist without a name (D-022).
        // ⚠️ *A record of a record tree has no name, only the word the boundary worked out for it (D-1084).*
        $shown = $subject instanceof Node ? $subject->name : ($subject instanceof RecordInTree ? $subject->label() : '');

        // ⚠️ **An icon is a Dashicon key**, stored without the `dashicons-` prefix — the shape the
        // legacy used and the owner confirmed: *for now simply the stock WordPress offers.* And it
        // goes **before** the name, as it did there.
        //
        // ⚠️ **Die Klasse steht dabei** ([D-716](../../../docs/NewConcept/90-decision-log.md), Schritt 1
        // des Bauplans) — klein hinter dem Namen, übersetzt vom Rand, und nur, wenn er sie hereingibt.
        //
        // ⚠️ **The link is put around what was drawn.** A URL comes in (`CD-1` — the core cannot make
        // one). *Clickable without looking like one: the icon stays black, which is what the owner asked for.*
        //
        // ⚠️ **An id per row, so the browser can scroll to it.** The owner: *the tree always slides to
        // the top when I select a node at the bottom.* Selecting a node is a full page load, and a
        // fresh document starts at the top — so the row gets an address and the link a `#fragment`.
        // **No script, no stored offset, and it survives a bookmark.**
        //
        // ⚠️ **A hidden node says so while it is being shown** — *hidden nodes should also get grey
        // text in the shown state.* Handed in, because `hide` sits on the **inheritance relation**
        // ([D-467](../../../docs/NewConcept/90-decision-log.md), [D-445](../../../docs/NewConcept/90-decision-log.md)),
        // after a setting (D-426) and a column on the node (D-457) had both been wrong.
        //
        // WICHTIG: Ohne Bearbeitung keine Funktionen rechts -- auf sein Wort: die Baumansicht im
        // Auswahldialog "sollte so wie in tree view aussehen, nur ohne die Funktionen rechts". Wer
        // nichts hineingibt, bekommt nichts; RenderContext::editable taugt nicht als Signal.
        //
        // ⚠️ **The write count is a diagnostic and shows only in developer mode**, one of the view
        // filters under the one mode ([D-705](../../../docs/NewConcept/90-decision-log.md)). *It is a
        // **write count** and never a version* ([D-349](../../../docs/NewConcept/90-decision-log.md)).
        //
        // ⚠️ **The actions arrive as {@see Submission} and {@see Control}**: the form's URL, its nonce
        // and the words are boundary facts; handing in finished HTML would leave the shape of a row
        // to the surface, which is what `R1` forbids.
        $acts = $context->surroundings->actions !== [] && $context->surroundings->submits !== null;

        return new NodeInTree(
            (int) $subject->id,
            $shown,
            $context->surroundings->rowIdPrefix,
            $icon === '' ? null : Icon::named($icon),
            $context->surroundings->classLabel,
            $context->surroundings->href,
            $context->surroundings->hidden,
            $acts ? $context->surroundings->submits->action : null,
            $acts ? Carried::of($context->surroundings->submits) : null,
            $acts ? array_values($context->surroundings->actions) : [],
            $subject instanceof Node && $context->developerMode ? (int) $subject->version : null,
        );
    }
}
