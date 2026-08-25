<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;

/**
 * One drawn attribute, with what was used to draw it.
 *
 * ⚠️ **The type and the renderer travel out with the markup on purpose.** A caller that had to
 * re-derive *which renderer drew this* in order to say anything about it would be resolving the
 * chain a second time, and the second answer is the one that drifts. Same reason
 * {@see RenderResult} carries its edges rather than only its string (D-021).
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RenderedField
{
    /**
     * @param bool $readOnly What the chain resolved for `read_only` — carried because the
     *                       **layout** needs it: R75 puts read-only values first, as *context*
     *                       rather than as something to fill in. Re-deriving it in a container
     *                       would mean resolving the chain a second time.
     */
    public function __construct(
        public readonly Relation $edge,
        public readonly ?SimpleType $type,
        public readonly string $rendererName,
        public readonly RenderResult $result,
        public readonly bool $readOnly = false,
    ) {
    }

    /**
     * Whether the model asked for this not to appear at all.
     *
     * ⚠️ **Empty markup is the answer, not a flag beside it.** `hide` is resolved in the renderer
     * (R11), and a second boolean saying the same thing would be the same fact twice — and the
     * two would eventually disagree.
     */
    public function isHidden(): bool
    {
        return $this->result->markup === '';
    }

    /** Drawn by the fallback: nobody chose, and the type has no default either (R14b). */
    public function hasNoRenderer(): bool
    {
        return $this->rendererName === PlainRenderer::NAME;
    }
}
