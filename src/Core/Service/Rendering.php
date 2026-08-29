<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Converter\Converter;
use Taxmod\Core\Converter\ConverterRegistry;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Identity;
use Taxmod\Core\Renderer\Renderable;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SettingShape;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\FieldRowRenderer;
use Taxmod\Core\Renderer\ChoiceRenderer;
use Taxmod\Core\Renderer\HeadRenderer;
use Taxmod\Core\Renderer\ChooserCellRenderer;
use Taxmod\Core\Renderer\DialogChooserRenderer;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\DrawnRow;
use Taxmod\Core\Renderer\FormRenderer;
use Taxmod\Core\Renderer\LabelSlot;
use Taxmod\Core\Renderer\LabelsRenderer;
use Taxmod\Core\Renderer\NodeRenderer;
use Taxmod\Core\Renderer\RecordRenderer;
use Taxmod\Core\Renderer\Section;
use Taxmod\Core\Renderer\SettingsRenderer;
use Taxmod\Core\Renderer\TreeRenderer;
use Taxmod\Core\Renderer\Submission;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\RenderedField;
use Taxmod\Core\Renderer\RenderedSetting;
use Taxmod\Core\Renderer\RenderResult;
use Taxmod\Core\Renderer\Surroundings;
use Taxmod\Core\Renderer\TreeNodeRenderer;
use Taxmod\Core\Renderer\Renderer;
use Taxmod\Core\Renderer\RendererRegistry;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\TypeNodes;

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
        /**
         * ⚠️ **Required, and that is the decision rather than an oversight** ([D-510](../../../docs/NewConcept/90-decision-log.md)).
         * *An optional binding with a name fallback behind it would mean a wiring nobody did looks
         * exactly like a wiring that worked — which is how the whole fault being fixed here survived
         * three days. The Notnagel lives in **one** place, in the implementation, where it can write
         * the id down.*
         */
        private readonly TypeNodes $typeNodes,
        private readonly ?Labels $labels = null,
        /**
         * ⚠️ **Optional, so every existing caller keeps working with no converter in effect** — which
         * is a complete state and not a gap ([R33b](../../../docs/NewConcept/30-renderer.md#r33b--several-are-eligible-exactly-one-is-in-effect)):
         * *no converter means the value is shown as it is stored.* Same shape as `$labels`, for the
         * same reason.
         */
        private readonly ?ConverterRegistry $converters = null,
    ) {
    }

    /**
     * What was typed into a form, as values to store — the converter's other direction.
     *
     * ⚠️ **The mirror of {@see self::fieldsFor()}, and it has to be, or the round trip is broken.**
     * *A field that draws `XII` and saves `XII` as text is a field that lost its value. [R36](../../../docs/NewConcept/30-renderer.md)
     * puts the reason plainly: the converter runs **on the way in as well**, and that is what makes
     * `> XII` in a search box possible at all.*
     *
     * ⚠️ **One resolution for the whole form, not one per field** (`CD-7`). *Same construction as the
     * drawing side: settings and types for every edge at once, then a loop with no query in it.*
     *
     * ⚠️ **Only an invertible converter is asked** ([D-076](../../../docs/NewConcept/90-decision-log.md)).
     * *A lossy one is display only, so what a person typed into it is read by the type — which is the
     * honest reading: the characters on screen were never the whole value.*
     *
     * @param  list<Relation>        $edges      The attributes the form drew.
     * @param  array<int, string>    $characters What was typed, by edge id. Empty strings belong to
     *                                           the caller: an empty field means *unanswered* and the
     *                                           row goes, which is not this method's decision.
     * @return array<int, TypedValue>            By edge id, for every edge that had a type.
     */
    public function valuesFrom(array $edges, array $characters): array
    {
        if ($edges === []) {
            return [];
        }

        $types    = $this->typesOf($edges);
        $resolved = $this->settings->resolveForUseSites($edges);
        $values   = [];

        foreach ($edges as $edge) {
            $typed = $characters[$edge->id] ?? null;
            $type  = $types[$edge->id] ?? null;

            if ($typed === null || $type === null) {
                continue;
            }

            $converter = $this->readingConverter($resolved[$edge->id] ?? [], $type);

            // ⚠️ *`NotAValueOfThatType` travels on either way — from the converter or from the type.
            // Both refuse rather than coerce, and the boundary turns it into a `WP_Error` (`CD-10`).*
            $values[$edge->id] = $converter === null
                ? $type->valueFrom($typed)
                : $converter->written($typed, $type);
        }

        return $values;
    }

    /**
     * The converter that may read this field back, or `null` where none may.
     *
     * @param array<string, ResolvedSetting> $settings
     */
    private function readingConverter(array $settings, ?SimpleType $type): ?Converter
    {
        if ($this->converters === null || $type === null) {
            return null;
        }

        $name = ($settings[SettingKey::Converter->value] ?? null)?->value->text;

        if ($name === null || $name === '' || ! $this->converters->knows($name)) {
            return null;
        }

        $converter = $this->converters->byName($name);

        // ⚠️ *Three conditions and all three are load-bearing: registered, eligible for this type, and
        // **invertible**. Dropping the last one would let a rounding converter parse `8.50` back and
        // store the rounded number over the one that was there.*
        if (! $converter->isInvertible() || ! in_array($type, $converter->handles(), true)) {
            return null;
        }

        return $converter;
    }

    /**
     * The characters the converter in effect produces, or `null` where none is.
     *
     * ⚠️ **Resolved here and never in a renderer** ([D-159](../../../docs/NewConcept/90-decision-log.md),
     * [D-445](../../../docs/NewConcept/90-decision-log.md)): the descent knows the chain, the registry
     * and the type, so it runs the mapping and hands the result over.
     *
     * ⚠️ **A converter nobody registered is left as it is rather than refused.** *`byName()` throws, and
     * `NotAPossibleTarget` here would take down a whole form because one attribute names a converter a
     * data pack removed. **The value is still true** — it just is not mapped — so the honest failure is
     * to show it stored, the same way [R14b](../../../docs/NewConcept/30-renderer.md)'s fallback shows
     * rather than hides. Refusing belongs at the **write**, where the name is chosen
     * ([D-360](../../../docs/NewConcept/90-decision-log.md)), and that is `SettingDoesNotApply`'s job.*
     *
     * ⚠️ *A mapping that cannot read the value it was handed is the same case: `range_min` on a text,
     * `roman` on `4000`. It says so in its own output ({@see \Taxmod\Core\Converter\RomanNumeralConverter::shown()}),
     * which is where a reader can see it.*
     *
     * @param array<string, ResolvedSetting> $settings
     */
    private function convertedCharacters(
        TypedValue $value,
        array $settings,
        ?SimpleType $type,
    ): ?string {
        if ($this->converters === null || $value->isNothing() || $type === null) {
            return null;
        }

        $chosen = $settings[SettingKey::Converter->value] ?? null;
        $name   = $chosen?->value->text;

        if ($name === null || $name === '' || ! $this->converters->knows($name)) {
            return null;
        }

        $converter = $this->converters->byName($name);

        // ⚠️ *Eligibility is checked here too, not only when the name is chosen: a type can change
        // under a stored setting — an attribute repointed from `Integer` to `Text` — and running an
        // integer mapping over characters would invent a reading rather than refuse one.*
        if (! in_array($type, $converter->handles(), true)) {
            return null;
        }

        return $converter->shown($value);
    }

    /**
     * The free key an attribute uses to say **which label** its reference should show.
     *
     * ⚠️ **Free rather than reserved, and that is [D-364](90-decision-log.md)'s own ruling.** It
     * says the free-key mechanism *has a real job* — *`cols` and `rows` are free keys today and
     * correctly so … it is how one renderer draws* — and settles who writes them: **whoever writes
     * renderers, not whoever models.** A label role is the same kind of thing as `rows`: no record
     * answers *which role*, so by D-364's own test it is a setting and not an attribute, and by its
     * precedent it needs no place on the reserved list.
     *
     * ⚠️ **This is the setting [D-049](90-decision-log.md) promised and nobody had built.** It said
     * the choice of text *is a setting on the renderer naming the label role* — and the role was
     * nailed to `form` here, so no author could ask for `symbol` and a prefix could only ever read
     * `kilo` where `k` was wanted. *The owner found it by asking the one question that mattered:
     * **which field do we hang the renderer on?** The answer is the referring edge, and this is the
     * key it carries.*
     *
     * ⚠️ *[D-264](90-decision-log.md) wants a **pattern** here eventually — roles and fixed
     * characters, `symbol – form` — and a single role is its first step rather than a rival to it.*
     */
    public const LABEL_ROLE = 'label_role';

    /**
     * What the referenced nodes are called, for every reference in this batch, in one query
     * **per role** that anybody asked for.
     *
     * ⚠️ **Resolved before the descent begins** (D-159). A reference is drawn as its target's
     * label (D-105) and a renderer fetches nothing, so this is the only place the labels can come
     * from — and it is one query for the whole form rather than one per row, which is what `CD-7`
     * forbids and what made the legacy parts list slow.
     *
     * ⚠️ **Keyed by *edge*, not by target, because the role belongs to the edge.** The same node
     * reached from two attributes may want `symbol` in one and `form` in the other — *a parts list
     * showing `k` and a heading showing `kilo`* — so a map keyed by target could only hold one of
     * them and would silently give the second row the first one's answer.
     *
     * ⚠️ **Grouped by role rather than asked per edge.** `CD-7` bounds this at *one query per
     * distinct role*, which is at most a handful whatever the size of the form; asking per edge
     * would be the loop again, one level down and harder to see.
     *
     * @param  list<Relation>                                          $edges
     * @param  array<int, TypedValue>                                  $values
     * @param  array<int, array<string, \Taxmod\Core\Model\ResolvedSetting>> $resolved
     * @return array<int, string>                                      Keyed by the **edge's** id.
     */
    private function namesOfReferences(array $edges, array $values, array $resolved, string $locale): array
    {
        if ($this->labels === null) {
            return [];
        }

        // Which targets each role has to answer for — the role is read off the edge that points.
        $wanted = [];

        foreach ($edges as $edge) {
            $reference = ($values[$edge->id] ?? null)?->reference;

            if ($reference === null) {
                continue;
            }

            $role = $this->roleOf($resolved[$edge->id] ?? []);

            $wanted[$role->value][$reference][] = $edge->id;
        }

        $names = [];

        foreach ($wanted as $role => $targets) {
            $resolvedNames = $this->labels->forNodes(
                array_values($this->nodes->byIds(array_keys($targets))),
                SeededRole::from($role),
                $locale
            );

            foreach ($targets as $target => $edgeIds) {
                foreach ($edgeIds as $edgeId) {
                    // ⚠️ Absent stays absent: a dangling reference is drawn as a marked fault
                    // rather than as its id (D-363), and that decision is the renderer's to make.
                    if (isset($resolvedNames[$target])) {
                        $names[$edgeId] = $resolvedNames[$target];
                    }
                }
            }
        }

        return $names;
    }

    /**
     * Which label role this edge asked for, or the ordinary one.
     *
     * ⚠️ **An unknown role falls back rather than throwing.** The set is seeded
     * ([D-196](90-decision-log.md)) and a typo, an import or a pack could name something outside it;
     * refusing to draw the whole form over one misspelt setting would be the wrong trade. *It is
     * still visible as wrong, because the text that appears is the `form` label and not the one the
     * author meant.*
     *
     * @param array<string, \Taxmod\Core\Model\ResolvedSetting> $settings
     */
    private function roleOf(array $settings): SeededRole
    {
        $asked = ($settings[self::LABEL_ROLE] ?? null)?->value->text;

        return $asked === null ? SeededRole::Form : (SeededRole::tryFrom($asked) ?? SeededRole::Form);
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

        // ⚠️ **The abort, and it is the descent's job rather than a renderer's**
        // ([D-450](90-decision-log.md), [D-452](90-decision-log.md), [D-457](90-decision-log.md)): a
        // hidden placement is not drawn and **not enumerated**. *Before, a renderer returned an empty
        // string — which means it had already been asked, and for a composed value its members had
        // already been drawn and thrown away.*
        //
        // ⚠️ **The edge's `hide`, and deliberately not the target node's.** *That distinction is what
        // keeps [D-426](90-decision-log.md)'s fix: as a setting, `hide` on a **type** reached every
        // field of that type and blanked them all — measured twice. A field is one **placement** of a
        // type, so hiding the type must not hide the fields that point at it. **A node's own `hide`
        // stops the walk where the walk enters the node** — the tree, and a composed value's members —
        // not where something merely points at it.* Recorded as [OQ-118](91-open-questions.md), because
        // the concept says «render no further» and does not say which walk.
        $edges = array_values(array_filter($edges, static fn (Relation $edge): bool => ! $edge->hide));

        if ($edges === []) {
            return [];
        }

        $types    = $this->typesOf($edges);
        $resolved = $this->settings->resolveForUseSites($edges);
        $names    = $this->namesOfReferences($edges, $values, $resolved, $locale);
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
                purpose: $purpose,
                value: $value,
                settings: $settings,
                locale: $locale,
                level: $level,
                editable: $editable,
                fieldName: $fieldPrefix === '' ? '' : $fieldPrefix . '[' . $edge->id . ']',
                type: $type,
                surroundings: new Surroundings(
                    // ⚠️ By **edge**, not by target: the role that decided this text belongs to the
                    // edge, so two attributes pointing at one node can show `k` and `kilo`.
                    refersTo: $value->reference === null ? null : ($names[$edge->id] ?? null),
                    // ⚠️ **Already known, so it is handed over rather than looked up** (D-445). A
                    // reference with no simple type behind it is a reference to a record: `typeOf()`
                    // answers `node_ref` for a constant and a real type for a data type, so `null`
                    // here is the composed case — *and it is the summary renderer (D-106) that is
                    // missing, not a renderer that is mis-set.*
                    refersToARecord: $value->reference !== null && $type === null,
                ),
                shown: $this->convertedCharacters($value, $settings, $type),
            );

            $fields[] = new RenderedField(
                $edge,
                $type,
                $renderer->name(),
                $renderer->render($edge, $context),
                // Carried for the **layout**: R75 puts read-only values first, as context rather
                // than as something to fill in. A container must not resolve the chain again.
                $context->setting(SettingKey::ReadOnly->value)?->asBool() ?? SettingKey::ReadOnly->defaultSwitch()
            );
        }

        return $fields;
    }

    /**
     * A node drawn **as a value** — what a field of this type looks like, with this node's settings.
     *
     * ⚠️ **[D-430](../../../docs/NewConcept/90-decision-log.md), and it exists because the descent
     * takes edges while a type node has none.** The owner: *why no preview on the simple data types?*
     * The panel refused them for a reason that answers a different question — *only a node that can
     * hold records has something to preview* — which is right about **records** and wrong about
     * **fields**: a data type does not hold one, it **is** one.
     *
     * ⚠️ **No synthetic edge.** {@see RendererRegistry::chosenFor()} and {@see Renderer::render()}
     * already accept a `Node`, so nothing has to be invented to fit a signature — *a fake `Relation`
     * in the core to satisfy a parameter list is the kind of thing that later gets stored.*
     *
     * ⚠️ **It resolves the chain once and finds its own example.** The value is the node's resolved
     * `default`, which is the same third rung {@see previewValuesFor()} uses — *real data → rows
     * marked as test data → the type's sample.* A type has no records, so the first two cannot apply
     * and the third is the whole of it.
     *
     * ⚠️ *Returns `null` where the node is no simple type at all, so the surface can say so rather
     * than draw an empty box: a composed node or a model wants [row 36](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)'s
     * composite renderer, which does not exist.*
     */
    public function valueOfType(
        Node $node,
        Purpose $purpose,
        ?TypedValue $value = null,
        string $locale = '',
        Level $level = Level::Admin,
        bool $editable = true,
    ): ?RenderResult {
        $type = $this->typeOf($node, $this->nodes->byIds($node->ancestorIds()));

        if ($type === null) {
            return null;
        }

        $settings = $this->settings->resolve($this->settings->chainFor($node));

        if ($value === null || $value->isNothing()) {
            $value = ($settings[SettingKey::DefaultValue->value] ?? null)?->value ?? TypedValue::nothing();
        }

        // ⚠️ *The fallback rather than nothing, for the same reason a field falls back: a value must
        // never silently disappear, and the fallback marks itself (R14b).*
        $renderer = $this->renderers->chosenFor($node, $settings, $purpose, $type)
            ?? $this->renderers->fallback();

        return $renderer->render($node, new RenderContext(
            purpose: $purpose,
            value: $value,
            settings: $settings,
            locale: $locale,
            level: $level,
            editable: $editable,
            // ⚠️ **Nameless on purpose**: this is a preview, and a named field inside the settings
            // form would be submitted as if somebody had filled it in.
            fieldName: '',
            type: $type,
            surroundings: new Surroundings(),
        ));
    }

    

    /**
     * What a subject is called, in one locale — the labels panel.
     *
     * ⚠️ **The rows arrive resolved**, because a renderer fetches nothing ([D-159](90-decision-log.md))
     * and because *what is stored here* and *what the chain answers* are two different facts the
     * panel needs side by side ([D-020](90-decision-log.md)).
     *
     * @param  list<LabelSlot>       $slots
     * @param  list<Control>         $acts
     * @param  array<string, Section> $sections The locale picker, keyed `locale`.
     * @param  string                $formId   The page's form, when the panel is to be saved **with
     *                                         the page** rather than by a button of its own — then it
     *                                         draws no form and its fields name this one.
     */
    public function labelsPanelFor(
        Renderable $subject,
        array $slots,
        array $acts = [],
        ?Submission $submits = null,
        array $sections = [],
        Purpose $purpose = Purpose::Edit,
        string $locale = '',
        Level $level = Level::Admin,
        string $formId = '',
    ): RenderResult {
        return $this->renderers->byName(LabelsRenderer::NAME)->render(
            $subject,
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                locale: $locale,
                level: $level,
                surroundings: new Surroundings(
                    actions: $acts,
                    submits: $submits,
                    rows: $slots,
                    sections: $sections,
                    formId: $formId
                )
            )
        );
    }

    /**
     * Walked rows plus their drawn cells, as the walker wants them.
     *
     * ⚠️ *One place, because the modelling tree and the chooser both need it and the shape is
     * [D-367](90-decision-log.md)'s seam: **depth, cell, and whether it folds**. A second copy would
     * be the drift that decision exists to prevent — collapsing worked in the tree and not in the
     * trash, one function, two call sites, one forgotten ([D-346](90-decision-log.md)).*
     *
     * @param  list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $walked
     * @param  array<int, RenderResult> $cells
     * @param  array<int, string>       $toggles
     * @return list<DrawnRow>
     */
    private function drawnRows(array $walked, array $cells, array $toggles = [], ?int $highlight = null): array
    {
        $rows = [];

        foreach ($walked as $row) {
            $node = $row['node'];

            $rows[] = new DrawnRow(
                $row['depth'],
                $cells[$node->id],
                $row['hasChildren'],
                $row['collapsed'],
                $toggles[$node->id] ?? null,
                $node->id === $highlight
            );
        }

        return $rows;
    }

    /**
     * A **tree chooser** — the candidates walked, each row drawn as a pickable one.
     *
     * The owner, looking at the flat eighty-entry `<select>` the toolbar had: *the select would have
     * to be the tree chooser.* He is right, and it is the same walker the modelling tree uses —
     * [D-367](90-decision-log.md)'s *one walker, several cells* finally has its second cell.
     *
     * ```mermaid
     * flowchart LR
     *   W["walked rows"] --> C["chooser cell · a radio per node"]
     *   C --> T["the walker · nests them"]
     *   T --> D["dialog · or inline"]
     * ```
     *
     * ⚠️ **A row that cannot be picked is drawn and not left out.** Its child may be perfectly
     * pickable, so removing it would tear a hole in the hierarchy — the cell draws it as text without
     * a radio ({@see ChooserCellRenderer}).
     *
     * ⚠️ **Which chooser draws it is the ordinary choice**: the dialog by default
     * ([D-244](90-decision-log.md)) and the inline one where somebody asked for it, resolved through
     * the chain like any renderer setting.
     *
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $walked
     * @param list<int>  $unpickable Node ids that may not be chosen — its own subtree, the protected.
     * @param string     $chooser    Which of the two to draw it with.
     */
    public function chooserFor(
        array $walked,
        string $fieldName,
        ?int $chosen = null,
        array $unpickable = [],
        ?string $chosenName = null,
        string $nothingToChoose = '',
        string $chooser = DialogChooserRenderer::NAME,
        string $locale = '',
        Level $level = Level::Admin,
        // ⚠️ **What opens the dialog, and what confirms inside it** — both boundary markup, because
        // both are buttons with capabilities, titles and translated labels behind them. *The owner
        // wants the **move button** to be the opener: «button move with dialog tree chooser», then
        // «nicht inline». So the surface hands in its own trigger and the renderer stops guessing.*
        string $trigger = '',
        string $confirm = '',
    ): RenderResult {
        $barred = [];

        foreach ($unpickable as $id) {
            $barred[$id] = [new Control(ChooserCellRenderer::UNPICKABLE, '', '')];
        }

        $nodes = array_map(static fn (array $row): Node => $row['node'], $walked);

        // ⚠️ **One value for every cell, because the radio has to know which row is checked** — and
        // the checked row is a property of the *chooser*, not of the node. *`cellsFor()` hands every
        // cell the same context apart from its own settings, which is exactly what is wanted here.*
        $cells = $this->cellsForChoosing($nodes, $fieldName, $chosen, $barred, $locale, $level);
        $tree  = $this->renderers->byName(TreeRenderer::NAME)->render(
            $nodes[0] ?? Node::create(0, '', null),
            new RenderContext(
                purpose: Purpose::Edit,
                value: TypedValue::nothing(),
                locale: $locale,
                level: $level,
                surroundings: new Surroundings(rows: $this->drawnRows($walked, $cells)),
            )
        );

        return $this->renderers->byName($chooser)->render(
            $nodes[0] ?? Node::create(0, '', null),
            new RenderContext(
                purpose: Purpose::Edit,
                value: $chosen === null ? TypedValue::nothing() : TypedValue::ofReference($chosen),
                locale: $locale,
                level: $level,
                // ⚠️ **The field name reaches the chooser, and it has to.** {@see DialogChooserRenderer}
                // builds its switch id from the subject **and** this — and both choosers on the node
                // page are built from the *first walked node*, so without it the ids matched and **each
                // trigger opened both dialogs**. *Measured: `taxmod-dialog-402` twice.*
                fieldName: $fieldName,
                surroundings: new Surroundings(
                    refersTo: $chosenName,
                    sections: [
                        DialogChooserRenderer::CANDIDATES => new Section($nothingToChoose, $tree->markup),
                        DialogChooserRenderer::TRIGGER    => new Section('', $trigger),
                        DialogChooserRenderer::CONFIRM    => new Section('', $confirm),
                    ]
                ),
            )
        );
    }

    /**
     * Every candidate as a pickable cell.
     *
     * ⚠️ *Its own method rather than a flag on {@see cellsFor()}: that one draws rows of a tree a
     * person **works** in and hands each cell its own acts, and this one hands every cell the same
     * field name and the same chosen value. Two different jobs that happen to share a walker.*
     *
     * @param  list<Node>                $nodes
     * @param  array<int, list<Control>> $barred
     * @return array<int, RenderResult>
     */
    private function cellsForChoosing(
        array $nodes,
        string $fieldName,
        ?int $chosen,
        array $barred,
        string $locale,
        Level $level,
    ): array {
        if ($nodes === []) {
            return [];
        }

        $settings = $this->settings->resolveForNodes($nodes);
        $renderer = $this->renderers->byName(ChooserCellRenderer::NAME);
        $cells    = [];

        foreach ($nodes as $node) {
            $cells[$node->id] = $renderer->render(
                $node,
                new RenderContext(
                    purpose: Purpose::Edit,
                    value: $chosen === null ? TypedValue::nothing() : TypedValue::ofReference($chosen),
                    settings: $settings[$node->id] ?? [],
                    locale: $locale,
                    level: $level,
                    fieldName: $fieldName,
                    surroundings: new Surroundings(actions: $barred[$node->id] ?? []),
                )
            );
        }

        return $cells;
    }

    /**
     * One record as a block — heading, its drawn form, its acts.
     *
     * ⚠️ **The form is drawn here and placed there**: the descent draws the fields and
     * {@see RecordRenderer} frames them.
     *
     * ⚠️ **This is how it was built and not what any decision requires** — the owner asked *«who told
     * you a renderer has no access to the registry?»* and the answer was **nobody.** *[D-159](90-decision-log.md)
     * says the narrower thing: «the descent has two inputs, **both loaded before it starts**», «a
     * descent that fetches per edge is N+1 by construction» and «the renderer never writes». **A
     * registry lookup is neither a fetch nor a write**, so nothing decided forbids a renderer from
     * descending. Three docblocks claimed it did, citing D-159, and then got quoted back as though
     * D-159 had said it — `PR-10`'s dangling rule, with a citation to make it look agreed.*
     *
     * @param list<Relation>         $edges  The model's attributes, in the order they are shown.
     * @param array<int, TypedValue> $values What this record holds, keyed by edge id.
     * @param list<Control>          $acts
     */
    public function recordAsBlock(
        Node $model,
        array $edges,
        array $values,
        string $title,
        array $acts = [],
        ?Submission $submits = null,
        string $fieldPrefix = '',
        string $diagnostic = '',
        bool $developerMode = false,
        Purpose $purpose = Purpose::Edit,
        string $locale = '',
        Level $level = Level::Admin,
    ): RenderResult {
        $sections = [
            RecordRenderer::FORM => new Section(
                $title,
                $this->nodeAsForm($model, $edges, $values, $purpose, $fieldPrefix, $locale, $level)->markup
            ),
        ];

        if ($diagnostic !== '') {
            $sections[RecordRenderer::DIAGNOSTIC] = new Section('', $diagnostic);
        }

        return $this->renderers->byName(RecordRenderer::NAME)->render(
            $model,
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                locale: $locale,
                level: $level,
                surroundings: new Surroundings(actions: $acts, submits: $submits, sections: $sections),
                developerMode: $developerMode,
            )
        );
    }

    

    /**
     * Draw a node's attributes as rows — one renderer per attribute, the subject being the **edge**.
     *
     * ⚠️ **The attribute table was the last hand-built markup on the detail page**, which `R1`
     * forbids: *everything that is displayed must be a renderer.* The owner found it by asking for
     * the one thing it could not do — *the name of the attributes should be changeable*
     * ([D-376](90-decision-log.md)).
     *
     * ```mermaid
     * flowchart LR
     *   E["each attribute edge"] --> C["its multiplicity, drawn"]
     *   E --> T["its target's name"]
     *   E --> A["what may be done, from the boundary"]
     *   C & T & A --> R["the attribute renderer · one row"]
     * ```
     *
     * ⚠️ **`editable` carries *declared here*, and it is not a second fact.** An attribute may be
     * renamed exactly where it is declared, so the flag the renderer reads to decide *field or text*
     * is the same one that decides *own or inherited*. Two fields for one truth would drift.
     *
     * ⚠️ **The multiplicity is drawn by the settings side rather than built here** — it is an
     * ordinary setting on the edge ([D-351](90-decision-log.md)), and a second select composed in
     * this method would be the same control twice. *That is precisely the defect D-376 records: the
     * hand-built one had been posting to a field name nobody read since the settings panel moved.*
     *
     * @param  list<Relation>                  $edges     The attributes, in the order shown.
     * @param  array<int, list<Control>>       $actions   What may be done, keyed by **edge** id.
     * @param  array<int, Submission>          $submits   Where those go, keyed by edge id.
     * @param  int                             $declaredBy The node whose page this is — an
     *                                                    attribute is editable only on the node that
     *                                                    declares it.
     * @param  array<int, string>              $targetHrefs Where a target node is reached, keyed by
     *                                                    **node** id — not by edge id, because two
     *                                                    attributes pointing at one node share the
     *                                                    address.
     * @return list<RenderedField>
     */
    public function fieldRowsFor(
        array $edges,
        int $declaredBy,
        array $actions = [],
        array $submits = [],
        string $namePrefix = '',
        string $settingPrefix = '',
        string $locale = '',
        Level $level = Level::Admin,
        // ⚠️ *Hier standen `$settingActs`, `$settingSubmits` und `$settingsTitle` — die Zutaten des
        // Einstellungsblocks je Zeile, der mit [D-520](../../../docs/NewConcept/90-decision-log.md)
        // entfallen ist. **Sie waren danach reine Mitläufer**: der Aufrufer baute sie, die Methode nahm
        // sie an, und niemand las sie mehr.*
        array $targetHrefs = [],
    ): array {
        if ($edges === []) {
            return [];
        }

        $renderer = $this->renderers->byName(FieldRowRenderer::NAME);
        $resolved = $this->settings->resolveForUseSites($edges);
        $targets  = $this->nodes->byIds(array_map(static fn (Relation $e): int => $e->toId, $edges));

        // ⚠️ One query for the whole table, not one per row (`CD-7`) — and through the ordinary
        // label walk, so an attribute's target reads the same here as it does anywhere else.
        $names = $this->labels === null
            ? []
            : $this->labels->forNodes(array_values($targets), SeededRole::Form, $locale);

        $rows = [];

        foreach ($edges as $edge) {
            $settings = $resolved[$edge->id] ?? [];

            // The multiplicity, drawn once by the settings side and handed to the row.
            $configured = [];

            // ⚠️ **The row's controls name the row's form**, because a `<tr>` cannot be wrapped in
            // one — see {@see FieldRowRenderer::formFor()}. Without it the multiplicity select sat
            // outside every form and submitted nothing.
            foreach ($this->settingsFor($edge, $settings, Purpose::Edit, $settingPrefix, $locale, $level, [], FieldRowRenderer::formFor($edge)) as $drawn) {
                $configured[$drawn->key] = $drawn;
            }

            $context = new RenderContext(
                purpose: Purpose::Edit,
                value: TypedValue::nothing(),
                settings: $settings,
                locale: $locale,
                level: $level,
                editable: $edge->fromId === $declaredBy,
                fieldName: $namePrefix === '' ? '' : $namePrefix . '[' . $edge->id . ']',
                surroundings: new Surroundings(
                    refersTo: $names[$edge->toId] ?? null,
                    actions: $actions[$edge->id] ?? [],
                    // ⚠️ **The target's address, so the row can be a way *to* it.** The owner,
                    // 2026-08-26: *should have a jump link to the node.* Reading a model meant
                    // finding `BOM Position` in the tree by eye.
                    //
                    // ⚠️ *Keyed by the **target's** id and handed in, because a URL is a boundary
                    // fact (`CD-1`) and one lookup per row would be `CD-7`'s loop. The screen
                    // builds it from the same method the tree rows use.*
                    href: $targetHrefs[$edge->toId] ?? null,
                    submits: $submits[$edge->id] ?? null,
                    configured: $configured,
                    // ⚠️ **The same panel as a node's, drawn here and placed there** — so the attribute
                    // row cannot grow a settings list of its own.
                    //
                    // ⚠️ **The reason given here used to be «a renderer cannot call another renderer
                    // (D-159)», and that rule does not exist.** *[D-452](../../../docs/NewConcept/90-decision-log.md)
                    // withdrew it — [R5](../../../docs/NewConcept/30-renderer.md) says the opposite in
                    // the owner's own words, *«a renderer may work with trees and **call other
                    // renderers**»*, and D-159 says only that the descent's inputs are loaded before it
                    // starts. **The withdrawal reached two docblocks out of three and this was the
                    // third** — the same shape [D-469](../../../docs/NewConcept/90-decision-log.md)
                    // measured for documents, in code.*
                    //
                    // ⚠️ *The arrangement stands on its own merit and needs no rule: the panel is drawn
                    // **once** at this level and placed in every row, so there is one place that knows
                    // what a settings panel looks like. That is `R1`, not a prohibition.*
                    // ⚠️ **Hier stand der Einstellungsblock je Feldzeile, und er ist weg.** *Der
                    // Eigentümer: «wenn ich Settings unter einem Field aufklappe, habe ich immer noch
                    // den Settings-Renderer — kannst du den mal auskommentieren?» **Nach
                    // [D-518](../../../docs/NewConcept/90-decision-log.md) ist er eine Doppelung**:
                    // dieselben Angaben stehen jetzt als Feldzeilen im Settings-Block, gezeichnet vom
                    // Feldzeilen-Renderer — und `R1` erlaubt **eine** Art, eine Sache zu zeichnen.*
                    //
                    // ⚠️ *Die Mehrfachheit bleibt: sie hängt an `surroundings->configured` und hat ihre
                    // eigene Spalte in der Zeile, nicht diesen Block.*
                    sections: []
                ),
            );

            $rows[] = new RenderedField(
                $edge,
                null,
                $renderer->name(),
                $renderer->render($edge, $context),
                false
            );
        }

        return $rows;
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
        Identity $node,
        array $resolved,
        Purpose $purpose = Purpose::Display,
        string $fieldPrefix = '',
        string $locale = '',
        Level $level = Level::Admin,
        array $choices = [],
        string $formId = '',
    ): array {
        // ⚠️ **A use site is configured too, and its type is its target's.** [C8](../../../docs/NewConcept/10-domain-core.md)
        // gives an edge settings of its own and [D-091](90-decision-log.md) resolves them the same
        // way; what differs is only where the type comes from — a node *is* the type, an edge
        // *points* at it. *Until the attribute renderer wanted the multiplicity drawn, nothing had
        // ever asked this method about an edge, so the narrower signature had never been wrong.*
        $subject = $node instanceof Relation ? $this->typeAt($node) : $this->typeOfNode($node);

        // ⚠️ **Every key that applies, not only the ones somebody wrote.** The owner, on an `int`
        // node whose chain was empty: *the settings that belong firmly to the data type — min,
        // max, step — should be shown as such.* An unset key becomes a row with an empty control
        // and `setHere = false`, which is the truth about it: nothing along the chain has said.
        //
        // ⚠️ **`multiplicity` applies only to an edge** and is the one key that does (D-351) — a
        // node describes a thing, and a thing has no multiplicity.
        foreach (SettingKey::applyingTo($subject, $node instanceof Relation) as $key) {
            $resolved[$key->value] ??= new ResolvedSetting(
                $key->value,
                TypedValue::nothing(),
                0,
                false
            );
        }

        ksort($resolved);

        $drawn = [];

        // ⚠️ **`hide` puts the renderer out of force** ([D-399](90-decision-log.md)). The owner:
        // *`hide` would have to override the renderer — so **no renderer is valid**, because it is
        // not used here; the field should then be greyed out.* **This is the honest answer to an
        // empty renderer**, and it narrows [D-352](90-decision-log.md) rather than breaking it: a
        // renderer is always resolved *where something is drawn*, and a hidden field draws nothing.
        //
        // ⚠️ *Read with `($a['x'] ?? null)?->y` and never `$a['x']?->y` — the second warns on a
        // missing key, which is a bug that was written two files from this line on 2026-08-26.*
        // ⚠️ **Read off the subject now** ([D-457](90-decision-log.md)): `hide` is a column on
        // {@see \Taxmod\Core\Model\Identity}, so there is no resolved setting to ask.
        //
        // ⚠️ **And [D-399](90-decision-log.md)'s second half is narrowed away by [D-448](90-decision-log.md)**:
        // *a hidden **node** has a renderer choice like any other, so the greying lost its ground.*
        // What survives is the case this line was written for — **a hidden placement draws nothing,
        // so «which renderer draws it» has no answer to force.*
        // ⚠️ *`$hidden` stood here, read for [D-399](90-decision-log.md)'s greying. With
        // [D-448](90-decision-log.md) the greying is gone, and so is its reader — a variable that
        // decides nothing is the dead code `CLAUDE.md` forbids outright.*

        foreach ($resolved as $key => $setting) {
            $engineKey = SettingKey::tryFrom($key);
            $shape     = $engineKey?->shape() ?? SettingShape::Words;
            $type      = $engineKey?->typeFor($subject);

            // ⚠️ **A choice is drawn now, by the choice renderer** — it was one of three rows that
            // came back undrawn, and the honest reason was *a chooser wants a set and none is
            // built*. It is built, so the reason is gone. *The other two remain honest: a free key
            // has no type the engine can know, and a borrowing key on a subject with no type of its
            // own has no shape to be drawn in.*
            if ($engineKey !== null && $shape->isAChoice()) {
                $drawn[] = $this->drawChoice($node, $engineKey, $shape, $setting, $purpose, $fieldPrefix, $locale, $level, $subject, $choices, $formId);

                continue;
            }

            // A free key, or a borrowed type the subject does not have. Nothing is drawn, and the
            // caller is told which of the two it is by the shape.
            if ($engineKey === null || $type === null) {
                $drawn[] = new RenderedSetting($key, $shape, $type, $setting, null, null, $subject);

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
                $renderer->name(),
                $subject
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
    /**
     * What a preview should be filled with, when nothing has been entered.
     *
     * ⚠️ **[D-160](90-decision-log.md) is explicit that this exists**: *the preview loads the test
     * data and renders that … **defaults remain the fallback** where no pack covers the model.* The
     * owner's reason is the whole point of a preview — *a form of empty fields shows that the
     * structure exists, a filled one shows whether it **reads***.
     *
     * ⚠️ **This is not «no record is the default record».** That was the tempting version and
     * [D-160](90-decision-log.md) turned it down: a missing value is **not answered**
     * ([D-232](90-decision-log.md)), and a real form must keep showing it that way. *So the
     * substitution happens here, for the preview only, and never on the way into storage.*
     *
     * ⚠️ **A default is a `choosing` setting, so it may be anything the type permits**
     * ([D-312](90-decision-log.md)) — which is why this reads the resolved chain rather than the
     * edge: a default written at the type is exactly the one a preview should show.
     *
     * @param  list<Relation>                                    $edges
     * @param  array<int, array<string, ResolvedSetting>>         $resolved Settings per edge id.
     * @param  array<int, TypedValue>                             $held     What a record holds, if any.
     * @return array<int, TypedValue>                                       Keyed by edge id.
     */
    /**
     * What a **non-persistent** attribute is worth for one particular node.
     *
     * ```mermaid
     * flowchart LR
     *   K["kilo"] -->|"default at path «exponent»"| V["3"]
     *   P["Prefixes declares exponent · persistent = false"] --> K
     * ```
     *
     * ⚠️ **This is the first consumer of `settings.path`** ([D-413](90-decision-log.md)) and it is
     * what makes [D-378](90-decision-log.md) work at last. That decision made a prefix's exponent an
     * **attribute** rather than a reserved key, so that *whoever hangs under `Prefixes` has one and
     * nobody else does* — and its value lives as a `default`, because
     * [D-026](90-decision-log.md) says *at model level there are no values, only defaults*.
     *
     * ⚠️ **Measured broken on 2026-08-26 and this is the repair.** The value had been written at the
     * **empty** path, meaning *kilo's own default*, and the attribute could never see it: a use site
     * resolves from its **target's** chain, and `kilo` is not in that chain. *So the question has to
     * be asked of the node, at the attribute's path — which is exactly what the column was added
     * for.*
     *
     * ⚠️ *Nothing is invented when nothing is there. A missing row means this node says nothing about
     * that attribute, which is a different fact from «zero» and is returned as such.*
     */
    public function nonPersistentValue(Node $node, Relation $edge): ?TypedValue
    {
        $resolved = $this->settings->resolve(
            $this->settings->chainFor($node),
            (string) $edge->id
        );

        $default = $resolved[SettingKey::DefaultValue->value] ?? null;

        return $default === null || $default->value->isNothing() ? null : $default->value;
    }

    /**
     * Which of a node's records a preview draws from — **real data before a row marked as test data**.
     *
     * ⚠️ **The middle rung of the decided order, and it was missing rather than deferred.**
     * [D-028](90-decision-log.md): *«Testdaten sind gewöhnliche Daten, gekennzeichnet. Zeilen können
     * als Testdaten markiert werden; die Vorschau zeichnet den Knoten in der Datenansicht über diesen
     * Zeilen und fällt auf die Vorgaben zurück, wo keine da sind.»* The order is
     * **real data → rows marked as test data → the type's sample**, and until schema 13 the column
     * did not exist, so the surface took whichever record came first by id.
     *
     * ```mermaid
     * flowchart LR
     *   A["records of the node"] --> R{"any not marked?"}
     *   R -- yes --> N["the first unmarked one"]
     *   R -- no --> T["the first marked one"]
     *   R -- none at all --> D["null · the defaults draw"]
     * ```
     *
     * ⚠️ **It chooses a record, it does not blend two.** *Taking the real values and topping them up
     * from a test row would answer «which record does a preview show» twice in one preview, and that
     * question is still nobody's — {@see previewValuesFor()} tops up from the **defaults**, which are
     * a fact about the type rather than about a second row.*
     *
     * ⚠️ **Order within a rung is left as it arrives**, so *which* real record is still the caller's
     * first — the unanswered half of the same question, and this method does not pretend to close it.
     *
     * @param  list<NodeRecord> $records
     */
    public function previewRecordAmong(array $records): ?NodeRecord
    {
        $marked = null;

        foreach ($records as $record) {
            if (! $record->isTest) {
                return $record;
            }

            $marked ??= $record;
        }

        return $marked;
    }

    public function previewValuesFor(array $edges, array $resolved, array $held = []): array
    {
        $values = [];

        foreach ($edges as $edge) {
            // ⚠️ **Real data wins, and the rung between it and the defaults is
            // {@see previewRecordAmong()}** — the caller has already chosen *which* record the
            // values came from, so what is left here is the decided *«fällt auf die Vorgaben
            // zurück, wo keine da sind»* of [D-028](90-decision-log.md).
            if (isset($held[$edge->id]) && ! $held[$edge->id]->isNothing()) {
                $values[$edge->id] = $held[$edge->id];

                continue;
            }

            $default = $resolved[$edge->id][SettingKey::DefaultValue->value] ?? null;

            if ($default !== null && ! $default->value->isNothing()) {
                $values[$edge->id] = $default->value;
            }
        }

        return $values;
    }

    /**
     * Which edges a preview may leave out, and which it must draw dead rather than absent.
     *
     * ⚠️ **This is what the owner is after** — *we need the preview to fix the flag and renderer
     * concept errors.* `hide` and `read_only` are stored, resolved and had **no surface that showed
     * them doing anything**: a settings panel draws the switch, not its effect. **Here they have
     * one.**
     *
     * ```mermaid
     * flowchart LR
     *   H["hide = true"] --> G["gone from the preview"]
     *   R["read_only = true"] --> D["drawn, not editable"]
     * ```
     *
     * ⚠️ **The two are not variations of one thing.** `hide` removes the row; `read_only` keeps it
     * and refuses the edit — *a computed value a reader should see and nobody may type*. Collapsing
     * them would make a read-only field invisible, which is the opposite of what it is for.
     *
     * @param  list<Relation>                            $edges
     * @param  array<int, array<string, ResolvedSetting>> $resolved
     * @return array{shown: list<Relation>, hidden: list<Relation>, fixed: list<int>}
     */
    public function previewVisibilityFor(array $edges, array $resolved): array
    {
        $shown  = [];
        $hidden = [];
        $fixed  = [];

        foreach ($edges as $edge) {
            $keys = $resolved[$edge->id] ?? [];

            // ⚠️ `($a['x'] ?? null)?->y` and **not** `$a['x']?->y` — the second is a warning on a
            // missing key, which is a bug this file's own docblock warns about and which was written
            // two files away on 2026-08-26.
            // ⚠️ *The edge's own column ([D-457](90-decision-log.md)) — no chain, no resolution.*
            if ($edge->hide) {
                $hidden[] = $edge;

                continue;
            }

            $shown[] = $edge;

            if ((($keys[SettingKey::ReadOnly->value] ?? null)?->value->asBool() ?? SettingKey::ReadOnly->defaultSwitch()) === true) {
                $fixed[] = $edge->id;
            }
        }

        return ['shown' => $shown, 'hidden' => $hidden, 'fixed' => $fixed];
    }
    /**
     * The detail head — three labelled rows, drawn by {@see HeadRenderer}.
     *
     * ⚠️ **The screen states the facts and the renderer decides the shape**, which is the same seam
     * every other panel uses ([D-393](90-decision-log.md)): the buttons, the constants and the name
     * field are boundary matter — they carry nonces, capabilities and translated labels — and how
     * they are arranged is not.
     *
     * @param array<string, Section> $rows Keyed by `HeadRenderer::ACTION` / `SYSTEM` / `NAME_ROW`.
     */
    public function headFor(
        Node $node,
        array $rows,
        Purpose $purpose = Purpose::Edit,
        string $locale = '',
        Level $level = Level::Admin,
    ): RenderResult {
        return $this->renderers->byName(HeadRenderer::NAME)->render(
            $node,
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                settings: [],
                locale: $locale,
                level: $level,
                surroundings: new Surroundings(sections: $rows)
            )
        );
    }
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

        $container = $this->containerFor($node, $purpose);

        return $container->render(
            $node,
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                locale: $locale,
                level: $level,
                editable: $editable,
                surroundings: new Surroundings(parts: $parts),
            )
        );
    }

    /**
     * Which container lays out a node's members — the chain, then the structural default.
     *
     * ⚠️ **This existed as a sentence in {@see nodeAsForm()}'s docblock and not as code.** *That
     * docblock said «the container is chosen the same way a field's renderer is — the chain, then the
     * structural default», while the line beneath it read `byName(FormRenderer::NAME)` and asked
     * nothing. **`form` worked only because it was the only one**, and the moment
     * {@see CompactRenderer} arrived the owner could choose it and nothing changed on screen.*
     *
     * ⚠️ *Same shape as `hide` stored-and-never-read ([D-396](90-decision-log.md)) and `label_role`
     * storable-and-unreachable — **written, decided, and not built**. It keeps happening at the seam
     * where a setting is offered before anything consumes it, and the owner keeps being the one who
     * finds it: «ich kann irgendwie hier noch nichts richtig aufsetzen».*
     *
     * ⚠️ **Eligibility is asked, not re-invented** ([D-481](90-decision-log.md)): a name only counts
     * if `eligibleFor()` would have offered it for this node. *So the read side and the write side
     * ask one question, and a name that is no longer offerable falls back instead of drawing nothing.*
     *
     * ⚠️ *The fallback is {@see FormRenderer} and deliberately **not** the registry's fallback: a
     * container that cannot lay out its members would drop them, and losing a person's fields is
     * worse than laying them out plainly. [R14b](30-renderer.md)'s «the fallback marks itself» is
     * about a **value**, not about a frame.*
     */
    private function containerFor(Node $node, Purpose $purpose): Renderer
    {
        $chosen = ($this->settings->resolve($this->settings->chainFor($node))[SettingKey::Renderer->value] ?? null)
            ?->value
            ->text;

        if ($chosen === null || $chosen === '') {
            return $this->renderers->byName(FormRenderer::NAME);
        }

        foreach ($this->renderers->eligibleFor($node, $this->typeOfNode($node), $purpose) as $one) {
            if ($one->name() === $chosen) {
                return $one;
            }
        }

        return $this->renderers->byName(FormRenderer::NAME);
    }

    /**
     * A tree's worth of nodes, each drawn by the cell — **three queries whatever the depth**.
     *
     * ⚠️ **The batching is the point, not an optimisation.** A cell draws the node's icon (D-251),
     * which is a setting on the chain — so asking per row would be a walk per row, and `CD-7`
     * forbids the loop. One query for every node's settings, then every cell drawn in memory.
     *
     * ⚠️ **No labels are fetched, because the tree shows the node's own name**
     * ([D-369](90-decision-log.md)). That closed [OQ-091](91-open-questions.md)'s second half and
     * saved a query with it.
     *
     * ⚠️ **Which cell is a parameter, and that is [D-367](90-decision-log.md)'s whole point:** the
     * modelling tree, the chooser and the trash walk one hierarchy and draw the node differently.
     *
     * @param  list<Node>                    $nodes
     * @param  array<int, list<Control>>     $actions What can be done to each node, **described**
     *                                               — the renderer builds the buttons.
     * @param  array<int, string>            $hrefs   Where each node is reached, per node id.
     * @param  array<int, Submission>        $submits Where its controls submit to, with the nonce.
     * @return array<int, RenderResult>      Keyed by node id.
     */
    public function cellsFor(
        array $nodes,
        array $actions = [],
        array $hrefs = [],
        array $submits = [],
        string $cell = TreeNodeRenderer::NAME,
        string $locale = '',
        Level $level = Level::Admin,
        bool $developerMode = false,
        array $hidden = [],
    ): array {
        if ($nodes === []) {
            return [];
        }

        $settings = $this->settings->resolveForNodes($nodes);
        $renderer = $this->renderers->byName($cell);

        $cells = [];

        foreach ($nodes as $node) {
            $cells[$node->id] = $renderer->render(
                $node,
                new RenderContext(
                    purpose: Purpose::Display,
                    value: TypedValue::nothing(),
                    settings: $settings[$node->id] ?? [],
                    locale: $locale,
                    level: $level,
                    editable: false,
                    surroundings: new Surroundings(
                        actions: $actions[$node->id] ?? [],
                        href: $hrefs[$node->id] ?? null,
                        submits: $submits[$node->id] ?? null,
                        // ⚠️ *Prepared, not asked: a cell draws a **node** and `hide` sits on its
                        // **edge** ([D-467](90-decision-log.md), [D-445](90-decision-log.md)).*
                        hidden: $hidden[$node->id] ?? false
                    ),
                    // ⚠️ **A circumstance and not a setting** (D-389): developer mode is a fact about
                    // the installation, so the boundary reads it from a WordPress option and hands it
                    // in — it never travelled the settings chain, where it could differ per branch.
                    developerMode: $developerMode,
                )
            );
        }

        return $cells;
    }

    /**
     * A whole tree — the walker over the cells.
     *
     * ⚠️ **Two renderers, one call** ([D-367](90-decision-log.md)): every node goes through the
     * **cell**, and the **walker** nests what came back. Which cell is a parameter, because the
     * modelling tree, the chooser and the trash draw a node differently and must not each grow
     * their own walker — that is the fault [D-346](90-decision-log.md) demonstrated.
     *
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $walked
     * @param array<int, list<Control>>  $actions
     * @param array<int, string>         $hrefs
     * @param array<int, Submission>     $submits
     * @param array<int, string>         $toggles Where folding a row leads, per node id.
     */
    public function treeFor(
        array $walked,
        array $actions = [],
        array $hrefs = [],
        array $submits = [],
        array $toggles = [],
        ?int $highlight = null,
        string $cell = TreeNodeRenderer::NAME,
        string $locale = '',
        Level $level = Level::Admin,
        bool $developerMode = false,
    ): RenderResult {
        if ($walked === []) {
            return RenderResult::of('');
        }

        $nodes = array_map(static fn (array $row): Node => $row['node'], $walked);
        // ⚠️ *The rows already carry it — {@see \Taxmod\Core\Service\Tree::rowsUnder()} reads it off
        // the inheritance edges it loads anyway ([D-467](90-decision-log.md)). No parameter at the
        // boundary, and no query here.*
        $hidden = [];

        foreach ($walked as $row) {
            $hidden[$row['node']->id] = $row['hidden'] ?? false;
        }

        $cells = $this->cellsFor($nodes, $actions, $hrefs, $submits, $cell, $locale, $level, $developerMode, $hidden);

        $rows = $this->drawnRows($walked, $cells, $toggles, $highlight);

        return $this->renderers->byName(TreeRenderer::NAME)->render(
            $nodes[0],
            new RenderContext(
                purpose: Purpose::Display,
                value: TypedValue::nothing(),
                locale: $locale,
                level: $level,
                editable: false,
                surroundings: new Surroundings(rows: $rows),
            )
        );
    }

    /**
     * A node as a page — the frame of [R20a](30-renderer.md#r20a--the-detail-view-is-not-a-special-screen).
     *
     * ⚠️ **The order is not a parameter.** It lives in {@see PageSlot} because
     * [R20a](30-renderer.md) decided it and wrote down why: *the owner walked that order out loud as
     * the sequence in which a person actually works on a node, and it is written down so a rebuild
     * does not reshuffle it for looks.* The caller says **what** goes in a slot, never **where**.
     *
     * @param array<string, Section> $sections Keyed by {@see PageSlot}'s values.
     */
    public function nodeAsPage(
        Node $node,
        array $sections,
        Purpose $purpose = Purpose::Edit,
        string $locale = '',
        Level $level = Level::Admin,
    ): RenderResult {
        return $this->renderers->byName(NodeRenderer::NAME)->render(
            $node,
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                locale: $locale,
                level: $level,
                surroundings: new Surroundings(sections: $sections),
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
     * Draw one choosing setting through the choice renderer.
     *
     * ⚠️ **The set comes from here and never from the renderer** ([D-159](90-decision-log.md)). Two
     * shapes, two sources, and they are genuinely different kinds of set: `OneOfFour` is the closed
     * list of [D-351](90-decision-log.md), fixed forever; `ARegisteredName` is whatever the registry
     * answers to, which grows with every renderer added ([R14](30-renderer.md)).
     *
     * ⚠️ **A setting may always be left unset, so *nothing* is always an outcome here.** That is not
     * [R29](30-renderer.md)'s multiplicity rule being ignored — it is that rule applied to a
     * setting: settings are **sparse** ([D-015](90-decision-log.md)), an absent one means *nothing
     * along the chain has said*, and there is no such thing as a mandatory setting. *R29's `1` and
     * `1..*` cases belong to a **value**, where the concept placed them.*
     *
     * ⚠️ *`converter` therefore arrives as an empty set and draws as a disabled control — the honest
     * state, because [D-219](90-decision-log.md) decided converters and none is built. R28–R32 asked
     * for exactly that rather than an empty box that looks fillable.*
     */
    private function drawChoice(
        Renderable $subject,
        SettingKey $key,
        SettingShape $shape,
        ResolvedSetting $setting,
        Purpose $purpose,
        string $fieldPrefix,
        string $locale,
        Level $level,
        ?SimpleType $subjectType = null,
        array $choices = [],
        string $formId = '',
    ): RenderedSetting {
        $renderer = $this->renderers->byName(ChoiceRenderer::NAME);
        $options  = [];

        // ⚠️ **The multiplicity is never nothing** (D-379): the owner — *multiplicity may not be
        // empty, the default is `0..1`* — so the chooser offers no blank option and an unset setting
        // arrives already reading the standard. *Every other setting may be left unsaid, which is
        // what makes settings sparse (D-015); this one has a meaning when unsaid instead.*
        $mayBeNothing = $shape !== SettingShape::OneOfFour;

        if ($shape === SettingShape::OneOfFour) {
            $setting = new ResolvedSetting(
                $setting->key,
                TypedValue::ofText(Multiplicity::fromSetting($setting->value->text)->value),
                $setting->fromOwnerId,
                $setting->setHere
            );

            foreach (Multiplicity::cases() as $one) {
                // ⚠️ **Ein `bool` bekommt keine Untergrenze null angeboten** ([D-412](../../../docs/NewConcept/90-decision-log.md)),
                // auf sein Wort: *«ein `bool` hat genau zwei Zustände … und ein `bool` darf keine
                // Multiplizität von null haben.»* `0..1` hiesse «vielleicht wahr, vielleicht falsch,
                // vielleicht keins» — und ein Drittes gibt es nicht.
                //
                // ⚠️ *Gefragt wird {@see Multiplicity::requiresOne()} und **nicht** eine Liste der
                // beiden Werte: die Frage «verlangt das eine Antwort» ist genau die, die hier
                // gestellt wird, und sie hat schon eine Stelle. Eine zweite Aufzählung daneben wäre
                // dieselbe Tatsache doppelt.*
                //
                // ⚠️ *Das ist `R28` — **ein Steuerelement bietet nur echte Wahlen an**. Die
                // Speicherseite kannte die Regel längst: der Schalter schreibt eine verborgene `0`
                // neben die Ankreuzbox ([D-370](../../../docs/NewConcept/90-decision-log.md)), damit
                // ein leeres Kästchen `false` sendet statt nichts. **Nur der Wähler log noch.***
                if ($subjectType === SimpleType::Bool && ! $one->requiresOne()) {
                    continue;
                }

                // ⚠️ The **notation**, deliberately not translated — `0..1` is not English and
                // survives a locale change without a label.
                $options[$one->value] = $one->notation();
            }
        }

        // ⚠️ **A set the boundary knows takes precedence over anything worked out here**
        // ([D-390](90-decision-log.md)). Which **icons** an installation offers is a boundary fact
        // (`CD-1`) — the core cannot list Dashicons — so the options are handed in and this places
        // them. *That is the same seam as `Control`: the boundary states, the core composes.*
        if ($choices[$key->value] ?? null) {
            $options      = $choices[$key->value];
            $mayBeNothing = true;
        }

        // ⚠️ **The converter's set comes from the converter registry, and it may be empty.** *An empty
        // set draws as a disabled control, which R28–R32 asked for over an empty box that looks
        // fillable — so this branch does not need to special-case «none registered»: no options is
        // already the honest state.*
        //
        // ⚠️ **And unlike the renderer below, *nothing* stays an outcome.** No converter means the value
        // is shown as it is stored ([R33b](30-renderer.md#r33b--several-are-eligible-exactly-one-is-in-effect)),
        // so there is no default to force and `mayBeNothing` is left alone. *Forcing one here would map
        // every number in the installation the moment a converter was registered.*
        if ($shape === SettingShape::ARegisteredName && $key === SettingKey::Converter && $this->converters !== null) {
            $forType = $subject instanceof Relation ? $this->typeAt($subject) : $this->typeOfNode($subject);

            foreach ($this->converters->eligibleFor($forType) as $one) {
                $options[$one->name()] = $one->name();
            }
        }

        if ($shape === SettingShape::ARegisteredName && $key === SettingKey::Renderer) {
            $eligible = $subject instanceof Relation
                ? $this->choicesFor($subject, $purpose)
                : $this->choicesForNode($subject, $purpose);

            foreach ($eligible as $one) {
                $options[$one->name()] = $one->name();
            }

            // ⚠️ **A renderer is never nothing, and the control must say which one is in force**
            // ([R33c](30-renderer.md#r33c--automatic-is-a-default-never-a-fact), [D-352](90-decision-log.md)).
            // The owner: *the renderer must always be set, we agreed that.* What was agreed is the
            // sharper thing — **it is always *resolved***, because *which one is the default is a fact
            // the registry holds* — and R33c adds that **an automatic choice must be visible**. The
            // panel was showing an empty option as selected on `decimal`, `text` and `bool` while
            // `field`, `field` and `toggle` were in fact drawing them. *An empty control over a
            // working default is the worst of the three states: it reads as «nothing draws this».*
            //
            // ⚠️ *Shown, not written.* Storing the default on every node would put one fact in a
            // thousand places and break what the type default is **for** — change it centrally and
            // nothing would follow (D-015: settings are sparse).
            $mayBeNothing = false;

            // ⚠️ *The `hide` exception that stood here is gone with [D-448](90-decision-log.md). It set
            // `mayBeNothing` for a hidden subject so the choice could stay empty — and it rested on
            // [D-399](90-decision-log.md)'s second half, which lived on `hide` being able to hide a
            // **field**. **A hidden node has a renderer choice like any other**: it is not shown in the
            // tree, and that says nothing about how it would be drawn.*
            if ($setting->value->isNothing()) {
                $inForce = $this->renderers->defaultFor(
                    $subject instanceof Relation ? $this->typeAt($subject) : $this->typeOfNode($subject)
                );

                // ⚠️ **Only where it is genuinely one of the choices.** For a subject with no simple
                // type the registry answers with the **fallback** — the marker that says *nothing
                // draws this yet* (R14b) — and that is not an option anybody may pick, so selecting
                // it would be the control claiming a choice the model does not offer.
                if (isset($options[$inForce->name()])) {
                    $setting = new ResolvedSetting(
                        $setting->key,
                        TypedValue::ofText($inForce->name()),
                        0,
                        false
                    );
                } else {
                    $mayBeNothing = true;
                }
            }
        }

        return new RenderedSetting(
            $key->value,
            $shape,
            null,
            $setting,
            $renderer->render(
                $subject,
                new RenderContext(
                    purpose: $purpose,
                    value: $setting->value,
                    settings: [],
                    locale: $locale,
                    level: $level,
                    fieldName: $fieldPrefix === '' ? '' : $fieldPrefix . '[' . $key->value . ']',
                    // ⚠️ **Greyed and not removed** ([D-399](90-decision-log.md), [R30](30-renderer.md)):
                    // a control that vanishes when a switch is thrown makes a person hunt for the row
                    // they were about to use. *A disabled control submits nothing, so keeping it costs
                    // nothing — which is the same argument the choice renderer already makes for a
                    // model that cannot be satisfied.*
                    // ⚠️ **The greying is gone** ([D-448](90-decision-log.md), confirmed by
                    // [D-457](90-decision-log.md)). *It was `! ($hidden && $key === Renderer)` and it
                    // implemented [D-399](90-decision-log.md)'s second half — which lived on `hide`
                    // being able to hide a **field**. Now it hides a node in the tree, and a hidden
                    // node's renderer choice is as real as any other's.*
                    editable: true,
                    surroundings: new Surroundings(options: $options, mayBeNothing: $mayBeNothing, formId: $formId)
                )
            ),
            $renderer->name(),
            $subjectType
        );
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

    /**
     * The simple type a node **is**, rather than the one an attribute points at.
     *
     * ⚠️ *It used to load every ancestor to read their names. Since the binding is by id
     * ([D-510](../../../docs/NewConcept/90-decision-log.md)) the ids off the node's own path are
     * enough, and the query is gone with the names.*
     */
    public function typeOfNode(Node $node): ?SimpleType
    {
        return $this->typeOf($node);
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
     * ⚠️ *The ancestors are no longer read. They were loaded only to have names to compare, and
     * since the binding is by id ([D-510](../../../docs/NewConcept/90-decision-log.md)) the ids a
     * node's path already carries answer the same question with one query fewer.*
     *
     * @param  list<Relation> $edges
     * @return array<int, SimpleType|null>
     */
    private function typesOf(array $edges): array
    {
        $targets = $this->nodes->byIds(array_map(static fn (Relation $e): int => $e->toId, $edges));

        $types = [];

        foreach ($edges as $edge) {
            $target           = $targets[$edge->toId] ?? null;
            $types[$edge->id] = $target === null ? null : $this->typeOf($target);
        }

        return $types;
    }

    private function typeOf(Node $target): ?SimpleType
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

        // ⚠️ **By the id the seed wrote down, never by the node's name** ([D-510](../../../docs/NewConcept/90-decision-log.md)).
        // *A name is a beschriftung and may change; [D-022](../../../docs/NewConcept/90-decision-log.md)
        // says node names are deliberately not unique, so a name could never have been a key. The
        // Notnagel — and the writing-back of the id — sits in {@see TypeNodes}, in one place.*
        $own = $this->typeNodes->typeOf($target->id);

        if ($own !== null) {
            return $own;
        }

        foreach (array_reverse($target->ancestorIds()) as $id) {
            $found = $this->typeNodes->typeOf($id);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
