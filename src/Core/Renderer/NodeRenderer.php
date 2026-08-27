<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;

/**
 * A whole node as a page — **and the page renderer is the same renderer**
 * ([D-256](../../../docs/NewConcept/90-decision-log.md), [D-233](../../../docs/NewConcept/90-decision-log.md)).
 *
 * [D-233](../../../docs/NewConcept/90-decision-log.md) reads [D-091](../../../docs/NewConcept/90-decision-log.md)
 * as having rejected an **interface**, not the idea: *a page renderer is an ordinary renderer whose
 * subject is a node standing for the page.* [D-256](../../../docs/NewConcept/90-decision-log.md)
 * then rules out a third term — so there is one renderer here and not two.
 *
 * ⚠️ **The frame's order is decided and is not taste**
 * ([R20a](../../../docs/NewConcept/30-renderer.md#r20a--the-detail-view-is-not-a-special-screen)),
 * and it lives in {@see PageSlot} so that nobody has to keep two lists in agreement. The owner
 * walked it out loud as the sequence in which a person actually works on a node, and it was written
 * down *so a rebuild does not reshuffle it for looks*.
 *
 * ```mermaid
 * flowchart TD
 *   A["acts"] --> B["fixed"] --> C["name"] --> D["display"]
 *   D --> E["attributes"] --> F["preview"] --> G["relations, collapsed"]
 * ```
 *
 * ⚠️ **A slot nobody filled is not drawn** — the preview and the relations are not built, and an
 * empty titled box would claim they were. *Absence here says the truth; the frame does not need to
 * be complete to be right.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class NodeRenderer implements Renderer
{
    public const NAME = 'node';

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /** @return list<SimpleType> Empty: structural, chosen for what a subject **is**. */
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
        $sections = $context->surroundings->sections;
        $body     = '';

        // ⚠️ **Declaration order of the enum is the frame order.** Iterating the slots rather than
        // what was handed in is what makes the sequence a property of the concept instead of a
        // property of whichever call site filled the array.
        foreach (PageSlot::cases() as $slot) {
            $section = $sections[$slot->value] ?? null;

            if ($section === null || $section->body === '') {
                continue;
            }

            $body .= $this->block($section);
        }

        if ($body === '') {
            return RenderResult::of('');
        }

        return RenderResult::of(
            // ⚠️ **The frame carries no paint of its own any more** (D-391, D-392): each band inside
            // draws its own box now, so a border here made a box inside a box. *What a renderer states
            // is that the page **is** one container; how heavy that container looks is the surface's.*
            '<div class="taxmod-page">' . $body . '</div>'
        );
    }

    /**
     * One block.
     *
     * ⚠️ **`relations` is collapsed and R20a says so** — *and last the relations, collapsed.* A
     * `<details>` does that in plain HTML, which is why no other section needs one: only this one
     * was decided to start shut.
     */
    private function block(Section $section): string
    {
        // ⚠️ **An empty title means no heading**, and the owner asked for that after seeing the whole
        // page: *look at a few more frames, and push the path behind the name, and leave out a few
        // headings.* Three bands had a heading over a single control — *Name* over a name field,
        // *Display* over two panels that carry their own headings — and a heading that repeats what
        // is directly under it is a line of noise. *An empty string rather than a second field,
        // because «this band has no heading» is exactly «its heading is nothing».*
        $heading = $section->title === ''
            ? ''
            : '<h3 style="margin:1em 0 .4em">' . RenderResult::escape($section->title) . '</h3>';

        if (! $section->collapsed) {
            return '<div class="taxmod-page-block">' . $heading . $section->body . '</div>';
        }

        return '<details class="taxmod-page-block" style="margin-top:1em">'
            . '<summary style="cursor:pointer;font-weight:600">'
            . RenderResult::escape($section->title) . '</summary>'
            . $section->body . '</details>';
    }
}
