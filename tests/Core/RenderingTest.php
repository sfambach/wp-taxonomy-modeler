<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\FieldRenderer;
use Taxmod\Core\Renderer\Submission;
use Taxmod\Core\Renderer\FormRenderer;
use Taxmod\Core\Renderer\PlainRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\ReferenceRenderer;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\SpinnerRenderer;
use Taxmod\Core\Renderer\TreeNodeRenderer;
use Taxmod\Core\Renderer\SwitchRenderer;
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
    /** @var array<string,Node> */
    private array $branchRoot = [];

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

        $framework = new FixedFramework($root, $trash, $this->branchRoot, self::INSTALLATION);

        $this->editor    = new ModelEditor($this->nodes, $this->edges, $identities, $framework, new RecordedChanges());
        $this->settings  = new Settings($this->stored, $this->nodes, $framework);
        $this->rendering = new Rendering(
            $this->nodes,
            $framework,
            $this->settings,
            ShippedRenderers::registry(),
            new Labels(new InMemoryLabels(), $framework)
        );
    }

    private function type(string $name, ?Node $under = null): Node
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
        $edge = $this->editor->addAttribute($part->id, $this->type('bool')->id, 'in stock');

        $fields = $this->rendering->fieldsFor([$edge], [], Purpose::Edit, 'v');

        self::assertCount(1, $fields);
        self::assertSame(SimpleType::Bool, $fields[0]->type);
        self::assertSame(SwitchRenderer::NAME, $fields[0]->rendererName);
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
        $edge = $this->editor->addAttribute($part->id, $description->id, 'notes');

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
        $edge = $this->editor->addAttribute($part->id, $impostor->id, 'supplier');

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
        $edge = $this->editor->addAttribute($part->id, $this->type('int')->id, 'count');

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
        $one  = $this->editor->addAttribute($part->id, $int->id, 'count');
        $two  = $this->editor->addAttribute($part->id, $int->id, 'spare count');

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
        $edge = $this->editor->addAttribute($part->id, $this->type('int')->id, 'count');

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
        $edge = $this->editor->addAttribute($part->id, $this->type('bool')->id, 'in stock');

        self::assertCount(1, $this->rendering->fieldsFor([$edge], [], Purpose::Display, 'v'));
        self::assertCount(0, $this->rendering->fieldsFor([$edge], [], Purpose::Search, 'v'));
    }

    #[Test]
    public function the_form_field_is_keyed_by_the_edge_and_never_by_position(): void
    {
        // ⚠️ A checkbox does not submit when unticked. Positional names would shift every later
        // value onto the wrong attribute — silently, and only for the rows somebody unticked.
        $part = $this->thing('Part');
        $flag = $this->editor->addAttribute($part->id, $this->type('bool')->id, 'in stock');
        $name = $this->editor->addAttribute($part->id, $this->type('text')->id, 'label');

        $fields = $this->rendering->fieldsFor([$flag, $name], [], Purpose::Edit, 'taxmod_value');

        self::assertStringContainsString('name="taxmod_value[' . $flag->id . ']"', $fields[0]->result->markup);
        self::assertStringContainsString('name="taxmod_value[' . $name->id . ']"', $fields[1]->result->markup);
    }

    #[Test]
    public function what_a_record_holds_is_drawn_and_what_it_does_not_is_left_empty(): void
    {
        $part   = $this->thing('Part');
        $filled = $this->editor->addAttribute($part->id, $this->type('text')->id, 'label');
        $empty  = $this->editor->addAttribute($part->id, $this->type('text')->id, 'notes');

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
        $edge = $this->editor->addAttribute($part->id, $this->type('text')->id, 'label');

        $fields = $this->rendering->fieldsFor([$edge], [], Purpose::Display, 'v');

        self::assertSame([$edge->id], $fields[0]->result->usedEdges);
    }

    #[Test]
    public function hiding_an_attribute_leaves_the_field_in_the_list_and_empty(): void
    {
        // ⚠️ It stays in the list so the caller can tell *hidden* from *not an attribute of this
        // model*; the markup is what is empty (R11).
        $part = $this->thing('Part');
        $edge = $this->editor->addAttribute($part->id, $this->type('text')->id, 'internal');

        $this->settings->put(
            $this->settings->chainForUseSite($edge),
            SettingKey::Hide->value,
            TypedValue::ofBool(true)
        );

        $fields = $this->rendering->fieldsFor([$edge], [], Purpose::Display, 'v');

        self::assertCount(1, $fields);
        self::assertTrue($fields[0]->isHidden());
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

        self::assertNotContains(SwitchRenderer::NAME, $offered);

        // Not offered — and not forbidden either.
        self::assertTrue($this->rendering->knowsRenderer(SwitchRenderer::NAME));

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

        self::assertSame([FormRenderer::NAME], $names);
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
        $unit = $this->editor->addAttribute($part->id, $gram->id, 'unit');

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
    public function a_reference_declines_the_edit_purpose_so_the_chooser_gap_stays_visible(): void
    {
        // ⚠️ Changing a reference means picking a node — the chooser, decided (D-244), not built.
        // The descent falls back for a **value**, and the fallback marks itself (R14b).
        $gram = $this->editor->createNode('Gramm', $this->branchRoot['constants']->id);
        $part = $this->thing('Part');
        $unit = $this->editor->addAttribute($part->id, $gram->id, 'unit');

        $field = $this->rendering->fieldsFor(
            [$unit],
            [$unit->id => TypedValue::ofReference($gram->id)],
            Purpose::Edit,
            'v'
        )[0];

        self::assertTrue($field->hasNoRenderer());
        self::assertStringContainsString('taxmod-no-renderer', $field->result->markup);
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

        $this->settings->put(
            $this->settings->chainFor($part),
            SettingKey::Developer->value,
            TypedValue::ofBool(true)
        );

        self::assertStringContainsString(
            'taxmod-tree-writes',
            $this->rendering->cellsFor([$part])[$part->id]->markup
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
        $label = $this->editor->addAttribute($part->id, $this->type('text')->id, 'label');

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

        $first  = $this->editor->addAttribute($part->id, $text->id, 'aaa ordinary');
        $flag   = $this->editor->addAttribute($part->id, $bool->id, 'bbb boolean');
        $second = $this->editor->addAttribute($part->id, $text->id, 'ccc ordinary');
        $fixed  = $this->editor->addAttribute($part->id, $text->id, 'ddd read only');

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
        $part   = $this->thing('Part');
        $secret = $this->editor->addAttribute($part->id, $this->type('text')->id, 'internal');

        $this->settings->put(
            $this->settings->chainForUseSite($secret),
            SettingKey::Hide->value,
            TypedValue::ofBool(true)
        );

        self::assertSame('', $this->rendering->nodeAsForm($part, [$secret], [], Purpose::Display)->markup);
    }

    // ------------------------------------------------------- the settings side

    #[Test]
    public function a_type_shows_the_settings_that_belong_to_it_even_when_nobody_wrote_one(): void
    {
        // ⚠️ The owner, looking at an `int` node whose chain was empty: *the settings that belong
        // firmly to the data type — min, max, step — should be shown as such.* A panel listing only
        // what somebody wrote cannot say what could be written, and R33c wants the opposite.
        $rows = $this->drawnSettings($this->type('int'));

        foreach (['range_min', 'range_max', 'range_step', 'default', 'mandatory'] as $key) {
            self::assertArrayHasKey($key, $rows, $key);
            self::assertTrue($rows[$key]->wasDrawn(), $key);
        }

        // ⚠️ And an unwritten one says so — *nobody has said* is a third state beside *set here*
        // and *inherited* (D-266), and it is the reason the row exists at all.
        self::assertSame(0, $rows['range_min']->setting->fromOwnerId);
        self::assertFalse($rows['range_min']->setting->setHere);
        self::assertTrue($rows['range_min']->setting->value->isNothing());
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

        self::assertArrayNotHasKey('range_min', $rows);
        self::assertArrayHasKey('mandatory', $rows);
    }

    #[Test]
    public function a_setting_is_drawn_by_the_renderer_its_key_asks_for(): void
    {
        // ⚠️ R20a: *the settings side is a series of attributes rendered under the edit purpose.*
        // It printed text until SettingKey::typeFor() said what type a setting's value has.
        $int = $this->type('int');

        $this->settings->put($this->settings->chainFor($int), SettingKey::Mandatory->value, TypedValue::ofBool(true));
        $this->settings->put($this->settings->chainFor($int), SettingKey::RangeMin->value, TypedValue::ofInt(3));

        $rows = $this->drawnSettings($int);

        self::assertTrue($rows[SettingKey::Mandatory->value]->wasDrawn());
        self::assertStringContainsString('type="checkbox"', $rows[SettingKey::Mandatory->value]->result->markup);
        self::assertSame(SwitchRenderer::NAME, $rows[SettingKey::Mandatory->value]->rendererName);

        self::assertSame(SimpleType::Int, $rows[SettingKey::RangeMin->value]->type);
        self::assertStringContainsString('3', $rows[SettingKey::RangeMin->value]->result->markup);
    }

    #[Test]
    public function a_borrowing_key_takes_the_type_of_the_node_it_sits_on(): void
    {
        // ⚠️ The same key, two nodes, two types — which is why the type cannot live on the key.
        $decimal = $this->type('decimal');
        $text    = $this->type('text');

        $this->settings->put($this->settings->chainFor($decimal), SettingKey::RangeMin->value, TypedValue::ofDecimal('2.50'));
        $this->settings->put($this->settings->chainFor($text), SettingKey::DefaultValue->value, TypedValue::ofText('n/a'));

        self::assertSame(
            SimpleType::Decimal,
            $this->drawnSettings($decimal)[SettingKey::RangeMin->value]->type
        );
        self::assertSame(
            SimpleType::Text,
            $this->drawnSettings($text)[SettingKey::DefaultValue->value]->type
        );
    }

    #[Test]
    public function a_choice_is_left_undrawn_rather_than_faked_as_a_field(): void
    {
        // ⚠️ Multiplicity's four constants and a registered name want a **chooser**, one is decided
        // (D-244) and none is built. A text box in its place would be the second way to draw.
        $int = $this->type('int');

        $this->settings->put($this->settings->chainFor($int), SettingKey::Renderer->value, TypedValue::ofText(SpinnerRenderer::NAME));

        $row = $this->drawnSettings($int)[SettingKey::Renderer->value];

        self::assertFalse($row->wasDrawn());
        self::assertTrue($row->shape->isAChoice());
        self::assertTrue($row->isEngineOwned());
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

    /** @return array<string, \Taxmod\Core\Renderer\RenderedSetting> */
    private function drawnSettings(Node $node): array
    {
        $rows = [];

        foreach ($this->rendering->settingsFor($node, $this->settings->resolve($this->settings->chainFor($node))) as $row) {
            $rows[$row->key] = $row;
        }

        return $rows;
    }

    #[Test]
    public function what_may_be_chosen_at_a_use_site_is_narrowed_by_the_type(): void
    {
        $part    = $this->thing('Part');
        $number  = $this->editor->addAttribute($part->id, $this->type('int')->id, 'count');
        $written = $this->editor->addAttribute($part->id, $this->type('text')->id, 'label');

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
        self::assertNotContains(SwitchRenderer::NAME, $forNumber);
    }
}
