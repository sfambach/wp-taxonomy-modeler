<?php declare(strict_types=1);

namespace Taxmod\WordPress\Draw;

use Taxmod\Core\Description\ActsForm;
use Taxmod\Core\Description\AddChosen;
use Taxmod\Core\Description\ChoicePurpose;
use Taxmod\Core\Description\Choosing;
use Taxmod\Core\Description\Carried;
use Taxmod\Core\Description\Describes;
use Taxmod\Core\Description\DialogBox;
use Taxmod\Core\Description\Drawn;
use Taxmod\Core\Description\Hint;
use Taxmod\Core\Description\Icon;
use Taxmod\Core\Description\Drawer;
use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Description\Locked;
use Taxmod\Core\Description\MediaButtons;
use Taxmod\Core\Description\MediaLink;
use Taxmod\Core\Description\MediaLook;
use Taxmod\Core\Description\MediaPicker;
use Taxmod\Core\Description\Sequence;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\ValueInput;
use Taxmod\Core\Description\ValueKind;
use Taxmod\Core\Renderer\Control;

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
    private const NOTHING = '<span class="taxmod-nothing">—</span>';

    public function draw(Describes $piece): string
    {
        return match (true) {
            $piece instanceof Drawn      => $piece->markup,
            $piece instanceof ShownValue => $this->shown($piece),
            $piece instanceof ValueInput => $this->input($piece),
            $piece instanceof Locked     => '<fieldset disabled class="taxmod-read-only">' . $this->draw($piece->inner) . '</fieldset>',
            $piece instanceof Sequence   => implode('', array_map($this->draw(...), $piece->pieces)),
            $piece instanceof Choosing   => $this->choosing($piece),
            $piece instanceof Icon       => $piece->character ? IconMarkup::glyph($piece->symbol, $piece->label) : IconMarkup::dashicon($piece->symbol, $piece->label),
            $piece instanceof Hint       => HintMarkup::icon($piece->text),
            $piece instanceof MediaLink  => $piece->asValue ? self::valueSpan(self::mediaLink($piece)) : self::mediaLink($piece),
            $piece instanceof MediaButtons => self::mediaButtons($piece),
            $piece instanceof MediaPicker => $this->mediaPicker($piece),
            $piece instanceof Control    => ControlMarkup::button($piece),
            $piece instanceof ActsForm   => ControlMarkup::actsForm($piece->id, $piece->submits, $piece->controls),
            $piece instanceof Carried    => ControlMarkup::carried($piece),
            $piece instanceof DialogBox  => DialogMarkup::of($piece->switch, $piece->opener, $piece->head, $piece->body, $piece->foot, $piece->openerClass, $piece->ok, $piece->cancel, $piece->open, $piece->tree),
            $piece instanceof AddChosen  => SelectMarkup::add($piece, $piece->purpose === ChoicePurpose::AddonCandidate ? 'taxmod-addon-add' : 'taxmod-list-add'),
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
            ValueKind::Switch   => self::valueSpan($text === '' ? '' : ToggleMarkup::track($text === '1', false)),
            ValueKind::Chosen   => $text === '' ? self::NOTHING : '<span class="taxmod-choice">' . self::e($text) . '</span>',
            ValueKind::NotOffered => '<span class="taxmod-choice taxmod-unknown-choice">' . self::e($text) . '</span>',
            ValueKind::ChosenColour => $text === '' ? self::NOTHING
                : '<span class="taxmod-choice taxmod-color-choice">' . self::swatch($value->target) . self::e($text) . '</span>',
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
            InputKind::Switch   => ToggleMarkup::input($in->name, $in->value === '1', $in->form),
            InputKind::Moment   => self::tag('input', [
                'type' => self::momentType($in->note), 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value,
                'pattern' => $in->pattern, 'min' => $in->min, 'max' => $in->max,
            ]),
            InputKind::Fixed    => self::tag('input', ['type' => 'text', 'class' => 'taxmod-locked', 'value' => $in->shown ?? '', 'disabled' => true, 'title' => $in->note])
                . self::tag('input', ['type' => 'hidden', 'name' => $in->name, 'form' => $in->form, 'value' => $in->value]),
        };
    }

    private function choosing(Choosing $wahl): string
    {
        $merkmale = match ($wahl->purpose) {
            ChoicePurpose::Value, ChoicePurpose::Colour => [],
            ChoicePurpose::Summary        => ['class' => 'taxmod-summary-choice'],
            ChoicePurpose::Candidate      => ['class' => 'taxmod-switch-candidates'],
            ChoicePurpose::ValueCandidate => ['class' => 'taxmod-switch-candidates taxmod-value-candidates', 'data-taxmod-name' => $wahl->name],
            ChoicePurpose::AddonCandidate => ['class' => 'taxmod-addon-candidates'],
            ChoicePurpose::ReferenceRow   => ['class' => 'taxmod-ref-select'],
        };

        if ($wahl->width !== null && $wahl->width > 0) {
            $merkmale['style'] = 'width:' . $wahl->width . 'ch';
        }

        if ($wahl->hint !== null) {
            $merkmale['title'] = $wahl->hint;
        }

        if ($wahl->purpose !== ChoicePurpose::Colour) {
            return SelectMarkup::select($wahl, $merkmale);
        }

        $merkmale['data-taxmod-swatches'] = (string) json_encode($wahl->swatches);

        return '<span class="taxmod-color-choice">' . self::swatch($wahl->swatches[(int) $wahl->now] ?? '') . SelectMarkup::select($wahl, $merkmale) . '</span>';
    }

    /** The patch of a chosen colour; dashed where the colour is not one a browser draws. */
    private static function swatch(string $hex): string
    {
        return '<span class="taxmod-swatch" style="display:inline-block;width:1em;height:1em;vertical-align:middle;margin-right:.3em;'
            . (self::isPatchable($hex) ? 'border:1px solid #8c8f94;background:' . self::e($hex) : 'border:1px dashed #8c8f94')
            . '"></span>';
    }

    private static function mediaLink(MediaLink $link): string
    {
        $auf  = $link->newTab ? ' target="_blank" rel="noopener"' : '';
        $wort = self::e($link->word);

        return match ($link->look) {
            MediaLook::Private           => '<a class="taxmod-media taxmod-media-private" href="' . self::e($link->url) . '">' . $wort . '</a>',
            MediaLook::PrivateUnreadable => '<span class="taxmod-media taxmod-media-private">' . $wort . '</span>',
            MediaLook::Missing           => '<span class="taxmod-media taxmod-media-missing">' . $wort . '</span>',
            MediaLook::Image             => '<a class="taxmod-media taxmod-media-image" href="' . self::e($link->url) . '" title="' . $wort . '"' . $auf . '>'
                . '<img class="taxmod-media-thumb" src="' . self::e($link->thumbnail) . '" alt="' . $wort . '" loading="lazy"></a>',
            MediaLook::File              => '<a class="taxmod-media" href="' . self::e($link->url) . '"' . $auf . '>' . $wort . '</a>',
            MediaLook::Address           => '<a class="taxmod-media" href="' . self::e($link->url) . '"' . $auf . '>' . $wort . '</a>'
                . ($link->state === '' ? '' : ' <span class="taxmod-media-state">' . self::e($link->state) . '</span>'),
        };
    }

    private static function mediaButtons(MediaButtons $knoepfe): string
    {
        $daten = ($knoepfe->addressField === '' ? '' : ' data-taxmod-address="' . self::e($knoepfe->addressField) . '"')
            . ($knoepfe->captionField === '' ? '' : ' data-taxmod-caption="' . self::e($knoepfe->captionField) . '"')
            . ' data-taxmod-newtab="' . ($knoepfe->newTab ? '1' : '0') . '"';
        $knopf = static fn (string $klasse, string $symbol, string $wort): string => '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' ' . $klasse . '" style="color:#1d2327"'
            . ($wort === '' ? '' : ' title="' . self::e($wort) . '"') . $daten . '>' . IconMarkup::dashicon($symbol, $wort) . '</button>';

        return $knopf('taxmod-media-library', 'media-default', $knoepfe->libraryWord) . $knopf('taxmod-media-wplink', 'admin-links', $knoepfe->linkWord);
    }

    private function mediaPicker(MediaPicker $feld): string
    {
        $link = '<input type="text" inputmode="url" class="taxmod-media-link" name="' . self::e($feld->name) . '"'
            . ' value="' . self::e($feld->address) . '" size="30"' . ($feld->form === '' ? '' : ' form="' . self::e($feld->form) . '"') . '>';

        return $feld->buttons === null
            ? $link
            : '<span class="taxmod-media-input">' . $link . self::mediaButtons($feld->buttons) . ($feld->current === null ? '' : ' ' . $this->draw($feld->current)) . '</span>';
    }

    private static function momentType(string $precision): string
    {
        return match ($precision) {
            'year'  => 'number',
            'month' => 'month',
            'date'  => 'date',
            'time'  => 'time',
            'any'   => 'text',
            default => 'datetime-local',
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
