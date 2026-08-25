<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SettingShape;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\FormRenderer;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\RenderedField;
use Taxmod\Core\Renderer\RenderedSetting;
use Taxmod\Core\Renderer\RenderResult;
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
        private readonly ?Labels $labels = null,
    ) {
    }

    /**
     * What the referenced nodes are called, for every reference in this batch, in one query.
     *
     * ⚠️ **Resolved before the descent begins** (D-159). A reference is drawn as its target's
     * label (D-105) and a renderer fetches nothing, so this is the only place the labels can come
     * from — and it is one query for the whole form rather than one per row, which is what `CD-7`
     * forbids and what made the legacy parts list slow.
     *
     * @param  array<int, TypedValue> $values
     * @return array<int, string>     Keyed by the **referenced node's** id.
     */
    private function namesOfReferences(array $values, string $locale): array
    {
        if ($this->labels === null) {
            return [];
        }

        $ids = [];

        foreach ($values as $value) {
            if ($value->reference !== null) {
                $ids[$value->reference] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        return $this->labels->forNodes(
            array_values($this->nodes->byIds(array_keys($ids))),
            SeededRole::Form,
            $locale
        );
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
        $names    = $this->namesOfReferences($values, $locale);
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

            $value = $values[$edge->id] ?? TypedValue::nothing();

            $context = new RenderContext(
                $purpose,
                $value,
                $settings,
                $locale,
                $level,
                $editable,
                $fieldPrefix === '' ? '' : $fieldPrefix . '[' . $edge->id . ']',
                $type,
                $value->reference === null ? null : ($names[$value->reference] ?? null),
            );

            $fields[] = new RenderedField(
                $edge,
                $type,
                $renderer->name(),
                $renderer->render($edge, $context),
                // Carried for the **layout**: R75 puts read-only values first, as context rather
                // than as something to fill in. A container must not resolve the chain again.
                $context->setting(SettingKey::ReadOnly->value)?->asBool() ?? false
            );
        }

        return $fields;
    }

    /**
     * Draw the settings resolved for a node — the settings side, through the renderers.
     *
     * ⚠️ **This is [R20a](30-renderer.md#r20a--the-detail-view-is-not-a-special-screen) applied
     * where it had not been.** *The settings side is a series of attributes rendered under the edit
     * purpose*, and it was printing key and value as text because nothing said what type a
     * setting's own value has. {@see SettingKey::typeFor()} says it, and the same renderers that
     * draw a record draw this — so there is no second way to draw a field.
     *
     * ⚠️ **Three rows come back undrawn, each for its own honest reason:** a **choice** wants a
     * chooser and none is built; a **free key** has no type the engine can know; and a *borrowing*
     * key on a subject with no type of its own has no shape to be drawn in.
     *
     * @param  array<string, \Taxmod\Core\Model\ResolvedSetting> $resolved
     * @return list<RenderedSetting>
     */
    public function settingsFor(
        Node $node,
        array $resolved,
        Purpose $purpose = Purpose::Display,
        string $fieldPrefix = '',
        string $locale = '',
        Level $level = Level::Admin,
    ): array {
        $subject = $this->typeOfNode($node);

        // ⚠️ **Every key that applies, not only the ones somebody wrote.** The owner, on an `int`
        // node whose chain was empty: *the settings that belong firmly to the data type — min,
        // max, step — should be shown as such.* An unset key becomes a row with an empty control
        // and `setHere = false`, which is the truth about it: nothing along the chain has said.
        foreach (SettingKey::applyingTo($subject) as $key) {
            $resolved[$key->value] ??= new ResolvedSetting(
                $key->value,
                TypedValue::nothing(),
                0,
                false
            );
        }

        ksort($resolved);

        $drawn = [];

        foreach ($resolved as $key => $setting) {
            $engineKey = SettingKey::tryFrom($key);
            $shape     = $engineKey?->shape() ?? SettingShape::Words;
            $type      = $engineKey?->typeFor($subject);

            // A free key, a choice, or a borrowed type the subject does not have. Nothing is
            // drawn, and the caller is told which of the three it is by the shape.
            if ($engineKey === null || $shape->isAChoice() || $type === null) {
                $drawn[] = new RenderedSetting($key, $shape, $type, $setting);

                continue;
            }

            $renderer = $this->renderers->defaultFor($type);

            $context = new RenderContext(
                $purpose,
                $setting->value,
                // ⚠️ **No settings inside a setting.** The chain resolved this value; a renderer
                // drawing it must not then resolve `hide` or `read_only` against the same node, or
                // hiding an attribute would hide the control that un-hides it.
                [],
                $locale,
                $level,
                true,
                $fieldPrefix === '' ? '' : $fieldPrefix . '[' . $key . ']',
                $type,
            );

            $drawn[] = new RenderedSetting(
                $key,
                $shape,
                $type,
                $setting,
                $renderer->render($node, $context),
                $renderer->name()
            );
        }

        return $drawn;
    }

    /**
     * A node drawn as a whole — its members through the descent, then laid out by a container.
     *
     * ⚠️ **This is the shape [R46](30-renderer.md#r46r47--a-container-renderer-is-the-same-recursion)
     * asks for, arranged so that D-159 still holds.** *Every cell goes back to the registry* — and
     * the descent is what asks, because a renderer reaches out to nothing. The container receives
     * the finished members in the context and regroups them (R75).
     *
     * ⚠️ **The container is chosen the same way a field's renderer is** — the chain, then the
     * structural default. A node with no simple type has no *typed* renderer and this is what fits
     * it, which is why `eligibleFor()` on a supplier stopped being empty the moment the form
     * renderer existed.
     *
     * @param list<Relation>        $edges
     * @param array<int, TypedValue> $values
     */
    public function nodeAsForm(
        Node $node,
        array $edges,
        array $values,
        Purpose $purpose,
        string $fieldPrefix = '',
        string $locale = '',
        Level $level = Level::Admin,
        bool $editable = true,
    ): RenderResult {
        $parts = $this->fieldsFor($edges, $values, $purpose, $fieldPrefix, $locale, $level, $editable);

        $container = $this->renderers->byName(FormRenderer::NAME);

        return $container->render(
            $node,
            new RenderContext(
                $purpose,
                TypedValue::nothing(),
                [],
                $locale,
                $level,
                $editable,
                '',
                null,
                null,
                $parts
            )
        );
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
        $branch = $this->framework->branchOf($target);

        // ⚠️ **A constant is drawn as a reference, and [D-232](90-decision-log.md) is where that
        // comes from** — the branch decides where a value lives, and for `Constants` the value
        // **is** a reference to a node. So the type to draw is `node_ref` whatever the constant
        // happens to be called; nothing is read off its name.
        //
        // ⚠️ A `Model` target is a reference to a **record**, which has no simple type of its own
        // and no renderer either — it wants the summary renderer (D-106) and stays undrawn until
        // then, honestly rather than as a reference to the wrong kind of thing.
        if ($branch === Branch::Constants) {
            return SimpleType::NodeRef;
        }

        if ($branch !== Branch::DataTypes) {
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
