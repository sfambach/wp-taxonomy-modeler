<?php declare(strict_types=1);

namespace Taxmod\Tests\Support;

use Taxmod\Core\Description\FieldDescription;
use Taxmod\Core\Description\Message;
use Taxmod\Core\Description\PageDescription;

/**
 * What a page holds, in order — fields, values, controls, messages — read either from a description or from drawn HTML.
 * Two drawers agree when their inventories do (D-636: «nichts fehlt und nichts kommt dazu»), whatever their markup.
 *
 * The contract every drawer keeps, so the HTML can be read back without knowing the drawer:
 *
 * ```text
 * CONTRACT
 * field    an element with data-taxmod-field="<address>"; its label is the first <label>, its value the first
 *          input/select/textarea/output inside (selected option for a select)
 * control  a <button name=…> with value, its address in data-taxmod-address; disabled = not available
 * message  an element with data-taxmod-message="<key>", its address in data-taxmod-address
 * ```
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class PageInventory
{
    /** @return list<string> */
    public static function of(PageDescription $page): array
    {
        $lines = ['page ' . $page->address->token() . ' «' . $page->title . '»', ...self::messageLines($page->messages)];

        foreach ($page->sections as $section) {
            $lines[] = 'section «' . $section->title . '»';

            foreach ($section->rows as $row) {
                foreach ($row->fields as $field) {
                    array_push($lines, ...self::fieldLines($field));
                }

                foreach ($row->controls as $control) {
                    $lines[] = self::controlLine($control->name, $control->value, $control->address?->token(), $control->available, $control->label);
                }
            }
        }

        return $lines;
    }

    /** @return list<string> */
    public static function read(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        $lines = [];

        foreach ($xpath->query('//*[@data-taxmod-address and (self::main) ] | //h2 | //*[@data-taxmod-field] | //button[@name] | //*[@data-taxmod-message]') ?: [] as $node) {
            \assert($node instanceof \DOMElement);

            if ($node->tagName === 'main') {
                $lines[] = 'page ' . $node->getAttribute('data-taxmod-address') . ' «' . self::text($xpath, './h1', $node) . '»';
            } elseif ($node->tagName === 'h2') {
                $lines[] = 'section «' . trim($node->textContent) . '»';
            } elseif ($node->hasAttribute('data-taxmod-field')) {
                $lines[] = 'field ' . $node->getAttribute('data-taxmod-field') . ' «' . self::text($xpath, './/label', $node) . '» = '
                    . self::value($xpath, $node) . ($node->hasAttribute('hidden') ? ' hidden' : '');
            } elseif ($node->tagName === 'button') {
                $lines[] = self::controlLine(
                    $node->getAttribute('name'),
                    $node->getAttribute('value'),
                    $node->hasAttribute('data-taxmod-address') ? $node->getAttribute('data-taxmod-address') : null,
                    ! $node->hasAttribute('disabled'),
                    trim($node->textContent)
                );
            } else {
                $lines[] = 'message ' . $node->getAttribute('data-taxmod-message') . ' @' . ($node->getAttribute('data-taxmod-address') ?: '-');
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private static function fieldLines(FieldDescription $field): array
    {
        return [
            'field ' . $field->address->token() . ' «' . $field->label . '» = ' . ($field->value ?? '') . ($field->hidden ? ' hidden' : ''),
            ...self::messageLines($field->messages),
        ];
    }

    /**
     * @param list<Message> $messages
     * @return list<string>
     */
    private static function messageLines(array $messages): array
    {
        return array_map(static fn (Message $m): string => 'message ' . $m->key . ' @' . ($m->address?->token() ?? '-'), $messages);
    }

    private static function controlLine(string $name, string $value, ?string $address, bool $available, string $label): string
    {
        return 'control ' . $name . '=' . $value . ' @' . ($address ?? '-') . ($available ? '' : ' unavailable') . ' «' . $label . '»';
    }

    private static function text(\DOMXPath $xpath, string $query, \DOMElement $in): string
    {
        $found = $xpath->query($query, $in);

        return $found !== false && $found->length > 0 ? trim((string) $found->item(0)?->textContent) : '';
    }

    private static function value(\DOMXPath $xpath, \DOMElement $field): string
    {
        $found = $xpath->query('.//input | .//select | .//textarea | .//output', $field);
        $first = $found !== false ? $found->item(0) : null;

        if (! $first instanceof \DOMElement) {
            return '';
        }

        return match ($first->tagName) {
            'input'  => $first->getAttribute('value'),
            'select' => self::text($xpath, './/option[@selected]', $first),
            default  => $first->textContent,
        };
    }
}
