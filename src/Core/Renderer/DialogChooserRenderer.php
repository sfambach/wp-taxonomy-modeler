<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;

/**
 * Picking a node in a **dialog** — the default chooser ([D-244](../../../docs/NewConcept/90-decision-log.md)).
 *
 * [D-108](../../../docs/NewConcept/90-decision-log.md) settled the shape — **two separate chooser
 * renderers, not one with a switch** ([D-018](../../../docs/NewConcept/90-decision-log.md)) — and made
 * *inline* the default. D-244 flipped the default on the owner's own account: *with search, tree
 * selection and so on we kept saying the dialog is probably the better alternative, also for searching
 * entries. So default to the dialog, but give the user the chance to do it inline where it really is
 * simple.*
 *
 * ```mermaid
 * flowchart LR
 *   C["the closed field · what is chosen now"] --> D["⌄ opens"]
 *   D --> T["the tree, walked · one cell per node"]
 * ```
 *
 * ⚠️ **A real overlay, because `<details>` was not one.** It began as a `<details>` — *shut by
 * default, one click away, no scripting* — and the owner looked at the result twice: *tree chooser
 * dialog here, please*, then, when told it already was the dialog renderer, **`nicht inline`**. He is
 * right: `<details>` expands **in place**, pushing the page down. A person calls that inline whatever
 * the class is named, and [D-244](../../../docs/NewConcept/90-decision-log.md) chose the dialog
 * precisely to get away from that.
 *
 * ⚠️ **And it is still scriptless.** A hidden checkbox with no `name` carries the open state, a
 * `<label>` styled as the trigger flips it, and CSS shows the overlay while it is checked. *No `name`
 * means it never submits, so a chooser sitting inside a form cannot corrupt what the form sends —
 * which is the same reason `form="…"` was chosen over scripting for the save button.*
 *
 * ⚠️ **The trigger is handed in** ({@see self::TRIGGER}), because the owner wants *the move button*
 * to be what opens it — not a button beside a field. *A dialog whose opener is supplied can be opened
 * by whatever the surface already has, and the renderer stops guessing what the trigger should look
 * like.*
 *
 * ⚠️ *What is still missing against a real `<dialog>`: focus is not trapped and Escape does not
 * close. Both need script. The shade closes it on a click, which is the part people reach for first.*
 *
 * ⚠️ **Shut by default, showing what is chosen.** That is the whole difference from
 * {@see InlineChooserRenderer}: a closed field needs only enough text to **recognise** — `Ω` — while
 * the open list needs enough to **tell apart** — `Ω — Ohm` ([D-263](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **The tree arrives walked and drawn** ([D-159](../../../docs/NewConcept/90-decision-log.md)): a
 * renderer reaches out to nothing, so the candidates are resolved before the descent and handed in as
 * a finished tree. *That is also why the same walker serves the modelling tree and this — one
 * hierarchy, two cells (D-367).*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class DialogChooserRenderer implements Renderer
{
    public const NAME = 'chooser-dialog';

    /** Where the walked tree of candidates is looked for in {@see Surroundings::$sections}. */
    public const CANDIDATES = 'candidates';

    /**
     * What opens the dialog, handed in by the surface.
     *
     * ⚠️ *The owner: **button move with dialog tree chooser** — so the move button is the opener
     * rather than a second control beside it. Absent, the current value opens it, which is what a
     * plain reference field wants.*
     */
    public const TRIGGER = 'trigger';

    /**
     * What confirms the pick, drawn inside the overlay.
     *
     * ⚠️ *It has to be **inside** the dialog, because a person who opened it to choose must be able
     * to say «this one» without hunting for a button behind the shade. It is the surface's markup for
     * the same reason the trigger is: it carries a capability, a nonce and a translated label.*
     */
    public const CONFIRM = 'confirm';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * ⚠️ **Edit only, and that is the point of it existing.** Showing a reference is
     * {@see ReferenceRenderer}'s job — *the target's label plus a link, and nothing behind it*
     * ([D-105](../../../docs/NewConcept/90-decision-log.md)). This is the half that had been missing,
     * which is why every reference field had been drawing as a fault.
     */
    public function supports(): array
    {
        return [Purpose::Edit];
    }

    /** @return list<SimpleType> A reference — what else would one pick a node for. */
    public function handles(): array
    {
        return [SimpleType::NodeRef];
    }

    public function fits(Renderable $subject): bool
    {
        return true;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $tree = $context->surroundings->sections[self::CANDIDATES] ?? null;

        if ($tree === null || $tree->body === '') {
            // ⚠️ **Nothing to choose from is said, not hidden** — R31: a control with no possible
            // answer is a fault in the model and drawing an empty box would let it pass for a field.
            return RenderResult::of(
                '<span class="taxmod-unsatisfiable">' . RenderResult::escape($tree?->title ?? '') . '</span>'
            );
        }

        // ⚠️ **One id per subject, because a checkbox is addressed by `for=`.** Two dialogs sharing an
        // id would open each other — the same class of fault as the four identical form ids that one
        // page grew before [D-397](../../../docs/NewConcept/90-decision-log.md)'s check caught them.
        // ⚠️ **The field name goes into the id, and that was a real collision.** Two choosers on one
        // page — the move target and an attribute's target — are both built from the **first walked
        // node**, so both ids were `taxmod-dialog-402` and **each label opened both dialogs**. *The
        // docblock above had warned about exactly this and I built it anyway; measuring the ids is
        // what found it, not reading the warning.*
        $switch = 'taxmod-dialog-' . $subject->id . '-' . preg_replace('/[^a-z0-9_-]/i', '', $context->fieldName);

        // ⚠️ The closed field: **what is chosen**, and only enough of it to recognise (D-263).
        $current = $context->surroundings->refersTo === null
            ? '<span class="taxmod-nothing">—</span>'
            : RenderResult::escape($context->surroundings->refersTo);

        $trigger = $context->surroundings->sections[self::TRIGGER] ?? null;

        return $this->overlay($switch, $current, $trigger, $tree, $context);
    }

    /** The confirm bar, left out entirely when the surface handed in nothing to confirm with. */
    private function foot(RenderContext $context): string
    {
        $confirm = $context->surroundings->sections[self::CONFIRM] ?? null;

        if ($confirm === null || $confirm->body === '') {
            return '';
        }

        return '<span class="taxmod-dialog-foot">' . $confirm->body . '</span>';
    }

    private function overlay(string $switch, string $current, ?Section $trigger, Section $tree, RenderContext $context): RenderResult
    {

        return RenderResult::of(
            '<span class="taxmod-chooser">'
            // No `name`, so it never submits — a chooser inside a form must not change what the form
            // sends.
            . RenderResult::htmlTag('input', [
                'type'  => 'checkbox',
                'class' => 'taxmod-dialog-switch',
                'id'    => $switch,
            ])
            // ⚠️ **The label carries the button classes itself.** A `<span class="button">` inside
            // a label is styled like a button and laid out like a span — the owner saw the move
            // button sitting *«leicht versetzt»* beside the others, and that was why.
            . '<label class="button taxmod-icon-button taxmod-dialog-open" for="' . RenderResult::escape($switch) . '">'
            . ($trigger === null || $trigger->body === '' ? $current : $trigger->body)
            . '</label>'
            . '<span class="taxmod-dialog">'
            // ⚠️ The shade is a second `<label>` for the same switch, which is how a click outside
            // closes it without script.
            . '<label class="taxmod-dialog-shade" for="' . RenderResult::escape($switch) . '"></label>'
            . '<span class="taxmod-dialog-panel">'
            . '<span class="taxmod-dialog-head">'
            . '<span class="taxmod-chooser-current">' . $current . '</span>'
            . '<label class="taxmod-dialog-close" for="' . RenderResult::escape($switch) . '">&times;</label>'
            . '</span>'
            // WICHTIG: Ein Suchfeld, weil die Liste achtzig Eintraege lang werden kann (TASK-031).
            // Es traegt kein Wort, sondern ein Zeichen -- AR-2: nichts vom Benutzer Sichtbares wird
            // fest hingeschrieben, und der Kern darf die Textdomaene nicht rufen (CD-1). Ohne
            // Skript bleibt es ein Feld, das nichts tut; es filtert also nur, wo Skript laeuft, und
            // die vollstaendige Liste steht ohnehin darunter.
            . '<span class="taxmod-chooser-search"><span aria-hidden="true">&#128269;</span>'
            . RenderResult::htmlTag('input', [
                'type'  => 'search',
                'class' => 'taxmod-chooser-filter',
            ])
            . '</span>'
            . '<span class="taxmod-chooser-tree">' . $tree->body . '</span>'
            . $this->foot($context)
            . '</span></span></span>'
        );
    }
}
