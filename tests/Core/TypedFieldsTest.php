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
use Taxmod\Core\Renderer\ColorRenderer;
use Taxmod\Core\Renderer\DateTimeRenderer;
use Taxmod\Core\Renderer\FieldRenderer;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\MailtoRenderer;
use Taxmod\Core\Renderer\PlainRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\SliderRenderer;
use Taxmod\Core\Renderer\SpinnerRenderer;
use Taxmod\Core\Renderer\SwitchRenderer;
use Taxmod\Core\Renderer\TextareaRenderer;

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
            [SimpleType::Bool, SwitchRenderer::NAME],
            [SimpleType::Email, MailtoRenderer::NAME],
            [SimpleType::DateTime, DateTimeRenderer::NAME],
            [SimpleType::Color, ColorRenderer::NAME],
        ];

        foreach ($expected as [$type, $name]) {
            self::assertSame($name, $registry->defaultFor($type)->name(), $type->value);
        }
    }

    #[Test]
    public function the_two_reference_types_have_no_renderer_yet_and_say_so(): void
    {
        // ⚠️ Deliberate, not forgotten: `node_ref` wants the reference renderer (D-105) and
        // `user_ref` one that resolves a WordPress user. A quiet plain field pretending otherwise
        // is what R14b forbids.
        $registry = ShippedRenderers::registry();

        self::assertSame(PlainRenderer::NAME, $registry->defaultFor(SimpleType::NodeRef)->name());
        self::assertSame(PlainRenderer::NAME, $registry->defaultFor(SimpleType::UserRef)->name());
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

        self::assertSame([], $registry->eligibleFor($this->subject, null, Purpose::Search));
    }

    // ------------------------------------------------------------ the controls

    #[Test]
    public function a_boolean_that_nobody_answered_is_not_drawn_as_false(): void
    {
        $result = (new SwitchRenderer())->render(
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
        $result = (new SwitchRenderer())->render(
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
        $result = (new SwitchRenderer())->render(
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
                SettingKey::RangeMin->value => TypedValue::ofInt(1),
                SettingKey::RangeMax->value => TypedValue::ofInt(10),
            ], 'v[7]')
        );

        self::assertStringContainsString('min="1"', $result->markup);
        self::assertStringContainsString('max="10"', $result->markup);
        self::assertStringContainsString('step="1"', $result->markup);
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
    public function hide_and_read_only_close_every_typed_field_the_same_way(): void
    {
        // ⚠️ They are answered once, in the base — a subclass adds a control, never a rule.
        foreach ([new FieldRenderer(), new SwitchRenderer(), new MailtoRenderer(), new ColorRenderer()] as $renderer) {
            $hidden = $renderer->render(
                $this->subject,
                $this->context(Purpose::Display, TypedValue::ofText('x'), SimpleType::Text, [
                    SettingKey::Hide->value => TypedValue::ofBool(true),
                ])
            );

            $fixed = $renderer->render(
                $this->subject,
                $this->context(Purpose::Edit, TypedValue::ofText('x'), SimpleType::Text, [
                    SettingKey::ReadOnly->value => TypedValue::ofBool(true),
                ], 'v[7]')
            );

            self::assertSame('', $hidden->markup, $renderer->name());
            self::assertStringNotContainsString('<input type="text" name', $fixed->markup, $renderer->name());
        }
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
            SimpleType::TIME_WITHOUT_A_DATE . ' 14:32:00',
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
