<?php declare(strict_types=1);

namespace Taxmod\WordPress\Draw;

use Taxmod\Core\Description\CurrentChoice;
use Taxmod\Core\Description\PickOpener;

/**
 * The one shape of an opener of the shared choosing tree — the core asks for it at a reference to the whole tree, the node page at
 * every field row's target.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class PickOpenerMarkup
{
    public static function of(PickOpener $o): string
    {
        $face = $o->face instanceof CurrentChoice
            ? match (true) {
                $o->face->word !== null => self::e($o->face->word),
                default                 => '<span class="taxmod-nothing">—</span><span class="screen-reader-text">' . self::e($o->face->forReader) . '</span>',
            }
            : IconMarkup::dashicon($o->face->symbol);
        // ⚠️ *Ein Symbol-Öffner trägt seinen Namen am Knopf (Prüftor zu Live-Check 19); ein Öffner mit Wort heisst wie sein Wort.*
        $named = $o->face instanceof CurrentChoice || $o->face->label === '' ? '' : ' aria-label="' . self::e($o->face->label) . '"';

        return '<button type="button" class="' . ($o->showsName ? 'button taxmod-dialog-open taxmod-pick-open' : 'button taxmod-icon-button taxmod-pick-open') . '"'
            . ' data-field="' . self::e($o->field) . '"'
            . ' data-chosen="' . ($o->chosen === null ? '' : $o->chosen) . '"'
            . ' data-barred="' . implode(',', array_map('intval', $o->barred)) . '"'
            . ' data-open="' . implode(',', array_map('intval', $o->open)) . '"'
            . ($o->showsName ? ' data-names="1"' : '')
            . $named . ' title="' . self::e($o->title) . '">' . $face . '</button>'
            . '<input type="hidden" name="' . self::e($o->field) . '" value="' . ($o->initial === null ? '' : $o->initial) . '"'
            . ($o->form === '' ? '' : ' form="' . self::e($o->form) . '"') . '>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
