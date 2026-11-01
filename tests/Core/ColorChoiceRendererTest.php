<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\ColorChoiceRenderer;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\Surroundings;

/**
 * Eine Farbe wird mit Namen und Kästchen gezeichnet (D-1080).
 */
final class ColorChoiceRendererTest extends TestCase
{
    private function drawn(Purpose $purpose, TypedValue $value, array $swatches, array $options = [], ?string $refersTo = null): string
    {
        return (new ColorChoiceRenderer())->render(
            Node::create(1, 'Farbe', null),
            new RenderContext(
                purpose: $purpose,
                value: $value,
                level: Level::Admin,
                fieldName: 'f[7]',
                surroundings: new Surroundings(options: $options, refersTo: $refersTo, swatches: $swatches),
            )
        )->markup;
    }

    #[Test]
    public function shown_with_name_and_swatch(): void
    {
        $markup = $this->drawn(Purpose::Display, TypedValue::ofReference(190), [190 => '#000000'], [], 'Schwarz');

        self::assertStringContainsString('Schwarz', $markup);
        self::assertStringContainsString('background:#000000', $markup);
    }

    #[Test]
    public function without_a_value_the_swatch_is_dashed_not_white(): void
    {
        $markup = $this->drawn(Purpose::Display, TypedValue::ofReference(191), [], [], 'Lila');

        self::assertStringContainsString('Lila', $markup);
        self::assertStringContainsString('dashed', $markup);
        self::assertStringNotContainsString('background:', $markup);
    }

    #[Test]
    public function nothing_chosen_is_a_dash(): void
    {
        self::assertStringContainsString('taxmod-nothing', $this->drawn(Purpose::Display, TypedValue::nothing(), []));
    }

    #[Test]
    public function edited_as_a_select_carrying_the_colours(): void
    {
        $markup = $this->drawn(Purpose::Edit, TypedValue::ofReference(190), [190 => '#000000', 186 => '#ff0000'], [190 => 'Schwarz', 186 => 'Rot']);

        self::assertStringContainsString('<select', $markup);
        self::assertStringContainsString('data-taxmod-swatches', $markup);
        self::assertStringContainsString('#ff0000', $markup);
        self::assertStringContainsString('background:#000000', $markup);
    }
}
