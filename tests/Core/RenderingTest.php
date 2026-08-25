<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\FieldRenderer;
use Taxmod\Core\Renderer\PlainRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\SpinnerRenderer;
use Taxmod\Core\Renderer\SwitchRenderer;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Settings;
use Taxmod\Tests\Core\Fake\CountingIdentities;
use Taxmod\Tests\Core\Fake\FixedFramework;
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
        $this->rendering = new Rendering($this->nodes, $framework, $this->settings, ShippedRenderers::registry());
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
