<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\ResolvedSetting;
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
     *
     * ⚠️ **The type is told, not inferred.** It is the registry key that chose the renderer in
     * the first place (R14a), and a renderer serving two types — a spinner draws an integer and a
     * decimal — otherwise has to guess from the value it was handed. An empty decimal field would
     * then be indistinguishable from an integer one and would quietly refuse decimals.
     *
     * ⚠️ **`refersTo` is how a renderer learns about a node it is not drawing.** A reference is
     * drawn as *the target's label* (D-105), and a renderer reaches out to nothing (D-159) — so the
     * label is resolved **before** the descent, for every reference at once, and handed in. *This is
     * the seam the summary and chooser renderers will widen; it is deliberately one string until
     * one of them needs more, because a bag of everything-a-target-has would be fetched whether or
     * not anybody drew it.*
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
        public readonly ?string $refersTo = null,
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
            $purpose,
            $this->value,
            $this->settings,
            $this->locale,
            $this->level,
            $this->editable,
            $this->fieldName,
            $this->type,
            $this->refersTo,
        );
    }

    /**
     * The same context around a different value — what a list does for each occurrence.
     *
     * ⚠️ **The label travels with the value, not with the context.** Two occurrences of one
     * multi-valued reference point at two different nodes, so carrying the first one's label into
     * the second row would put the wrong name on it.
     */
    public function withValue(TypedValue $value, string $fieldName = '', ?string $refersTo = null): self
    {
        return new self(
            $this->purpose,
            $value,
            $this->settings,
            $this->locale,
            $this->level,
            $this->editable,
            $fieldName === '' ? $this->fieldName : $fieldName,
            $this->type,
            $refersTo,
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

        return ! ($this->setting('read_only')?->asBool() ?? false);
    }
}
