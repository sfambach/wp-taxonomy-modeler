<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\ChoiceRenderer;
use Taxmod\Core\Renderer\CompactRenderer;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\Surroundings;
use Taxmod\Core\Renderer\FieldRenderer;
use Taxmod\Core\Renderer\Submission;
use Taxmod\Core\Renderer\FormRenderer;
use Taxmod\Core\Renderer\NodeRenderer;
use Taxmod\Core\Renderer\PageSlot;
use Taxmod\Core\Renderer\PlainRenderer;
use Taxmod\Core\Renderer\Section;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\ReferenceRenderer;
use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\SpinnerRenderer;
use Taxmod\Core\Renderer\TreeNodeRenderer;
use Taxmod\Core\Renderer\CheckboxRenderer;
use Taxmod\Core\Renderer\ToggleRenderer;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Settings;
use Taxmod\Tests\Core\Fake\CountingIdentities;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryLabels;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemoryRelations;
use Taxmod\Tests\Core\Fake\InMemorySettings;
use Taxmod\Tests\Core\Fake\RecordedChanges;
use Taxmod\Tests\Core\Fake\RememberedTypeNodes;

/**
 * The descent: attribute → target → simple type → renderer → markup.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RenderingTest extends TestCase
{
    private const INSTALLATION = 999000;

    private InMemoryNodes $nodes;
    private InMemoryRelations $edges;
    private InMemorySettings $stored;
    private Settings $settings;
    private ModelEditor $editor;
    private Rendering $rendering;
    private RememberedTypeNodes $typeNodes;
    private InMemoryLabels $labelStore;
    /** @var array<string,Node> */
    private array $branchRoot = [];

    /**
     * ⚠️ **Distinct ids per role, because the double used to answer `0` for every one of them** —
     * so `form` and `symbol` were the same role and a test of *which* label was drawn could not
     * fail. That is a test double hiding the thing under test.
     *
     * @var array<string,int>
     */
    private const ROLE_IDS = ['form' => 901, 'table' => 902, 'select' => 903, 'symbol' => 904, 'help' => 905];

    protected function setUp(): void
    {
        $this->edges  = new InMemoryRelations();
        $this->nodes  = new InMemoryNodes($this->edges);
        $this->stored = new InMemorySettings();
        $identities   = new CountingIdentities();

        $make = function (string $name, ?Node $parent) use ($identities): Node {
            $node = Node::create($identities->next(), $name, $parent?->path);
            $this->nodes->add($node);

            if ($parent !== null) {
                $this->edges->add(Relation::inheritance(
                    $identities->next(),
                    $parent->id,
                    $node->id,
                    $this->edges->nextPositionUnder($parent->id)
                ));
            }

            return $node;
        };

        $root  = $make('Root', null);
        $trash = $make('Trash', $root);

        $this->branchRoot['model']        = $make('Model', $root);
        $this->branchRoot['compositions'] = $make('Compositions', $root);

        $primitives = $make('Primitives', $root);

        $this->branchRoot['data-types'] = $make('Data Types', $primitives);
        $this->branchRoot['constants']  = $make('Constants', $primitives);

        $framework = new FixedFramework($root, $trash, $this->branchRoot, self::INSTALLATION, self::ROLE_IDS);

        $this->labelStore = new InMemoryLabels();

        $this->editor    = new ModelEditor($this->nodes, $this->edges, $identities, $framework, new RecordedChanges());
        $this->settings  = new Settings($this->stored, $this->nodes, $framework);
        $this->typeNodes = new RememberedTypeNodes();
        $this->rendering = new Rendering(
            $this->nodes,
            $framework,
            $this->settings,
            ShippedRenderers::registry(),
            $this->typeNodes,
            new Labels($this->labelStore, $framework),
            ShippedConverters::registry()
        );
    }

    /**
     * A data type node, **and the id written down** the way the seed writes it
     * ([D-510](../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *The remembering is here and not inside `createNode()`, because those are two different
     * acts: making a node, and declaring that this node **is** the type. {@see lookalike()} is the
     * first act without the second, which is the case the decision exists for.*
     */
    private function type(string $name, ?Node $under = null): Node
    {
        $node = $this->editor->createNode($name, ($under ?? $this->branchRoot['data-types'])->id);
        $type = SimpleType::fromNodeName($name);

        if ($type !== null && $under === null) {
            $this->typeNodes->remember($type, $node->id);
        }

        return $node;
    }

    /** A node in `Data Types` that merely **bears** a type's name — nobody seeded it. */
    private function lookalike(string $name, ?Node $under = null): Node
    {
        return $this->editor->createNode($name, ($under ?? $this->branchRoot['data-types'])->id);
    }

    private function thing(string $name): Node
    {
        return $this->editor->createNode($name, $this->branchRoot['model']->id);
    }

    // --------------------------------------------------------- finding the type

    #[Test]
    public function an_attribute_is_drawn_by_the_default_of_the_type_it_points_at(): void
    {
        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $this->type('bool')->id, 'in stock');

        $fields = $this->rendering->fieldsFor([$edge], [], Purpose::Edit, 'v');

        self::assertCount(1, $fields);
        self::assertSame(SimpleType::Bool, $fields[0]->type);
        // ⚠️ The **toggle** — *bools always with a slider.* The checkbox stays offered beside it.
        self::assertSame(ToggleRenderer::NAME, $fields[0]->rendererName);
        self::assertFalse($fields[0]->hasNoRenderer());
    }

    #[Test]
    public function a_subtype_of_a_type_is_still_that_type(): void
    {
        // ⚠️ A node `Description` under `text` has no `SimpleType` of its own name and stores
        // exactly what a text stores. Walking up to the nearest ancestor that **is** one is what
        // stops every authored subtype from arriving as *no renderer*.
        $text        = $this->type('text');
        $description = $this->type('Description', $text);

        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $description->id, 'notes');

        $fields = $this->rendering->fieldsFor([$edge], [], Purpose::Edit, 'v');

        self::assertSame(SimpleType::Text, $fields[0]->type);
        self::assertSame(FieldRenderer::NAME, $fields[0]->rendererName);
    }

    #[Test]
    public function a_node_that_merely_shares_a_words_name_is_not_a_type(): void
    {
        // ⚠️ Reading a type off a name found anywhere would be the special-casing-by-name the
        // code standard forbids — a supplier called `text` under `Model` is somebody's thing.
        $impostor = $this->editor->createNode('text', $this->branchRoot['model']->id);

        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $impostor->id, 'supplier');

        $fields = $this->rendering->fieldsFor([$edge], [], Purpose::Display, 'v');

        self::assertNull($fields[0]->type);
        self::assertTrue($fields[0]->hasNoRenderer());
    }

    // --------------------------------------------- the binding is the id (D-510)

    #[Test]
    public function a_type_node_that_is_renamed_is_still_that_type(): void
    {
        // ⚠️ **This is the fault D-510 was written for, made small.** *A check looked for a node
        // called `int`; it is called `Integer` since D-428, so the check never ran and preserved a
        // contradiction for three days. A name is a beschriftung and may change — and D-022 makes
        // node names deliberately non-unique, so a name could never have been a key.*
        $int  = $this->type('int');
        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $int->id, 'count');

        $this->editor->rename($int->id, 'Ganzzahl');

        $fields = $this->rendering->fieldsFor([$edge], [], Purpose::Edit, 'v');

        self::assertSame(SimpleType::Int, $fields[0]->type);
        self::assertFalse($fields[0]->hasNoRenderer());
    }

    #[Test]
    public function a_subtype_keeps_its_type_when_the_type_above_it_is_renamed(): void
    {
        // ⚠️ *The walk upwards used to compare **ancestor names**; it compares ids now, so a rename
        // one level up no longer silently turns every authored subtype into «no renderer».*
        $text        = $this->type('text');
        $description = $this->type('Description', $text);

        $this->editor->rename($text->id, 'Freitext');

        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $description->id, 'notes');

        $fields = $this->rendering->fieldsFor([$edge], [], Purpose::Edit, 'v');

        self::assertSame(SimpleType::Text, $fields[0]->type);
        self::assertSame(FieldRenderer::NAME, $fields[0]->rendererName);
    }

    #[Test]
    public function a_node_that_merely_bears_a_types_name_inside_data_types_is_not_that_type(): void
    {
        // ⚠️ **The other half of the same decision, and the sharper half.** *The existing test above
        // puts the impostor under `Model`, where the **branch** already refuses it. This one is
        // inside `Data Types` — the branch says yes and only the id says no. Under a name binding
        // this node answered `text`, which is D-022 walking straight through the front door.*
        $seeded   = $this->type('text');
        $impostor = $this->lookalike('text');

        self::assertSame(SimpleType::Text, $this->rendering->typeOfNode($seeded));
        self::assertNull($this->rendering->typeOfNode($impostor));

        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $impostor->id, 'supplier');

        $fields = $this->rendering->fieldsFor([$edge], [], Purpose::Display, 'v');

        self::assertNull($fields[0]->type);
        self::assertTrue($fields[0]->hasNoRenderer());
    }

    // ------------------------------------------------------------- the choosing

    #[Test]
    public function the_use_site_overrides_the_types_default(): void
    {
        // D-032 / R14a: *I give the whole thing a new look by using it.* The chain already does
        // this; nothing separate is walked for the renderer.
        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $this->type('int')->id, 'count');

        $this->settings->put(
            $this->settings->chainForUseSite($edge),
            SettingKey::Renderer->value,
            TypedValue::ofText(SpinnerRenderer::NAME)
        );

        $fields = $this->rendering->fieldsFor([$edge], [], Purpose::Edit, 'v');

        self::assertSame(SpinnerRenderer::NAME, $fields[0]->rendererName);
    }

    #[Test]
    public function a_choice_made_at_the_type_reaches_every_use_of_it(): void
    {
        $int  = $this->type('int');
        $part = $this->thing('Part');
        $one  = $this->editor->addField($part->id, $int->id, 'count');
        $two  = $this->editor->addField($part->id, $int->id, 'spare count');

        $this->settings->put(
            $this->settings->chainFor($int),
            SettingKey::Renderer->value,
            TypedValue::ofText(SpinnerRenderer::NAME)
        );

        $fields = $this->rendering->fieldsFor([$one, $two], [], Purpose::Edit, 'v');

        self::assertSame(SpinnerRenderer::NAME, $fields[0]->rendererName);
        self::assertSame(SpinnerRenderer::NAME, $fields[1]->rendererName);
    }

    #[Test]
    public function a_renderer_nobody_registered_is_a_visible_fault_not_a_silent_one(): void
    {
        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $this->type('int')->id, 'count');

        $this->settings->put(
            $this->settings->chainForUseSite($edge),
            SettingKey::Renderer->value,
            TypedValue::ofText('a renderer from a plugin that is gone')
        );

        $fields = $this->rendering->fieldsFor([$edge], [], Purpose::Display, 'v');

        self::assertSame(PlainRenderer::NAME, $fields[0]->rendererName);
        self::assertStringContainsString('taxmod-no-renderer', $fields[0]->result->markup);
    }

    // --------------------------------------------------------------- the policy

    #[Test]
    public function a_value_never_disappears_but_an_unanswerable_filter_never_appears(): void
    {
        // ⚠️ The two halves of one policy, and they differ deliberately. No typed renderer
        // answers for search yet (D-217's mechanism), so the same attribute is drawn for display
        // and left out entirely for search.
        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $this->type('bool')->id, 'in stock');

        self::assertCount(1, $this->rendering->fieldsFor([$edge], [], Purpose::Display, 'v'));
        self::assertCount(0, $this->rendering->fieldsFor([$edge], [], Purpose::Search, 'v'));
    }

    #[Test]
    public function the_form_field_is_keyed_by_the_edge_and_never_by_position(): void
    {
        // ⚠️ A checkbox does not submit when unticked. Positional names would shift every later
        // value onto the wrong attribute — silently, and only for the rows somebody unticked.
        $part = $this->thing('Part');
        $flag = $this->editor->addField($part->id, $this->type('bool')->id, 'in stock');
        $name = $this->editor->addField($part->id, $this->type('text')->id, 'label');

        $fields = $this->rendering->fieldsFor([$flag, $name], [], Purpose::Edit, 'taxmod_value');

        self::assertStringContainsString('name="taxmod_value[' . $flag->id . ']"', $fields[0]->result->markup);
        self::assertStringContainsString('name="taxmod_value[' . $name->id . ']"', $fields[1]->result->markup);
    }

    #[Test]
    public function what_a_record_holds_is_drawn_and_what_it_does_not_is_left_empty(): void
    {
        $part   = $this->thing('Part');
        $filled = $this->editor->addField($part->id, $this->type('text')->id, 'label');
        $empty  = $this->editor->addField($part->id, $this->type('text')->id, 'notes');

        $fields = $this->rendering->fieldsFor(
            [$filled, $empty],
            [$filled->id => TypedValue::ofText('4k7')],
            Purpose::Display,
            'v'
        );

        self::assertStringContainsString('4k7', $fields[0]->result->markup);
        self::assertStringNotContainsString('4k7', $fields[1]->result->markup);
    }

    #[Test]
    public function the_rendering_says_which_edge_it_used(): void
    {
        // D-021: metadata a caller cannot recover from the markup afterwards.
        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $this->type('text')->id, 'label');

        $fields = $this->rendering->fieldsFor([$edge], [], Purpose::Display, 'v');

        self::assertSame([$edge->id], $fields[0]->result->usedEdges);
    }

    #[Test]
    public function a_hidden_field_is_not_enumerated_at_all(): void
    {
        // ⚠️ **This test asserted the opposite until 2026-08-28, and the reversal is the decision.**
        // *It said «it stays in the list so the caller can tell hidden from not-an-attribute; the markup
        // is what is empty». Under D-450 and D-452 `hide` is an **abort**: the walk stops **before**
        // drawing and **before** looking for children.*
        //
        // ⚠️ *An empty markup meant the renderer had already been asked — and for a composed value its
        // members had already been drawn and thrown away. «Does not look at the children» is the owner's
        // own wording, and a list entry with empty markup does not honour it.*
        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $this->type('text')->id, 'internal');

        self::assertCount(1, $this->rendering->fieldsFor([$edge], [], Purpose::Display, 'v'));

        // The column, not a setting — D-457. Hiding is a property of the placement.
        $this->edges->save($edge->withHide(true), $edge->version);

        $fresh = $this->edges->fieldEdgesOf([$part->id]);

        self::assertSame([], $this->rendering->fieldsFor($fresh, [], Purpose::Display, 'v'));
    }

    #[Test]
    public function what_may_be_chosen_at_a_type_is_the_set_that_can_draw_it(): void
    {
        // ⚠️ The owner: *of course the renderer should be picked — there are only certain ones for
        // the current purpose, and how would the user know the name?* (D-358). So the set is the
        // control, and a name never has to be known.
        $names = array_map(
            static fn ($renderer): string => $renderer->name(),
            $this->rendering->choicesForNode($this->type('int'))
        );

        sort($names);

        self::assertSame([FieldRenderer::NAME, 'slider', SpinnerRenderer::NAME], $names);
    }

    #[Test]
    public function what_is_offered_and_what_is_allowed_are_two_questions(): void
    {
        // ⚠️ The owner drew this line: *you cannot turn a text into a binary number — well, you
        // can, it just makes no sense, unless you have a special use case* (D-360). R14 puts the
        // type declaration behind the **offer**; reading it as a prohibition forecloses the
        // special case for everybody to prevent a mistake nobody has made.
        $text = $this->type('text');

        $offered = array_map(
            static fn ($renderer): string => $renderer->name(),
            $this->rendering->choicesForNode($text)
        );

        self::assertNotContains(CheckboxRenderer::NAME, $offered);

        // Not offered — and not forbidden either.
        self::assertTrue($this->rendering->knowsRenderer(CheckboxRenderer::NAME));

        // What is genuinely broken is a name nothing answers to.
        self::assertFalse($this->rendering->knowsRenderer('a renderer from a plugin that is gone'));
    }

    #[Test]
    public function the_fallback_is_not_something_anybody_can_choose(): void
    {
        // ⚠️ Reaching it means nobody chose and the type has no default (R14b). Naming it would
        // make *no renderer* a decision, which is the one thing it must never look like.
        self::assertFalse($this->rendering->knowsRenderer(PlainRenderer::NAME));
    }

    #[Test]
    public function a_thing_under_model_is_offered_structural_renderers_and_not_typed_ones(): void
    {
        // ⚠️ **This used to assert *nothing at all*.** A supplier has no simple type, so what fits
        // it is a **structural** renderer — and there was none until the form renderer arrived.
        // Offering a spinner for a supplier is still the mistake it was.
        $names = array_map(
            static fn ($renderer): string => $renderer->name(),
            $this->rendering->choicesForNode($this->thing('Supplier'))
        );

        // ⚠️ All three structural renderers, and all three legitimate for a thing: `form` stacks its
        // attributes (D-098), `compact` puts them on one line or in one column (D-471), `node` draws
        // it as a whole page (D-256). A typed one is still refused.
        sort($names);
        self::assertSame([CompactRenderer::NAME, FormRenderer::NAME, NodeRenderer::NAME], $names);
    }

    #[Test]
    public function an_authored_subtype_may_be_given_what_its_type_may_be_given(): void
    {
        $description = $this->type('Description', $this->type('text'));

        $names = array_map(
            static fn ($renderer): string => $renderer->name(),
            $this->rendering->choicesForNode($description)
        );

        self::assertContains(FieldRenderer::NAME, $names);
        self::assertNotContains(SpinnerRenderer::NAME, $names);
    }

    #[Test]
    public function a_reference_is_drawn_as_the_targets_name_and_not_as_its_id(): void
    {
        // ⚠️ D-105, and the label arrives **in** the context: a renderer fetches nothing (D-159),
        // so the descent resolves every referenced node's name in one query beforehand.
        $gram = $this->editor->createNode('Gramm', $this->branchRoot['constants']->id);
        $part = $this->thing('Part');
        $unit = $this->editor->addField($part->id, $gram->id, 'unit');

        $field = $this->rendering->fieldsFor(
            [$unit],
            [$unit->id => TypedValue::ofReference($gram->id)],
            Purpose::Display,
            ''
        )[0];

        self::assertSame(ReferenceRenderer::NAME, $field->rendererName);
        self::assertStringContainsString('Gramm', $field->result->markup);
        self::assertStringNotContainsString((string) $gram->id, $field->result->markup);
    }

    #[Test]
    public function an_attribute_says_which_label_of_its_target_to_show(): void
    {
        // ⚠️ **This is D-049's promised setting, and `2k7` is the case that needs it.** The owner:
        // *I type `2k7` and it lands in two different fields, the `2.7` and the `k`* (D-220) — where
        // `k` is not stored at all: what a record holds is a **reference to `kilo`** (D-039's
        // value + prefix + unit, one row per member by path, D-134), and `k` is that node's
        // `symbol` label resolved at render time (D-049, D-260).
        //
        // ⚠️ **Two edges at one target, because that is what could not work before.** The role was
        // nailed to `form` in the descent, so a prefix could only ever read `kilo`. Keying the
        // resolved names by **target** would have been the same bug one level up: whichever edge
        // was resolved second would have won for both.
        $kilo = $this->editor->createNode('kilo', $this->branchRoot['constants']->id);

        $this->labelStore->put(new Label(
            $kilo->id,
            '',
            self::ROLE_IDS['symbol'],
            Label::BASE_NUMBER,
            '',
            'k'
        ));

        $resistor = $this->thing('Widerstandswert');
        $short    = $this->editor->addField($resistor->id, $kilo->id, 'prefix');
        $spelled  = $this->editor->addField($resistor->id, $kilo->id, 'prefix in full');

        // The setting rides on the **edge** — the use site, which is what makes the two differ.
        $this->settings->put(
            $this->settings->chainForUseSite($short),
            Rendering::LABEL_ROLE,
            TypedValue::ofText(SeededRole::Symbol->value)
        );

        $fields = $this->rendering->fieldsFor(
            [$short, $spelled],
            [
                $short->id   => TypedValue::ofReference($kilo->id),
                $spelled->id => TypedValue::ofReference($kilo->id),
            ],
            Purpose::Display,
            ''
        );

        self::assertStringContainsString('k</', $fields[0]->result->markup, 'the symbol role was not used');
        self::assertStringNotContainsString('kilo', $fields[0]->result->markup);

        // The other edge said nothing, so it keeps the ordinary role — same node, other text.
        self::assertStringContainsString('kilo', $fields[1]->result->markup);
    }

    #[Test]
    public function an_unknown_label_role_falls_back_instead_of_breaking_the_form(): void
    {
        // ⚠️ A typo, an import or a data pack can name a role outside the seeded set (D-196).
        // Refusing to draw the whole form over one misspelt setting would be the wrong trade — and
        // it stays visible as wrong, because the text shown is the ordinary one.
        $kilo = $this->editor->createNode('kilo', $this->branchRoot['constants']->id);
        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $kilo->id, 'prefix');

        $this->settings->put(
            $this->settings->chainForUseSite($edge),
            Rendering::LABEL_ROLE,
            TypedValue::ofText('symbool')
        );

        $field = $this->rendering->fieldsFor(
            [$edge],
            [$edge->id => TypedValue::ofReference($kilo->id)],
            Purpose::Display,
            ''
        )[0];

        self::assertStringContainsString('kilo', $field->result->markup);
    }

    #[Test]
    public function a_reference_declines_the_edit_purpose_so_the_chooser_gap_stays_visible(): void
    {
        // ⚠️ Changing a reference means picking a node — the chooser, decided (D-244), not built.
        // The descent falls back for a **value**, and the fallback marks itself (R14b).
        $gram = $this->editor->createNode('Gramm', $this->branchRoot['constants']->id);
        $part = $this->thing('Part');
        $unit = $this->editor->addField($part->id, $gram->id, 'unit');

        $field = $this->rendering->fieldsFor(
            [$unit],
            [$unit->id => TypedValue::ofReference($gram->id)],
            Purpose::Edit,
            'v'
        )[0];

        self::assertTrue($field->hasNoRenderer());
        self::assertStringContainsString('taxmod-no-renderer', $field->result->markup);

        // ⚠️ **And it blames the renderer control, correctly**: a constant *can* be drawn, the name
        // stored here just cannot draw it. The record case below must not say the same thing.
        self::assertStringContainsString('the one set for this cannot draw', $field->result->markup);
    }

    #[Test]
    public function a_reference_to_a_record_says_the_summary_renderer_is_missing_and_not_that_one_is_mis_set(): void
    {
        // Working list row 2: `→ 285` was a **bare record id on screen**, which D-363 forbids. The id
        // is gone, but measuring 2026-08-27 showed the marker then blamed *«the one set for this
        // cannot draw a reference»* — and for a record that is false: nothing is mis-set, the
        // summary renderer (D-106) is not built. A fault naming the wrong cause sends a person to a
        // control with nothing to fix.
        $composed = $this->editor->createNode('Einheitenwert', $this->branchRoot['compositions']->id);
        $part     = $this->thing('Resistor');
        $edge     = $this->editor->addField($part->id, $composed->id, 'resistance');

        $field = $this->rendering->fieldsFor(
            [$edge],
            // A record id, not a node id — which is exactly why no simple type comes back for it.
            [$edge->id => TypedValue::ofReference(999_001)],
            Purpose::Display
        )[0];

        self::assertTrue($field->hasNoRenderer(), 'it is still a marked fault, not a value');
        self::assertStringNotContainsString('999001', $field->result->markup, 'and never the bare id');
        self::assertStringContainsString('summary renderer', $field->result->markup);
        self::assertStringNotContainsString('the one set for this cannot draw', $field->result->markup);
    }

    // ------------------------------------------------ the converter in effect · D-219

    #[Test]
    public function the_converter_in_effect_decides_the_characters_and_no_renderer_knows_it_exists(): void
    {
        // Working list row 7: the `converter` key existed and drew as a **dead** control, because
        // nothing was registered. D-219 decided converters; this is the descent running one.
        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $this->type('int')->id, 'count');

        $before = $this->rendering->fieldsFor([$edge], [$edge->id => TypedValue::ofInt(12)], Purpose::Display)[0];

        self::assertStringContainsString('12', $before->result->markup, 'no converter means shown as stored');

        $this->settings->put(
            $this->settings->chainForUseSite($edge),
            SettingKey::Converter->value,
            TypedValue::ofText('roman')
        );

        $after = $this->rendering->fieldsFor([$edge], [$edge->id => TypedValue::ofInt(12)], Purpose::Display)[0];

        self::assertStringContainsString('XII', $after->result->markup);
        // ⚠️ *The same renderer as before* — the mapping changed, the form did not. That is D-219's
        // split: the converter is the mapping, the renderer is the form.
        self::assertSame($before->rendererName, $after->rendererName);
    }

    #[Test]
    public function nothing_stays_nothing_whatever_converter_is_in_effect(): void
    {
        // ⚠️ A mapping of a value that is not there would be a reading of an unanswered question
        // (D-232) — and `roman` would have drawn its out-of-range marker over an empty field.
        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $this->type('int')->id, 'count');

        $this->settings->put(
            $this->settings->chainForUseSite($edge),
            SettingKey::Converter->value,
            TypedValue::ofText('roman')
        );

        $field = $this->rendering->fieldsFor([$edge], [], Purpose::Display)[0];

        self::assertStringNotContainsString('—', $field->result->markup);
    }

    #[Test]
    public function a_converter_that_cannot_map_this_type_is_left_out_rather_than_run(): void
    {
        // ⚠️ **A type can change under a stored setting** — an attribute repointed from `int` to
        // `text` — and running an integer mapping over characters would invent a reading. So
        // eligibility is checked at the drawing too, not only where the name was chosen.
        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $this->type('text')->id, 'notes');

        $this->settings->put(
            $this->settings->chainForUseSite($edge),
            SettingKey::Converter->value,
            TypedValue::ofText('roman')
        );

        $field = $this->rendering->fieldsFor([$edge], [$edge->id => TypedValue::ofText('12')], Purpose::Display)[0];

        self::assertStringContainsString('12', $field->result->markup);
    }

    #[Test]
    public function a_converter_name_nobody_registered_shows_the_stored_value_rather_than_taking_the_form_down(): void
    {
        // ⚠️ **The value is still true, it just is not mapped.** A data pack that removed a converter
        // would otherwise throw on every form that named it — so the honest failure here is to show
        // the value stored, and refusing belongs at the **write** where the name is chosen (D-360).
        $part = $this->thing('Part');
        $edge = $this->editor->addField($part->id, $this->type('int')->id, 'count');

        $this->settings->put(
            $this->settings->chainForUseSite($edge),
            SettingKey::Converter->value,
            TypedValue::ofText('ein-konverter-den-es-nicht-gibt')
        );

        $field = $this->rendering->fieldsFor([$edge], [$edge->id => TypedValue::ofInt(12)], Purpose::Display)[0];

        self::assertStringContainsString('12', $field->result->markup);
    }

    // --------------------------------------------------------- the tree's cell

    #[Test]
    public function the_cell_draws_the_icon_as_a_dashicon_before_the_name(): void
    {
        // ⚠️ An icon is a **Dashicon key** stored without the prefix — the legacy shape, confirmed
        // by the owner: *for now simply the stock WordPress offers.* Drawing it is two class names,
        // which the core may write: a class is a string, not a call into WordPress (`CD-1`).
        $part = $this->thing('Part');

        $this->settings->put(
            $this->settings->chainFor($part),
            SettingKey::Icon->value,
            TypedValue::ofText('marker')
        );

        $markup = $this->rendering->cellsFor([$part])[$part->id]->markup;

        self::assertStringContainsString('dashicons dashicons-marker', $markup);
        self::assertStringContainsString('Part', $markup);

        // Before the name, as it was in the legacy tree.
        self::assertLessThan(strpos($markup, 'Part'), strpos($markup, 'dashicons'));
    }

    #[Test]
    public function a_node_without_an_icon_gets_no_empty_icon_element(): void
    {
        $part = $this->thing('Part');

        self::assertStringNotContainsString('dashicons', $this->rendering->cellsFor([$part])[$part->id]->markup);
    }

    #[Test]
    public function the_icon_inherits_along_the_chain(): void
    {
        // ⚠️ **Worth a check because the legacy did it differently**: there the icon was *copied
        // once on create* and later parent changes did **not** cascade. In this concept an icon is
        // a **setting** (D-251, D-252) and settings inherit (D-079) — so a change above arrives.
        // Recorded rather than reconciled: legacy is a quarry, not a source (`PR-1`).
        $part  = $this->thing('Part');
        $child = $this->editor->createNode('Resistor', $part->id);

        $this->settings->put(
            $this->settings->chainFor($part),
            SettingKey::Icon->value,
            TypedValue::ofText('marker')
        );

        self::assertStringContainsString(
            'dashicons-marker',
            $this->rendering->cellsFor([$child])[$child->id]->markup
        );
    }

    #[Test]
    public function a_cell_without_a_label_still_reads_as_something(): void
    {
        // ⚠️ The chain ends on the node's own name and never on nothing (D-020, D-022), so an empty
        // cell where a name belongs is impossible by construction rather than by care.
        $part = $this->thing('Supplier');

        self::assertStringContainsString('Supplier', $this->rendering->cellsFor([$part])[$part->id]->markup);
    }

    #[Test]
    public function the_whole_row_is_the_cells_including_buttons_link_and_write_count(): void
    {
        // ⚠️ The owner: *the node renderer should render a node — a row — in the tree, and that
        // includes **everything that makes up the row**.* So the cell draws the link around icon and
        // name, places the controls after it, and shows the write count. What it does **not** know
        // is where the row sits: indent and collapse are the walker's (D-367).
        $part = $this->thing('Part');

        $markup = $this->rendering->cellsFor(
            [$part],
            [$part->id => [new Control('do', 'up', '↑', 'Move up')]],
            [$part->id => 'https://example.test/?taxmod_node=' . $part->id],
            [$part->id => new Submission('https://example.test/post', ['_nonce' => 'abc123'])]
        )[$part->id]->markup;

        // ⚠️ **The button is built here, from what the boundary described.** The owner corrected the
        // claim that a renderer cannot: what it cannot do is invent the URL, the nonce and the
        // words — composing the element out of given values is what a renderer does.
        self::assertStringContainsString('name="do" value="up" title="Move up"', $markup);
        self::assertStringContainsString('>↑</button>', $markup);
        self::assertStringContainsString('action="https://example.test/post"', $markup);
        self::assertStringContainsString('name="_nonce" value="abc123"', $markup);

        self::assertStringContainsString('href="https://example.test/?taxmod_node=' . $part->id . '"', $markup);

        // ⚠️ The link wraps the name and **not** the buttons: a button inside a link does not work.
        self::assertLessThan(strpos($markup, '<button'), strpos($markup, '</a>'));
    }

    #[Test]
    public function an_ordinary_act_reads_black_and_one_that_takes_something_away_reads_red(): void
    {
        // ⚠️ The meaning arrives as a **fact** — `destroys` — and the renderer picks the colour.
        // Otherwise every surface would choose its own red, and the one control that must not be
        // clicked by accident would look different in each of them.
        $part = $this->thing('Part');

        $markup = $this->rendering->cellsFor(
            [$part],
            [$part->id => [
                new Control('do', 'up', '↑', 'Move up'),
                new Control('do', 'trash_node', '🗑', 'Park it', true, destroys: true),
            ]],
            submits: [$part->id => new Submission('https://example.test/post')]
        )[$part->id]->markup;

        self::assertStringContainsString('value="up" title="Move up" style="color:#1d2327"', $markup);
        self::assertStringContainsString('value="trash_node" title="Park it" style="color:#b32d2e"', $markup);
    }

    #[Test]
    public function the_write_count_shows_only_in_developer_mode_and_is_off_by_default(): void
    {
        // ⚠️ The owner asked for it off by default, and D-248 says there is **one** mode for that
        // rather than a switch per diagnostic. It is a **write count** and never a version (D-349).
        $part = $this->thing('Part');

        self::assertStringNotContainsString(
            'taxmod-tree-writes',
            $this->rendering->cellsFor([$part])[$part->id]->markup,
            'off unless somebody said otherwise'
        );

        // ⚠️ **It arrives as a circumstance and no longer as a setting** (D-389). The owner:
        // *develop is not a setting on the node but a setting in the WordPress admin settings menu* —
        // so nothing is written to the chain here, the caller simply says so. *That is also the whole
        // argument: on the chain it could differ per branch, and «developer mode, but only under
        // Compositions» is not a thing.*
        self::assertStringContainsString(
            'taxmod-tree-writes',
            $this->rendering->cellsFor([$part], developerMode: true)[$part->id]->markup
        );
    }

    #[Test]
    public function without_a_url_the_row_is_drawn_and_simply_not_clickable(): void
    {
        // A URL is a boundary fact (`CD-1`); a surface that has nowhere to go hands none in.
        $part = $this->thing('Part');

        self::assertStringNotContainsString('<a ', $this->rendering->cellsFor([$part])[$part->id]->markup);
    }

    #[Test]
    public function every_cell_gets_its_own_icon_and_not_the_last_ones(): void
    {
        // ⚠️ The recurring batch fault, guarded a third time: it shows as one wrong row and gets
        // blamed on the data.
        $one = $this->thing('Alpha');
        $two = $this->thing('Beta');

        $this->settings->put($this->settings->chainFor($two), SettingKey::Icon->value, TypedValue::ofText('★'));

        $cells = $this->rendering->cellsFor([$one, $two]);

        self::assertStringNotContainsString('★', $cells[$one->id]->markup);
        self::assertStringContainsString('★', $cells[$two->id]->markup);
    }

    #[Test]
    public function the_cell_is_registered_but_never_offered_as_a_choice(): void
    {
        // ⚠️ D-367: *which* cell a tree draws is the surface's decision, not the author's — the
        // chooser and the trash want another. Offering it would let somebody turn the detail view
        // into a tree row. R12 still holds: it is in the registry.
        $registry = ShippedRenderers::registry();

        self::assertSame(TreeNodeRenderer::NAME, $registry->byName(TreeNodeRenderer::NAME)->name());
        self::assertNotContains(
            TreeNodeRenderer::NAME,
            array_map(
                static fn ($renderer): string => $renderer->name(),
                $this->rendering->choicesForNode($this->thing('Part'))
            )
        );
    }

    // ------------------------------------------------------------ the node page

    #[Test]
    public function the_page_puts_its_blocks_in_the_order_r20a_decided(): void
    {
        // ⚠️ **The caller says what, never where.** R20a: *what acts · what cannot be changed · the
        // name, because that is what you change first · display · the attributes · the preview ·
        // and last the relations, collapsed* — written down *so a rebuild does not reshuffle it for
        // looks*. Handed in deliberately jumbled, so the order can only come from the enum.
        $part = $this->thing('Part');

        $markup = $this->rendering->nodeAsPage($part, [
            PageSlot::Attributes->value => new Section('ATTRS', '<p>a</p>'),
            PageSlot::Name->value       => new Section('NAME', '<p>n</p>'),
            PageSlot::Acts->value       => new Section('ACTS', '<p>b</p>'),
            PageSlot::Display->value    => new Section('DISPLAY', '<p>d</p>'),
            PageSlot::Fixed->value      => new Section('FIXED', '<p>f</p>'),
        ])->markup;

        $at = static fn (string $title): int => (int) strpos($markup, $title);

        self::assertLessThan($at('FIXED'), $at('ACTS'));
        self::assertLessThan($at('NAME'), $at('FIXED'));
        self::assertLessThan($at('DISPLAY'), $at('NAME'));
        self::assertLessThan($at('ATTRS'), $at('DISPLAY'));
    }

    #[Test]
    public function the_page_has_one_frame_around_everything(): void
    {
        $part = $this->thing('Part');

        $markup = $this->rendering->nodeAsPage($part, [
            PageSlot::Name->value => new Section('Name', '<p>n</p>'),
        ])->markup;

        self::assertStringContainsString('class="taxmod-page"', $markup);
        self::assertSame(1, substr_count($markup, 'class="taxmod-page"'));
    }

    #[Test]
    public function a_slot_nobody_filled_is_not_drawn(): void
    {
        // ⚠️ The preview and the relations are not built, and an empty titled box would claim they
        // were. Absence says the truth; the frame need not be complete to be right.
        $part = $this->thing('Part');

        $markup = $this->rendering->nodeAsPage($part, [
            PageSlot::Name->value    => new Section('Name', '<p>n</p>'),
            PageSlot::Preview->value => new Section('Preview', ''),
        ])->markup;

        self::assertStringNotContainsString('Preview', $markup);
    }

    #[Test]
    public function the_relations_block_starts_shut_because_r20a_says_so(): void
    {
        // *and last the relations, collapsed* — a `<details>` does that in plain HTML, which is why
        // no other block needs one.
        $part = $this->thing('Part');

        $markup = $this->rendering->nodeAsPage($part, [
            PageSlot::Name->value      => new Section('Name', '<p>n</p>'),
            PageSlot::Relations->value => new Section('Relations', '<p>r</p>', collapsed: true),
        ])->markup;

        // Exactly one block starts shut, and it is not the name.
        self::assertSame(1, substr_count($markup, '<details'));
        self::assertLessThan((int) strpos($markup, '<details'), (int) strpos($markup, 'Name'));
    }

    #[Test]
    public function a_page_with_nothing_in_it_draws_nothing(): void
    {
        self::assertSame('', $this->rendering->nodeAsPage($this->thing('Part'), [])->markup);
    }

    // --------------------------------------------------------- the tree's walker

    #[Test]
    public function the_walker_nests_and_the_cell_draws(): void
    {
        // ⚠️ D-367's split: the tree renderer walks and builds the hierarchy, the node renderer
        // draws the node. Depth lives with the walker; the cell never sees it.
        $part  = $this->thing('Part');
        $child = $this->editor->createNode('Resistor', $part->id);

        $tree = $this->rendering->treeFor([
            ['node' => $part,  'depth' => 0, 'hasChildren' => true,  'collapsed' => false, 'isFirst' => true, 'isLast' => true],
            ['node' => $child, 'depth' => 1, 'hasChildren' => false, 'collapsed' => false, 'isFirst' => true, 'isLast' => true],
        ]);

        self::assertStringContainsString('taxmod-tree-row', $tree->markup);
        self::assertStringContainsString('Part', $tree->markup);
        self::assertStringContainsString('Resistor', $tree->markup);

        // The child is indented and the parent is not.
        self::assertStringContainsString('width:1.40em', $tree->markup);
        self::assertStringContainsString('width:0.00em', $tree->markup);

        // ⚠️ Not a table: a tree is one column at varying depth, and a table forces every row to
        // the height of its tallest cell.
        self::assertStringNotContainsString('<table', $tree->markup);
    }

    #[Test]
    public function the_fold_control_is_the_walkers_and_a_leaf_keeps_its_space(): void
    {
        // ⚠️ *Collapsing is a question about the tree* (D-345) — so the triangle is the walker's,
        // and a cell that knew whether it was folded would know where it sits.
        $part = $this->thing('Part');
        $leaf = $this->thing('Supplier');

        $tree = $this->rendering->treeFor(
            [
                ['node' => $part, 'depth' => 0, 'hasChildren' => true,  'collapsed' => true,  'isFirst' => true, 'isLast' => false],
                ['node' => $leaf, 'depth' => 0, 'hasChildren' => false, 'collapsed' => false, 'isFirst' => false, 'isLast' => true],
            ],
            toggles: [$part->id => 'https://example.test/fold']
        );

        self::assertStringContainsString('href="https://example.test/fold"', $tree->markup);
        self::assertStringContainsString('&#9656;', $tree->markup, 'collapsed shows the closed triangle');

        // A leaf keeps the space, or depth stops being readable.
        self::assertSame(2, substr_count($tree->markup, 'width:1.4em;flex:none'));
    }

    #[Test]
    public function the_selected_row_is_marked_by_the_walker(): void
    {
        $part  = $this->thing('Part');
        $other = $this->thing('Supplier');

        $tree = $this->rendering->treeFor(
            [
                ['node' => $part,  'depth' => 0, 'hasChildren' => false, 'collapsed' => false, 'isFirst' => true, 'isLast' => false],
                ['node' => $other, 'depth' => 0, 'hasChildren' => false, 'collapsed' => false, 'isFirst' => false, 'isLast' => true],
            ],
            highlight: $part->id
        );

        self::assertSame(1, substr_count($tree->markup, 'background:#e8f0fb'));
    }

    #[Test]
    public function an_empty_tree_draws_nothing(): void
    {
        self::assertSame('', $this->rendering->treeFor([])->markup);
    }

    // ---------------------------------------------------- the container renderer

    #[Test]
    public function a_form_lays_out_the_members_the_descent_drew(): void
    {
        // ⚠️ R46's recursion, arranged so D-159 still holds: the **descent** goes back to the
        // registry per cell, the container regroups the finished parts.
        $part  = $this->thing('Part');
        $label = $this->editor->addField($part->id, $this->type('text')->id, 'label');

        $form = $this->rendering->nodeAsForm(
            $part,
            [$label],
            [$label->id => TypedValue::ofText('4k7')],
            Purpose::Display
        );

        self::assertStringContainsString('taxmod-form', $form->markup);
        self::assertStringContainsString('label', $form->markup);
        self::assertStringContainsString('4k7', $form->markup);
        self::assertSame([$label->id], $form->usedEdges);
    }

    #[Test]
    public function the_form_puts_read_only_first_booleans_last_and_keeps_the_authors_order(): void
    {
        // R75 / D-118: read-only values, ordinary fields, booleans collected, multi-valued last —
        // and D-082's `position` orders **within** a group, so a careful author is not rearranged.
        $part  = $this->thing('Part');
        $text  = $this->type('text');
        $bool  = $this->type('bool');

        $first  = $this->editor->addField($part->id, $text->id, 'aaa ordinary');
        $flag   = $this->editor->addField($part->id, $bool->id, 'bbb boolean');
        $second = $this->editor->addField($part->id, $text->id, 'ccc ordinary');
        $fixed  = $this->editor->addField($part->id, $text->id, 'ddd read only');

        $this->settings->put(
            $this->settings->chainForUseSite($fixed),
            SettingKey::ReadOnly->value,
            TypedValue::ofBool(true)
        );

        $markup = $this->rendering->nodeAsForm($part, [$first, $flag, $second, $fixed], [], Purpose::Display)->markup;

        $order = [];

        foreach (['aaa ordinary', 'bbb boolean', 'ccc ordinary', 'ddd read only'] as $name) {
            $order[$name] = strpos($markup, $name);
        }

        self::assertLessThan($order['aaa ordinary'], $order['ddd read only'], 'read-only comes first');
        self::assertLessThan($order['ccc ordinary'], $order['aaa ordinary'], 'position orders within the group');
        self::assertGreaterThan($order['ccc ordinary'], $order['bbb boolean'], 'booleans are collected after the fields');
    }

    #[Test]
    public function a_hidden_member_takes_no_row_in_the_form(): void
    {
        // R11, and R75's level dependency: `hide` overrides the layout wherever it matters.
        //
        // ⚠️ *The mechanism changed on 2026-08-28 and the outcome did not: `hide` is a **column** on
        // the placement (D-457), not a setting on its chain. The form is empty because the field was
        // never enumerated, rather than because its markup came back empty.*
        $part   = $this->thing('Part');
        $secret = $this->editor->addField($part->id, $this->type('text')->id, 'internal');

        $this->edges->save($secret->withHide(true), $secret->version);

        $fresh = $this->edges->fieldEdgesOf([$part->id]);

        self::assertSame('', $this->rendering->nodeAsForm($part, $fresh, [], Purpose::Display)->markup);
    }

    // ------------------------------------------------------- the settings side

    #[Test]
    public function a_type_shows_the_settings_that_belong_to_it_even_when_nobody_wrote_one(): void
    {
        // ⚠️ The owner, looking at an `int` node whose chain was empty: *the settings that belong
        // firmly to the data type — min, max, step — should be shown as such.* A panel listing only
        // what somebody wrote cannot say what could be written, and R33c wants the opposite.
        $rows = $this->drawnSettings($this->type('int'));

        // ⚠️ `mandatory` was in this list until [D-405]: the multiplicity says it, so the key is gone.
        // ⚠️ *`hide` verliess diese Liste 2026-08-28 — es ist eine Spalte und kein Setting mehr
        // ([D-457]). `read_only` steht dafuer, weil es einer bleibt ([D-461]).*
        foreach (['min', 'max', 'step', 'default', 'read_only'] as $key) {
            self::assertArrayHasKey($key, $rows, $key);
            self::assertTrue($rows[$key]->wasDrawn(), $key);
        }

        // ⚠️ And an unwritten one says so — *nobody has said* is a third state beside *set here*
        // and *inherited* (D-266), and it is the reason the row exists at all.
        self::assertSame(0, $rows['min']->setting->fromOwnerId);
        self::assertFalse($rows['min']->setting->setHere);
        self::assertTrue($rows['min']->setting->value->isNothing());
    }

    #[Test]
    public function multiplicity_is_not_offered_on_a_node(): void
    {
        // D-351: a node describes a thing, and a thing has no multiplicity.
        self::assertArrayNotHasKey('multiplicity', $this->drawnSettings($this->type('int')));
    }

    #[Test]
    public function a_thing_is_not_shown_the_settings_of_a_number(): void
    {
        // ⚠️ A supplier has no type to borrow, so `range_min` has no shape to be drawn in — it is
        // left out rather than offered as an empty box that could never be filled sensibly.
        $rows = $this->drawnSettings($this->thing('Supplier'));

        self::assertArrayNotHasKey('min', $rows);
        // ⚠️ A key that applies to **anything** still appears — `hide` stands in for what `mandatory`
        // used to demonstrate here ([D-405]), and it makes the point better: it is a rule about the
        // field, not about its type.
        self::assertArrayHasKey('read_only', $rows);
    }

    #[Test]
    public function a_setting_is_drawn_by_the_renderer_its_key_asks_for(): void
    {
        // ⚠️ R20a: *the settings side is a series of attributes rendered under the edit purpose.*
        // It printed text until SettingKey::typeFor() said what type a setting's value has.
        $int = $this->type('int');

        $this->settings->put($this->settings->chainFor($int), SettingKey::ReadOnly->value, TypedValue::ofBool(true));
        $this->settings->put($this->settings->chainFor($int), SettingKey::Min->value, TypedValue::ofInt(3));

        $rows = $this->drawnSettings($int);

        // A switch is still drawn as a switch; which switch is beside the point of this test.
        self::assertTrue($rows[SettingKey::ReadOnly->value]->wasDrawn());
        self::assertStringContainsString('taxmod-toggle-track', $rows[SettingKey::ReadOnly->value]->result->markup);
        self::assertSame(ToggleRenderer::NAME, $rows[SettingKey::ReadOnly->value]->rendererName);

        self::assertSame(SimpleType::Int, $rows[SettingKey::Min->value]->type);
        self::assertStringContainsString('3', $rows[SettingKey::Min->value]->result->markup);
    }

    #[Test]
    public function a_borrowing_key_takes_the_type_of_the_node_it_sits_on(): void
    {
        // ⚠️ The same key, two nodes, two types — which is why the type cannot live on the key.
        $decimal = $this->type('decimal');
        $text    = $this->type('text');

        $this->settings->put($this->settings->chainFor($decimal), SettingKey::Min->value, TypedValue::ofDecimal('2.50'));
        $this->settings->put($this->settings->chainFor($text), SettingKey::DefaultValue->value, TypedValue::ofText('n/a'));

        self::assertSame(
            SimpleType::Decimal,
            $this->drawnSettings($decimal)[SettingKey::Min->value]->type
        );
        self::assertSame(
            SimpleType::Text,
            $this->drawnSettings($text)[SettingKey::DefaultValue->value]->type
        );
    }

    #[Test]
    public function a_choice_is_drawn_as_a_set_and_not_as_a_text_box(): void
    {
        // ⚠️ **This test used to assert the opposite**, and the old reason was honest at the time:
        // a choice wants a chooser and none was built, so a text box in its place would have been
        // the second way to draw a field (R20a). **The chooser exists**, so the reason is gone —
        // and the assertion is rewritten rather than loosened, because a test that no longer
        // matches the decision is worse than no test.
        $int = $this->type('int');

        $this->settings->put($this->settings->chainFor($int), SettingKey::Renderer->value, TypedValue::ofText(SpinnerRenderer::NAME));

        $row = $this->drawnSettings($int, Purpose::Edit)[SettingKey::Renderer->value];

        self::assertTrue($row->wasDrawn());
        self::assertTrue($row->shape->isAChoice());
        self::assertSame(ChoiceRenderer::NAME, $row->rendererName);

        // The eligible renderers are the set, so the one that is set is in it and the plain field is
        // there beside it — a real control, because there is more than one outcome.
        self::assertStringContainsString('<select', $row->result->markup);
        self::assertStringContainsString(SpinnerRenderer::NAME, $row->result->markup);
        self::assertStringNotContainsString('disabled', $row->result->markup);
    }

    #[Test]
    public function a_choice_with_nothing_to_choose_is_disabled_rather_than_an_empty_box(): void
    {
        // ⚠️ **R31**: with no available entry there is nothing to choose and the control is
        // disabled — a dead control instead of a box that looks fillable.
        //
        // ⚠️ **This test used to use `converter` as its live case, and row 7 took that away.** *«None
        // is built» was true until 2026-08-27; two are now registered, so `converter` draws a real
        // control on an `int`. The rule did not change — its example had to.* **`text` is the honest
        // one now**: neither shipped converter maps characters, so an attribute of that type has an
        // empty set and the row is dead for the reason R31 describes.
        $row = $this->drawnSettings($this->type('text'), Purpose::Edit)[SettingKey::Converter->value];

        self::assertTrue($row->wasDrawn());
        self::assertStringContainsString('disabled', $row->result->markup);
    }

    #[Test]
    public function the_converter_control_comes_alive_where_a_converter_is_eligible(): void
    {
        // The other half of the rule above, and the point of list row 7: a set that is **not** empty
        // draws a live control offering exactly the eligible names.
        $row = $this->drawnSettings($this->type('int'), Purpose::Edit)[SettingKey::Converter->value];

        self::assertStringNotContainsString('disabled', $row->result->markup);

        // ⚠️ **All four, because R34 named all four** — *binary, hexadecimal, octal or in Roman
        // numerals* ([D-523](../../docs/NewConcept/90-decision-log.md)). *A converter that is
        // registered but never offered is one nobody can choose, and nothing else would notice.*
        foreach (['binary', 'hexadecimal', 'octal', 'roman'] as $name) {
            self::assertStringContainsString($name, $row->result->markup);
        }

        // ⚠️ **And *nothing* stays an outcome, unlike the renderer choice** (R33b): no converter means
        // the value is shown as it is stored, so there is no default to force.
        self::assertStringContainsString('<option value="" selected>', $row->result->markup);
    }

    #[Test]
    public function one_entry_that_may_also_be_left_empty_stays_a_live_control(): void
    {
        // ⚠️ **R31b, and the case the rule must not be over-applied to.** *The test is never how
        // many rows are in the list but how many outcomes this control can produce.* One entry plus
        // *nothing* is two outcomes, so greying it out would remove a decision the user really has.
        $renderer = ShippedRenderers::registry()->byName(ChoiceRenderer::NAME);

        $live = $renderer->render(
            $this->thing('Part'),
            new RenderContext(
                purpose: Purpose::Edit,
                value: TypedValue::nothing(),
                surroundings: new Surroundings(options: ['only' => 'the only one'], mayBeNothing: true)
            )
        );

        self::assertStringNotContainsString('disabled', $live->markup);

        // The same single entry where nothing is **not** an answer is genuinely decided — R30 —
        // so it is preselected and greyed rather than merely offered.
        $decided = $renderer->render(
            $this->thing('Other'),
            new RenderContext(
                purpose: Purpose::Edit,
                value: TypedValue::nothing(),
                surroundings: new Surroundings(options: ['only' => 'the only one'], mayBeNothing: false)
            )
        );

        self::assertStringContainsString('disabled', $decided->markup);
        self::assertStringContainsString('selected', $decided->markup);
    }

    #[Test]
    public function a_key_of_someones_own_is_not_given_a_type_the_engine_cannot_know(): void
    {
        // ⚠️ Reading a type off whatever value happens to be stored is the guessing D-354 ended.
        $int = $this->type('int');

        $this->settings->declareFree($this->settings->chainFor($int), 'house_style', TypedValue::ofText('narrow'));

        $row = $this->drawnSettings($int)['house_style'];

        self::assertFalse($row->wasDrawn());
        self::assertFalse($row->isEngineOwned());
        self::assertNull($row->type);
    }

    #[Test]
    public function a_borrowing_key_on_a_thing_has_no_type_to_borrow(): void
    {
        // ⚠️ A fact about the model, not a missing feature: a supplier is not a simple data type,
        // so a `default` on it has no shape to be drawn in.
        $part = $this->thing('Part');

        $this->settings->put($this->settings->chainFor($part), SettingKey::DefaultValue->value, TypedValue::ofText('x'));

        $row = $this->drawnSettings($part)[SettingKey::DefaultValue->value];

        self::assertFalse($row->wasDrawn());
        self::assertNull($row->type);
        self::assertTrue($row->isEngineOwned());
    }

    /**
     * ⚠️ **Ein `bool` bekommt keine Untergrenze null angeboten** (D-412). Der Eigentümer: *«ein `bool`
     * hat genau zwei Zustände … und ein `bool` darf keine Multiplizität von null haben.»* `0..1` hiesse
     * «vielleicht wahr, vielleicht falsch, vielleicht keins», und ein Drittes gibt es nicht.
     *
     * ⚠️ *Geprüft wird am **Markup** und nicht an einer Liste im Inneren: was angeboten wird, ist das,
     * was ein Mensch anklicken kann. Und die Gegenprobe steht daneben — an einem `int` bleiben alle
     * vier stehen, sonst hielte die Zusage auch, wenn der Wähler überhaupt nichts mehr anböte.*
     */
    #[Test]
    public function a_bool_is_not_offered_a_floor_of_zero(): void
    {
        $part  = $this->thing('Part');
        $flag  = $this->editor->addField($part->id, $this->type('bool')->id, 'in stock');
        $count = $this->editor->addField($part->id, $this->type('int')->id, 'count');

        $fuerBool = $this->multiplicityMarkup($flag);
        $fuerInt  = $this->multiplicityMarkup($count);

        self::assertStringNotContainsString('value="0..1"', $fuerBool);
        self::assertStringNotContainsString('value="0..*"', $fuerBool);
        self::assertStringContainsString('value="1..1"', $fuerBool);
        self::assertStringContainsString('value="1..*"', $fuerBool);

        // Die Gegenprobe: an einem `int` steht die ganze Vier weiterhin zur Wahl.
        self::assertStringContainsString('value="0..1"', $fuerInt);
        self::assertStringContainsString('value="0..*"', $fuerInt);
    }

    /** Das gezeichnete Multiplizitäts-Steuerelement einer Feldkante. */
    private function multiplicityMarkup(\Taxmod\Core\Model\Relation $edge): string
    {
        $drawn = $this->rendering->settingsFor(
            $edge,
            $this->settings->resolve($this->settings->chainForUseSite($edge)),
            Purpose::Edit
        );

        foreach ($drawn as $row) {
            if ($row->key === SettingKey::Multiplicity->value) {
                return $row->result?->markup ?? '';
            }
        }

        self::fail('Die Multiplizitaet wurde fuer diese Kante gar nicht gezeichnet.');
    }

    /** @return array<string, \Taxmod\Core\Renderer\RenderedSetting> */
    private function drawnSettings(Node $node, Purpose $purpose = Purpose::Display): array
    {
        $rows = [];

        $drawn = $this->rendering->settingsFor(
            $node,
            $this->settings->resolve($this->settings->chainFor($node)),
            $purpose
        );

        foreach ($drawn as $row) {
            $rows[$row->key] = $row;
        }

        return $rows;
    }

    #[Test]
    public function what_may_be_chosen_at_a_use_site_is_narrowed_by_the_type(): void
    {
        $part    = $this->thing('Part');
        $number  = $this->editor->addField($part->id, $this->type('int')->id, 'count');
        $written = $this->editor->addField($part->id, $this->type('text')->id, 'label');

        $forNumber = array_map(
            static fn ($renderer): string => $renderer->name(),
            $this->rendering->choicesFor($number)
        );

        $forText = array_map(
            static fn ($renderer): string => $renderer->name(),
            $this->rendering->choicesFor($written)
        );

        self::assertContains(SpinnerRenderer::NAME, $forNumber);
        self::assertNotContains(SpinnerRenderer::NAME, $forText);
        self::assertNotContains(CheckboxRenderer::NAME, $forNumber);
    }

    // ------------------------------------------------- the row points at its target

    /**
     * ⚠️ **Nothing exercised `fieldRowsFor()` at all** before this, which is why an eleven-parameter
     * method could gain a twelfth without a single test noticing. *The two cases below are the whole
     * contract of the new one: an address handed in becomes a link, no address stays text.*
     */
    #[Test]
    public function an_attributes_target_becomes_a_link_when_the_surface_says_where_it_is(): void
    {
        $part     = $this->thing('Part');
        $position = $this->thing('BOM Position');
        $edge     = $this->editor->addField($part->id, $position->id, 'position');

        $rows = $this->rendering->fieldRowsFor(
            [$edge],
            $part->id,
            [],
            [],
            '',
            '',
            '',
            \Taxmod\Core\Renderer\Level::Admin,
            [$position->id => '/wp-admin/admin.php?page=taxmod&taxmod_node=' . $position->id]
        );

        self::assertCount(1, $rows);

        $markup = $rows[0]->result->markup;

        self::assertStringContainsString('taxmod_node=' . $position->id, $markup);
        self::assertStringContainsString('class="taxmod-field-target-link"', $markup);
        // ⚠️ *The `&` of the query string has to arrive escaped — `htmlTag()` does that, and this is
        // the assertion that would fail if the cell were ever handed the URL as trusted markup.*
        self::assertStringContainsString('&amp;taxmod_node=', $markup);
    }

    #[Test]
    public function a_target_with_no_address_stays_plain_text(): void
    {
        $part     = $this->thing('Part');
        $position = $this->thing('BOM Position');
        $edge     = $this->editor->addField($part->id, $position->id, 'position');

        $rows = $this->rendering->fieldRowsFor([$edge], $part->id);

        $markup = $rows[0]->result->markup;

        self::assertStringNotContainsString('taxmod-field-target-link', $markup);
        self::assertStringNotContainsString('<a ', $markup);
    }

    // ------------------------------------------- which container lays out a node

    /**
     * ⚠️ **`nodeAsForm()`'s own docblock said this and the code did not do it.** *«The container is
     * chosen the same way a field's renderer is — the chain, then the structural default», while the
     * line beneath read `byName(FormRenderer::NAME)` and asked nothing. **`form` worked only because
     * it was the only one**, and the owner found it the moment a second container existed: «ich kann
     * irgendwie hier noch nichts richtig aufsetzen».*
     */
    #[Test]
    public function a_node_is_laid_out_by_the_container_its_chain_names(): void
    {
        $part = $this->thing('Posten');
        $one  = $this->editor->addField($part->id, $this->type('int')->id, 'menge');

        $before = $this->rendering->nodeAsForm($part, [$one], [], Purpose::Edit, 'v')->markup;

        self::assertStringContainsString('taxmod-form', $before);

        $this->settings->put(
            $this->settings->chainFor($part),
            SettingKey::Renderer->value,
            TypedValue::ofText(CompactRenderer::NAME)
        );

        $after = $this->rendering->nodeAsForm($part, [$one], [], Purpose::Edit, 'v')->markup;

        self::assertStringContainsString('taxmod-compact', $after);
        // ⚠️ *Und die Gegenprobe zum Namen: es ist wirklich ein anderer Aufbau und nicht dieselbe
        // Zeichenkette mit einer zusätzlichen Klasse.*
        self::assertNotSame($before, $after);
    }

    #[Test]
    public function a_container_name_that_cannot_be_offered_falls_back_to_the_form(): void
    {
        $part = $this->thing('Posten');
        $one  = $this->editor->addField($part->id, $this->type('int')->id, 'menge');

        $this->settings->put(
            $this->settings->chainFor($part),
            SettingKey::Renderer->value,
            TypedValue::ofText('gibtsnicht')
        );

        // ⚠️ **Das Formular und nicht der Auffang der Registry.** *Ein Container, der seine Teile
        // nicht auslegen kann, verliert sie — und die Felder eines Menschen zu verlieren ist
        // schlimmer, als sie schlicht auszulegen. [R14b](../../docs/NewConcept/30-renderer.md)s «der
        // Auffang meldet sich selbst» handelt von einem **Wert**, nicht von einem Rahmen.*
        $markup = $this->rendering->nodeAsForm($part, [$one], [], Purpose::Edit, 'v')->markup;

        self::assertStringContainsString('taxmod-form', $markup);
        self::assertStringContainsString('menge', $markup);
    }

    /**
     * Die mittlere Sprosse der entschiedenen Reihenfolge — [D-028](../../docs/NewConcept/90-decision-log.md).
     *
     * ⚠️ *Vor Schema 13 gab es die Spalte nicht, also nahm die Vorschau `records[0]`. Damit schlug
     * eine als Testdaten markierte Zeile echte Daten allein deshalb, weil sie die kleinere Id
     * hatte — und keiner der 382 Tests hätte das bemerkt.*
     */
    #[Test]
    public function real_data_outranks_a_row_marked_as_test_data(): void
    {
        $marked = new NodeRecord(1, 7, 1, '2026-08-29 10:00:00', true);
        $real   = new NodeRecord(2, 7, 1, '2026-08-29 10:01:00', false);

        // Die markierte Zeile steht **vorn**, also entscheidet die Regel und nicht die Reihenfolge.
        self::assertSame($real, $this->rendering->previewRecordAmong([$marked, $real]));
    }

    #[Test]
    public function a_row_marked_as_test_data_draws_where_there_is_no_real_one(): void
    {
        $first  = new NodeRecord(1, 7, 1, '2026-08-29 10:00:00', true);
        $second = new NodeRecord(2, 7, 1, '2026-08-29 10:01:00', true);

        // ⚠️ *Testdaten sind besser als gar nichts: die dritte Sprosse sind die Vorgaben, nicht
        // die zweite.*
        self::assertSame($first, $this->rendering->previewRecordAmong([$first, $second]));
    }

    #[Test]
    public function within_one_rung_the_first_record_still_wins(): void
    {
        $first  = new NodeRecord(1, 7, 1, '2026-08-29 10:00:00', false);
        $second = new NodeRecord(2, 7, 1, '2026-08-29 10:01:00', false);

        self::assertSame($first, $this->rendering->previewRecordAmong([$first, $second]));
        self::assertNull($this->rendering->previewRecordAmong([]));
    }
}
