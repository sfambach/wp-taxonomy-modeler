<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SimpleType;

/**
 * One line — the *plain field* of [D-018](90-decision-log.md)'s three ways to draw a scalar.
 *
 * ⚠️ **It knows nothing about the number it may be holding**, and that is the point of the
 * decision: field, spinner and slider are **three renderers**, not one with three modes (R15).
 * A field that started reading `range_min` to bound itself would be a spinner without saying so.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class FieldRenderer extends TypedFieldRenderer
{
    public const NAME = 'field';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return [
            SimpleType::Text,
            SimpleType::Char,
            SimpleType::Version,
            SimpleType::Int,
            SimpleType::Decimal,
        ];
    }

    protected function display(RenderContext $context): string
    {
        return $this->shown(RenderResult::escape($this->characters($context)));
    }

    protected function input(RenderContext $context): string
    {
        return '<input type="text"'
            . $this->attribute('name', $context->fieldName)
            . $this->attribute('value', $this->characters($context))
            . '>';
    }
}
