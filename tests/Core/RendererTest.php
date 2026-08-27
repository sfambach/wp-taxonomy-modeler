<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\PlainRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\RendererRegistry;
use Taxmod\Core\Renderer\RenderResult;

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
    public function hidden_draws_nothing_at_all(): void
    {
        $result = (new PlainRenderer())->render(
            $this->subject,
            $this->context(Purpose::Display, TypedValue::ofText('secret'), [
                SettingKey::Hide->value => TypedValue::ofBool(true),
            ])
        );

        self::assertSame('', $result->markup);
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
        self::assertSame([7, 9], $both->usedEdges);
    }
}
