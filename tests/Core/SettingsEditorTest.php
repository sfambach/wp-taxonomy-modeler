<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Exception\SettingDoesNotApply;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\NodeClass\Constant;
use Taxmod\Core\Model\NodeClass\Contracts;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\Type\IntType;
use Taxmod\Core\Renderer\CompactRenderer;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\SettingsEditor;
use Taxmod\Core\Service\SettingsResolver;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemorySettings;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/**
 * Schreiben in das Einstellungsmodell — nur Gesetztes, an Knoten und Kante, einfach und als Objekt
 * (Anforderung 4.5, 5.1–5.5).
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class SettingsEditorTest extends TestCase
{
    private InMemoryNodes $nodes;
    private InMemorySettings $settings;
    private SettingsResolver $resolver;
    private SettingsEditor $editor;
    private RecordedChanges $changes;
    private Node $kontakt;
    private Node $integer;
    private Relation $kante;

    protected function setUp(): void
    {
        Contracts::forget();

        $this->nodes    = new InMemoryNodes();
        $this->settings = new InMemorySettings();
        $this->changes  = new RecordedChanges();
        $this->resolver = new SettingsResolver($this->settings, $this->nodes, ShippedRenderers::registry(), ShippedConverters::registry());
        $this->editor   = new SettingsEditor($this->settings, $this->nodes, $this->resolver, ShippedRenderers::registry(), ShippedConverters::registry(), $this->changes);

        $root          = $this->nodes->add(Node::create(1, 'Root', null));
        $this->kontakt = $this->nodes->add(Node::create(2, 'Kontakt', '1', 1, 0, Category::class));
        $this->integer = $this->nodes->add(Node::create(3, 'Integer', '1', 1, 1, IntType::class));
        $this->kante   = Relation::attribute(10, $this->kontakt->id, $this->integer->id, RelationKind::Composition, 'nr', 0, Multiplicity::ExactlyOne);
    }

    #[Test]
    public function a_value_is_written_once_and_read_back(): void
    {
        self::assertTrue($this->editor->put($this->integer, 'max', '999'));
        self::assertSame(999, $this->resolver->forNode($this->integer)['max']->value->int);
        self::assertSame(1, $this->settings->countValues());
        self::assertSame(['setting max'], $this->changes->verbsFor($this->integer->id));
    }

    #[Test]
    public function a_conversion_is_an_object_whose_factor_and_offset_are_addressed_by_their_names(): void
    {
        $kilo = $this->nodes->add(Node::create(5, 'kilo', '1', 1, 3, Constant::class));

        self::assertTrue($this->editor->put($kilo, 'umrechnung', 'conversion'), 'der Umrechnungssatz heisst wie seine Klasse, klein');
        self::assertFalse($this->editor->put($kilo, 'umrechnung', 'conversion'), 'derselbe noch einmal');
        self::assertTrue($this->editor->put($kilo, 'factor', '1000'));
        self::assertFalse($this->editor->put($kilo, 'offset', '0'), 'die Vorgabe des Satzes schreibt keine Zeile');

        $aufgeloest = $this->resolver->forNode($kilo);
        self::assertSame('conversion', $aufgeloest['umrechnung']->value->text);
        self::assertSame('1000', $aufgeloest['factor']->value->decimal);
        self::assertSame('0', $aufgeloest['offset']->value->decimal, 'die Vorgabe aus der Klasse');
        self::assertSame([\Taxmod\Core\Model\Setting\Conversion::class], array_values($this->resolver->chosenObjectClasses($kilo)));
        self::assertSame(1, $this->settings->countObjects());
    }

    #[Test]
    public function a_list_entry_can_be_switched_off_at_the_node_and_the_row_stays(): void
    {
        $this->editor->put($this->integer, 'renderer', 'spinner');
        $glieder = $this->resolver->listOf($this->integer, 'renderer');

        self::assertCount(1, $glieder);
        self::assertSame('spinner', $glieder[0]->word);
        self::assertTrue($glieder[0]->aktiv);
        self::assertTrue($glieder[0]->setHere);

        self::assertTrue($this->editor->setListEntry($this->integer, 'renderer', $glieder[0]->rowId, false, null));
        self::assertFalse($this->editor->setListEntry($this->integer, 'renderer', $glieder[0]->rowId, false, null), 'dasselbe noch einmal');
        self::assertFalse($this->resolver->listOf($this->integer, 'renderer')[0]->aktiv);
        self::assertFalse($this->resolver->forNode($this->integer)['renderer']->setHere, 'nur die Vorgabe steht da');
        self::assertSame(1, $this->settings->countValues(), 'abschalten ist nicht löschen');

        self::assertTrue($this->editor->setListEntry($this->integer, 'renderer', $glieder[0]->rowId, true, null));
        self::assertSame('spinner', $this->resolver->forNode($this->integer)['renderer']->value->text);
    }

    #[Test]
    public function at_the_edge_an_inherited_entry_gets_its_own_row_and_the_edge_wins(): void
    {
        $this->editor->put($this->integer, 'renderer', 'spinner');
        $this->editor->put($this->integer, 'renderer', 'slider', $this->kante);

        $anDerKante = $this->resolver->listOf($this->integer, 'renderer', $this->kante);
        self::assertSame(['slider', 'spinner'], array_map(static fn ($g): string => $g->word, $anDerKante), 'das eigene Glied vor dem geerbten');
        self::assertTrue($anDerKante[0]->setHere);
        self::assertFalse($anDerKante[1]->aktiv, 'das geerbte Glied ist an der Kante abgeschaltet (5.5.3)');
        self::assertSame('slider', $this->resolver->forUseSite($this->kante, $this->integer)['renderer']->value->text);

        self::assertFalse($this->editor->put($this->integer, 'renderer', 'slider', $this->kante), 'was schon gilt, wird nicht noch einmal gesetzt');

        $zeilenVorher = $this->settings->countValues();
        self::assertTrue($this->editor->setListEntry($this->integer, 'renderer', $anDerKante[1]->rowId, true, 0, $this->kante));
        self::assertTrue($this->editor->setListEntry($this->integer, 'renderer', $anDerKante[0]->rowId, null, 1, $this->kante));
        self::assertSame($zeilenVorher, $this->settings->countValues(), 'die Zeile mit Kante gab es schon — keine dritte');

        $umgeordnet = $this->resolver->listOf($this->integer, 'renderer', $this->kante);
        self::assertSame(['spinner', 'slider'], array_map(static fn ($g): string => $g->word, $umgeordnet));
        self::assertSame('spinner', $this->resolver->forUseSite($this->kante, $this->integer)['renderer']->value->text, 'gezeichnet wird das erste aktive Glied');
        self::assertSame('spinner', $this->resolver->forNode($this->integer)['renderer']->value->text, 'der Knoten ist unberührt');

        $amKnoten = $this->resolver->listOf($this->integer, 'renderer');
        $this->editor->setListEntry($this->integer, 'renderer', $amKnoten[0]->rowId, false, null);
        self::assertFalse($this->resolver->forNode($this->integer)['renderer']->setHere, 'nur die Vorgabe steht da');
        self::assertSame('spinner', $this->resolver->forUseSite($this->kante, $this->integer)['renderer']->value->text, 'die Kante gilt über dem Knoten (Z3a)');
    }

    #[Test]
    public function a_row_of_another_node_or_edge_is_refused(): void
    {
        $this->editor->put($this->integer, 'renderer', 'spinner');
        $andere = $this->nodes->add(Node::create(4, 'Andere Zahl', '1', 1, 2, IntType::class));
        $zeile  = $this->resolver->listOf($this->integer, 'renderer')[0]->rowId;

        $this->expectException(SettingDoesNotApply::class);
        $this->editor->setListEntry($andere, 'renderer', $zeile, false, null);
    }

    #[Test]
    public function what_equals_the_default_writes_nothing(): void
    {
        self::assertFalse($this->editor->put($this->integer, 'display_size', '20'), 'die Vorgabe des Vertrags');
        self::assertFalse($this->editor->put($this->integer, 'step', '1'));
        self::assertSame(0, $this->settings->countValues());
    }

    #[Test]
    public function the_same_value_again_writes_nothing_and_a_change_keeps_the_old_one_in_the_shadow(): void
    {
        $this->editor->put($this->integer, 'max', '999');

        self::assertFalse($this->editor->put($this->integer, 'max', '999'));
        self::assertTrue($this->editor->put($this->integer, 'max', '99'));
        self::assertSame(99, $this->resolver->forNode($this->integer)['max']->value->int);
        self::assertSame(999, $this->settings->shadow[0]->value->int);
    }

    #[Test]
    public function an_empty_value_takes_the_row_out(): void
    {
        $this->editor->put($this->integer, 'max', '999');

        self::assertTrue($this->editor->put($this->integer, 'max', ''));
        self::assertSame(0, $this->settings->countValues());
        self::assertArrayNotHasKey('max', $this->resolver->forNode($this->integer));
        self::assertFalse($this->editor->put($this->integer, 'max', ''), 'nichts zu löschen');
    }

    #[Test]
    public function a_value_at_the_edge_leaves_the_node_alone(): void
    {
        $this->editor->put($this->integer, 'max', '999');

        self::assertTrue($this->editor->put($this->integer, 'max', '99', $this->kante));
        self::assertSame(99, $this->resolver->forUseSite($this->kante, $this->integer)['max']->value->int);
        self::assertSame(999, $this->resolver->forNode($this->integer)['max']->value->int);
        self::assertSame(2, $this->settings->countValues());
    }

    #[Test]
    public function the_type_comes_from_the_contract_and_nonsense_is_refused(): void
    {
        $this->expectException(SettingDoesNotApply::class);

        $this->editor->put($this->integer, 'max', 'viele');
    }

    #[Test]
    public function an_unknown_attribute_is_refused(): void
    {
        $this->expectException(SettingDoesNotApply::class);

        $this->editor->put($this->integer, 'farbe', 'rot');
    }

    #[Test]
    public function choosing_a_renderer_makes_an_object_and_its_attributes_become_writable(): void
    {
        self::assertTrue($this->editor->put($this->kontakt, 'renderer', 'compact'));
        self::assertSame(1, $this->settings->countObjects());
        self::assertSame('compact', $this->resolver->forNode($this->kontakt)['renderer']->value->text);

        self::assertTrue($this->editor->put($this->kontakt, 'orientation', 'vertical'));
        self::assertSame('vertical', $this->resolver->forNode($this->kontakt)['orientation']->value->text);
        self::assertFalse($this->editor->put($this->kontakt, 'with_label', '1'), 'entspricht der Vorgabe des Renderers');
        self::assertTrue($this->editor->put($this->kontakt, 'with_label', '0'));
        self::assertFalse($this->resolver->forNode($this->kontakt)['with_label']->value->asBool());

        self::assertFalse($this->editor->put($this->kontakt, 'renderer', 'compact'), 'dieselbe Wahl noch einmal');
    }

    #[Test]
    public function choosing_another_renderer_replaces_the_object_and_its_rows_wander(): void
    {
        $this->editor->put($this->kontakt, 'renderer', 'compact');
        $this->editor->put($this->kontakt, 'orientation', 'vertical');

        self::assertTrue($this->editor->put($this->kontakt, 'renderer', 'table'));
        self::assertSame('table', $this->resolver->forNode($this->kontakt)['renderer']->value->text);
        self::assertSame(1, $this->settings->countObjects(), 'das alte Objekt ist gewandert');
        self::assertSame('horizontal', $this->resolver->forNode($this->kontakt)['orientation']->value->text, 'die Vorgabe des neuen Objekts');
    }

    #[Test]
    public function an_enum_refuses_a_case_that_does_not_exist(): void
    {
        $this->editor->put($this->kontakt, 'renderer', 'compact');

        $this->expectException(SettingDoesNotApply::class);

        $this->editor->put($this->kontakt, 'orientation', 'diagonal');
    }

    #[Test]
    public function at_the_edge_another_renderer_switches_the_inherited_one_off_and_adds_its_own(): void
    {
        $this->editor->put($this->integer, 'renderer', 'spinner');

        self::assertTrue($this->editor->put($this->integer, 'renderer', 'slider', $this->kante));
        self::assertSame('slider', $this->resolver->forUseSite($this->kante, $this->integer)['renderer']->value->text);
        self::assertSame('spinner', $this->resolver->forNode($this->integer)['renderer']->value->text, 'der Knoten bleibt, wie er war');
    }

    #[Test]
    public function an_inner_value_at_the_edge_overrides_one_value_of_the_inherited_renderer(): void
    {
        $this->editor->put($this->kontakt, 'renderer', 'compact');
        $feld = Relation::attribute(11, $this->integer->id, $this->kontakt->id, RelationKind::Composition, 'kontakt', 0, Multiplicity::ExactlyOne);

        self::assertTrue($this->editor->put($this->kontakt, 'orientation', 'vertical', $feld));
        self::assertSame('vertical', $this->resolver->forUseSite($feld, $this->kontakt)['orientation']->value->text);
        self::assertSame('horizontal', $this->resolver->forNode($this->kontakt)['orientation']->value->text);
    }

    #[Test]
    public function a_reference_is_written_from_the_name_of_the_node(): void
    {
        $roles  = $this->nodes->add(Node::create(7, 'Label roles', '1', 1, 3, \Taxmod\Core\Model\NodeClass\Choice::class));
        $symbol = $this->nodes->add(Node::create(8, 'symbol', '1.7', 7, 0, Constant::class));
        $this->editor->put($this->kontakt, 'renderer', 'reference');

        self::assertTrue($this->editor->put($this->kontakt, 'label_role', 'symbol'));
        self::assertSame('symbol', $this->resolver->forNode($this->kontakt)['label_role']->value->text);

        $this->expectException(SettingDoesNotApply::class);

        $this->editor->put($this->kontakt, 'label_role', 'nirgends');
    }
}
