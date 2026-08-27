<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;

/**
 * The registry has two jobs, and they are asked at different moments (D-217).
 *
 * | When | Question |
 * |---|---|
 * | render time | *give me the renderer of this name* |
 * | configuration time | *which renderers are eligible for this node at all* |
 *
 * ⚠️ **The key is the type** (R14a). That is what lets *where several are eligible and nobody has
 * chosen, one is marked **default per type*** be a fact the registry holds, rather than a
 * convention every caller has to remember — and it is why registration order decides nothing:
 * a default is named when the renderer is added, or there is none.
 *
 * ⚠️ **It is internal** (D-276). No public API for other plugins hangs off it, so it may change
 * freely — the boundary exists for portability, not for third parties.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RendererRegistry
{
    /** @var array<string, Renderer> */
    private array $byName = [];

    /** @var array<string, Renderer> Keyed by the simple type's own value. */
    private array $defaultByType = [];

    /** @var array<string, array<string, Renderer>> Type ⇒ purpose ⇒ renderer. See {@see addForPurpose()}. */
    private array $defaultByTypeAndPurpose = [];

    /** @var array<string, true> Names a surface asks for and nobody is offered. */
    private array $surfaceOnly = [];

    public function __construct(private readonly Renderer $fallback = new PlainRenderer())
    {
        $this->add($this->fallback);
    }

    /**
     * @param SimpleType ...$asDefaultFor The types this renderer answers for until somebody
     *                                    chooses otherwise. ⚠️ **Named here rather than derived
     *                                    from `handles()`**: three renderers handle an integer
     *                                    and exactly one of them is the default, which is a
     *                                    decision and not a property of the class.
     */
    public function add(Renderer $renderer, SimpleType ...$asDefaultFor): void
    {
        $this->byName[$renderer->name()] = $renderer;

        foreach ($asDefaultFor as $type) {
            $this->defaultByType[$type->value] = $renderer;
        }
    }

    /**
     * Registered, and **not offered as a choice** — a renderer a *surface* asks for by name.
     *
     * ⚠️ **[D-367](90-decision-log.md) makes this a real category rather than a special case.** The
     * tree walks and the **node renderer draws**, and *which* cell is the surface's decision: the
     * modelling tree, the chooser and the trash want the node drawn differently. **So the cell is
     * not something a model author picks for a node** — offering it would let somebody set a tree
     * cell as a node's renderer and turn the detail view into a row.
     *
     * ⚠️ **Registered all the same, because [R12](30-renderer.md#r12r17) says the registry is *the
     * one place where all renderers are registered*.** Bypassing it and instantiating the cell at
     * the call site would break that for the sake of one flag.
     *
     * *Until now only the fallback had this treatment, for the analogous reason: naming it would
     * make «no renderer» a decision somebody made (R14b).*
     */
    public function addForSurfaces(Renderer $renderer): void
    {
        $this->byName[$renderer->name()] = $renderer;
        $this->surfaceOnly[$renderer->name()] = true;
    }

    /** Render time: by name, or the fallback when the name is unknown. */
    public function byName(string $name): Renderer
    {
        return $this->byName[$name] ?? $this->fallback;
    }

    /**
     * Whether any renderer answers to this name at all.
     *
     * ⚠️ **A different question from *is it eligible*, and the difference is the owner's point.**
     * *You cannot turn a text into a binary number — well, you can, it just makes no sense, unless
     * you have a special use case.* So {@see eligibleFor()} says what **makes sense** and builds
     * the list a person is offered ([R14](30-renderer.md#r12r17): *so the settings UI can offer a
     * choice*), while this says what **exists**. A name nobody registered is a real error — it
     * resolves to the fallback and shows as *no renderer* on a node that has one. A name that is
     * registered but unusual is somebody's special case (D-360).
     */
    public function knows(string $name): bool
    {
        return isset($this->byName[$name]) && $this->byName[$name] !== $this->fallback;
    }

    /**
     * What draws this type when nobody has chosen — and the fallback where nothing was marked.
     *
     * ⚠️ **Reaching the fallback here is the fault [R14b](30-renderer.md#r14b--the-last-resort-renderer-is-a-fault-indicator-not-a-floor)
     * describes**, not a quiet floor: a type with no default is a type somebody forgot, and the
     * fallback marks its output so the omission is visible instead of merely tidy.
     */
    public function defaultFor(?SimpleType $type, ?Purpose $purpose = null): Renderer
    {
        if ($type === null) {
            return $this->fallback;
        }

        // ⚠️ **A purpose-specific default wins where one was marked** ([D-108](90-decision-log.md),
        // [D-244](90-decision-log.md)): a reference is *shown* by the reference renderer and *picked*
        // by a chooser, and those are deliberately two renderers rather than one with a switch.
        if ($purpose !== null) {
            $forPurpose = $this->defaultByTypeAndPurpose[$type->value][$purpose->value] ?? null;

            if ($forPurpose !== null) {
                return $forPurpose;
            }
        }

        return $this->defaultByType[$type->value] ?? $this->fallback;
    }

    /**
     * A default for one type **and one purpose**, where showing and choosing are different renderers.
     *
     * ⚠️ **[D-108](90-decision-log.md) needs this and [R14a](30-renderer.md#r14a--the-key-is-the-type-purpose-travels-in-the-context)
     * alone could not express it.** R14a marks one default *per type*; D-108 settles that a chooser is
     * **two separate renderers** rather than one with a switch, and [D-244](90-decision-log.md) makes
     * the **dialog** the default. So a reference is drawn one way and picked another — and until now
     * `node_ref` had a single default that **declined** `edit`, so the descent fell back and marked
     * every reference field as a fault.
     *
     * ⚠️ *The type default stays the general answer; this overrides it only where a purpose genuinely
     * wants a different renderer. Registered as an offered renderer too, because a person may pick the
     * **inline** chooser instead — D-244 flips the default and leaves D-108's construction alone.*
     *
     * @var array<string, array<string, Renderer>>
     */
    public function addForPurpose(Renderer $renderer, Purpose $purpose, SimpleType ...$types): void
    {
        $this->byName[$renderer->name()] = $renderer;

        foreach ($types as $type) {
            $this->defaultByTypeAndPurpose[$type->value][$purpose->value] = $renderer;
        }
    }

    public function fallback(): Renderer
    {
        return $this->fallback;
    }

    /**
     * Configuration time: what this subject may be given.
     *
     * ⚠️ **`null` means *this subject has no simple type*, not *do not filter*.** The two look
     * alike and conflating them is how a spinner ends up offered for a supplier: a node under
     * `Model` has no simple type, so what fits it is a **structural** renderer — one that declares
     * `handles() === []`, chosen for what a subject *is* rather than for what it holds. Today
     * there are none, and an empty list is the honest answer.
     *
     * @param  SimpleType|null $type       The subject's type, or null when it has none.
     * @param  Purpose|null    $forPurpose Narrow to renderers that can answer for it — that is
     *                                     how *not searchable* stops being a special case.
     * @return list<Renderer>
     */
    public function eligibleFor(
        Renderable $subject,
        ?SimpleType $type = null,
        ?Purpose $forPurpose = null,
    ): array {
        $fitting = [];

        foreach ($this->byName as $name => $renderer) {
            if ($renderer === $this->fallback || isset($this->surfaceOnly[$name])) {
                // ⚠️ Never offered as a choice. The fallback because naming it would make *no
                // renderer* a decision somebody made (R14b); a surface renderer because *which*
                // cell a tree draws is the surface's call and not the author's (D-367).
                continue;
            }

            if (! $renderer->fits($subject)) {
                continue;
            }

            $drawn = $renderer->handles();

            if ($type === null ? $drawn !== [] : ! in_array($type, $drawn, true)) {
                continue;
            }

            if ($forPurpose !== null && ! in_array($forPurpose, $renderer->supports(), true)) {
                continue;
            }

            $fitting[] = $renderer;
        }

        return $fitting;
    }

    /**
     * The renderer the chain chose — the edge's own setting, then the target, then its ancestors,
     * then the type's default (R41). Nothing separate is walked here: the chain has already been
     * resolved and its answer simply read.
     *
     * ⚠️ **Null means *nothing can answer for this purpose*, and it is a real answer** (D-217).
     * That is the mechanism behind *not searchable*: a renderer that declines `Search` makes its
     * attribute absent from the filter. **Substituting the fallback here would defeat it** — every
     * attribute would become searchable again, through a control that cannot search. What the
     * caller does with a null is the caller's policy, and it differs by purpose: a **value** must
     * never silently disappear, an unanswerable **filter** must never silently appear.
     *
     * @param array<string, \Taxmod\Core\Model\ResolvedSetting> $settings
     */
    public function chosenFor(
        Renderable $subject,
        array $settings,
        Purpose $purpose,
        ?SimpleType $type = null,
    ): ?Renderer {
        $chosen = $settings[SettingKey::Renderer->value]->value->text ?? null;

        if ($chosen === null || $chosen === '') {
            $renderer = $this->defaultFor($type);

            return in_array($purpose, $renderer->supports(), true) ? $renderer : null;
        }

        $named = $this->byName($chosen);

        // ⚠️ **A named renderer that cannot serve here is not in force, and the *type's default*
        // draws — not the fallback.** The owner found this by asking *how do we render a constant
        // node?* after setting `renderer = chooser-inline` on `Base units`: **a chooser is for
        // picking**, so it is registered for surfaces only and supports `Edit`, and the field descent
        // asked it for `Display`. It said no, `fieldsFor()` reached for the fallback, and the fallback
        // printed the reference as `→ 4044`. *The unit `Ω` became a bare id, which is the same fault
        // he had already reported once as `→ 285`.*
        //
        // ⚠️ **Why the type's default and not nothing.** The type still says what a `node_ref` looks
        // like — `reference` (R14a) — and a setting that cannot apply here has no business removing an
        // answer the registry already holds. *The fallback means «nothing draws this», and something
        // does.*
        //
        // ⚠️ *What this does **not** do is tell anybody the stored name is unusable. It is substituted
        // quietly, and quiet substitution is how the bug hid in the first place — so the renderer
        // control has to say it, and that is its own row on the working list.*
        if (in_array($purpose, $named->supports(), true) && ! isset($this->surfaceOnly[$chosen])) {
            return $named;
        }

        // ⚠️ **Three cases, and the tests were right that they are three.** My first attempt
        // substituted the type's default for all of them and broke two checks that had been honest
        // when written — *`PR-9` earning its place*:
        //
        // | The stored name | What must happen |
        // |---|---|
        // | **unknown to the registry** | the **fallback**, visibly — somebody typed a name that does not exist, and substituting would hide it |
        // | **known but cannot serve here** | the **type's default** — the type still says what a `node_ref` looks like |
        // | **known, cannot serve, and the type has no default either** | **nothing** — a renderer that declines a purpose yields nothing for it |
        //
        // ⚠️ *The middle row is the fix; the outer two are what the tests were defending.*
        if (! isset($this->byName[$chosen])) {
            return in_array($purpose, $this->fallback->supports(), true) ? $this->fallback : null;
        }

        $default = $this->defaultFor($type);

        if ($default === $this->fallback) {
            return null;
        }

        return in_array($purpose, $default->supports(), true) ? $default : null;
    }
}
