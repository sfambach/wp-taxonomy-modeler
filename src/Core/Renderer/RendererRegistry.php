<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;

/**
 * The registry has two jobs, and they are asked at different moments (D-217).
 *
 * | When | Question |
 * |---|---|
 * | render time | *give me the renderer of this name* |
 * | configuration time | *which renderers are eligible for this node at all* |
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

    public function __construct(private readonly Renderer $fallback = new PlainRenderer())
    {
        $this->add($this->fallback);
    }

    public function add(Renderer $renderer): void
    {
        $this->byName[$renderer->name()] = $renderer;
    }

    /** Render time: by name, or the fallback when the name is unknown. */
    public function byName(string $name): Renderer
    {
        return $this->byName[$name] ?? $this->fallback;
    }

    /**
     * Configuration time: what this subject may be given.
     *
     * @param  Purpose|null   $forPurpose Narrow to renderers that can answer for it — that is
     *                                    how *not searchable* stops being a special case.
     * @return list<Renderer>
     */
    public function eligibleFor(Node|Relation $subject, ?Purpose $forPurpose = null): array
    {
        $fitting = [];

        foreach ($this->byName as $renderer) {
            if (! $renderer->fits($subject)) {
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
     * The renderer the chain chose — the edge's own setting, then the target, then its
     * ancestors, then the fallback (R41). Nothing separate is walked here: the chain has already
     * been resolved and its answer simply read.
     *
     * @param array<string, \Taxmod\Core\Model\ResolvedSetting> $settings
     */
    public function chosenFor(Node|Relation $subject, array $settings, Purpose $purpose): Renderer
    {
        $chosen = $settings[SettingKey::Renderer->value]->value->text ?? null;

        if ($chosen === null) {
            return $this->fallback;
        }

        $renderer = $this->byName($chosen);

        // ⚠️ A renderer that cannot answer for this purpose is not an error — it is the
        // mechanism. The caller gets the fallback rather than an empty field, so a value never
        // silently disappears because somebody chose a display-only renderer.
        return in_array($purpose, $renderer->supports(), true) ? $renderer : $this->fallback;
    }
}
