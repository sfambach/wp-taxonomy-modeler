<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\ValueInput;
use Taxmod\Core\Description\ValueKind;
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

    public function handles(): array
    {
        return [SimpleType::Text];
    }

    protected function display(RenderContext $context): ShownValue
    {
        return new ShownValue($this->outputValue($context), ValueKind::Code, CodeLanguage::fromContext($context)->value);
    }

    protected function input(RenderContext $context): ValueInput
    {
        return new ValueInput(
            InputKind::Code,
            $context->fieldName,
            $this->controlValue($context),
            $context->surroundings->formId,
            $this->required($context),
            rows: $this->numberSetting($context, 'rows') ?? self::DEFAULT_ROWS,
            note: CodeLanguage::fromContext($context)->value,
        );
    }
}
