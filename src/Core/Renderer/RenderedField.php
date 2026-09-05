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
 * {@see RenderResult} carries its relations rather than only its string (D-021).
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
        public readonly Relation $relation,
        public readonly ?SimpleType $type,
        public readonly string $rendererName,
        public readonly RenderResult $result,
        public readonly bool $readOnly = false,
        /**
         * Wo dieser Wert unter seinem Feld sitzt — leer für den einzigen.
         *
         * ⚠️ **Es gibt ihn, weil mehrere Werte mehrere Pfade sind**
         * ([D-530](../../../docs/NewConcept/90-decision-log.md)): drei Werte eines Feldes teilen sich
         * **eine Kante und einen Pfad**, also kann keines von beiden sie auseinanderhalten — **die
         * Zeilen-Id kann es**, und sie war immer da. *Und {@see RepeatableRenderer} ordnet jedem
         * Eintrag sein «entfernen» darüber zu — **nicht über die Stellung in der Liste**, denn die
         * verschiebt sich beim Entfernen und der nächste Klick träfe den falschen.*
         */
        public readonly string $valueId = '',
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
