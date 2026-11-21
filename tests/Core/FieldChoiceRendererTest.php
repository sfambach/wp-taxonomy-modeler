<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\FieldChoiceRenderer;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\Surroundings;

/** A rule filter's field is chosen by name and stored as its relation id (D-1541). */
final class FieldChoiceRendererTest extends TestCase
{
    private function drawn(Purpose $purpose, TypedValue $value, array $options): string
    {
        return \Taxmod\WordPress\Draw\Markup::of((new FieldChoiceRenderer())->render(
            Node::create(1, 'Feld', null),
            new RenderContext(purpose: $purpose, value: $value, level: Level::Admin, fieldName: 'f[7]', surroundings: new Surroundings(options: $options)),
        ));
    }

    #[Test]
    public function edited_as_a_choice_of_the_offered_fields_with_the_stored_one_chosen(): void
    {
        $markup = $this->drawn(Purpose::Edit, TypedValue::ofInt(40), ['40' => 'Bauform', '41' => 'Toleranz']);

        self::assertStringContainsString('name="f[7]"', $markup);
        self::assertStringContainsString('Toleranz', $markup);
        self::assertMatchesRegularExpression('/value="40"[^>]*selected/', $markup);
    }

    #[Test]
    public function shown_by_its_name_and_a_field_no_longer_offered_by_its_number(): void
    {
        self::assertStringContainsString('Bauform', $this->drawn(Purpose::Display, TypedValue::ofInt(40), ['40' => 'Bauform']));
        self::assertStringContainsString('#99', $this->drawn(Purpose::Display, TypedValue::ofInt(99), ['40' => 'Bauform']));
        self::assertStringContainsString('#99', $this->drawn(Purpose::Edit, TypedValue::ofInt(99), []), 'a stored field stays in the choice');
    }
}
