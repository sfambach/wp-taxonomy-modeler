<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\Describes;
use Taxmod\Core\Description\Sequence;

/**
 * What a renderer hands back: the description of what it shows, and what it used to make it.
 *
 * ⚠️ **The metadata is not decoration** (D-021). A caller that knows which attributes went into
 * a rendering can invalidate a cache, build a filter, or say *this figure came from these three
 * fields* — none of which is recoverable by looking at the drawing afterwards.
 *
 * ⚠️ **Under the search purpose the piece is a control and `condition` carries the meaning**
 * (D-165): operator plus operand, feeding the generic query builder directly. That is why the
 * result has room for it instead of a second interface.
 *
 * ⚠️ *No markup here since renderer step 6c (D-1424): the core describes, the edge draws.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RenderResult
{
    /**
     * @param list<int>  $usedRelations The relations whose values went into it.
     * @param mixed|null $condition Under `Purpose::Search`, what to filter by.
     */
    public function __construct(
        /** What it shows; `null`: nothing at all (R11). */
        public readonly ?Describes $piece = null,
        public readonly array $usedRelations = [],
        public readonly mixed $condition = null,
    ) {
    }

    /** The description of this result — an empty sequence where it shows nothing. */
    public function piece(): Describes
    {
        return $this->piece ?? new Sequence();
    }

    /** Shows nothing at all — a hidden member, or nothing to choose (R11). */
    public static function nothing(int ...$usedRelations): self
    {
        return new self(null, array_values($usedRelations));
    }

    public static function described(Describes $piece, int ...$usedRelations): self
    {
        return new self($piece, array_values($usedRelations));
    }

    /** The same metadata, showing this piece instead. */
    public function showing(Describes $piece): self
    {
        return new self($piece, $this->usedRelations, $this->condition);
    }

    /**
     * Two results one after the other — how a node's ordered list of renderers is combined.
     *
     * The list has one mandatory entry and any number of additions (D-236), and their outputs
     * follow each other in the list's order. Nothing in the interface moves for it.
     */
    public function followedBy(self $next): self
    {
        return new self(
            $this->piece === null && $next->piece === null ? null : new Sequence($this->piece(), $next->piece()),
            array_values(array_unique([...$this->usedRelations, ...$next->usedRelations])),
            $this->condition ?? $next->condition,
        );
    }
}
