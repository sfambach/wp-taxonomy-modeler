<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
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
final class TreeNodeRenderer extends RendererNode
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

    public function fits(Renderable $subject): bool
    {
        return $subject instanceof Node;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $icon = $context->setting(SettingKey::Icon->value)?->text ?? '';

        // ⚠️ **The node's own name, not a label** ([D-369](../../../docs/NewConcept/90-decision-log.md)).
        // The owner: *there I would take the node name.* The modelling tree is where the model is
        // worked on, and its names are what one works with — so the cell needs nothing handed in,
        // and it can never read as nothing, because a node cannot exist without a name (D-022).
        $shown = $subject instanceof Node ? $subject->name : '';

        // ⚠️ **An icon is a Dashicon key**, stored without the `dashicons-` prefix — the shape the
        // legacy used and the owner confirmed: *for now simply the stock WordPress offers.* Drawing
        // it is two **class names**, which the core may write: a class is a string, not a call into
        // WordPress (`CD-1`). And it goes **before** the name, as it did there.
        $named = ($icon === '' ? '' : IconMarkup::dashicon($icon) . ' ')
            . '<span class="taxmod-tree-label">' . RenderResult::escape($shown) . '</span>';

        // ⚠️ **The link is put around what was drawn, not handed back to be wrapped.** A URL comes
        // in (`CD-1` — the core cannot make one); wrapping is ordinary markup, so the renderer keeps
        // deciding the shape of the row. *Clickable without looking like one: an anchor that gives
        // up its colour lets the icon stay black, which is what the owner asked for.*
        if ($context->surroundings->href !== null) {
            $named = '<a href="' . RenderResult::escape($context->surroundings->href) . '"'
                . ' class="taxmod-tree-select" style="text-decoration:none;color:inherit">'
                . $named . '</a>';
        }

        // ⚠️ A `div`, not a `span`: the controls handed in are forms, and a form may not sit inside
        // phrasing content.
        // ⚠️ **An id per row, so the browser can scroll to it.** The owner: *the tree always slides to
        // the top when I select a node at the bottom — it should stay where it is; probably you reload
        // instead of only re-rendering?* **He is right about the cause**: selecting a node is a full
        // page load, because this screen has no script at all, and a fresh document starts at the top.
        //
        // ⚠️ *So the row gets an address and the link gets a `#fragment` — the browser then scrolls the
        // selected row into view by itself, inside the tree's own scroll pane. **No script, no stored
        // offset, and it survives a bookmark.** It is coarser than restoring an exact pixel offset, and
        // «the node I picked is on screen» is what was actually asked for.*
        // ⚠️ **A hidden node says so while it is being shown.** The owner: *hidden nodes should also get
        // grey text in the shown state.* With *show hidden* on, a hidden row is otherwise
        // indistinguishable from a visible one — and the whole point of that mode is to work **on** the
        // hidden ones.
        //
        // ⚠️ **Handed in, because a cell draws a **node** and `hide` sits on its **edge**.**
        // *This line has been rewritten three times and the last one is the design:
        // it read a **setting** (which put `hide` in the chain, so hiding a type blanked every field of
        // that type — [D-426](../../../docs/NewConcept/90-decision-log.md)), then a **column on the
        // node** ([D-457](../../../docs/NewConcept/90-decision-log.md)), and now the prepared fact
        // ([D-467](../../../docs/NewConcept/90-decision-log.md), [D-445](../../../docs/NewConcept/90-decision-log.md)):
        // `hide` is on the **inheritance edge**, which is what puts the node in the tree at all, and
        // `Tree::rowsUnder()` loads those edges anyway.*
        $hidden = $context->surroundings->hidden;

        $markup = '<div class="taxmod-tree-node' . ($hidden ? ' taxmod-tree-node-hidden' : '') . '"'
            . ' id="' . RenderResult::escape($context->surroundings->rowIdPrefix) . (int) $subject->id . '"'
            . ' style="display:flex;gap:.5em;align-items:center">'
            . $named;

        // ⚠️ **Right-aligned**, the owner's ask: `margin-left:auto` pushes everything after the
        // name to the far edge, so the names stay a readable column and the controls line up.
        // WICHTIG: Ohne Bearbeitung keine Funktionen rechts -- auf sein Wort: die Baumansicht im
        // Auswahldialog "sollte so wie in tree view aussehen, nur ohne die Funktionen rechts,
        // somit koennte hier der gleiche Render verwendet werden, wir muessen nur eine Einstellung
        // schaffen die sagt editable oder nicht". In der Seitenansicht an, im Dialog aus.
        $funktionen = $this->controls($context->surroundings);
        $zaehler    = $subject instanceof Node && $context->developerMode
            ? '<span class="taxmod-tree-writes" style="opacity:.55">' . (int) $subject->version . '</span>'
            : '';

        // WICHTIG: Kein leerer Schwanz. Im Auswahldialog werden keine Funktionen hereingereicht --
        // das ist genau, was er wollte: "sollte so wie in tree view aussehen, nur ohne die
        // Funktionen rechts". Und es braucht keinen neuen Schalter dafuer: wer nichts hineingibt,
        // bekommt nichts. RenderContext::editable taugt nicht als Signal -- es heisst "ein
        // Eingabefeld darf angeboten werden", und cellsFor() setzt es auch im Modellbaum auf false.
        if ($funktionen === '' && $zaehler === '') {
            return RenderResult::of($markup . '</div>');
        }

        $markup .= '<span class="taxmod-tree-tail" style="margin-left:auto;display:flex;'
            . 'gap:.4em;align-items:center">'
            . $funktionen
            // ⚠️ **The write count is a diagnostic and shows only in developer mode** — the owner
            // asked for it off by default, and [D-248](../../../docs/NewConcept/90-decision-log.md)
            // says there is **one** mode for that rather than a switch per diagnostic.
            //
            // ⚠️ *It is a **write count** and never a version* ([D-349](../../../docs/NewConcept/90-decision-log.md)):
            // *version* promises a state to return to, and this only says *nobody changed this row
            // since you read it*. **It stays visible in that mode because it earns its place** —
            // that decision was written after a defect was found by reading these numbers.
            . $zaehler
            . '</span>';

        return RenderResult::of($markup . '</div>');
    }

    /**
     * The controls, built here from what the boundary described.
     *
     * ⚠️ **A renderer can build a button.** What it cannot do is invent the three WordPress-shaped
     * values one needs — the form's **URL**, its **nonce**, and the **words** — so those arrive as
     * {@see Submission} and {@see Control} and the markup is composed here. *Handing in finished
     * HTML instead would have left the shape of a row to the surface, which is what `R1` forbids.*
     */
    private function controls(Surroundings $surroundings): string
    {
        if ($surroundings->actions === [] || $surroundings->submits === null) {
            return '';
        }

        $fields = ControlMarkup::hidden($surroundings->submits);

        $buttons = '';

        foreach ($surroundings->actions as $control) {
            // ⚠️ **Composed in one place** ({@see ControlMarkup}) — greyed rather than gone (D-370),
            // red only where something is taken away, and an icon-only button marked so no surface
            // has to guess. *There were four copies of this and the borderless rule reached one of
            // them, which is how boxes came back around the icons everywhere else.*
            $buttons .= ControlMarkup::button($control);
        }

        return '<form method="post" action="' . RenderResult::escape($surroundings->submits->action) . '"'
            . ' style="display:flex;gap:.2em">' . $fields . $buttons . '</form>';
    }
}
