<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SettingKey;
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

        self::assertSame('<span class="taxmod-value"></span>', $result->markup);
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
            $this->registry->chosenFor($this->subject, [], Purpose::Display)->name()
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

        self::assertSame(PlainRenderer::NAME, $chosen->name());
    }

    #[Test]
    public function a_renderer_that_declines_a_purpose_is_not_used_for_it(): void
    {
        // ⚠️ Declining is the mechanism behind *not searchable* (D-217) — a missing capability,
        // not a missing registration. The caller still gets something rather than an empty gap.
        $displayOnly = new class implements \Taxmod\Core\Renderer\Renderer {
            public function name(): string { return 'display-only'; }
            /** @return list<Purpose> */
            public function supports(): array { return [Purpose::Display]; }
            public function fits(Node|\Taxmod\Core\Model\Relation $subject): bool { return true; }
            public function render(Node|\Taxmod\Core\Model\Relation $subject, RenderContext $context): RenderResult
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

        self::assertSame('display-only', $this->registry->chosenFor($this->subject, $settings, Purpose::Display)->name());
        self::assertSame(PlainRenderer::NAME, $this->registry->chosenFor($this->subject, $settings, Purpose::Search)->name());

        self::assertCount(1, $this->registry->eligibleFor($this->subject, Purpose::Search));
        self::assertCount(2, $this->registry->eligibleFor($this->subject, Purpose::Display));
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
