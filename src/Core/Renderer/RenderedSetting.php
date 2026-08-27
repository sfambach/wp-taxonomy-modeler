<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SettingCategory;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SettingShape;
use Taxmod\Core\Model\SimpleType;

/**
 * One setting, drawn — the settings side's answer to {@see RenderedField}.
 *
 * ⚠️ **A setting is not an attribute, so it does not borrow that class.** An attribute is an edge
 * pointing at a type; a setting is a key resolved along a chain, and it carries **where it came
 * from** ([D-079](../../../docs/NewConcept/90-decision-log.md)), which an attribute has no notion
 * of. Sharing one class would have meant a null edge on every row.
 *
 * ⚠️ **`result` is null when the key is a *choice* rather than a value** — multiplicity's four
 * constants, or a name a registry answers to. Those want a chooser, one is decided
 * ([D-244](../../../docs/NewConcept/90-decision-log.md)) and none is built, so they keep their own
 * controls rather than pretending to be fields.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RenderedSetting implements Renderable
{
    public function __construct(
        public readonly string $key,
        public readonly SettingShape $shape,
        public readonly ?SimpleType $type,
        public readonly ResolvedSetting $setting,
        public readonly ?RenderResult $result = null,
        public readonly ?string $rendererName = null,
        /**
         * The simple type of **what is being configured**, where there is one.
         *
         * ⚠️ **Carried because the category needs it** ([D-390](../../../docs/NewConcept/90-decision-log.md)):
         * `range_min` on an integer is an **integer** setting and on a decimal a **double** one, so
         * whose a key is cannot be answered from the key alone.
         *
         * ⚠️ *Not the same as `$type`, which is the type the **control** is drawn as: for a switch
         * that is `bool` whatever the subject holds, and for `factor` it is `decimal` on an integer
         * node. Two nearly identical fields, and confusing them puts every range under `bool`.*
         */
        public readonly ?SimpleType $subject = null,
    ) {
    }

    /**
     * What this setting is **about** — the axis the panel groups by.
     *
     * ⚠️ **Derived rather than carried**, because it follows from the key and nothing else. A stored
     * field would be one more thing that can disagree with {@see SettingCategory::of()}, and the
     * owner asked for the grouping precisely so the panel stops mixing two kinds of thing.
     */
    public function category(): SettingCategory
    {
        $key = SettingKey::tryFrom($this->key);

        return $key === null ? SettingCategory::ofFreeKey() : SettingCategory::of($key, $this->subject);
    }

    /**
     * The heading this row sits under.
     *
     * ⚠️ **The type's own name where the key belongs to the type**, so `int` and `decimal` are
     * separate groups without anybody enumerating them — the owner's principle: *settings for `int`
     * category integer, settings for `double` category double.*
     */
    public function group(): string
    {
        return $this->category()->label($this->subject);
    }

    /** Whether a control was drawn, or whether the caller has to offer a set instead. */
    public function wasDrawn(): bool
    {
        return $this->result !== null;
    }

    /**
     * Whether the engine owns this key.
     *
     * ⚠️ **Asked of {@see SettingKey}, never inferred from the value.** A free key belongs to
     * whoever made it and the engine knows nothing of its type, so nothing is drawn for it and its
     * characters are printed. Reading a type off whatever value happens to be stored is exactly
     * the guessing [D-354](../../../docs/NewConcept/90-decision-log.md) was written to end.
     */
    public function isEngineOwned(): bool
    {
        return SettingKey::isReserved($this->key);
    }

    /**
     * Was ein Leser fuer diese Einstellung liest — der Schluessel.
     *
     * ⚠️ **Der Eigentuemer fragte: *ist ein Setting damit auch ein renderable object?* — und die Antwort
     * ist ja, weil es beides hat: einen Namen und einen Inhalt.** *Damit ist dies der **zweite**
     * Implementierer des Vertrags, und ein Vertrag mit einem Implementierer garantiert niemandem etwas.*
     *
     * ⚠️ *Der Schluessel selbst, weil ob ein Schluesselname uebersetzbar ist, [OQ-100](../../../docs/NewConcept/91-open-questions.md)
     * noch nicht entschieden hat. Das Wort, das dort herauskommt, wird hier zurueckgegeben — an einer
     * Stelle, und genau das ist der Sinn einer Methode gegenueber einer Eigenschaft.*
     */
    public function label(): string
    {
        return $this->key;
    }

    /**
     * Der aufgeloeste Wert als Zeichen — leer, wo nichts gesetzt ist.
     *
     * ⚠️ *Nichts wird als nichts gezeichnet, nie als Strich und nie als Null
     * ([D-232](../../../docs/NewConcept/90-decision-log.md)): ein fehlender Wert heisst **nicht
     * beantwortet**, und ein Platzhalter wuerde das vor dem Leser verbergen.*
     */
    public function content(): string
    {
        return $this->setting->value->isNothing() ? '' : $this->setting->value->describe();
    }
}
