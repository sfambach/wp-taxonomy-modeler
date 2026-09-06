<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\CompactRenderer;
use Taxmod\Core\Renderer\FieldRenderer;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\RenderResult;
use Taxmod\Core\Renderer\RenderedField;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\Surroundings;

/**
 * The compact container: one renderer, two properties — [D-471](../../docs/NewConcept/90-decision-log.md).
 *
 * ⚠️ **The parts are built here rather than descended for**, which is the same arrangement
 * {@see \Taxmod\Core\Renderer\FormRenderer} is tested under in {@see RenderingTest}: a container
 * lays out what it was handed ([D-159](../../docs/NewConcept/90-decision-log.md)), so handing it
 * something is exactly the fixture.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class CompactRendererTest extends TestCase
{
    private Node $subject;
    private CompactRenderer $renderer;

    protected function setUp(): void
    {
        $this->subject  = Node::create(1, 'Widerstand', null);
        $this->renderer = new CompactRenderer();
    }

    /**
     * One drawn attribute, with markup nothing else in this file produces — so a test that says
     * *the parts come out in this order* is looking at the parts and not at the frame.
     */
    private function part(int $id, string $name, string $markup, string $hint = ''): RenderedField
    {
        return new RenderedField(
            Relation::attribute($id, $this->subject->id, 500 + $id, RelationKind::Composition, $name, $id),
            SimpleType::Text,
            FieldRenderer::NAME,
            new RenderResult($markup, [$id]),
            false,
            '',
            $hint
        );
    }

    /**
     * @param  list<RenderedField>          $parts
     * @param  array<string, TypedValue>    $settings Free keys, resolved as if the chain had spoken.
     */
    private function draw(array $parts, array $settings = [], Purpose $purpose = Purpose::Display): RenderResult
    {
        $resolved = [];

        foreach ($settings as $key => $value) {
            $resolved[$key] = new ResolvedSetting($key, $value, 1, true);
        }

        return $this->renderer->render(
            $this->subject,
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                settings: $resolved,
                level: Level::Admin,
                surroundings: new Surroundings(parts: $parts),
            )
        );
    }

    // ------------------------------------------------------------- the axis

    #[Test]
    public function horizontal_is_what_silence_means(): void
    {
        // D-471, the owner verbatim: «bei horizontal/vertikal ist **Standard horizontal**».
        $markup = $this->draw([$this->part(1, 'Wert', '<i>4k7</i>')])->markup;

        self::assertStringContainsString('taxmod-compact-horizontal', $markup);
        self::assertStringContainsString('flex-direction:row', $markup);
        self::assertStringNotContainsString('flex-direction:column', $markup);
    }

    #[Test]
    public function vertical_is_drawn_when_the_setting_says_so(): void
    {
        $markup = $this->draw(
            [$this->part(1, 'Wert', '<i>4k7</i>')],
            [CompactRenderer::ORIENTATION => TypedValue::ofText(CompactRenderer::VERTICAL)]
        )->markup;

        self::assertStringContainsString('taxmod-compact-vertical', $markup);
        self::assertStringContainsString('flex-direction:column', $markup);
        self::assertStringNotContainsString('flex-direction:row', $markup);
    }

    #[Test]
    public function an_unrecognised_orientation_falls_back_to_the_default_and_invents_no_third_axis(): void
    {
        // ⚠️ Not decided anywhere, and deliberately not guessed into a third behaviour: an axis
        // nobody named is the declared default. The renderer's docblock says so and OQ-120 owns it.
        $markup = $this->draw(
            [$this->part(1, 'Wert', '<i>4k7</i>')],
            [CompactRenderer::ORIENTATION => TypedValue::ofText('diagonal')]
        )->markup;

        self::assertStringContainsString('taxmod-compact-horizontal', $markup);
        self::assertStringContainsString('flex-direction:row', $markup);
    }

    // ------------------------------------------------------------ the labels

    #[Test]
    public function the_label_is_on_when_nobody_said_otherwise(): void
    {
        // D-471, the owner verbatim: «**Standard ist Label an**».
        $markup = $this->draw([$this->part(1, 'Toleranz', '<i>1%</i>')])->markup;

        self::assertStringContainsString('taxmod-compact-label', $markup);
        self::assertStringContainsString('Toleranz', $markup);
    }

    #[Test]
    public function switching_the_label_off_leaves_it_out_and_keeps_the_value(): void
    {
        $markup = $this->draw(
            [$this->part(1, 'Toleranz', '<i>1%</i>')],
            [CompactRenderer::LABEL => TypedValue::ofBool(false)]
        )->markup;

        self::assertStringNotContainsString('taxmod-compact-label', $markup);
        self::assertStringNotContainsString('Toleranz', $markup);
        self::assertStringContainsString('<i>1%</i>', $markup);
    }

    #[Test]
    public function a_switch_that_says_yes_is_the_same_as_silence(): void
    {
        $markup = $this->draw(
            [$this->part(1, 'Toleranz', '<i>1%</i>')],
            [CompactRenderer::LABEL => TypedValue::ofBool(true)]
        )->markup;

        self::assertStringContainsString('taxmod-compact-label', $markup);
        self::assertStringContainsString('Toleranz', $markup);
    }

    #[Test]
    public function a_setting_that_holds_nothing_is_silence_and_not_a_no(): void
    {
        // ⚠️ *Nothing* means **not answered**, never *no* — the rule {@see RenderContext} states
        // for values, applied to the switch that configures the drawing.
        $markup = $this->draw(
            [$this->part(1, 'Toleranz', '<i>1%</i>')],
            [CompactRenderer::LABEL => TypedValue::nothing()]
        )->markup;

        self::assertStringContainsString('taxmod-compact-label', $markup);
    }

    #[Test]
    public function a_label_is_escaped_and_never_placed_as_markup(): void
    {
        $markup = $this->draw([$this->part(1, '4<7 & "gross"', '<i>x</i>')])->markup;

        self::assertStringContainsString('4&lt;7 &amp; &quot;gross&quot;', $markup);
        self::assertStringNotContainsString('<7 &', $markup);
    }

    // ------------------------------------------------------------- the parts

    #[Test]
    public function the_parts_appear_in_the_order_they_were_handed_in(): void
    {
        // ⚠️ **No regrouping**, and that is the difference from the form: R75's four groups are how
        // a form reads top to bottom, while D-245 asks only for *as compactly as possible together*.
        $markup = $this->draw([
            $this->part(3, 'drei', '<i>C</i>'),
            $this->part(1, 'eins', '<i>A</i>'),
            $this->part(2, 'zwei', '<i>B</i>'),
        ])->markup;

        self::assertSame(
            ['C', 'A', 'B'],
            array_map(
                static fn (array $found): string => $found[1],
                self::matchesOf('/<i>([ABC])<\/i>/', $markup)
            )
        );
    }

    #[Test]
    public function every_part_carries_its_relation_out_with_the_markup(): void
    {
        // D-021: what went into a rendering is not recoverable from the string afterwards.
        $result = $this->draw([$this->part(7, 'sieben', '<i>G</i>'), $this->part(9, 'neun', '<i>I</i>')]);

        self::assertSame([7, 9], $result->usedRelations);
    }

    #[Test]
    public function a_hidden_part_takes_no_place_at_all(): void
    {
        // R11 through {@see RenderedField::isHidden()}: empty markup is the answer, not a flag.
        $markup = $this->draw([
            $this->part(1, 'sichtbar', '<i>A</i>'),
            $this->part(2, 'versteckt', ''),
        ])->markup;

        self::assertStringContainsString('sichtbar', $markup);
        self::assertStringNotContainsString('versteckt', $markup);
        self::assertSame(1, substr_count($markup, 'taxmod-compact-part'));
    }

    #[Test]
    public function nothing_to_lay_out_draws_nothing(): void
    {
        // Same as the form and the tree: an empty container is markup with no content, and a caller
        // that wants to know whether anything was drawn should not have to parse a `<div>`.
        self::assertSame('', $this->draw([])->markup);
        self::assertSame('', $this->draw([$this->part(1, 'versteckt', '')])->markup);
    }

    // -------------------------------------------------------- die Hilfe, D-662

    #[Test]
    public function a_field_with_a_help_gets_a_mark_and_one_without_gets_none(): void
    {
        // ⚠️ **[D-662](../../docs/NewConcept/90-decision-log.md), sein Wort:** *«überall dort, wo help
        // label ist, sollte auch ein kleines Fragezeichen hinter dem Feld stehen.»*
        $mit  = $this->draw([$this->part(1, 'Wert', '<i>4k7</i>', 'der Widerstandswert')])->markup;
        $ohne = $this->draw([$this->part(1, 'Wert', '<i>4k7</i>')])->markup;

        self::assertSame(1, substr_count($mit, 'taxmod-hint-icon'));
        self::assertStringNotContainsString('taxmod-hint', $ohne);
    }

    #[Test]
    public function horizontally_one_mark_at_the_end_carries_every_help_of_the_row(): void
    {
        // ⚠️ **Sein Wort:** *«Bei compact horizontal würde ich die Texte sammeln und in ein
        // Fragezeichen am Ende kombinieren.»* *Der Grund steht in D-662: dort trennt die Felder nur
        // ein Leerzeichen — **ein Zeichen je Feld wäre ein Zeichen je Leerzeichen**.*
        $markup = $this->draw([
            $this->part(1, 'Wert', '<i>A</i>', 'erste Hilfe'),
            $this->part(2, 'Toleranz', '<i>B</i>'),
            $this->part(3, 'Bauform', '<i>C</i>', 'dritte Hilfe'),
        ])->markup;

        self::assertSame(1, substr_count($markup, 'taxmod-hint-icon'));
        self::assertStringContainsString('erste Hilfe', $markup);
        self::assertStringContainsString('dritte Hilfe', $markup);

        // Am **Ende**, hinter dem letzten Feld — nicht zwischen den Feldern.
        self::assertGreaterThan(strpos($markup, '<i>C</i>'), strpos($markup, 'taxmod-hint-icon'));
    }

    #[Test]
    public function vertically_every_field_keeps_its_own_mark(): void
    {
        // ⚠️ *Senkrecht hat jedes Feld seine eigene Zeile und damit Platz für sein eigenes Zeichen —
        // gesammelt wird nur, wo ein Leerzeichen trennt.*
        $markup = $this->draw(
            [
                $this->part(1, 'Wert', '<i>A</i>', 'erste Hilfe'),
                $this->part(2, 'Toleranz', '<i>B</i>', 'zweite Hilfe'),
            ],
            [CompactRenderer::ORIENTATION => TypedValue::ofText(CompactRenderer::VERTICAL)]
        )->markup;

        self::assertSame(2, substr_count($markup, 'taxmod-hint-icon'));
        self::assertStringContainsString('erste Hilfe', $markup);
        self::assertStringContainsString('zweite Hilfe', $markup);
    }

    #[Test]
    public function the_sentence_stands_in_the_markup_and_not_only_in_the_title(): void
    {
        // ⚠️ **Der Preis, den [D-661](../../docs/NewConcept/90-decision-log.md) ausdrücklich nennt:**
        // *ein Tooltip allein ist auf einem Berührungsbildschirm und für eine Vorlesehilfe nicht
        // erreichbar. Also steht der Satz im Baum, und die Hülle ist fokussierbar.*
        $markup = $this->draw([$this->part(1, 'Wert', '<i>A</i>', 'der Widerstandswert')])->markup;

        self::assertStringContainsString('<span class="taxmod-hint-text">der Widerstandswert</span>', $markup);
        self::assertStringContainsString('tabindex="0"', $markup);
    }

    #[Test]
    public function a_hidden_field_takes_its_help_with_it(): void
    {
        // ⚠️ *R11: ein verstecktes Feld nimmt keinen Platz ein — und ein Satz zu einem Feld, das
        // niemand sieht, erklärt nichts.*
        $markup = $this->draw([
            $this->part(1, 'sichtbar', '<i>A</i>'),
            $this->part(2, 'versteckt', '', 'die Hilfe des Versteckten'),
        ])->markup;

        self::assertStringNotContainsString('taxmod-hint', $markup);
    }

    #[Test]
    public function a_help_is_escaped_and_never_placed_as_markup(): void
    {
        $markup = $this->draw([$this->part(1, 'Wert', '<i>A</i>', 'a < b & "so"')])->markup;

        self::assertStringContainsString('a &lt; b &amp; &quot;so&quot;', $markup);
        self::assertStringNotContainsString('a < b &', $markup);
    }

    // ------------------------------------------------------- the registration

    #[Test]
    public function it_is_offered_to_a_modeller_and_not_kept_for_surfaces(): void
    {
        // ⚠️ The point of `add()` over `addForSurfaces()`: D-471 makes the compact shape a choice a
        // modeller makes, unlike a tree cell, which is the surface's call (D-367).
        $registry = ShippedRenderers::registry();

        self::assertTrue($registry->knows(CompactRenderer::NAME));
        self::assertSame(CompactRenderer::NAME, $registry->byName(CompactRenderer::NAME)->name());

        $offered = array_map(
            static fn ($renderer): string => $renderer->name(),
            $registry->eligibleFor($this->subject)
        );

        self::assertContains(CompactRenderer::NAME, $offered);
    }

    #[Test]
    public function it_is_structural_and_never_pulled_in_as_a_types_default(): void
    {
        // `handles() === []` is what makes it structural (D-098's reasoning, applied again): it is
        // chosen for what a subject **is**, so no type inherits it by accident.
        self::assertSame([], $this->renderer->handles());
        self::assertSame([Purpose::Display, Purpose::Edit], $this->renderer->supports());

        $registry = ShippedRenderers::registry();

        foreach (SimpleType::cases() as $type) {
            self::assertNotSame(
                CompactRenderer::NAME,
                $registry->defaultFor($type)->name(),
                $type->value . ' must not have the compact container as its default'
            );
        }
    }

    #[Test]
    public function an_relation_is_not_a_subject_it_claims(): void
    {
        // Deliberately narrow: D-245 speaks of a **node** with several attributes, and what a
        // compact rendering of an relation would mean is not decided.
        $relation = Relation::attribute(1, $this->subject->id, 501, RelationKind::Composition, 'Wert', 1);

        self::assertTrue($this->renderer->fits($this->subject));
        self::assertFalse($this->renderer->fits($relation));
    }

    /** @return list<array<int, string>> */
    private static function matchesOf(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $found, PREG_SET_ORDER);

        return $found;
    }
}
