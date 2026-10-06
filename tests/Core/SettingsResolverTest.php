<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\NodeClass\Constant;
use Taxmod\Core\Model\NodeClass\Contracts;
use Taxmod\Core\Model\NodeClass\NodeAttributes;
use Taxmod\Core\Model\NodeClass\Unit;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\Setting\SettingsObject;
use Taxmod\Core\Model\Setting\SettingsValue;
use Taxmod\Core\Model\Type\IntType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\CompactRenderer;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\TableRenderer;
use Taxmod\Core\Service\SettingsResolver;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemorySettings;

/**
 * Die Auflösung Kante → Knoten → Vertrag, aus `settings_value` (Anforderung 5.7, 5.1–5.5).
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class SettingsResolverTest extends TestCase
{
    private InMemoryNodes $nodes;
    private InMemorySettings $settings;
    private SettingsResolver $resolver;
    private Node $kontakt;
    private Node $hausnummer;
    private Relation $kante;

    protected function setUp(): void
    {
        Contracts::forget();

        $this->nodes    = new InMemoryNodes();
        $this->settings = new InMemorySettings();
        $this->resolver = new SettingsResolver($this->settings, $this->nodes, ShippedRenderers::registry());

        $root             = $this->nodes->add(Node::create(1, 'Root', null));
        $this->kontakt    = $this->nodes->add(Node::create(2, 'Kontakt', $root->path, $root->id, 0, Category::class));
        $this->hausnummer = $this->nodes->add(Node::create(3, 'Hausnummer', $root->path, $root->id, 1, IntType::class));
        $this->kante      = Relation::attribute(10, $this->kontakt->id, $this->hausnummer->id, RelationKind::Composition, 'nr', 0, Multiplicity::ExactlyOne);
    }

    #[Test]
    public function without_a_row_the_contract_answers(): void
    {
        $aus = $this->resolver->forNode($this->hausnummer);

        self::assertSame(20, $aus['display_size']->value->int);
        self::assertFalse($aus['display_size']->setHere);
        self::assertSame(0, $aus['display_size']->fromOwnerId, 'die Vorgabe gehört niemandem');
        self::assertSame(1, $aus['step']->value->int);
        self::assertArrayNotHasKey('min', $aus, 'ohne Vorgabe und ohne Zeile: nichts');
        self::assertFalse($aus['renderer']->setHere, 'kein Renderer gewählt: die Vorgabe der Registratur steht da, nicht hier gesetzt');
        self::assertSame(0, $aus['renderer']->fromOwnerId);
    }

    #[Test]
    public function a_row_at_the_node_beats_the_contract(): void
    {
        $this->settings->addValue(SettingsValue::atNode($this->hausnummer->id, IntType::class, 'max', TypedValue::ofInt(999)));
        $this->settings->addValue(SettingsValue::atNode($this->hausnummer->id, \Taxmod\Core\Model\Type\SpecialisedType::class, 'display_size', TypedValue::ofInt(4)));

        $aus = $this->resolver->forNode($this->hausnummer);

        self::assertSame(999, $aus['max']->value->int);
        self::assertTrue($aus['max']->setHere);
        self::assertSame($this->hausnummer->id, $aus['max']->fromOwnerId);
        self::assertSame(4, $aus['display_size']->value->int);
    }

    #[Test]
    public function a_row_at_the_edge_beats_the_node_and_the_node_stays_untouched(): void
    {
        $this->settings->addValue(SettingsValue::atNode($this->hausnummer->id, IntType::class, 'max', TypedValue::ofInt(999)));
        $this->settings->addValue(SettingsValue::atNode($this->hausnummer->id, IntType::class, 'max', TypedValue::ofInt(99), relationId: $this->kante->id));

        $anKante  = $this->resolver->forUseSite($this->kante, $this->hausnummer);
        $amKnoten = $this->resolver->forNode($this->hausnummer);

        self::assertSame(99, $anKante['max']->value->int);
        self::assertTrue($anKante['max']->setHere);
        self::assertSame($this->kante->id, $anKante['max']->fromOwnerId);
        self::assertSame(20, $anKante['display_size']->value->int, 'was die Kante nicht setzt, gilt vom Knoten oder Vertrag');
        self::assertSame(999, $amKnoten['max']->value->int);
    }

    #[Test]
    public function an_inherited_value_at_the_edge_is_marked_as_not_set_here(): void
    {
        $this->settings->addValue(SettingsValue::atNode($this->hausnummer->id, IntType::class, 'max', TypedValue::ofInt(999)));

        $anKante = $this->resolver->forUseSite($this->kante, $this->hausnummer);

        self::assertSame(999, $anKante['max']->value->int);
        self::assertFalse($anKante['max']->setHere);
        self::assertSame($this->hausnummer->id, $anKante['max']->fromOwnerId);
    }

    #[Test]
    public function the_chosen_renderer_is_the_first_of_the_list_with_its_own_attributes(): void
    {
        $compact = $this->settings->addObject(SettingsObject::create(CompactRenderer::class));
        $table   = $this->settings->addObject(SettingsObject::create(TableRenderer::class));
        $this->settings->addValue(SettingsValue::objectAtNode($this->kontakt->id, NodeAttributes::class, 'renderer', $table->id, position: 2));
        $this->settings->addValue(SettingsValue::objectAtNode($this->kontakt->id, NodeAttributes::class, 'renderer', $compact->id, position: 1));
        $this->settings->addValue(SettingsValue::inObject($compact->id, CompactRenderer::class, 'orientation', TypedValue::ofText('vertical')));

        $aus = $this->resolver->forNode($this->kontakt);

        self::assertSame('compact', $aus['renderer']->value->text);
        self::assertTrue($aus['renderer']->setHere);
        self::assertSame('vertical', $aus['orientation']->value->text, 'die eigene Zeile des Objekts');
        self::assertTrue($aus['with_label']->value->asBool(), 'die Vorgabe der Renderer-Klasse');
        self::assertFalse($aus['with_label']->setHere);
    }

    #[Test]
    public function the_edge_overrides_one_value_inside_the_inherited_renderer(): void
    {
        $compact = $this->settings->addObject(SettingsObject::create(CompactRenderer::class));
        $this->settings->addValue(SettingsValue::objectAtNode($this->hausnummer->id, NodeAttributes::class, 'renderer', $compact->id, position: 1));
        $this->settings->addValue(SettingsValue::inObject($compact->id, CompactRenderer::class, 'orientation', TypedValue::ofText('vertical')));
        $this->settings->addValue(SettingsValue::inObject($compact->id, CompactRenderer::class, 'orientation', TypedValue::ofText('horizontal'), relationId: $this->kante->id));

        $anKante  = $this->resolver->forUseSite($this->kante, $this->hausnummer);
        $amKnoten = $this->resolver->forNode($this->hausnummer);

        self::assertSame('compact', $anKante['renderer']->value->text);
        self::assertSame('horizontal', $anKante['orientation']->value->text, 'Lesart A: der eine Wert, direkt im geerbten Objekt');
        self::assertSame($this->kante->id, $anKante['orientation']->fromOwnerId);
        self::assertSame('vertical', $amKnoten['orientation']->value->text);
    }

    #[Test]
    public function the_edge_may_switch_an_inherited_entry_off_and_add_its_own(): void
    {
        $compact = $this->settings->addObject(SettingsObject::create(CompactRenderer::class));
        $table   = $this->settings->addObject(SettingsObject::create(TableRenderer::class));
        $this->settings->addValue(SettingsValue::objectAtNode($this->hausnummer->id, NodeAttributes::class, 'renderer', $compact->id, position: 1));
        // ⚠️ an der Kante: den geerbten Eintrag aus, einen eigenen dazu (5.5.3, 5.5.1)
        $this->settings->addValue(SettingsValue::objectAtNode($this->hausnummer->id, NodeAttributes::class, 'renderer', $compact->id, relationId: $this->kante->id, position: 1, aktiv: false));
        $this->settings->addValue(SettingsValue::objectAtNode($this->hausnummer->id, NodeAttributes::class, 'renderer', $table->id, relationId: $this->kante->id, position: 2));

        $anKante  = $this->resolver->forUseSite($this->kante, $this->hausnummer);
        $amKnoten = $this->resolver->forNode($this->hausnummer);

        self::assertSame('table', $anKante['renderer']->value->text);
        self::assertSame('compact', $amKnoten['renderer']->value->text);
    }

    #[Test]
    public function the_edge_may_reorder_inherited_entries(): void
    {
        $compact = $this->settings->addObject(SettingsObject::create(CompactRenderer::class));
        $table   = $this->settings->addObject(SettingsObject::create(TableRenderer::class));
        $this->settings->addValue(SettingsValue::objectAtNode($this->hausnummer->id, NodeAttributes::class, 'renderer', $compact->id, position: 1));
        $this->settings->addValue(SettingsValue::objectAtNode($this->hausnummer->id, NodeAttributes::class, 'renderer', $table->id, position: 2));
        // ⚠️ an der Kante: die Tabelle nach vorn (5.5.2)
        $this->settings->addValue(SettingsValue::objectAtNode($this->hausnummer->id, NodeAttributes::class, 'renderer', $table->id, relationId: $this->kante->id, position: 0));

        self::assertSame('table', $this->resolver->forUseSite($this->kante, $this->hausnummer)['renderer']->value->text);
        self::assertSame('compact', $this->resolver->forNode($this->hausnummer)['renderer']->value->text);
    }

    #[Test]
    public function a_node_reference_arrives_as_the_name_of_the_node(): void
    {
        $prefixes = $this->nodes->add(Node::create(4, 'Prefixes', '1', 1, 2, \Taxmod\Core\Model\NodeClass\Choice::class));
        $kilo     = $this->nodes->add(Node::create(5, 'kilo', '1.4', $prefixes->id, 0, Constant::class));
        $gramm    = $this->nodes->add(Node::create(6, 'Gramm', '1', 1, 3, Unit::class));
        $this->settings->addValue(SettingsValue::atNode($gramm->id, Unit::class, 'erlaubte_praefixe', TypedValue::ofReference($kilo->id), position: 1));
        $this->settings->addValue(SettingsValue::atNode($gramm->id, Unit::class, 'symbol', TypedValue::ofText('g')));

        $aus = $this->resolver->forNode($gramm);

        self::assertSame('kilo', $aus['erlaubte_praefixe']->value->text);
        self::assertSame('g', $aus['symbol']->value->text);
        self::assertFalse($aus['mit_praefix']->value->asBool());
    }

    #[Test]
    public function a_node_is_resolved_once_and_then_held(): void
    {
        $erste = $this->resolver->forNode($this->hausnummer);

        $this->settings->addValue(SettingsValue::atNode($this->hausnummer->id, IntType::class, 'max', TypedValue::ofInt(5)));

        self::assertSame($erste, $this->resolver->forNode($this->hausnummer), 'gehalten, bis jemand vergisst');

        $this->resolver->forget();

        self::assertSame(5, $this->resolver->forNode($this->hausnummer)['max']->value->int);
    }
}
