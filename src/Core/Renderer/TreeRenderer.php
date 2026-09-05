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
final class TreeRenderer extends RendererNode
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

    public function fits(Renderable $subject): bool
    {
        return $subject instanceof Node;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $rows = $context->surroundings->rows;

        if ($rows === []) {
            return RenderResult::of('');
        }

        $markup = '';
        $used   = [];
        // ⚠️ **Zu, solange eine Zeile unter einem geschlossenen Ast liegt.** *Auf der Seite kommt
        // eine zugeklappte Zeile nie hierher — der Server laesst sie weg. Im Dialog stehen dagegen
        // alle Zeilen im Dokument ({@see Rendering::nodeChooser()}), weil ein Neuaufbau ihn
        // schliessen wuerde, und muessen deshalb hier ihr Anfangsbild bekommen; dasselbe Mass, das
        // der Klapper im Skript benutzt, um sie wieder zu zeigen.*
        $zuAb = null;

        foreach ($rows as $row) {
            $used = [...$used, ...$row->cell->usedRelations];

            $versteckt = $zuAb !== null && $row->depth > $zuAb;

            if (! $versteckt) {
                $zuAb = ($row->collapsed && $row->hasChildren) ? $row->depth : null;
            }

            // WICHTIG: Die Tiefe steht am Element, nicht nur in der Einrueckung. Ein Skript, das
            // einen Ast auf- und zuklappt, muss wissen, wo er aufhoert -- und im Dokument ist der
            // Baum flach.
            $markup .= '<div class="taxmod-tree-row" data-depth="' . (int) $row->depth . '"'
                . ' style="display:' . ($versteckt ? 'none' : 'flex') . ';align-items:center;'
                . 'padding:1px 6px;border-bottom:1px solid #f0f0f1'
                . ($row->highlighted ? ';background:#e8f0fb' : '') . '">'
                . '<span style="display:inline-block;width:'
                . number_format($row->depth * self::STEP, 2, '.', '') . 'em;flex:none"></span>'
                . $this->fold($row)
                . '<span style="flex:1;min-width:0">' . $row->cell->markup . '</span>'
                . '</div>';
        }

        // WICHTIG: Das Suchfeld gehoert an den Baum und nicht in den Auswahldialog. Auf sein Wort:
        // "das Suchfeld sollten wir in die Baumansicht integrieren, kann auch in tax config Sinn
        // ergeben zu filtern". Vorher stand es im Dialogkopf und half genau dort, wo der Baum
        // ohnehin kurz ist -- die lange Liste steht in der Seitenansicht.
        //
        // WICHTIG: Ein Zeichen, kein Wort. Der Kern darf die Textdomaene nicht rufen (CD-1), und
        // AR-2 verbietet fest hingeschriebene Beschriftungen.
        $suche = '<div class="taxmod-tree-search">'
            . '<span aria-hidden="true">&#128269;</span>'
            . RenderResult::htmlTag('input', array_filter([
                'type'  => 'search',
                'class' => 'taxmod-tree-filter',
                'name'  => $context->surroundings->filterName,
                'value' => $context->surroundings->filterValue,
            ], static fn (string $v): bool => $v !== ''))
            . '</div>';

        return new RenderResult(
            '<div class="taxmod-tree">' . $suche . $markup . '</div>',
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

        // WICHTIG: '#' heisst "klappt im Browser". Im Dialog kann der Klapper kein Link sein --
        // ein Neuaufbau schliesst den Dialog, weil er von einer angehakten Checkbox offengehalten
        // wird. Auf der Seite bleibt es ein echter Link und braucht kein Skript.
        if ($row->toggle === '#') {
            return '<a href="#" class="taxmod-tree-fold" data-fold="' . ($row->collapsed ? 'zu' : 'auf') . '"'
                . ' style="' . $box . ';text-decoration:none;color:inherit">'
                . ($row->collapsed ? '&#9656;' : '&#9662;') . '</a>';
        }

        return '<a href="' . RenderResult::escape($row->toggle) . '"'
            . ' style="' . $box . ';text-decoration:none;color:inherit">'
            . ($row->collapsed ? '&#9656;' : '&#9662;') . '</a>';
    }
}
