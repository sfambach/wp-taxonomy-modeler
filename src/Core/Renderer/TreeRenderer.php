<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;

/**
 * The tree itself — **it walks and nests, and draws no node**
 * ([D-367](../../../docs/NewConcept/90-decision-log.md)).
 *
 * The owner: *the tree renderer only sees to it that the tree is walked and builds the hierarchy,
 * and the node is then rendered by the node renderer. Perhaps we want to render the nodes
 * differently for the tree chooser — then we only need to swap the node renderer.*
 *
 * ```mermaid
 * flowchart LR
 *   W["this · nesting · folding"] --> C["the cell · one node"]
 * ```
 *
 * ⚠️ **Not a table.** A tree is not rows of equal columns; it is one column at varying depth. The
 * scaffolding used a `<table>` because it began as one, and a table forces every row to the height
 * of its tallest cell — which is what made the rows tall enough for the owner to notice.
 *
 * ⚠️ **The fold control belongs here and not in the cell**, and that is the split, not a
 * preference: *collapsing is a question about the tree*
 * ([D-345](../../../docs/NewConcept/90-decision-log.md)), answered in `Tree` and asked the same way
 * by every surface. A cell that knew whether it was folded would know where it sits.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class TreeRenderer implements Renderer
{
    public const NAME = 'tree';

    /** How far one level indents. */
    private const STEP = 1.4;

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(): array
    {
        return [Purpose::Display];
    }

    /** @return list<SimpleType> Empty: structural, and it draws a **set** rather than a value. */
    public function handles(): array
    {
        return [];
    }

    public function fits(Identity $subject): bool
    {
        return $subject instanceof Node;
    }

    public function render(Identity $subject, RenderContext $context): RenderResult
    {
        $rows = $context->surroundings->rows;

        if ($rows === []) {
            return RenderResult::of('');
        }

        $markup = '';
        $used   = [];

        foreach ($rows as $row) {
            $used   = [...$used, ...$row->cell->usedEdges];
            $markup .= '<div class="taxmod-tree-row" style="display:flex;align-items:center;'
                . 'padding:1px 6px;border-bottom:1px solid #f0f0f1'
                . ($row->highlighted ? ';background:#e8f0fb' : '') . '">'
                . '<span style="display:inline-block;width:'
                . number_format($row->depth * self::STEP, 2, '.', '') . 'em;flex:none"></span>'
                . $this->fold($row)
                . '<span style="flex:1;min-width:0">' . $row->cell->markup . '</span>'
                . '</div>';
        }

        return new RenderResult(
            '<div class="taxmod-tree">' . $markup . '</div>',
            array_values(array_unique($used))
        );
    }

    /**
     * The fold control, or the space it would take.
     *
     * ⚠️ **A leaf keeps the space rather than closing it up.** Without it every level of leaves
     * would sit a little to the left of its siblings that have children, and the depth would stop
     * being readable — which is the one thing a tree is for.
     */
    private function fold(DrawnRow $row): string
    {
        $box = 'display:inline-block;width:1.4em;flex:none;text-align:center';

        if (! $row->hasChildren || $row->toggle === null) {
            return '<span style="' . $box . '"></span>';
        }

        return '<a href="' . RenderResult::escape($row->toggle) . '"'
            . ' style="' . $box . ';text-decoration:none;color:inherit">'
            . ($row->collapsed ? '&#9656;' : '&#9662;') . '</a>';
    }
}
