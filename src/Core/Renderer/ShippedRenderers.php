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
 * ⚠️ **Two types are deliberately left without one**, and they show the fault marker until they
 * get theirs: `node_ref` wants the **reference renderer** ([D-105](90-decision-log.md)) and
 * `user_ref` a renderer that resolves a WordPress user, which is a boundary concern. *Neither is
 * built, and a quiet plain field pretending otherwise would be the worse outcome* (R14b).
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
        $registry->add(new MailtoRenderer(), SimpleType::Email);
        $registry->add(new DateTimeRenderer(), SimpleType::DateTime);
        $registry->add(new ColorRenderer(), SimpleType::Color);

        // Eligible everywhere they fit, default nowhere.
        $registry->add(new TextareaRenderer());
        $registry->add(new SpinnerRenderer());
        $registry->add(new SliderRenderer());

        return $registry;
    }
}
