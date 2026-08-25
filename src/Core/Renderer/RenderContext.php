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
     * ⚠️ **`parts` is how a *container* renderer gets its children, and it is the finding the form
     * renderer produced.** [R46](../../../docs/NewConcept/30-renderer.md#r46r47--a-container-renderer-is-the-same-recursion)
     * says *every cell goes back to the registry* — and a renderer may not reach out (D-159), so it
     * cannot be the container that does the asking. **The descent resolves and draws the members;
     * the container lays them out.** That keeps the recursion R46 wants without giving a renderer a
     * repository, and it is why a container is a layout over finished parts rather than a driver.
     *
     * @param list<RenderedField> $parts The members, already drawn, in the order the descent found
     *                                   them. A container regroups them; it does not draw them.
     *
     * ⚠️ **`subjectLabel` is what the node **being drawn** is called; `refersTo` is what the node a
     * value **points at** is called.** Two fields rather than one overloaded name: the same question
     * about different nodes. A tree row draws its own subject, a reference draws its target.
     *
     * @param list<string> $actions Finished controls to place with the subject, in order.
     *
     * ⚠️ **The core cannot build a button, and that is not a limitation to work around.** A control
     * carries a URL and a nonce, both of which are the boundary's facts (`CD-1`), and *what may be
     * done to this node* depends on things a renderer must not fetch — a protected node cannot be
     * deleted ([D-194](../../../docs/NewConcept/90-decision-log.md)), the last child has no *down*
     * ([D-050](../../../docs/NewConcept/90-decision-log.md)). **So the boundary builds them and the
     * renderer places them**, which is the same seam as `refersTo` and `parts`. *It is also the
     * third symptom of [OQ-087](../../../docs/NewConcept/91-open-questions.md): a word, a link, and
     * now a control — all things a core renderer can only be handed.*
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
        public readonly array $parts = [],
        public readonly ?string $subjectLabel = null,
        public readonly array $actions = [],
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
