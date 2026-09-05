<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SimpleType;

/**
 * The renderers that come in the box, and which of them is the default for which type.
 *
 * ⚠️ **The defaults are a decision and live in one place.** [R14a](30-renderer.md#r14a--the-key-is-the-type-purpose-travels-in-the-context)
 * says *where several are eligible and nobody has chosen, one is marked default per type* — this
 * is that marking. Spreading it over the renderer classes would make each one claim its own
 * territory, and *which of the three ways to draw a number is the ordinary one* is not a property
 * of the spinner.
 *
 * ⚠️ **One type is still deliberately without one** and shows the fault marker until it gets
 * hers: `user_ref` wants a renderer that resolves a WordPress user, which is a boundary concern
 * reaching into the core's hands. *A quiet plain field pretending otherwise would be the worse
 * outcome* (R14b). `node_ref` got its **reference renderer** ([D-105](90-decision-log.md)).
 *
 * ```mermaid
 * flowchart LR
 *   T["type"] --> D["its default renderer"]
 *   S["the renderer setting"] -.->|overrides| D
 * ```
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ShippedRenderers
{
    public static function registry(): RendererRegistry
    {
        $registry = new RendererRegistry();

        // ⚠️ The *plain field* is the default for every scalar that has no better idea — D-018's
        // first of three ways. Spinner and slider are offered beside it and chosen, never
        // inherited by accident.
        $registry->add(
            new FieldRenderer(),
            SimpleType::Text,
            SimpleType::Char,
            SimpleType::Version,
            SimpleType::Int,
            SimpleType::Decimal,
        );

        // ⚠️ **The sliding switch is the default for a boolean** — the owner: *bools always with a
        // slider.* The checkbox stays **offered**, because a variant nobody can choose is a variant
        // that need not exist (D-018's pattern: one renderer per presentation variant).
        $registry->add(new ToggleRenderer(), SimpleType::Bool);
        $registry->add(new CheckboxRenderer());

        // ⚠️ **The default for a reference, which is what D-105 asks for** — and it bounds the
        // load as well as the display (R58): one label per row, not a whole target.
        $registry->add(new ReferenceRenderer(), SimpleType::NodeRef);

        $registry->add(new MailtoRenderer(), SimpleType::Email);
        $registry->add(new DateTimeRenderer(), SimpleType::DateTime);
        $registry->add(new ColorRenderer(), SimpleType::Color);

        // ⚠️ **The first structural renderer** — chosen for what a subject **is** rather than for
        // what it holds, which is why it declares no type at all (D-098). Until it existed, a node
        // with no simple type could honestly be given nothing.
        $registry->add(new FormRenderer());

        // ⚠️ *Ohne Typ, wie das Formular: ein Behälter fasst keinen an. Er zeichnet mehrere
        // Datensätze untereinander ([D-542](../../../docs/NewConcept/90-decision-log.md)).*
        $registry->add(new TableRenderer());

        // ⚠️ **The second structural renderer, and the one D-245 has been carrying since 2026-08-23**
        // — *a node with several attributes shown as compactly as possible together*. **Offered**
        // rather than surface-only, because which of the two container shapes a node wants is exactly
        // the kind of thing a modeller decides (D-471: one renderer with a switch, not two).
        $registry->add(new CompactRenderer());

        // ⚠️ **A whole node as a page**, and the page renderer is the same renderer (D-256, D-233).
        // Offered like any other structural renderer: naming it on a node means *draw this one as a
        // page*, which is a legitimate thing for an author to want.
        $registry->add(new NodeRenderer());

        // ⚠️ **The tree's cell** (D-367): registered so R12 holds, not offered because *which* cell
        // a tree draws is the surface's decision — the chooser and the trash want another.
        $registry->addForSurfaces(new TreeNodeRenderer());

        // ⚠️ **The walker** (D-367): it nests what the cell drew and draws no node itself. Asked for
        // by a surface, never chosen for a node.
        $registry->addForSurfaces(new TreeRenderer());

        // ⚠️ **What a subject is called, in one locale** — the third hand-built panel to go through
        // `R1` (D-384). Surface-only for the same reason as the settings panel.
        $registry->addForSurfaces(new LabelsRenderer());

        // ⚠️ **The two choosers of D-108, and the dialog is the default** (D-244). Registered
        // against the **edit** purpose for `node_ref`, which is what `addForPurpose()` exists for: a
        // reference is *shown* by the reference renderer and *picked* by a chooser, and R14a's one
        // default per type could not say both. *Until this, `node_ref` had a default that declined
        // `edit`, so every reference field fell back and drew as a fault.*
        $registry->addForPurpose(new DialogChooserRenderer(), Purpose::Edit, SimpleType::NodeRef);
        $registry->add(new InlineChooserRenderer());

        // ⚠️ **The chooser's cell** (D-367) — the thing that split was built for: one walker, several
        // cells. Surface-only, because *which* cell a tree draws is never a model author's choice.
        $registry->addForSurfaces(new ChooserCellRenderer());

        // ⚠️ **One record as a block** — the fourth hand-built panel to go through `R1` (D-393).
        $registry->addForSurfaces(new RecordRenderer());

        // ⚠️ **The fifth hand-built panel to become a renderer** — the owner, pointing at a head
        // that still had none of his layout: *that is a renderer, right?* Surface-only, because a
        // node's **head** is a screen's furniture and never a model author's choice of how a value
        // looks.
        $registry->addForSurfaces(new HeadRenderer());

        // ⚠️ **One settings panel for a node and for an attribute alike.** Surface-only: it is
        // asked for by a panel, never named as a node's `renderer`, because it draws a subject's
        // **configuration** and not its value.
        $registry->addForSurfaces(new SettingsRenderer());
        // ⚠️ *Als Oberflaeche und nicht als Wahl: ein Behaelter ist nichts, was jemand fuer einen
        // Wert **aussucht** — der Rand nimmt ihn, wenn ein Feld mehrere Werte tragen darf
        // ([D-527](../../../docs/NewConcept/90-decision-log.md)). Damit bleibt er auch aus der
        // Saat heraus, die nur `namesForNodes()` saet.*
        $registry->addForSurfaces(new RepeatableRenderer());

        // ⚠️ **One answer out of a set, and the only implementation of R28–R32.** Surface-only
        // because it is chosen for a **shape** rather than for a type: nothing about a node says
        // *draw me as a choice*, and the set it needs has to be handed in by whoever knows it.
        $registry->addForSurfaces(new ChoiceRenderer());

        // ⚠️ **Die Renderer-Wahl selbst** ([D-647](../../../docs/NewConcept/90-decision-log.md)):
        // *sie holt ihre Menge nicht aus den Kindern des Kantenziels, sondern aus den Renderer-Knoten,
        // gesiebt durch diese Registratur.* **Oberflaechen-Renderer und damit ohne Knoten**
        // ([D-648](../../../docs/NewConcept/90-decision-log.md), sein Wort: *«ist was Internes»*) —
        // ein Knoten machte ihn waehlbar, und dann stuende «Renderer-Waehler» in der Renderer-Liste
        // eines Textfeldes.
        $registry->addForSurfaces(new RendererChoiceRenderer());

        // ⚠️ **The attribute row, and the first renderer whose subject is an **relation**.** Surface-only
        // for the same reason as the tree's cell: it is asked for by a panel, and naming it as a
        // node's `renderer` would be meaningless — it cannot draw a node at all ({@see
        // FieldRowRenderer::fits()}).
        $registry->addForSurfaces(new FieldRowRenderer());

        // Eligible everywhere they fit, default nowhere.
        $registry->add(new TextareaRenderer());
        $registry->add(new SpinnerRenderer());
        $registry->add(new SliderRenderer());

        return $registry;
    }
}
