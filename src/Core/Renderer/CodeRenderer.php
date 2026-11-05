<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SimpleType;

/**
 * A text edited as source code: several lines, a fixed-width font, and the language it is written
 * in, which the editor at the boundary uses for highlighting ([D-1180](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *The renderer only draws; whether the text is valid is the syntax validator's question
 * (`syntax`, [D-845](../../../docs/NewConcept/90-decision-log.md)). Both read the same `language`
 * setting, so it is stated once.*
 *
 * ⚠️ *Without a script it is a plain textarea and stays usable — the editor upgrades it.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class CodeRenderer extends TypedFieldRenderer
{
    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public CodeLanguage $language = CodeLanguage::Json;

    /** Visible lines of the editor; without it twelve. */
    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public ?int $rows = null;

    public const NAME = 'code';

    private const DEFAULT_ROWS = '12';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return [SimpleType::Text];
    }

    protected function display(RenderContext $context): string
    {
        return '<pre class="taxmod-value taxmod-code" data-taxmod-code="' . CodeLanguage::fromContext($context)->value . '">'
            . RenderResult::escape($this->outputValue($context))
            . '</pre>';
    }

    protected function input(RenderContext $context): string
    {
        return '<textarea class="large-text code taxmod-code" spellcheck="false"'
            . $this->createHtmlAttribute('data-taxmod-code', CodeLanguage::fromContext($context)->value)
            . $this->createHtmlAttribute('name', $context->fieldName)
            . $this->createHtmlAttribute('form', $context->surroundings->formId)
            . ($context->surroundings->mayBeNothing ? '' : ' aria-required="true"')
            . $this->createHtmlAttribute('rows', $this->numberSetting($context, 'rows') ?? self::DEFAULT_ROWS)
            . '>' . RenderResult::escape($this->controlValue($context)) . '</textarea>';
    }
}
