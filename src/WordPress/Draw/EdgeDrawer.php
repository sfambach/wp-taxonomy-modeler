<?php declare(strict_types=1);

namespace Taxmod\WordPress\Draw;

use Taxmod\Core\Description\Describes;
use Taxmod\Core\Description\Drawer;
use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Description\Locked;
use Taxmod\Core\Description\Sequence;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\ValueInput;
use Taxmod\Core\Description\ValueKind;
use Taxmod\Core\Renderer\IconMarkup;

/**
 * The edge drawer: one way of drawing per kind of description (D-1424, D-1429 A14). The markup is the one the core drew
 * before, character for character, so every check and every screen stays as it was.
 *
 * ⚠️ *Escaping is plain `htmlspecialchars`, not `esc_html()`: the drawer is called in the core run too, which loads no
 * WordPress, and the two agree on every character this draws.*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class EdgeDrawer implements Drawer
{
    public function draw(Describes $piece): string
    {
        return match (true) {
            $piece instanceof ShownValue => $this->shown($piece),
            $piece instanceof ValueInput => $this->input($piece),
            $piece instanceof Locked     => '<fieldset disabled class="taxmod-read-only">' . $this->draw($piece->inner) . '</fieldset>',
            $piece instanceof Sequence   => implode('', array_map($this->draw(...), $piece->pieces)),
            default                      => throw new \InvalidArgumentException('Nothing draws a ' . $piece::class . '.'),
        };
    }

    private function shown(ShownValue $value): string
    {
        $text = $value->text;

        return match ($value->kind) {
            ValueKind::Unchosen => '<span class="taxmod-value taxmod-no-renderer">' . self::e($text) . '</span>',
            ValueKind::Dangling => '<span class="taxmod-value taxmod-dangling">' . self::e($text) . '</span>',
            ValueKind::LongText => '<span class="taxmod-value" style="white-space:pre-wrap">' . self::e($text) . '</span>',
            ValueKind::Code     => '<pre class="taxmod-value taxmod-code" data-taxmod-code="' . self::e($value->target) . '">' . self::e($text) . '</pre>',
            ValueKind::Text     => self::valueSpan(self::e($text)),
            ValueKind::Colour   => self::valueSpan($text === '' ? '' : self::patch($text) . self::e($text)),
            ValueKind::Email    => self::valueSpan($text === '' ? '' : '<a href="mailto:' . self::e($text) . '">' . self::e($text) . '</a>'),
            ValueKind::Jump     => self::valueSpan($value->target === '' ? '' : '<a class="taxmod-jump" href="' . self::e($value->target) . '">'
                . IconMarkup::dashicon('external', $text) . '</a>'),
            ValueKind::Flag     => self::valueSpan($text === '' ? '' : self::tag('input', ['type' => 'checkbox', 'disabled' => true, 'checked' => $text === '1'])),
        };
    }

    private function input(ValueInput $in): string
    {
        $required = $in->required ? 'true' : null;

        return match ($in->kind) {
            InputKind::Unchosen => self::tag('input', ['type' => 'text', 'class' => 'taxmod-no-renderer', 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value]),
            InputKind::Line     => self::tag('input', [
                'type' => 'text', 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value,
                'size' => $in->size, 'pattern' => $in->pattern, 'inputmode' => $in->inputMode,
            ]),
            InputKind::Number   => self::tag('input', [
                'type' => 'number', 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value,
                'min' => $in->min, 'max' => $in->max, 'step' => $in->step,
            ]),
            InputKind::Range    => self::tag('input', [
                'type' => 'range', 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value,
                'min' => $in->min, 'max' => $in->max, 'step' => $in->step,
            ]) . self::valueSpan(self::e($in->shown ?? '')),
            InputKind::Colour   => $in->value !== '' && ! self::isPatchable($in->value)
                ? self::tag('input', ['type' => 'text', 'name' => $in->name, 'form' => $in->form, 'value' => $in->value])
                : self::tag('input', ['type' => 'color', 'name' => $in->name, 'form' => $in->form, 'value' => $in->value]),
            InputKind::Email    => self::tag('input', ['type' => 'email', 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value]),
            InputKind::LongText => '<textarea' . self::attribute('name', $in->name) . self::attribute('form', $in->form)
                . ($in->required ? ' aria-required="true"' : '') . self::attribute('cols', $in->columns) . self::attribute('rows', $in->rows)
                . '>' . self::e($in->value) . '</textarea>',
            InputKind::Code     => '<textarea class="large-text code taxmod-code" spellcheck="false"' . self::attribute('data-taxmod-code', $in->note)
                . self::attribute('name', $in->name) . self::attribute('form', $in->form) . ($in->required ? ' aria-required="true"' : '')
                . self::attribute('rows', $in->rows) . '>' . self::e($in->value) . '</textarea>',
            // The hidden field is what makes *unticked* mean false rather than absent; PHP keeps the last of two equal names.
            InputKind::Flag     => self::tag('input', ['type' => 'hidden', 'name' => $in->name, 'form' => $in->form, 'value' => '0'])
                . self::tag('input', ['type' => 'checkbox', 'name' => $in->name, 'form' => $in->form, 'value' => '1', 'checked' => $in->value === '1']),
            InputKind::Fixed    => self::tag('input', ['type' => 'text', 'class' => 'taxmod-locked', 'value' => $in->shown ?? '', 'disabled' => true, 'title' => $in->note])
                . self::tag('input', ['type' => 'hidden', 'name' => $in->name, 'form' => $in->form, 'value' => $in->value]),
        };
    }

    private static function valueSpan(string $markup): string
    {
        return '<span class="taxmod-value">' . $markup . '</span>';
    }

    private static function isPatchable(string $colour): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $colour) === 1;
    }

    private static function patch(string $colour): string
    {
        return self::isPatchable($colour)
            ? '<span class="taxmod-swatch" style="display:inline-block;width:1em;height:1em;vertical-align:middle;margin-right:.3em;border:1px solid #8c8f94;background:'
                . self::e($colour) . '"></span>'
            : '';
    }

    /** @param array<string, string|bool|null> $attributes empty, null and false leave the attribute out; true writes it bare */
    private static function tag(string $name, array $attributes): string
    {
        $markup = '<' . $name;

        foreach ($attributes as $attribute => $value) {
            if ($value === null || $value === false || $value === '') {
                continue;
            }

            $markup .= $value === true ? ' ' . $attribute : ' ' . $attribute . '="' . self::e($value) . '"';
        }

        return $markup . '>';
    }

    private static function attribute(string $name, ?string $value): string
    {
        return $value === null || $value === '' ? '' : ' ' . $name . '="' . self::e($value) . '"';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
