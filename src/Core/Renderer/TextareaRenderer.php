<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SimpleType;

/**
 * Several lines — for the long end of `text`, which is one type covering *short strings and long
 * ones alike, source code included* ([D-316](90-decision-log.md)).
 *
 * ⚠️ **Not the default.** A text is one line until somebody says otherwise; guessing from the
 * length of what happens to be stored would make the control jump about as the content grows.
 *
 * ⚠️ **`cols` × `rows` are read as free settings**, not engine-owned ones. They shape one
 * renderer and nothing resolves against them, so reserving the names would take two words out of
 * every author's vocabulary for no gain (D-084).
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class TextareaRenderer extends TypedFieldRenderer
{
    public const NAME = 'textarea';

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
        // ⚠️ Newlines survive as newlines. Turning them into `<br>` would be the renderer
        // rewriting the value on the way out, and the same text would read differently
        // depending on which renderer drew it.
        return '<span class="taxmod-value" style="white-space:pre-wrap">'
            . RenderResult::escape($this->characters($context))
            . '</span>';
    }

    protected function input(RenderContext $context): string
    {
        return '<textarea'
            . $this->attribute('name', $context->fieldName)
            . $this->attribute('cols', $this->numberSetting($context, 'cols'))
            . $this->attribute('rows', $this->numberSetting($context, 'rows'))
            . '>' . RenderResult::escape($this->characters($context)) . '</textarea>';
    }
}
