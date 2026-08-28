<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SimpleType;

/**
 * A colour, with the patch beside the characters.
 *
 * ⚠️ **The picker is offered only where the stored value is one it can hold.** `<input
 * type="color">` accepts exactly `#rrggbb`; handed anything else every browser silently reports
 * `#000000`, and the next save would write black over a value nobody touched. **A control that
 * can lose a value on the way past is worse than a plain field**, so anything the picker cannot
 * represent is edited as text.
 *
 * ⚠️ **That is a fallback within one renderer, not a second renderer** — the same distinction
 * R15 draws for read-only. What is *not* built is [D-226](90-decision-log.md)'s coupled pair, the
 * hex field standing **beside** the picker with both live: two controls writing one field need
 * JavaScript to stay in step, and a renderer produces markup (D-021).
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ColorRenderer extends TypedFieldRenderer
{
    public const NAME = 'color';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return [SimpleType::Color];
    }

    protected function display(RenderContext $context): string
    {
        $outputValue = $this->outputValue($context);

        if ($outputValue === '') {
            return $this->createHtmlValueSpan('');
        }

        return $this->createHtmlValueSpan(
            $this->patch($outputValue) . RenderResult::escape($outputValue)
        );
    }

    protected function input(RenderContext $context): string
    {
        $outputValue = $this->outputValue($context);

        if ($outputValue !== '' && ! $this->pickerCanHold($outputValue)) {
            return RenderResult::htmlTag('input', [
                'type'  => 'text',
                'name'  => $context->fieldName,
                'value' => $outputValue,
            ]) . $this->patch($outputValue);
        }

        return RenderResult::htmlTag('input', [
            'type'  => 'color',
            'name'  => $context->fieldName,
            'value' => $outputValue,
        ]);
    }

    private function pickerCanHold(string $outputValue): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $outputValue) === 1;
    }

    /** A swatch of whatever was stored — inline, because a renderer ships no stylesheet. */
    private function patch(string $outputValue): string
    {
        if (! $this->pickerCanHold($outputValue)) {
            return '';
        }

        return '<span class="taxmod-swatch" style="display:inline-block;width:1em;height:1em;'
            . 'vertical-align:middle;margin-right:.3em;border:1px solid #8c8f94;background:'
            . RenderResult::escape($outputValue) . '"></span>';
    }
}
