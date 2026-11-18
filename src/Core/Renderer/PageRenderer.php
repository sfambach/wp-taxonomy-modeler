<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\PageView;
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
final class PageRenderer extends RendererNode
{
    /**
     * ⚠️ **Er hiess `node`, und der Name sagte etwas Falsches** — *sein Wort am 2026-09-06:
     * «dann ist es aber keiner unserer Knoten-Renderer, sondern der der Seite und sollte nicht Teil
     * der Renderer sein, die der Benutzer auswählen kann; der Name ist auch doof, sollte
     * Seitenrenderer heissen … und nicht Knoten, das spiegelt was Falsches wieder.»*
     *
     * ⚠️ **Gemessen, was er wirklich tut:** *eine einzige Aufrufstelle —
     * {@see \Taxmod\Core\Service\Rendering::nodeAsPage()}, aus dem Knotenschirm. Er zeichnet **die
     * Seite**, nicht einen Wert: Kopf, Beschriftungen, Felder, Einstellungen, Vorschau, Datensätze.
     * Als Wahl stand er trotzdem in der Liste, und an `Dimension` war er auch gewählt.*
     */
    public const NAME = 'page';

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /** @return list<SimpleType> Empty: structural, chosen for what a subject **is**. */
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
        $sections = $context->surroundings->sections;
        $body     = [];

        // ⚠️ **Declaration order of the enum is the frame order.** Iterating the slots rather than
        // what was handed in is what makes the sequence a property of the concept instead of a
        // property of whichever call site filled the array.
        foreach (PageSlot::cases() as $slot) {
            $section = $sections[$slot->value] ?? null;

            if ($section === null || ! $section->shows()) {
                continue;
            }

            $body[] = ['title' => $section->title, 'body' => $section->piece ?? $section->body, 'collapsed' => $section->collapsed];
        }

        return RenderResult::described(new PageView($body));
    }
}
