<?php declare(strict_types=1);

namespace Taxmod\Tests\Support;

use Taxmod\Core\Description\ControlDescription;
use Taxmod\Core\Description\FieldDescription;
use Taxmod\Core\Description\Message;
use Taxmod\Core\Description\PageDescription;

/**
 * The second, tiny drawer (D-636): plain HTML from a page description alone, never asking anything else. It is not
 * there to be used — it proves the description carries the page.
 *
 * ```mermaid
 * flowchart LR
 *   D[PageDescription] --> P[PlainDrawer] --> I[PageInventory::read]
 *   D --> E[PageInventory::of]
 *   I -- "=" --- E
 * ```
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class PlainDrawer
{
    public static function draw(PageDescription $page): string
    {
        $html = '<main data-taxmod-address="' . self::e($page->address->token()) . '"><h1>' . self::e($page->title) . '</h1>'
            . self::messages($page->messages);

        foreach ($page->sections as $section) {
            $html .= '<section><h2>' . self::e($section->title) . '</h2>';

            foreach ($section->rows as $row) {
                $html .= '<div data-taxmod-row="' . self::e($row->address->token()) . '">';

                foreach ($row->fields as $field) {
                    $html .= self::field($field);
                }

                foreach ($row->controls as $control) {
                    $html .= self::control($control);
                }

                $html .= '</div>';
            }

            $html .= '</section>';
        }

        return $html . '</main>';
    }

    private static function field(FieldDescription $field): string
    {
        return '<p data-taxmod-field="' . self::e($field->address->token()) . '"' . ($field->hidden ? ' hidden' : '') . '>'
            . '<label>' . self::e($field->label) . '</label>'
            . '<output>' . self::e($field->value ?? '') . '</output>'
            . self::messages($field->messages)
            . '</p>';
    }

    private static function control(ControlDescription $control): string
    {
        return '<button name="' . self::e($control->name) . '" value="' . self::e($control->value) . '"'
            . ($control->address === null ? '' : ' data-taxmod-address="' . self::e($control->address->token()) . '"')
            . ($control->available ? '' : ' disabled')
            . '>' . self::e($control->label) . '</button>';
    }

    /** @param list<Message> $messages */
    private static function messages(array $messages): string
    {
        $html = '';

        foreach ($messages as $message) {
            $html .= '<li data-taxmod-message="' . self::e($message->key) . '"'
                . ($message->address === null ? '' : ' data-taxmod-address="' . self::e($message->address->token()) . '"') . '></li>';
        }

        return $html;
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
