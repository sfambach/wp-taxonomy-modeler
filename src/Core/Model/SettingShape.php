<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * What a setting's value looks like — so that a control can be **drawn** for it rather than typed.
 *
 * ⚠️ **This is the piece the settings side was missing.**
 * [R20a](../../../docs/NewConcept/30-renderer.md#r20a--the-detail-view-is-not-a-special-screen)
 * and [D-190](../../../docs/NewConcept/90-decision-log.md) settle that the settings side **is** a
 * series of attributes rendered under the edit purpose — but a setting is not an attribute
 * pointing at a type, so nothing said what type its own value has. Without that answer a panel can
 * only print key and value as text, which is the second way to draw a field that R20a warns about.
 *
 * ⚠️ **And the engine keys do not map onto {@see SimpleType} one-to-one, which is why this exists
 * at all.** Three of them are not a fixed type:
 *
 * ```mermaid
 * flowchart TD
 *   S["a setting key"] --> F["a type of its own<br/>mandatory · order"]
 *   S --> L["whatever the subject is<br/>default · range_min · range_max · range_step"]
 *   S --> C["a choice from a set<br/>multiplicity · renderer · converter"]
 * ```
 *
 * The middle row is the interesting one: `range_min` on an integer is an integer and on a decimal
 * is a decimal. Its type is not a property of the **key** but of the **node being configured**.
 *
 * @see docs/NewConcept/30-renderer.md
 */
enum SettingShape
{
    /** True or false — `mandatory`, `hide`, `read_only`. */
    case Switch;

    /** A whole number of its own, independent of whatever is being configured. */
    case Whole;

    /**
     * An exact decimal of its own — a conversion factor, an offset.
     *
     * ⚠️ **Its own, not borrowed.** A factor is a decimal whatever the unit measures: the factor
     * from inch to millimetre is `25.4` on a length and the shape of the value does not change
     * because the dimension does ([D-274](../../../docs/NewConcept/90-decision-log.md)).
     */
    case Exact;

    /**
     * Whatever the node being configured is.
     *
     * ⚠️ **A default for a text is a text; a minimum for a decimal is a decimal.** These keys
     * borrow their type from their subject, which is why a control for them cannot be chosen
     * without knowing what it is sitting on.
     */
    case LikeTheSubject;

    /**
     * One of the four multiplicities ([D-351](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ Not a type but a **set**, and a closed one — which is the whole reason D-351 replaced two
     * number fields with four constants: a value out of that set cannot be written by accident.
     */
    case OneOfFour;

    /**
     * A name a registry answers to — `renderer`, `converter`, and `validator` when it arrives.
     *
     * ⚠️ **The set is computed, not stored** ([R14](../../../docs/NewConcept/30-renderer.md#r12r17)):
     * a registration declares which node types a renderer is responsible for *so the settings UI
     * can offer a choice*. What is offered is narrowed by type and purpose; what is **allowed** is
     * anything registered ([D-360](../../../docs/NewConcept/90-decision-log.md)).
     */
    case ARegisteredName;

    /** Characters, with nothing more said about them — an author's own key, and `icon` for now. */
    case Words;

    /**
     * Whether a plain typed field can draw it, or whether it needs a set to choose from.
     *
     * ⚠️ **The two chooser shapes have no renderer yet.** One chooser is decided
     * ([R25](../../../docs/NewConcept/30-renderer.md)–[R27](../../../docs/NewConcept/30-renderer.md),
     * [D-244](../../../docs/NewConcept/90-decision-log.md)) and not built, so those two keep the
     * dedicated controls they already have rather than pretending to be fields.
     */
    public function isAChoice(): bool
    {
        return $this === self::OneOfFour || $this === self::ARegisteredName;
    }
}
