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

        $registry->add(new SwitchRenderer(), SimpleType::Bool);

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

        // Eligible everywhere they fit, default nowhere.
        $registry->add(new TextareaRenderer());
        $registry->add(new SpinnerRenderer());
        $registry->add(new SliderRenderer());

        return $registry;
    }
}
