<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;

/**
 * A node's attributes stacked as a form ([D-098](../../../docs/NewConcept/90-decision-log.md),
 * [D-091](../../../docs/NewConcept/90-decision-log.md)) — the first **structural** renderer.
 *
 * ⚠️ **Structural means chosen for what a subject *is*, not for what it holds**, so `handles()` is
 * empty. Until this class existed, `eligibleFor()` on a node with no simple type honestly returned
 * nothing at all — a supplier could be given no renderer, because none fitted a supplier.
 *
 * ⚠️ **It lays out parts the descent drew; it does not draw them.**
 * [R46](../../../docs/NewConcept/30-renderer.md#r46r47--a-container-renderer-is-the-same-recursion)
 * wants *every cell back through the registry*, and a renderer reaches out to nothing (D-159) — so
 * the container cannot be what asks. The members arrive in {@see RenderContext::$parts}.
 *
 * ⚠️ **The order is [R75](../../../docs/NewConcept/30-renderer.md#the-default-layout) /
 * [D-118](../../../docs/NewConcept/90-decision-log.md), reasons and all, and it is written down
 * there so nobody improves it away:**
 *
 * ```mermaid
 * flowchart TD
 *   A["1 · read-only values<br/>context, not something to fill in"] --> B["2 · ordinary fields"]
 *   B --> C["3 · booleans, collected"]
 *   C --> D["4 · multi-valued attributes"]
 * ```
 *
 * ⚠️ **And `position` orders *within* a group** ([D-082](../../../docs/NewConcept/90-decision-log.md)):
 * *the rule defines the groups, and position orders within a group* — so an author who ordered
 * attributes carefully does not find them rearranged.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class FormRenderer extends RendererNode
{
    public const NAME = 'form';

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /** @return list<SimpleType> Empty: it is chosen for what a subject **is**. */
    public function handles(): array
    {
        return [];
    }

    /**
     * ⚠️ **A node, not a use site.** D-098 says *a node's attributes*; what a form of an **relation**
     * would mean — the target's attributes, or the relation's own — is not decided, and answering it
     * here by accident is how a concept acquires a rule nobody wrote.
     */
    public function fits(Renderable $subject): bool
    {
        return $subject instanceof Node;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $rows      = '';
        $usedRelations = [];

        foreach ($this->grouped($context->surroundings->parts) as $part) {
            if ($part->isHidden()) {
                // R11, and R75's level dependency: `hide` overrides the layout wherever it
                // matters. A hidden member takes no row at all.
                continue;
            }

            $usedRelations = [...$usedRelations, ...$part->result->usedRelations];
            $rows     .= $this->row($part);
        }

        return new RenderResult(
            $rows === '' ? '' : '<div class="taxmod-form">' . $rows . '</div>',
            array_values(array_unique($usedRelations))
        );
    }

    /**
     * R75's four groups, with `position` inside each.
     *
     * ⚠️ **Group four is empty in practice and that is not a bug.** Multiplicity above one is not
     * storable yet — [D-232](../../../docs/NewConcept/90-decision-log.md)'s path index is designed
     * and not built — so nothing reaches it. The group exists because the order is decided, and
     * leaving it out would mean rediscovering it later.
     *
     * @param  list<RenderedField> $parts
     * @return list<RenderedField>
     */
    private function grouped(array $parts): array
    {
        $groups = [1 => [], 2 => [], 3 => [], 4 => []];

        foreach ($parts as $part) {
            $groups[$this->groupOf($part)][] = $part;
        }

        $ordered = [];

        foreach ($groups as $group) {
            usort(
                $group,
                static fn (RenderedField $a, RenderedField $b): int
                    => [$a->relation->sortOrder, $a->relation->id] <=> [$b->relation->sortOrder, $b->relation->id]
            );

            $ordered = [...$ordered, ...$group];
        }

        return $ordered;
    }

    private function groupOf(RenderedField $part): int
    {
        return match (true) {
            // ⚠️ **Einstellungen zuletzt, auf sein Wort:** *«das verstehe ich auch nicht, aber bitte
            // hinten anhängen».* *Er hat es im Datensatzblock von `Adresse` gesehen: dort stand
            // `Street`, dann `Display Option`, dann `No.`, dann `validator` — **zwischen** den Feldern,
            // weil sie von der Wurzel geerbt sind und deren Kanten die kleineren Positionen haben.*
            //
            // ⚠️ *Die vierte Gruppe war angelegt und leer. **Und die Prüfung steht zuerst**, weil ein
            // `read_only` sonst in Gruppe 1 landet und ein `validator` in Gruppe 2 — die Einstellungen
            // wären über drei Gruppen verstreut statt hinten.*
            //
            // ⚠️ *`position` ordnet weiterhin **innerhalb** der Gruppe
            // ([D-407](../../../docs/NewConcept/90-decision-log.md)) — sie sagt die Reihenfolge unter
            // Geschwistern, nicht den Rang zwischen Feld und Einstellung.*
            $part->relation->isSetting()         => 4,
            $part->readOnly                  => 1,
            $part->type === SimpleType::Bool => 3,
            default                          => 2,
        };
    }

    /**
     * ⚠️ **The label is the attribute's name, and that is a gap named rather than filled.** A field
     * in a form should read its label in the **`form` role** ([D-196](../../../docs/NewConcept/90-decision-log.md)
     * seeds one by that name), which means the label has to arrive in the context the way a
     * reference's does (D-363). *Until it does this shows the relation's internal name, which is the
     * same honesty the chain itself ends on — a node's own name, never nothing (D-020).*
     *
     * ⚠️ **Und hinter dem Feld das Fragezeichen, wo eine Hilfe geschrieben ist**
     * ([D-662](../../../docs/NewConcept/90-decision-log.md)). *Hier je Feld und nicht gesammelt: ein
     * Formular hat je Feld eine **Zeile**, also steht das Zeichen dort, wo es hingehört. Gesammelt
     * wird nur im waagerechten `compact` ({@see CompactRenderer}), wo die Felder ein Leerzeichen
     * trennt.*
     */
    private function row(RenderedField $part): string
    {
        // ⚠️ **Ein geschachteltes Formular ist eine Trennzeile mit dem Namen, und seine Felder stehen in
        // derselben Beschriftungsspalte** ([D-725](../../../docs/NewConcept/90-decision-log.md)). *Sein Wort am
        // 2026-09-11: «darstellung trennzeile mit addresse und dann labels ganz links.» Das innere Formular
        // ist schon flach — seine eigenen Teile gingen durch dieselbe Stelle —, also wird nur die Hülle
        // abgestreift und der Name davorgesetzt.*
        $markup = $part->result->markup;

        if ($part->rendererName === self::NAME && str_starts_with($markup, '<div class="taxmod-form">') && str_ends_with($markup, '</div>')) {
            return '<div class="taxmod-form-group"><span class="taxmod-form-group-name">' . RenderResult::escape($part->relation->name) . '</span></div>'
                . substr($markup, strlen('<div class="taxmod-form">'), -strlen('</div>'));
        }

        return '<div class="taxmod-form-row">'
            . '<span class="taxmod-form-label">' . RenderResult::escape($part->relation->name) . '</span>'
            . '<span class="taxmod-form-field">' . $part->result->markup . '</span>'
            . HintMarkup::icon($part->hint)
            . '</div>';
    }
}
