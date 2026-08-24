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
     * @param list<int>  $usedEdges The edges whose values went into it.
     * @param mixed|null $condition Under `Purpose::Search`, what to filter by.
     */
    public function __construct(
        public readonly string $markup,
        public readonly array $usedEdges = [],
        public readonly mixed $condition = null,
    ) {
    }

    public static function of(string $markup, int ...$usedEdges): self
    {
        return new self($markup, array_values($usedEdges));
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
            array_values(array_unique([...$this->usedEdges, ...$next->usedEdges])),
            $this->condition ?? $next->condition,
        );
    }

    /** Escape text for HTML. Plain PHP, because the core may not reach for `esc_html()`. */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
