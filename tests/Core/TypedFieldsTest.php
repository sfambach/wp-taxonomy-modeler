<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\NotAValueOfThatType;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Model\Type\DateTimeType;
use Taxmod\Core\Renderer\ColorRenderer;
use Taxmod\Core\Renderer\CompactRenderer;
use Taxmod\Core\Renderer\DateTimeRenderer;
use Taxmod\Core\Renderer\DialogChooserRenderer;
use Taxmod\Core\Renderer\Section;
use Taxmod\Core\Renderer\FieldRenderer;
use Taxmod\Core\Renderer\FormRenderer;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\MailtoRenderer;
use Taxmod\Core\Renderer\NodeRenderer;
use Taxmod\Core\Renderer\PlainRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\TableRenderer;
use Taxmod\Core\Renderer\ReferenceRenderer;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\SliderRenderer;
use Taxmod\Core\Renderer\SpinnerRenderer;
use Taxmod\Core\Renderer\Surroundings;
use Taxmod\Core\Renderer\CheckboxRenderer;
use Taxmod\Core\Renderer\TextareaRenderer;
use Taxmod\Core\Renderer\ToggleRenderer;
use Taxmod\Core\Renderer\UserRefRenderer;

/**
 * The renderers that draw one typed value, and the reading back that pairs with them.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class TypedFieldsTest extends TestCase
{
    private Node $subject;

    protected function setUp(): void
    {
        $this->subject = Node::create(1, 'Widerstand', null);
    }

    /** @param array<string, TypedValue> $settings */
    private function context(
        Purpose $purpose,
        TypedValue $value,
        ?SimpleType $type = null,
        array $settings = [],
        string $field = '',
    ): RenderContext {
        $resolved = [];

        foreach ($settings as $key => $one) {
            $resolved[$key] = new ResolvedSetting($key, $one, 1, true);
        }

        return new RenderContext($purpose, $value, $resolved, '', Level::Admin, true, $field, $type);
    }

    // ---------------------------------------------------------- the shipped set

    #[Test]
    public function every_type_that_has_a_default_gets_the_renderer_it_should(): void
    {
        $registry = ShippedRenderers::registry();

        // An enum cannot be an array key, so the pairs are a list.
        $expected = [
            [SimpleType::Text, FieldRenderer::NAME],
            [SimpleType::Char, FieldRenderer::NAME],
            [SimpleType::Version, FieldRenderer::NAME],
            [SimpleType::Int, FieldRenderer::NAME],
            [SimpleType::Decimal, FieldRenderer::NAME],
            [SimpleType::Bool, ToggleRenderer::NAME],
            [SimpleType::Email, MailtoRenderer::NAME],
            [SimpleType::DateTime, DateTimeRenderer::NAME],
            [SimpleType::Color, ColorRenderer::NAME],
        ];

        foreach ($expected as [$type, $name]) {
            self::assertSame($name, $registry->defaultFor($type)->name(), $type->value);
        }
    }

    #[Test]
    public function both_kinds_of_reference_have_their_own_renderer(): void
    {
        // ⚠️ **Hier stand «und ein Benutzerschlüssel von nichts»**, mit der Begründung, `user_ref`
        // greife vom Rand in den Kern hinein und ein stilles `plain` wäre schlimmer (R14b).
        // **Die Begründung war richtig und die Folgerung falsch** ([D-649](../../docs/NewConcept/90-decision-log.md)):
        // der Rand greift nicht hinein, er **reicht den Namen herein** — dieselbe Naht, über die ein
        // Knotenverweis seine Beschriftung bekommt (D-159). *Die Zusage wandert also mit dem Modell
        // mit, statt einen vergangenen Zustand zu bewachen (`PR-9`).*
        $registry = ShippedRenderers::registry();

        self::assertSame(ReferenceRenderer::NAME, $registry->defaultFor(SimpleType::NodeRef)->name());
        self::assertSame(UserRefRenderer::NAME, $registry->defaultFor(SimpleType::UserRef)->name());

        // ⚠️ *Und keiner der beiden ist der Rückfall — sonst wäre die Zusage oben auch dann grün,
        // wenn wieder nichts zeichnete.*
        self::assertNotSame(PlainRenderer::NAME, $registry->defaultFor(SimpleType::UserRef)->name());
    }

    #[Test]
    public function a_reference_shows_the_targets_name_and_declines_being_edited(): void
    {
        $renderer = new ReferenceRenderer();

        $shown = $renderer->render(
            $this->subject,
            new RenderContext(
                purpose: Purpose::Display,
                value: TypedValue::ofReference(4711),
                type: SimpleType::NodeRef,
                surroundings: new Surroundings(refersTo: 'Gramm'),
            )
        );

        self::assertStringContainsString('Gramm', $shown->markup);
        self::assertStringNotContainsString('4711', $shown->markup);

        // ⚠️ **Er zeichnet jetzt auch beim Bearbeiten, und zwar dasselbe** *(2026-09-06, auf seine
        // Anweisung «für die Base units ist das einfach Referenz für alle»)*. **Der alte Grund —
        // «Ändern heisst wählen, und der Wähler ist nicht gebaut» — ist erledigt:** *der Wähler steht
        // seit D-589, und er steht an der **Verwendungsstelle**. Wo die Konstante selbst gezeichnet
        // wird, zeigt man ihre Beschriftung; ein Kasten für eine Id war nie gemeint und ist es
        // weiterhin nicht.*
        //
        // ⚠️ *Was ohne diese Zeile geschah, ist gemessen: `Base units` trug `reference`, und im Zweck
        // «bearbeiten» loeste **nichts** auf — der Waechter meldete «gespeichert `reference`,
        // gezeichnet nichts».*
        self::assertSame([Purpose::Display, Purpose::Edit], $renderer->supports());
    }

    #[Test]
    public function a_reference_whose_label_never_arrived_is_drawn_as_a_fault(): void
    {
        // ⚠️ It means the target is gone, or the descent did not resolve it. A bare number is the
        // sort of thing that gets copied into a spreadsheet as if it meant something.
        $shown = (new ReferenceRenderer())->render(
            $this->subject,
            $this->context(Purpose::Display, TypedValue::ofReference(4711), SimpleType::NodeRef)
        );

        self::assertStringContainsString('taxmod-dangling', $shown->markup);
        self::assertStringContainsString('#4711', $shown->markup);
    }

    /**
     * ⚠️ **Derselbe Fall im Bedienweg** ([D-604](../../docs/NewConcept/90-decision-log.md), TASK-038).
     *
     * ⚠️ *Der Auswahldialog zeichnete «zeigt auf einen geloeschten Knoten» als Gedankenstrich —
     * **ununterscheidbar von «nichts gewaehlt»**. Sein Wort: «das muss sichtbar sein, also am Feld
     * in der Kante.»*
     */
    #[Test]
    public function the_chooser_tells_a_deleted_target_apart_from_no_choice(): void
    {
        $baum = new Section('', '<span class="row">Gramm</span>');

        $ins_leere = (new DialogChooserRenderer())->render(
            $this->subject,
            new RenderContext(
                purpose: Purpose::Edit,
                value: TypedValue::ofReference(4711),
                editable: true,
                fieldName: 'wert',
                type: SimpleType::NodeRef,
                surroundings: new Surroundings(
                    sections: [DialogChooserRenderer::CANDIDATES => $baum]
                ),
            )
        )->markup;

        $nichts = (new DialogChooserRenderer())->render(
            $this->subject,
            new RenderContext(
                purpose: Purpose::Edit,
                value: TypedValue::nothing(),
                editable: true,
                fieldName: 'wert',
                type: SimpleType::NodeRef,
                surroundings: new Surroundings(
                    sections: [DialogChooserRenderer::CANDIDATES => $baum]
                ),
            )
        )->markup;

        self::assertStringContainsString('taxmod-dangling', $ins_leere);
        self::assertStringContainsString('#4711', $ins_leere);

        // ⚠️ *Der Gegenfall traegt die Zusage: ohne ihn waere «markiert» auch dann wahr, wenn
        // **jede** leere Wahl markiert wuerde — und das Mal saehe man ueberall und nirgends.*
        self::assertStringNotContainsString('taxmod-dangling', $nichts);
        self::assertStringContainsString('taxmod-nothing', $nichts);
    }

    #[Test]
    public function three_renderers_are_offered_for_a_number_and_the_plain_one_is_the_default(): void
    {
        // D-018, confirmed independently by the legacy `Spinner` / `Range` choices.
        $registry = ShippedRenderers::registry();

        $names = array_map(
            static fn ($renderer): string => $renderer->name(),
            $registry->eligibleFor($this->subject, SimpleType::Int)
        );

        sort($names);

        self::assertSame([FieldRenderer::NAME, SliderRenderer::NAME, SpinnerRenderer::NAME], $names);
        self::assertSame(FieldRenderer::NAME, $registry->defaultFor(SimpleType::Int)->name());
    }

    #[Test]
    public function a_textarea_is_offered_for_a_text_and_never_for_a_number(): void
    {
        $registry = ShippedRenderers::registry();

        $forText = array_map(
            static fn ($renderer): string => $renderer->name(),
            $registry->eligibleFor($this->subject, SimpleType::Text)
        );

        self::assertContains(TextareaRenderer::NAME, $forText);
        self::assertNotContains(
            TextareaRenderer::NAME,
            array_map(
                static fn ($renderer): string => $renderer->name(),
                $registry->eligibleFor($this->subject, SimpleType::Decimal)
            )
        );
    }

    #[Test]
    public function no_typed_renderer_claims_the_search_purpose_yet(): void
    {
        // ⚠️ Declining is honest: a search rendering is a **condition** feeding a query builder
        // (D-165) and there is no query builder. Claiming it would hand back a control nothing
        // can execute.
        $registry = ShippedRenderers::registry();

        foreach (SimpleType::cases() as $type) {
            self::assertSame(
                [],
                $registry->eligibleFor($this->subject, $type, Purpose::Search),
                $type->value
            );
        }
    }

    #[Test]
    public function a_node_with_no_simple_type_is_offered_the_structural_renderers_only(): void
    {
        // ⚠️ **This assertion used to read *nothing at all*, and the change is the point.** A node
        // under `Model` wants a **structural** renderer — chosen for what it **is** — and until the
        // form renderer existed there was none, so an empty list was the honest answer. Now there
        // is one, and offering a spinner for a supplier is still the mistake it always was.
        $names = array_map(
            static fn ($renderer): string => $renderer->name(),
            ShippedRenderers::registry()->eligibleFor($this->subject, null)
        );

        sort($names);
        // ⚠️ *`table` ist seit [D-542](../../docs/NewConcept/90-decision-log.md) dabei — ein Behälter
        // ohne Typ, wie `form` und `compact`. **Diese Zusage hat den neuen Renderer gemeldet**, und
        // das ist ihre Aufgabe: sie haelt fest, was einem Knoten angeboten wird.*
        //
        // ⚠️ **`page` ist seit [D-670](../../docs/NewConcept/90-decision-log.md) **nicht** mehr dabei**
        // — *sein Wort: «keiner unserer Knoten-Renderer, sondern der der Seite … sollte nicht Teil der
        // Renderer sein, die der Benutzer auswählen kann». Er heisst seitdem `page` statt `node` und
        // wird wie die Baumzelle nur von der Oberfläche gerufen.*
        self::assertSame(
            [CompactRenderer::NAME, FormRenderer::NAME, TableRenderer::NAME],
            $names
        );
    }

    #[Test]
    public function a_structural_renderer_declares_no_type_and_a_typed_one_declares_no_structure(): void
    {
        // ⚠️ The two axes stay apart: `handles()` is the type key (R14a), `fits()` the structural
        // question. A form is not offered *for an integer*, and a spinner is not offered for a
        // thing that has no type.
        $registry = ShippedRenderers::registry();

        self::assertSame([], (new FormRenderer())->handles());
        self::assertNotContains(
            FormRenderer::NAME,
            array_map(
                static fn ($renderer): string => $renderer->name(),
                $registry->eligibleFor($this->subject, SimpleType::Int)
            )
        );
    }

    // ------------------------------------------------------------ the controls

    #[Test]
    public function a_boolean_that_nobody_answered_is_not_drawn_as_false(): void
    {
        $result = (new CheckboxRenderer())->render(
            $this->subject,
            $this->context(Purpose::Display, TypedValue::nothing(), SimpleType::Bool)
        );

        self::assertStringNotContainsString('checkbox', $result->markup);
    }

    #[Test]
    public function an_unticked_box_submits_false_rather_than_nothing(): void
    {
        // ⚠️ Without the hidden field an unticked box is absent from the request, which would be
        // read as *not answered* — and every mandatory check would become unanswerable.
        $result = (new CheckboxRenderer())->render(
            $this->subject,
            $this->context(Purpose::Edit, TypedValue::ofBool(false), SimpleType::Bool, [], 'v[7]')
        );

        self::assertStringContainsString('<input type="hidden" name="v[7]" value="0">', $result->markup);
        self::assertStringContainsString('type="checkbox"', $result->markup);
        self::assertStringNotContainsString('checked', $result->markup);
    }

    #[Test]
    public function a_ticked_box_is_ticked(): void
    {
        $result = (new CheckboxRenderer())->render(
            $this->subject,
            $this->context(Purpose::Edit, TypedValue::ofBool(true), SimpleType::Bool, [], 'v[7]')
        );

        self::assertStringContainsString('checked', $result->markup);
    }

    #[Test]
    public function a_spinner_carries_the_bounds_the_chain_resolved(): void
    {
        $result = (new SpinnerRenderer())->render(
            $this->subject,
            $this->context(Purpose::Edit, TypedValue::ofInt(5), SimpleType::Int, [
                SettingKey::Min->value => TypedValue::ofInt(1),
                SettingKey::Max->value => TypedValue::ofInt(10),
            ], 'v[7]')
        );

        self::assertStringContainsString('min="1"', $result->markup);
        self::assertStringContainsString('max="10"', $result->markup);
        self::assertStringContainsString('step="1"', $result->markup);
    }

    #[Test]
    public function the_step_is_a_setting_on_the_node_like_the_two_bounds_beside_it(): void
    {
        // ⚠️ R17 names min, max and step in one breath as settings a numeric **node** has. `step`
        // was briefly a free key here, which made one of three siblings an outsider.
        self::assertTrue(SettingKey::isReserved('step'));

        foreach ([new SpinnerRenderer(), new SliderRenderer()] as $renderer) {
            $result = $renderer->render(
                $this->subject,
                $this->context(Purpose::Edit, TypedValue::ofInt(10), SimpleType::Int, [
                    SettingKey::Step->value => TypedValue::ofInt(5),
                ], 'v[7]')
            );

            self::assertStringContainsString('step="5"', $result->markup, $renderer->name());
        }
    }

    #[Test]
    public function a_decimal_spinner_does_not_refuse_decimals(): void
    {
        // ⚠️ The step comes off the **type**, which the context is told. Guessing it from the
        // value would make an empty decimal field step by one and silently reject `2.5`.
        $result = (new SpinnerRenderer())->render(
            $this->subject,
            $this->context(Purpose::Edit, TypedValue::nothing(), SimpleType::Decimal, [], 'v[7]')
        );

        self::assertStringContainsString('step="any"', $result->markup);
    }

    #[Test]
    public function a_plain_integer_field_does_not_offer_letters_it_will_then_refuse(): void
    {
        // ⚠️ R28: a control offers only real choices. Reported by the owner — the field accepted
        // letters and the core refused them, which is the trap that rule exists to prevent.
        $result = (new FieldRenderer())->render(
            $this->subject,
            $this->context(Purpose::Edit, TypedValue::nothing(), SimpleType::Int, [], 'v[7]')
        );

        self::assertStringContainsString('pattern="-?\d+"', $result->markup);
        self::assertStringContainsString('inputmode="numeric"', $result->markup);

        // ⚠️ Not `type="number"`: it reports an **empty value** for content it cannot parse, and
        // empty here means *clear this attribute* — a stray keystroke would delete a value.
        self::assertStringContainsString('type="text"', $result->markup);
    }

    #[Test]
    public function the_control_and_the_core_check_one_rule_rather_than_two(): void
    {
        // ⚠️ The pattern in the markup and the rule `valueFrom()` applies are the **same string**.
        // Two copies is how a control comes to accept what its core refuses.
        foreach ([SimpleType::Int, SimpleType::Decimal] as $type) {
            $pattern = $type->pattern();
            self::assertNotNull($pattern);

            $markup = (new FieldRenderer())->render(
                $this->subject,
                $this->context(Purpose::Edit, TypedValue::nothing(), $type, [], 'v[7]')
            )->markup;

            self::assertStringContainsString('pattern="' . $pattern . '"', $markup, $type->value);
        }
    }

    #[Test]
    public function a_text_field_is_not_given_a_pattern_it_has_no_business_having(): void
    {
        // A text takes any characters; validity beyond the shape is a validator's question (D-319).
        $result = (new FieldRenderer())->render(
            $this->subject,
            $this->context(Purpose::Edit, TypedValue::nothing(), SimpleType::Text, [], 'v[7]')
        );

        self::assertStringNotContainsString('pattern', $result->markup);
        self::assertStringNotContainsString('inputmode', $result->markup);
    }

    #[Test]
    public function a_slider_shows_the_figure_beside_the_track(): void
    {
        $result = (new SliderRenderer())->render(
            $this->subject,
            $this->context(Purpose::Edit, TypedValue::ofInt(47), SimpleType::Int, [], 'v[7]')
        );

        self::assertStringContainsString('type="range"', $result->markup);
        self::assertStringContainsString('47', $result->markup);
    }

    /**
     * ⚠️ **Der Konverter gehört neben die Bahn, nicht in die Bahn.**
     *
     * *Der Eigentümer: «wenn ich einen Konverter habe, müssten die Werte anders dargestellt werden —
     * wenn ich römisch habe, in römischen Werten. Ich weiss gar nicht, ob man beim Slider aktuell schon
     * den Wert sieht.»* **Man sieht ihn — und vorher stand er auch im Attribut `value`.**
     *
     * ⚠️ *`<input type="range" value="XII">` ist für den Browser kein Wert: der Griff springt in die
     * Mitte, und das nächste Speichern schreibt die Mitte. **Ein Konverter hätte den Wert gelöscht,
     * ohne dass etwas rot geworden wäre** — genau die Sorte Fehler, die `PR-12` beschreibt.*
     */
    #[Test]
    public function a_slider_keeps_the_stored_number_in_the_track_and_the_notation_beside_it(): void
    {
        $result = (new SliderRenderer())->render(
            $this->subject,
            new RenderContext(
                Purpose::Edit,
                TypedValue::ofInt(12),
                [],
                '',
                Level::Admin,
                true,
                'v[7]',
                SimpleType::Int,
                new Surroundings(),
                false,
                // Was der Konverter `roman` aus der 12 macht.
                'XII'
            )
        );

        self::assertStringContainsString('value="12"', $result->markup);
        self::assertStringNotContainsString('value="XII"', $result->markup);

        // Und die Ziffer, die ein Mensch liest, steht daneben.
        self::assertStringContainsString('XII', $result->markup);
    }

    /** ⚠️ *Dieselbe Trennlinie am Zahlenfeld — dieselben Typen, dieselben Konverter.* */
    #[Test]
    public function a_spinner_keeps_the_stored_number(): void
    {
        $result = (new SpinnerRenderer())->render(
            $this->subject,
            new RenderContext(
                Purpose::Edit,
                TypedValue::ofInt(255),
                [],
                '',
                Level::Admin,
                true,
                'v[7]',
                SimpleType::Int,
                new Surroundings(),
                false,
                'FF'
            )
        );

        self::assertStringContainsString('value="255"', $result->markup);
        self::assertStringNotContainsString('value="FF"', $result->markup);
    }

    /**
     * ⚠️ **Der Gegenfall, und ohne ihn wäre der Wächter halb.** *Ein freies Textfeld bekommt die
     * Notation — dort ist sie der Sinn der Sache, und der Konverter liest sie wieder ein. Ohne diese
     * Zusage könnte jemand `controlValue()` überall einsetzen und der Konverter wäre wirkungslos.*
     */
    #[Test]
    public function a_text_field_shows_the_notation(): void
    {
        $result = (new FieldRenderer())->render(
            $this->subject,
            new RenderContext(
                Purpose::Edit,
                TypedValue::ofInt(12),
                [],
                '',
                Level::Admin,
                true,
                'v[7]',
                SimpleType::Int,
                new Surroundings(),
                false,
                'XII'
            )
        );

        self::assertStringContainsString('value="XII"', $result->markup);
    }

    #[Test]
    public function an_address_becomes_a_link_and_cannot_break_out_of_it(): void
    {
        $result = (new MailtoRenderer())->render(
            $this->subject,
            $this->context(Purpose::Display, TypedValue::ofText('a"@b.example'), SimpleType::Email)
        );

        self::assertStringContainsString('href="mailto:a&quot;@b.example"', $result->markup);
        self::assertStringNotContainsString('"@b.example"', $result->markup);
    }

    #[Test]
    public function an_empty_address_is_not_a_link_to_nowhere(): void
    {
        $result = (new MailtoRenderer())->render(
            $this->subject,
            $this->context(Purpose::Display, TypedValue::nothing(), SimpleType::Email)
        );

        self::assertStringNotContainsString('mailto', $result->markup);
    }

    #[Test]
    public function a_date_shows_only_as_much_of_itself_as_the_model_asked_for(): void
    {
        $stored = TypedValue::ofDate('2026-08-25 14:32:00');

        $whole = (new DateTimeRenderer())->render(
            $this->subject,
            $this->context(Purpose::Edit, $stored, SimpleType::DateTime, [], 'v[7]')
        );

        $dateOnly = (new DateTimeRenderer())->render(
            $this->subject,
            $this->context(Purpose::Edit, $stored, SimpleType::DateTime, [
                DateTimeRenderer::PRECISION => TypedValue::ofText('date'),
            ], 'v[7]')
        );

        self::assertStringContainsString('type="datetime-local"', $whole->markup);
        self::assertStringContainsString('value="2026-08-25T14:32"', $whole->markup);

        self::assertStringContainsString('type="date"', $dateOnly->markup);
        self::assertStringContainsString('value="2026-08-25"', $dateOnly->markup);
    }

    #[Test]
    public function a_colour_the_picker_cannot_hold_is_edited_as_text(): void
    {
        // ⚠️ `<input type="color">` reports `#000000` for anything it cannot parse, and the next
        // save would write black over a value nobody touched.
        $named = (new ColorRenderer())->render(
            $this->subject,
            $this->context(Purpose::Edit, TypedValue::ofText('rebeccapurple'), SimpleType::Color, [], 'v[7]')
        );

        $hex = (new ColorRenderer())->render(
            $this->subject,
            $this->context(Purpose::Edit, TypedValue::ofText('#663399'), SimpleType::Color, [], 'v[7]')
        );

        self::assertStringContainsString('type="text"', $named->markup);
        self::assertStringContainsString('type="color"', $hex->markup);
    }

    #[Test]
    public function read_only_closes_every_typed_field_the_same_way(): void
    {
        // ⚠️ **`hide` left this test on 2026-08-28, and that is the point of D-457.** *It used to
        // assert that `hide` and `read_only` close a field «the same way» — and they never were the
        // same thing. `read_only` **keeps the field and refuses the edit**; `hide` now stops the walk
        // before a renderer is asked at all, so there is nothing here for it to do.*
        //
        // ⚠️ *`read_only` stays a setting and stays freely settable in both directions
        // ([D-461](../../docs/NewConcept/90-decision-log.md)) — only `hide` left the settings, because
        // only `hide` had a second meaning nobody asked for.*
        //
        // ⚠️ They are answered once, in the base — a subclass adds a control, never a rule.
        foreach ([new FieldRenderer(), new CheckboxRenderer(), new MailtoRenderer(), new ColorRenderer()] as $renderer) {
            $fixed = $renderer->render(
                $this->subject,
                $this->context(Purpose::Edit, TypedValue::ofText('x'), SimpleType::Text, [
                    \Taxmod\Core\Model\EdgeColumn::READ_ONLY => TypedValue::ofBool(true),
                ], 'v[7]')
            );

            self::assertStringNotContainsString('<input type="text" name', $fixed->markup, $renderer->name());
        }
    }

    #[Test]
    public function a_renderer_ignores_a_setting_called_hide_because_there_is_no_such_setting(): void
    {
        // ⚠️ *The guard against the old behaviour creeping back: a context carrying `hide` must draw
        // the value anyway. `hide` is a column on the identity (D-457) and an abort in the descent
        // (D-450) — a renderer honouring it would be a second answer to a settled question.*
        $drawn = (new FieldRenderer())->render(
            $this->subject,
            $this->context(Purpose::Display, TypedValue::ofText('x'), SimpleType::Text, [
                'hide' => TypedValue::ofBool(true),
            ])
        );

        self::assertStringContainsString('x', $drawn->markup);
    }

    // ------------------------------------------------------------ reading back

    #[Test]
    public function an_empty_field_is_not_answered_rather_than_zero(): void
    {
        self::assertTrue(SimpleType::Int->valueFrom('')->isNothing());
        self::assertTrue(SimpleType::Text->valueFrom('   ')->isNothing());
    }

    #[Test]
    public function nothing_is_coerced_into_a_number(): void
    {
        // ⚠️ `(int) 'abc'` is `0`, and afterwards that zero is indistinguishable from one
        // somebody meant (D-071).
        $this->expectException(NotAValueOfThatType::class);

        SimpleType::Int->valueFrom('abc');
    }

    #[Test]
    public function a_decimal_stays_the_characters_it_arrived_as(): void
    {
        // D-057: exact, never floating point. `2.50` must not come back as `2.5`.
        self::assertSame('2.50', SimpleType::Decimal->valueFrom('2.50')->decimal);
    }

    #[Test]
    public function a_char_is_one_code_point_not_one_byte(): void
    {
        self::assertSame('ä', SimpleType::Char->valueFrom('ä')->text);

        $this->expectException(NotAValueOfThatType::class);

        SimpleType::Char->valueFrom('ab');
    }

    #[Test]
    public function the_three_shapes_a_date_control_submits_all_land_in_the_column(): void
    {
        self::assertSame('2026-08-25 00:00:00', SimpleType::DateTime->valueFrom('2026-08-25')->date);
        self::assertSame('2026-08-25 14:32:00', SimpleType::DateTime->valueFrom('2026-08-25T14:32')->date);
        self::assertSame(
            DateTimeType::TIME_WITHOUT_A_DATE . ' 14:32:00',
            SimpleType::DateTime->valueFrom('14:32')->date
        );
    }

    #[Test]
    public function a_date_that_is_not_one_is_refused(): void
    {
        $this->expectException(NotAValueOfThatType::class);

        SimpleType::DateTime->valueFrom('25.08.2026');
    }

    #[Test]
    public function a_boolean_reads_back_from_what_the_control_submits(): void
    {
        self::assertTrue(SimpleType::Bool->valueFrom('1')->asBool());
        self::assertFalse(SimpleType::Bool->valueFrom('0')->asBool());
    }

    #[Test]
    public function an_address_is_stored_as_given_because_validity_is_a_validators_question(): void
    {
        // D-319: a type earns its place through storage, rendering or ordering — never through
        // validation. The renderer makes it clickable; whether it is reachable is asked elsewhere.
        self::assertSame('not an address', SimpleType::Email->valueFrom('not an address')->text);
    }
}
