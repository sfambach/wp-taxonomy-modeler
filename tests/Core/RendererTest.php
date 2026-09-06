<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\FieldRenderer;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\PlainRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\RendererRegistry;
use Taxmod\Core\Renderer\RenderResult;
use Taxmod\Core\Renderer\TextareaRenderer;

/**
 * The render contract, the fallback and the registry's two jobs.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RendererTest extends TestCase
{
    private Node $subject;
    private RendererRegistry $registry;

    protected function setUp(): void
    {
        $this->subject  = Node::create(1, 'Text', null);
        $this->registry = new RendererRegistry();
    }

    /** @param array<string, TypedValue> $settings */
    private function context(Purpose $purpose, TypedValue $value, array $settings = [], string $field = ''): RenderContext
    {
        $resolved = [];

        foreach ($settings as $key => $one) {
            $resolved[$key] = new ResolvedSetting($key, $one, 1, true);
        }

        return new RenderContext($purpose, $value, $resolved, '', Level::Admin, true, $field);
    }

    // ------------------------------------------------------------- the fallback

    #[Test]
    public function the_fallback_shows_a_value(): void
    {
        $result = (new PlainRenderer())->render(
            $this->subject,
            $this->context(Purpose::Display, TypedValue::ofText('Widerstand'))
        );

        self::assertStringContainsString('Widerstand', $result->markup);
        self::assertStringNotContainsString('<input', $result->markup);
    }

    #[Test]
    public function nothing_is_drawn_as_nothing_not_as_a_dash(): void
    {
        // ⚠️ A missing row means *not answered*, never *no*. A placeholder would hide exactly
        // the state the model went to trouble to keep.
        $result = (new PlainRenderer())->render(
            $this->subject,
            $this->context(Purpose::Display, TypedValue::nothing())
        );

        // ⚠️ And it is marked `taxmod-no-renderer`: reaching the fallback at all means nobody
        // chose and the type has no default, which R14b says must **look** like the fault it is.
        self::assertSame('<span class="taxmod-value taxmod-no-renderer"></span>', $result->markup);
    }

    #[Test]
    public function markup_is_escaped_without_reaching_for_wordpress(): void
    {
        $result = (new PlainRenderer())->render(
            $this->subject,
            $this->context(Purpose::Display, TypedValue::ofText('<script>alert(1)</script>'))
        );

        self::assertStringNotContainsString('<script>', $result->markup);
        self::assertStringContainsString('&lt;script&gt;', $result->markup);
    }

    #[Test]
    public function the_edit_purpose_offers_an_input_under_the_field_name(): void
    {
        $result = (new PlainRenderer())->render(
            $this->subject,
            $this->context(Purpose::Edit, TypedValue::ofInt(40), [], 'value[7]')
        );

        self::assertStringContainsString('<input', $result->markup);
        self::assertStringContainsString('name="value[7]"', $result->markup);
        self::assertStringContainsString('value="40"', $result->markup);
    }

    #[Test]
    public function read_only_closes_the_field_even_under_the_edit_purpose(): void
    {
        // D-312: once fixed, never unfixed further down.
        $result = (new PlainRenderer())->render(
            $this->subject,
            $this->context(Purpose::Edit, TypedValue::ofText('x'), [
                SettingKey::ReadOnly->value => TypedValue::ofBool(true),
            ], 'v')
        );

        self::assertStringNotContainsString('<input', $result->markup);
    }

    #[Test]
    public function a_renderer_no_longer_knows_what_hidden_means(): void
    {
        // ⚠️ **This test used to assert the opposite, and the reversal is D-457.** *`hide` was a
        // setting, and a renderer handed a `hide = true` context returned an empty string. Now `hide`
        // is a **column on the identity** and an **abort**: the descent stops before it asks a renderer
        // at all (D-450, D-452).*
        //
        // ⚠️ *So a renderer given a setting called `hide` must **ignore** it — there is no such setting
        // any more, and a renderer that still honoured one would be a second answer to a question the
        // model already answers. The value is drawn, because drawing is all this class does.*
        $result = (new PlainRenderer())->render(
            $this->subject,
            $this->context(Purpose::Display, TypedValue::ofText('secret'), [
                'hide' => TypedValue::ofBool(true),
            ])
        );

        self::assertStringContainsString('secret', $result->markup);
    }

    // -------------------------------------------------------------- the registry

    #[Test]
    public function an_unknown_name_falls_back_rather_than_failing(): void
    {
        self::assertSame(PlainRenderer::NAME, $this->registry->byName('no such renderer')->name());
    }

    #[Test]
    public function a_silent_chain_falls_back(): void
    {
        self::assertSame(
            PlainRenderer::NAME,
            $this->registry->chosenFor($this->subject, [], Purpose::Display)?->name()
        );
    }

    #[Test]
    public function the_chain_choice_is_read_not_walked(): void
    {
        $chosen = $this->registry->chosenFor(
            $this->subject,
            [SettingKey::Renderer->value => new ResolvedSetting(
                SettingKey::Renderer->value,
                TypedValue::ofText(PlainRenderer::NAME),
                1,
                true
            )],
            Purpose::Display
        );

        self::assertSame(PlainRenderer::NAME, $chosen?->name());
    }

    #[Test]
    public function a_renderer_that_declines_a_purpose_yields_nothing_for_it(): void
    {
        // ⚠️ **Corrected against the first version of this test.** It asserted that a declined
        // purpose falls back — which is right for a **value** and wrong for a **filter**. If the
        // registry substitutes the fallback under `Search`, every attribute becomes searchable
        // again through a control that cannot search, and D-217's *not searchable* stops
        // existing. So the registry answers `null` and the **caller** applies the policy, which
        // differs by purpose (see `Rendering`).
        $displayOnly = new class implements \Taxmod\Core\Renderer\Renderer {
            public function name(): string { return 'display-only'; }
            /** @return list<Purpose> */
            public function supports(): array { return [Purpose::Display]; }
            /** @return list<\Taxmod\Core\Model\SimpleType> */
            public function handles(): array { return [SimpleType::Text]; }
            public function fits(\Taxmod\Core\Renderer\Renderable $subject): bool { return true; }
            public function render(\Taxmod\Core\Renderer\Renderable $subject, RenderContext $context): RenderResult
            {
                return RenderResult::of('shown');
            }
        };

        $this->registry->add($displayOnly);

        $settings = [SettingKey::Renderer->value => new ResolvedSetting(
            SettingKey::Renderer->value,
            TypedValue::ofText('display-only'),
            1,
            true
        )];

        self::assertSame(
            'display-only',
            $this->registry->chosenFor($this->subject, $settings, Purpose::Display)?->name()
        );
        self::assertNull($this->registry->chosenFor($this->subject, $settings, Purpose::Search));

        // ⚠️ The fallback is never offered as a choice — picking it would make *no renderer* a
        // decision somebody made (R14b).
        self::assertCount(0, $this->registry->eligibleFor($this->subject, SimpleType::Text, Purpose::Search));
        self::assertCount(1, $this->registry->eligibleFor($this->subject, SimpleType::Text, Purpose::Display));
        self::assertCount(1, $this->registry->eligibleFor($this->subject, SimpleType::Text));
        self::assertCount(0, $this->registry->eligibleFor($this->subject, SimpleType::Bool));

        // ⚠️ **`null` is not *do not filter*; it means the subject has no simple type at all.**
        // What fits such a subject is a **structural** renderer — one declaring `handles() === []`
        // — and this one draws a text. Conflating the two is how a spinner gets offered for a
        // supplier, which is the one mistake a picker must not make.
        self::assertCount(0, $this->registry->eligibleFor($this->subject, null));
    }

    // ---------------------------------------------------------------- the result

    #[Test]
    public function results_concatenate_in_the_lists_order(): void
    {
        // D-236: one mandatory entry and any number of additions; their outputs follow each
        // other. Nothing in the interface moves for it.
        $first  = RenderResult::of('<b>40</b>', 7);
        $second = RenderResult::of('<span class="rings"></span>', 7, 9);

        $both = $first->followedBy($second);

        self::assertSame('<b>40</b><span class="rings"></span>', $both->markup);
        self::assertSame([7, 9], $both->usedRelations);
    }

    // ------------------------------------------------------- die Anzeigebreite (D-659)

    /**
     * ⚠️ **Sein Anlass, gemessen:** *`Street / H#` zeichnet waagerecht und ohne Umbruch, **aber beide
     * Felder sind gleich breit** — ein Textfeld ohne Angabe nimmt die Vorgabe des Rahmenwerks.*
     */
    #[Test]
    public function the_display_size_reaches_the_drawn_field(): void
    {
        $markup = (new FieldRenderer())->render(
            $this->subject,
            $this->context(
                Purpose::Edit,
                TypedValue::ofText('Bahnhofstrasse'),
                [SettingKey::DisplaySize->value => TypedValue::ofInt(40)],
                'v'
            )
        )->markup;

        self::assertStringContainsString('size="40"', $markup);

        // ⚠️ **Keine Längenbegrenzung** ([D-659](../../../docs/NewConcept/90-decision-log.md)):
        // *«sie beschneidet nichts und weist nichts zurück; wer eine Grenze will, braucht einen
        // Validator».* **Die Verwechslung mit `maxlength` ist der eine Fehler, den sie benennt.**
        self::assertStringNotContainsString('maxlength', $markup);
    }

    /** Zwei Angaben, zwei Breiten — sonst zeichnete nicht die Angabe, sondern etwas hinter ihr. */
    #[Test]
    public function two_widths_draw_two_widths(): void
    {
        $breit = (new FieldRenderer())->render($this->subject, $this->context(
            Purpose::Edit,
            TypedValue::ofText('Bahnhofstrasse'),
            [SettingKey::DisplaySize->value => TypedValue::ofInt(40)],
            'v'
        ))->markup;

        $schmal = (new FieldRenderer())->render($this->subject, $this->context(
            Purpose::Edit,
            TypedValue::ofText('12a'),
            [SettingKey::DisplaySize->value => TypedValue::ofInt(4)],
            'v'
        ))->markup;

        self::assertStringContainsString('size="4"', $schmal);
        self::assertNotSame($breit, $schmal);
    }

    /**
     * ⚠️ **Ohne Angabe bleibt es, wie es war.** *Sie ist ein **Wunsch, kein Befehl** — ein Rand, der
     * sie nicht umsetzen kann oder keine bekommt, ignoriert sie, statt zu scheitern.*
     */
    #[Test]
    public function without_a_display_size_nothing_changes(): void
    {
        $markup = (new FieldRenderer())->render(
            $this->subject,
            $this->context(Purpose::Edit, TypedValue::ofText('Bahnhofstrasse'), [], 'v')
        )->markup;

        self::assertStringNotContainsString('size=', $markup);
        self::assertStringContainsString('value="Bahnhofstrasse"', $markup);
    }

    /**
     * ⚠️ *Der mehrzeilige Textrenderer zählt in derselben Einheit und nimmt die Angabe darum auch —
     * seine eigene `cols` bleibt die nähere Aussage und gewinnt.*
     */
    #[Test]
    public function the_textarea_takes_it_too_and_its_own_cols_wins(): void
    {
        $nurBreite = (new TextareaRenderer())->render($this->subject, $this->context(
            Purpose::Edit,
            TypedValue::ofText('x'),
            [SettingKey::DisplaySize->value => TypedValue::ofInt(40)],
            'v'
        ))->markup;

        self::assertStringContainsString('cols="40"', $nurBreite);

        $mitCols = (new TextareaRenderer())->render($this->subject, $this->context(
            Purpose::Edit,
            TypedValue::ofText('x'),
            [
                SettingKey::DisplaySize->value => TypedValue::ofInt(40),
                'cols'                         => TypedValue::ofInt(12),
            ],
            'v'
        ))->markup;

        self::assertStringContainsString('cols="12"', $mitCols);
    }

    /**
     * ⚠️ **Die Angabe ist dem Rahmenwerk vorbehalten** (`D-084`): *ein Autor darf keinen eigenen
     * Schlüssel `display_size` erfinden, sonst hiesse derselbe Name an zwei Stellen Verschiedenes.*
     */
    #[Test]
    public function the_display_size_is_the_engines_own_key(): void
    {
        self::assertTrue(SettingKey::isReserved('display_size'));
        self::assertSame(SettingKey::DisplaySize, SettingKey::tryFrom('display_size'));
    }
}
