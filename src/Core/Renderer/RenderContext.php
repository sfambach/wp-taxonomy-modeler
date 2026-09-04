<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Everything a renderer is told, so that it has to ask nobody.
 *
 * ⚠️ **A renderer never reaches out.** It receives the value, the resolved settings and the
 * locale, and it returns a string — no repository, no clock, no `$wpdb`. That is what makes the
 * core testable without a WordPress bootstrap (`CD-1`), and it is also why a renderer can never
 * write, not even to tidy up (D-159).
 *
 * ⚠️ **`level` is a circumstance, not a purpose** (R15). Admin, block and front end are options
 * *inside* one renderer, decided by the caller; a slider and a spinner are different renderers,
 * but a read-only slider is the same renderer given a different option. That split is what keeps
 * three variants × three levels × two edit modes from becoming eighteen classes.
 *
 * ⚠️ **Everything a renderer is told about anything *other than its own value* lives in
 * {@see Surroundings}** — a target's label, the members a container lays out, the controls the
 * boundary built. **Grouped there rather than listed here**, because this constructor had grown to
 * twelve parameters and the chooser wants a thirteenth.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RenderContext
{
    /**
     * @param TypedValue                     $value    What is in the record here. `nothing()`
     *                                                 means **not answered**, never *no*.
     * @param array<string, ResolvedSetting> $settings The chain, already resolved — the renderer
     *                                                 walks nothing itself.
     * @param string                         $locale   Empty for the neutral one.
     * @param string                         $fieldName The form field to write under, for the
     *                                                 edit purpose. Empty when nothing is being
     *                                                 edited.
     * @param SimpleType|null                $type     Which type is being drawn.
     * @param bool $developerMode Whether the installation is in developer mode.
     *
     * ⚠️ **The type is told, not inferred.** It is the registry key that chose the renderer in
     * the first place (R14a), and a renderer serving two types — a spinner draws an integer and a
     * decimal — otherwise has to guess from the value it was handed. An empty decimal field would
     * then be indistinguishable from an integer one and would quietly refuse decimals.
     *
     * ⚠️ **Developer mode is a *circumstance*, exactly like `level`, and it used to be a setting on
     * a node** ([D-389](../../../docs/NewConcept/90-decision-log.md)). The owner ended that: *develop
     * is not a setting on the node but a setting in the WordPress admin settings menu.* **He is
     * right, and it closes [OQ-039](../../../docs/NewConcept/91-open-questions.md)** — a posture is a
     * fact about the **installation**, not about whichever node it happened to be resolved on, and
     * putting it on the chain meant it could differ per branch, which is meaningless.
     *
     * ⚠️ *That it lived on the root node was always described as an interim — «the chain doing its
     * job for want of a screen». The screen is a WordPress option, which is a screen that already
     * exists, so the interim ends rather than being replaced.*
     */
    public function __construct(
        public readonly Purpose $purpose,
        public readonly TypedValue $value,
        public readonly array $settings = [],
        public readonly string $locale = '',
        public readonly Level $level = Level::Admin,
        public readonly bool $editable = true,
        public readonly string $fieldName = '',
        public readonly ?SimpleType $type = null,
        public readonly Surroundings $surroundings = new Surroundings(),
        public readonly bool $developerMode = false,
        /**
         * The value already run through the converter in effect, or `null` where none is.
         *
         * ⚠️ **Prepared, not fetched** ([D-445](../../../docs/NewConcept/90-decision-log.md)): the
         * descent resolves which converter applies and runs it, so a renderer does not know converters
         * exist. *That is [D-159](../../../docs/NewConcept/90-decision-log.md) held rather than bent —
         * a renderer is handed what it needs and reaches for nothing.*
         *
         * ⚠️ **`null` and `''` mean different things.** *`null` is «no converter is in effect, show the
         * stored value»; an empty string is a converter that mapped the value to nothing. Collapsing
         * them would make an unmapped value and a deliberately blank one look identical.*
         *
         * ⚠️ *It carries characters and not a `TypedValue`, because that is what a converter produces:
         * [D-219](../../../docs/NewConcept/90-decision-log.md) calls the converter **the mapping** and
         * the renderer **the form**, so the mapping's output is what a person reads.*
         */
        public readonly ?string $shown = null,
    ) {
    }

    /** A setting by key, resolved, or null when the whole chain is silent about it. */
    public function setting(string $key): ?TypedValue
    {
        // ⚠️ `?->` guards a null object, not a missing key — the two look alike and are not.
        return ($this->settings[$key] ?? null)?->value;
    }

    /** The same context for a different purpose — what a frame does as it descends. */
    public function forPurpose(Purpose $purpose): self
    {
        return new self(
            purpose: $purpose,
            value: $this->value,
            settings: $this->settings,
            locale: $this->locale,
            level: $this->level,
            editable: $this->editable,
            fieldName: $this->fieldName,
            type: $this->type,
            surroundings: $this->surroundings,
            developerMode: $this->developerMode,
        );
    }

    /**
     * The same context around a different value — what a list does for each occurrence.
     *
     * ⚠️ **A target's label travels with the value**, so it is replaced rather than carried: two
     * occurrences of one multi-valued reference point at two different nodes, and keeping the first
     * one's label would name the second wrongly.
     */
    public function withValue(TypedValue $value, string $fieldName = '', ?string $refersTo = null): self
    {
        return new self(
            purpose: $this->purpose,
            value: $value,
            settings: $this->settings,
            locale: $this->locale,
            level: $this->level,
            editable: $this->editable,
            fieldName: $fieldName === '' ? $this->fieldName : $fieldName,
            type: $this->type,
            surroundings: $this->surroundings->referringTo($refersTo),
            developerMode: $this->developerMode,
        );
    }

    /**
     * Whether an input may actually be offered.
     *
     * ⚠️ **Two different things have to agree.** `read_only` comes from the model and travels
     * down the chain, never to be unfixed further down (D-312); `editable` is the caller's
     * circumstance — a preview, a printed page. Either one closes the field.
     */
    public function mayEdit(): bool
    {
        if (! $this->editable || $this->purpose !== Purpose::Edit) {
            return false;
        }

        // ⚠️ **The key, not the string, and the key's own default** ([D-401](../../../docs/NewConcept/90-decision-log.md)).
        // *A literal `'read_only'` beside a `?? false` is two copies of one fact in one line: rename
        // the key and this survives compilation while quietly answering «editable» for ever.*
        return ! ($this->setting(SettingKey::ReadOnly->value)?->asBool() ?? SettingKey::ReadOnly->defaultSwitch());
    }
}
