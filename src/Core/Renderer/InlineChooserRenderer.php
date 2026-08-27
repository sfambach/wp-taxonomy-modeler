<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;

/**
 * Picking a node **in the form** — the other of [D-108](../../../docs/NewConcept/90-decision-log.md)'s
 * two choosers.
 *
 * ⚠️ **Two renderers and not one with a switch**, which is [D-018](../../../docs/NewConcept/90-decision-log.md)'s
 * pattern: *one renderer per presentation variant.* [D-244](../../../docs/NewConcept/90-decision-log.md)
 * made the dialog the **default** and left this one offered — the owner: *give the user the chance to
 * do it inline where it really is simple.*
 *
 * ⚠️ **The difference is exactly one thing: it is always open.** A short list in a form is quicker to
 * use than a thing to click open; a hundred prefixes are not. *So this is the renderer somebody chooses
 * where they know the list is short, and choosing it is a setting on the chain like any other
 * ([D-032](../../../docs/NewConcept/90-decision-log.md)).*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class InlineChooserRenderer implements Renderer
{
    public const NAME = 'chooser-inline';

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(): array
    {
        return [Purpose::Edit];
    }

    /** @return list<SimpleType> */
    public function handles(): array
    {
        return [SimpleType::NodeRef];
    }

    public function fits(Identity $subject): bool
    {
        return true;
    }

    public function render(Identity $subject, RenderContext $context): RenderResult
    {
        $tree = $context->surroundings->sections[DialogChooserRenderer::CANDIDATES] ?? null;

        if ($tree === null || $tree->body === '') {
            return RenderResult::of(
                '<span class="taxmod-unsatisfiable">' . RenderResult::escape($tree?->title ?? '') . '</span>'
            );
        }

        // ⚠️ *The same slot as the dialog reads, deliberately: the **candidates** are the same fact
        // whichever way they are shown, so a caller does not have to know which chooser it drew.*
        return RenderResult::of(
            '<div class="taxmod-chooser taxmod-chooser-open">'
            . '<div class="taxmod-chooser-tree">' . $tree->body . '</div>'
            . '</div>'
        );
    }
}
