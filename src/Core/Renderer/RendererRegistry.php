<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

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
    public function defaultFor(?SimpleType $type): Renderer
    {
        return $type === null
            ? $this->fallback
            : $this->defaultByType[$type->value] ?? $this->fallback;
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
        Node|Relation $subject,
        ?SimpleType $type = null,
        ?Purpose $forPurpose = null,
    ): array {
        $fitting = [];

        foreach ($this->byName as $renderer) {
            if ($renderer === $this->fallback) {
                // ⚠️ Never offered as a choice. It is what answers when nobody chose, and
                // putting it in the list would make *no renderer* something somebody picked.
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
        Node|Relation $subject,
        array $settings,
        Purpose $purpose,
        ?SimpleType $type = null,
    ): ?Renderer {
        $chosen = $settings[SettingKey::Renderer->value]->value->text ?? null;

        $renderer = $chosen === null || $chosen === ''
            ? $this->defaultFor($type)
            : $this->byName($chosen);

        return in_array($purpose, $renderer->supports(), true) ? $renderer : null;
    }
}
