<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\CodeLanguage;

/**
 * Is the text valid in the language the field says it holds? Refuses invalid JSON on save, with
 * line and column ([D-1180](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *The language is the `language` setting of the code renderer at the same use site — read
 * here, not declared a second time. XML, CSS and ZPL pass unchecked until their check is built.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class SyntaxValidator implements ChecksOnSave
{
    public const NAME = 'syntax';

    public function name(): string
    {
        return self::NAME;
    }

    public function sites(): array
    {
        return [AddonSite::Node, AddonSite::Edge];
    }

    public function requirements(): array
    {
        return [];
    }

    public function handles(): array
    {
        return [SimpleType::Text];
    }

    public function check(TypedValue $value, ?SimpleType $type, array $settings): array
    {
        $text = $value->text;

        if ($value->isNothing() || $text === null || trim($text) === '') {
            return [];
        }

        if (CodeLanguage::fromSettings($settings) !== CodeLanguage::Json) {
            return [];
        }

        $error = (new JsonSyntax())->firstError($text);

        if ($error === null) {
            return [];
        }

        return [new Complaint(self::NAME, 'invalid_json', [
            'line'   => (string) $error['line'],
            'column' => (string) $error['column'],
        ])];
    }
}
