<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\NodeClass\Constant;
use Taxmod\Core\Model\NodeClass\Contracts;
use Taxmod\Core\Model\NodeClass\NodeAttributes;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\Setting\SettingsObject;
use Taxmod\Core\Model\Setting\SettingsValue;
use Taxmod\Core\Model\Type\IntType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\CompactRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\SpinnerRenderer;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\SettingsResolver;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemoryRelations;
use Taxmod\Tests\Core\Fake\InMemorySettings;
use Taxmod\Tests\Core\Fake\RememberedTypeNodes;

/**
 * Der Zeichner mit der Auflösung aus `settings_value` und dem Vertrag — Schritt 4 des Bauplans:
 * die Renderer zeichnen aus der neuen Auflösung, der Einstellungsbereich zeigt die Attribute des
 * Vertrags (D-712).
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class RenderingFromContractTest extends TestCase
{
    private InMemoryNodes $nodes;
    private InMemorySettings $settings;
    private Rendering $rendering;
    private Node $kontakt;
    private Node $integer;

    protected function setUp(): void
    {
        Contracts::forget();

        $this->nodes    = new InMemoryNodes();
        $this->settings = new InMemorySettings();

        $root          = $this->nodes->add(Node::create(1, 'Root', null));
        $trash         = $this->nodes->add(Node::create(2, 'Trash', '1', 1, 0));
        $model         = $this->nodes->add(Node::create(3, 'Model', '1', 1, 1));
        $types         = $this->nodes->add(Node::create(4, 'Data Types', '1', 1, 2));
        $this->kontakt = $this->nodes->add(Node::create(5, 'Kontakt', '1.3', 3, 0, Category::class));
        $this->integer = $this->nodes->add(Node::create(6, 'Integer', '1.4', 4, 0, IntType::class));
        // ⚠️ *Die Rollen hängen am Anker `roles` — ein Verweisattribut mit Anker bietet dessen Kinder an (D-728).*
        $roles         = $this->nodes->add(Node::create(7, 'Label roles', '1', 1, 3, \Taxmod\Core\Model\NodeClass\Choice::class));

        $typeNodes = new RememberedTypeNodes();
        $typeNodes->remember(\Taxmod\Core\Model\SimpleType::Int, $this->integer->id);

        $this->rendering = new Rendering(
            $this->nodes,
            new FixedFramework($root, $trash, ['model' => $model, 'data-types' => $types], anchors: ['roles' => $roles]),
            ShippedRenderers::registry(),
            $typeNodes,
            null,
            ShippedConverters::registry(),
            relations: new InMemoryRelations(),
            resolver: new SettingsResolver($this->settings, $this->nodes, ShippedRenderers::registry(), ShippedConverters::registry())
        );
    }

    /** @return array<string, \Taxmod\Core\Renderer\RenderedSetting> */
    private function rows(Node|Relation $subject, Purpose $purpose = Purpose::Edit): array
    {
        $resolved = $subject instanceof Node
            ? $this->rendering->settingsForNode($subject)
            : $this->rendering->settingsForUseSites([$subject])[$subject->id];

        $rows = [];

        foreach ($this->rendering->settingsFor($subject, $resolved, $purpose, 'taxmod_setting') as $row) {
            $rows[$row->key] = $row;
        }

        return $rows;
    }

    #[Test]
    public function the_panel_shows_the_attributes_of_the_class_with_their_defaults(): void
    {
        $rows = $this->rows($this->integer);

        foreach (['renderer', 'converter', 'validator', 'display_size', 'min', 'max', 'step'] as $name) {
            self::assertArrayHasKey($name, $rows, $name);
        }

        self::assertSame(20, $rows['display_size']->setting->value->int, 'die Vorgabe des Vertrags');
        self::assertFalse($rows['display_size']->setting->setHere);
        self::assertTrue($rows['display_size']->wasDrawn());
        self::assertStringContainsString('name="taxmod_setting[display_size]"', $rows['display_size']->result->markup);
        self::assertStringContainsString('value="20"', $rows['display_size']->result->markup);
        self::assertTrue($rows['min']->wasDrawn(), 'ohne Vorgabe, aber gezeichnet — leer');
        self::assertArrayNotHasKey('read_only', $rows, 'read_only ist eine Spalte der Kante (D-714)');
        self::assertArrayNotHasKey('orientation', $rows, 'kein Renderer gewählt: keine Renderer-Attribute');
    }

    #[Test]
    public function a_category_has_no_bounds_and_the_renderer_choice_offers_what_can_draw(): void
    {
        $rows = $this->rows($this->kontakt);

        self::assertArrayNotHasKey('min', $rows);
        self::assertArrayHasKey('renderer', $rows);
        self::assertStringContainsString('<select', $rows['renderer']->result->markup);
        self::assertStringContainsString('value="form"', $rows['renderer']->result->markup);
    }

    #[Test]
    public function a_stored_row_is_drawn_as_set_here_and_the_renderer_reads_it(): void
    {
        $this->settings->addValue(SettingsValue::atNode($this->integer->id, IntType::class, 'max', TypedValue::ofInt(999)));
        $this->settings->addValue(SettingsValue::atNode($this->integer->id, \Taxmod\Core\Model\Type\SpecialisedType::class, 'display_size', TypedValue::ofInt(4)));

        $rows = $this->rows($this->integer);

        self::assertSame(999, $rows['max']->setting->value->int);
        self::assertTrue($rows['max']->setting->setHere);
        self::assertStringContainsString('value="999"', $rows['max']->result->markup);

        // ⚠️ *Und der Renderer eines Feldes auf diesen Typ liest dieselbe Auflösung.*
        $feld  = Relation::attribute(10, $this->kontakt->id, $this->integer->id, RelationKind::Composition, 'nr', 0, Multiplicity::ExactlyOne);
        $drawn = $this->rendering->fieldsFor([$feld], [], Purpose::Edit, 'v');

        self::assertStringContainsString('size="4"', $drawn[0]->result->markup);
    }

    #[Test]
    public function the_chosen_renderer_object_brings_its_own_attributes_into_the_panel(): void
    {
        $compact = $this->settings->addObject(SettingsObject::create(CompactRenderer::class));
        $this->settings->addValue(SettingsValue::objectAtNode($this->kontakt->id, NodeAttributes::class, 'renderer', $compact->id, position: 1));
        $this->settings->addValue(SettingsValue::inObject($compact->id, CompactRenderer::class, 'orientation', TypedValue::ofText('vertical')));

        $rows = $this->rows($this->kontakt);

        self::assertSame('compact', $rows['renderer']->setting->value->text);
        self::assertArrayHasKey('orientation', $rows);
        self::assertSame('vertical', $rows['orientation']->setting->value->text);
        self::assertStringContainsString('value="vertical" selected', $rows['orientation']->result->markup);
        self::assertStringContainsString('value="horizontal"', $rows['orientation']->result->markup, 'die Fälle des Enums');
        self::assertArrayHasKey('with_label', $rows);
        self::assertTrue($rows['with_label']->setting->value->asBool(), 'die Vorgabe der Renderer-Klasse');
        self::assertStringContainsString('taxmod-toggle-track', $rows['with_label']->result->markup);
    }

    #[Test]
    public function the_edge_draws_the_node_value_as_inherited_and_its_own_as_set_here(): void
    {
        $this->settings->addValue(SettingsValue::atNode($this->integer->id, IntType::class, 'max', TypedValue::ofInt(999)));
        $feld = Relation::attribute(10, $this->kontakt->id, $this->integer->id, RelationKind::Composition, 'nr', 0, Multiplicity::ExactlyOne);
        $this->settings->addValue(SettingsValue::atNode($this->integer->id, IntType::class, 'min', TypedValue::ofInt(1), relationId: $feld->id));

        $rows = $this->rows($feld);

        self::assertSame(999, $rows['max']->setting->value->int);
        self::assertFalse($rows['max']->setting->setHere, 'vom Knoten geerbt');
        self::assertSame(1, $rows['min']->setting->value->int);
        self::assertTrue($rows['min']->setting->setHere, 'an der Kante gesetzt');
        self::assertArrayHasKey('multiplicity', $rows, 'die Spalten der Kante stehen daneben');
        self::assertArrayHasKey('read_only', $rows);
    }

    #[Test]
    public function a_spinner_reads_its_bounds_from_the_resolution(): void
    {
        $spinner = $this->settings->addObject(SettingsObject::create(SpinnerRenderer::class));
        $this->settings->addValue(SettingsValue::objectAtNode($this->integer->id, NodeAttributes::class, 'renderer', $spinner->id, position: 1));
        $this->settings->addValue(SettingsValue::atNode($this->integer->id, IntType::class, 'min', TypedValue::ofInt(1)));
        $this->settings->addValue(SettingsValue::atNode($this->integer->id, IntType::class, 'max', TypedValue::ofInt(999)));

        $feld  = Relation::attribute(10, $this->kontakt->id, $this->integer->id, RelationKind::Composition, 'nr', 0, Multiplicity::ExactlyOne);
        $drawn = $this->rendering->fieldsFor([$feld], [], Purpose::Edit, 'v');

        self::assertSame(SpinnerRenderer::NAME, $drawn[0]->rendererName);
        self::assertStringContainsString('min="1"', $drawn[0]->result->markup);
        self::assertStringContainsString('max="999"', $drawn[0]->result->markup);
    }

    #[Test]
    public function a_reference_attribute_offers_the_nodes_of_its_class(): void
    {
        $this->nodes->add(Node::create(8, 'form', '1.7', 7, 0, Constant::class));
        $this->nodes->add(Node::create(9, 'symbol', '1.7', 7, 1, Constant::class));
        $reference = $this->settings->addObject(SettingsObject::create(\Taxmod\Core\Renderer\ReferenceRenderer::class));
        $this->settings->addValue(SettingsValue::objectAtNode($this->kontakt->id, NodeAttributes::class, 'renderer', $reference->id, position: 1));
        $this->settings->addValue(SettingsValue::inObject($reference->id, \Taxmod\Core\Renderer\ReferenceRenderer::class, 'label_role', TypedValue::ofReference(9)));

        $rows = $this->rows($this->kontakt);

        self::assertArrayHasKey('label_role', $rows);
        self::assertSame('symbol', $rows['label_role']->setting->value->text, 'ein Verweis kommt als Wort');
        self::assertStringContainsString('value="form"', $rows['label_role']->result->markup);
        self::assertStringContainsString('value="symbol" selected', $rows['label_role']->result->markup);
    }
}
