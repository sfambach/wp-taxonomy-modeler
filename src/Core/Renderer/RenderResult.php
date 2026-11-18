<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * What a renderer hands back: the markup, and what it used to make it.
 *
 * ⚠️ **The metadata is not decoration** (D-021). A caller that knows which attributes went into
 * a rendering can invalidate a cache, build a filter, or say *this figure came from these three
 * fields* — none of which is recoverable by looking at the markup afterwards.
 *
 * ⚠️ **Under the search purpose the markup is a control and `condition` carries the meaning**
 * (D-165): operator plus operand, feeding the generic query builder directly. That is why the
 * result has room for it instead of a second interface.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RenderResult
{
    /**
     * @param string     $markup    Already escaped by whoever built it. ⚠️ Escaping happens
     *                              **here**, in the core, with plain PHP — a renderer may not
     *                              call a WordPress function (`CD-1`), and markup that leaves
     *                              unescaped would have no second chance.
     * @param list<int>  $usedRelations The relations whose values went into it.
     * @param mixed|null $condition Under `Purpose::Search`, what to filter by.
     */
    public function __construct(
        public readonly string $markup,
        public readonly array $usedRelations = [],
        public readonly mixed $condition = null,
        /** What the markup shows, where the renderer described it (6c); `null` while it still draws itself. */
        public readonly ?\Taxmod\Core\Description\Describes $piece = null,
    ) {
    }

    /** The description of this result — the drawn markup carried along where nothing described it yet (A15). */
    public function piece(): \Taxmod\Core\Description\Describes
    {
        return $this->piece ?? new \Taxmod\Core\Description\Drawn($this->markup);
    }

    public static function of(string $markup, int ...$usedRelations): self
    {
        return new self($markup, array_values($usedRelations));
    }

    /**
     * Two results one after the other — how a node's ordered list of renderers is combined.
     *
     * The list has one mandatory entry and any number of additions (D-236), and their outputs
     * are concatenated in the list's order. Nothing in the interface moves for it.
     */
    public function followedBy(self $next): self
    {
        return new self(
            $this->markup . $next->markup,
            array_values(array_unique([...$this->usedRelations, ...$next->usedRelations])),
            $this->condition ?? $next->condition,
            $this->piece === null && $next->piece === null ? null : new \Taxmod\Core\Description\Sequence($this->piece(), $next->piece()),
        );
    }

    private static ?\Taxmod\Core\Description\Drawer $drawer = null;

    /**
     * ⚠️ *Scaffolding of renderer step 6c (A15): the edge hands its drawer over once, so a renderer that only describes still
     * gives the composites around it markup. Goes when the last composite describes.*
     */
    public static function drawWith(\Taxmod\Core\Description\Drawer $drawer): void
    {
        self::$drawer = $drawer;
    }

    /** A described piece, drawn by the edge's drawer. */
    public static function described(\Taxmod\Core\Description\Describes $piece, int ...$usedRelations): self
    {
        return new self(
            (self::$drawer ?? throw new \LogicException('No drawer: the edge registers one with the autoloader.'))->draw($piece),
            array_values($usedRelations),
            null,
            $piece
        );
    }

    public static function draw(\Taxmod\Core\Description\Describes $piece): string
    {
        return self::described($piece)->markup;
    }
}
