<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\RenderedField;
use Taxmod\Core\Renderer\Renderer;
use Taxmod\Core\Renderer\RendererRegistry;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;

/**
 * The descent, for the attributes of one node.
 *
 * ```mermaid
 * flowchart LR
 *   E["attribute edge"] --> T["its target"] --> Y["the simple type<br/>own name, else an ancestor's"]
 *   Y --> R["the renderer<br/>the chain, else the type default"]
 *   R --> M["markup"]
 * ```
 *
 * ⚠️ **Everything is loaded before the first renderer is called** (D-159). Targets in one query,
 * their ancestors in a second, every chain in a third — because the alternative is a query per
 * field, which is the loop `CD-7` forbids outright.
 *
 * ⚠️ **The policy living here is what to do with a purpose nobody can answer for, and it is not
 * the same answer twice.** A **value** must never silently disappear, so display and edit fall
 * back and the fallback marks itself (R14b). A **filter** that cannot be executed must never
 * appear, so search yields nothing — which is D-217's *not searchable*, reached through a missing
 * capability rather than a special case.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class Rendering
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
        private readonly Settings $settings,
        private readonly RendererRegistry $renderers,
    ) {
    }

    /**
     * Draw every attribute of a record.
     *
     * @param  list<Relation>         $edges       The attributes, in the order they are shown.
     * @param  array<int, TypedValue> $values      What the record holds, keyed by edge id. A
     *                                             missing key is *not answered* (D-232).
     * @param  string                 $fieldPrefix Form fields become `prefix[edge id]`. Keyed by
     *                                             the edge and never by position: a checkbox that
     *                                             does not submit when unticked would shift every
     *                                             later field onto the wrong attribute.
     * @return list<RenderedField>
     */
    public function fieldsFor(
        array $edges,
        array $values,
        Purpose $purpose,
        string $fieldPrefix = '',
        string $locale = '',
        Level $level = Level::Admin,
        bool $editable = true,
    ): array {
        if ($edges === []) {
            return [];
        }

        $types    = $this->typesOf($edges);
        $resolved = $this->settings->resolveForUseSites($edges);
        $fields   = [];

        foreach ($edges as $edge) {
            $type     = $types[$edge->id] ?? null;
            $settings = $resolved[$edge->id] ?? [];
            $renderer = $this->renderers->chosenFor($edge, $settings, $purpose, $type);

            if ($renderer === null) {
                if ($purpose === Purpose::Search) {
                    continue;
                }

                $renderer = $this->renderers->fallback();
            }

            $context = new RenderContext(
                $purpose,
                $values[$edge->id] ?? TypedValue::nothing(),
                $settings,
                $locale,
                $level,
                $editable,
                $fieldPrefix === '' ? '' : $fieldPrefix . '[' . $edge->id . ']',
                $type,
            );

            $fields[] = new RenderedField(
                $edge,
                $type,
                $renderer->name(),
                $renderer->render($edge, $context)
            );
        }

        return $fields;
    }

    /** What this attribute's value has to be read back as. */
    public function typeAt(Relation $edge): ?SimpleType
    {
        return $this->typesFor([$edge])[$edge->id] ?? null;
    }

    /**
     * The same question for a whole form's worth of attributes, in two queries.
     *
     * ⚠️ **Public because writing needs it too.** A form comes back as characters and each one has
     * to be read as its own type; asking per field would be a query per field on **save** as well
     * as on draw, which is the loop `CD-7` forbids either way round.
     *
     * @param  list<Relation> $edges
     * @return array<int, SimpleType|null>
     */
    public function typesFor(array $edges): array
    {
        return $this->typesOf($edges);
    }

    /**
     * Which renderers a person may choose at this use site — the registry's second job (R14a).
     *
     * @return list<Renderer>
     */
    public function choicesFor(Relation $edge, ?Purpose $purpose = null): array
    {
        return $this->renderers->eligibleFor($edge, $this->typeAt($edge), $purpose);
    }

    /**
     * The same question asked of a node — *what may this type be drawn by?*
     *
     * ⚠️ **This is what a person actually needs, and the owner said so plainly:** a renderer is
     * **chosen**, never typed. *There are only certain ones for the current purpose — and how
     * would the user know the name?* So the eligible set is the control, and the name never has to
     * be known. It holds for converters and validators in the same way (D-358).
     *
     * @return list<Renderer>
     */
    public function choicesForNode(Node $node, ?Purpose $purpose = null): array
    {
        return $this->renderers->eligibleFor($node, $this->typeOfNode($node), $purpose);
    }

    /**
     * Whether a renderer of this name exists — **not** whether it is the obvious choice.
     *
     * ⚠️ **The eligible set is guidance, not a fence** (D-360). The owner drew the line: *you
     * cannot turn a text into a binary number — well, you can, it just makes no sense, unless you
     * have a special use case.* [R14](30-renderer.md#r12r17) puts the type declaration behind the
     * **offer**, and reading it as a prohibition forecloses the special case for everybody in
     * order to prevent a mistake nobody has made yet.
     */
    public function knowsRenderer(string $name): bool
    {
        return $this->renderers->knows($name);
    }

    /** The simple type a node **is**, rather than the one an attribute points at. */
    public function typeOfNode(Node $node): ?SimpleType
    {
        $ancestorIds = $node->ancestorIds();

        return $this->typeOf(
            $node,
            $ancestorIds === [] ? [] : $this->nodes->byIds($ancestorIds)
        );
    }

    /**
     * The simple type behind each attribute's target, keyed by edge id.
     *
     * ⚠️ **A subtype of a type is still that type.** A node `Description` under `text` has no
     * `SimpleType` of its own name and stores exactly what a text stores — so the nearest ancestor
     * that **is** one answers, walked from the node upwards, because the closest statement wins
     * everywhere else in this model too.
     *
     * ⚠️ **Only inside `Data Types`.** A node called `text` sitting under `Model` is somebody's
     * own thing that happens to share a word, and reading a type off its name would be exactly
     * the special-casing-by-name the code standard forbids.
     *
     * @param  list<Relation> $edges
     * @return array<int, SimpleType|null>
     */
    private function typesOf(array $edges): array
    {
        $targets = $this->nodes->byIds(array_map(static fn (Relation $e): int => $e->toId, $edges));

        $ancestorIds = [];

        foreach ($targets as $target) {
            $ancestorIds = [...$ancestorIds, ...$target->ancestorIds()];
        }

        $ancestors = $ancestorIds === []
            ? []
            : $this->nodes->byIds(array_values(array_unique($ancestorIds)));

        $types = [];

        foreach ($edges as $edge) {
            $target           = $targets[$edge->toId] ?? null;
            $types[$edge->id] = $target === null ? null : $this->typeOf($target, $ancestors);
        }

        return $types;
    }

    /** @param array<int, Node> $ancestors */
    private function typeOf(Node $target, array $ancestors): ?SimpleType
    {
        if ($this->framework->branchOf($target) !== Branch::DataTypes) {
            return null;
        }

        $own = SimpleType::tryFrom($target->name);

        if ($own !== null) {
            return $own;
        }

        foreach (array_reverse($target->ancestorIds()) as $id) {
            $found = isset($ancestors[$id]) ? SimpleType::tryFrom($ancestors[$id]->name) : null;

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
