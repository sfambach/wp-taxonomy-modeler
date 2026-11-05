<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Addon\ChosenAddon;
use Taxmod\Core\Addon\JsonSyntax;
use Taxmod\Core\Addon\ShippedAddons;
use Taxmod\Core\Addon\SyntaxValidator;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\CodeRenderer;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\ShippedRenderers;

/**
 * The code field: an editor with a language, and invalid JSON refused with line and column (D-1180).
 */
final class CodeFieldTest extends TestCase
{
    /** @param array<string, TypedValue> $settings */
    private function drawn(Purpose $purpose, TypedValue $value, array $settings = []): string
    {
        $resolved = [];

        foreach ($settings as $key => $one) {
            $resolved[$key] = new ResolvedSetting($key, $one, 1, true);
        }

        return (new CodeRenderer())->render(
            Node::create(1, 'Layout', null),
            new RenderContext($purpose, $value, $resolved, '', Level::Admin, true, 'f[7]')
        )->markup;
    }

    #[Test]
    public function it_is_offered_for_text_by_name(): void
    {
        $registry = ShippedRenderers::registry();

        self::assertTrue($registry->knows(CodeRenderer::NAME));
        self::assertContains(CodeRenderer::NAME, $registry->namesForNodes());
        self::assertContains(SyntaxValidator::NAME, ShippedAddons::registry()->names());
    }

    #[Test]
    public function edited_as_a_textarea_carrying_its_language(): void
    {
        $markup = $this->drawn(Purpose::Edit, TypedValue::ofText('{"a": "<b>"}'), ['language' => TypedValue::ofText('xml'), 'rows' => TypedValue::ofInt(20)]);

        self::assertStringContainsString('<textarea', $markup);
        self::assertStringContainsString('data-taxmod-code="xml"', $markup);
        self::assertStringContainsString('rows="20"', $markup);
        self::assertStringContainsString('name="f[7]"', $markup);
        self::assertStringContainsString('&lt;b&gt;', $markup);
    }

    #[Test]
    public function without_a_language_it_is_json_with_twelve_rows(): void
    {
        $markup = $this->drawn(Purpose::Edit, TypedValue::nothing());

        self::assertStringContainsString('data-taxmod-code="json"', $markup);
        self::assertStringContainsString('rows="12"', $markup);
    }

    #[Test]
    public function shown_as_preformatted_text(): void
    {
        $markup = $this->drawn(Purpose::Display, TypedValue::ofText("{\n  \"a\": 1\n}"));

        self::assertStringContainsString('<pre', $markup);
        self::assertStringContainsString("\n  &quot;a&quot;", $markup);
    }

    #[Test]
    public function valid_json_has_no_error(): void
    {
        self::assertNull((new JsonSyntax())->firstError('{"elements": [{"type": "text", "x": 1.5e2, "ok": true, "n": null, "s": "ä\n"}]}'));
    }

    #[Test]
    public function the_error_is_found_at_its_line_and_column(): void
    {
        $syntax = new JsonSyntax();

        self::assertSame(['line' => 3, 'column' => 3], $syntax->firstError("{\n  \"a\": 1,\n  }"));
        self::assertSame(['line' => 2, 'column' => 10], $syntax->firstError("{\n  \"a\": 1 \"b\": 2}"));
        self::assertSame(['line' => 1, 'column' => 7], $syntax->firstError('{"a": tru}'));
        self::assertSame(['line' => 1, 'column' => 5], $syntax->firstError('[1, ]'));
        self::assertSame(['line' => 1, 'column' => 9], $syntax->firstError('{"ä": 1}x'));
        self::assertSame(['line' => 1, 'column' => 6], $syntax->firstError('{"a":'));
    }

    #[Test]
    public function the_validator_refuses_invalid_json_with_line_and_column(): void
    {
        $complaints = ShippedAddons::registry()->complaintsAbout(
            TypedValue::ofText("{\n  \"a\": 1,\n}"),
            SimpleType::Text,
            [new ChosenAddon(SyntaxValidator::class, 1, [], true)],
            ['language' => TypedValue::ofText('json')]
        );

        self::assertCount(1, $complaints);
        self::assertSame('invalid_json', $complaints[0]->key);
        self::assertSame(['line' => '3', 'column' => '1'], $complaints[0]->values);
    }

    #[Test]
    public function valid_json_empty_text_and_other_languages_pass(): void
    {
        $validator = new SyntaxValidator();

        self::assertSame([], $validator->check(TypedValue::ofText('{"a": [1, 2]}'), SimpleType::Text, []));
        self::assertSame([], $validator->check(TypedValue::ofText('  '), SimpleType::Text, []));
        self::assertSame([], $validator->check(TypedValue::nothing(), SimpleType::Text, []));
        self::assertSame([], $validator->check(TypedValue::ofText('<a>'), SimpleType::Text, ['language' => TypedValue::ofText('xml')]));
        self::assertCount(1, $validator->check(TypedValue::ofText('<a>'), SimpleType::Text, []));
    }
}
