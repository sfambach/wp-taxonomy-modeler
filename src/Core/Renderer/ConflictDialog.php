<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * The one dialog for «somebody else saved in the meantime» — the modeller's record form and every module show the same
 * ([D-1148](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Open when the page arrives, because it answers the save just refused. Its foot is fixed: the caller's way to write its own input
 * anyway (undoable like every act, or empty where the module has none) and «show the current state», which only closes — the page
 * behind it already shows what is stored now. Same form as every dialog ({@see DialogMarkup}, D-804).*
 *
 * @see docs/NewConcept/90-decision-log.md D-1148
 */
final class ConflictDialog
{
    public const SWITCH = 'taxmod-conflict-dialog';

    /**
     * @param string $head    the dialog's title, translated by the caller
     * @param string $text    what happened, translated by the caller
     * @param string $anyway  ready markup: a form with the button that writes the kept input anyway; empty when there is none
     * @param string $current the label of the button that closes and leaves the current state
     */
    public static function markup(string $head, string $text, string $anyway, string $current): string
    {
        return '<div class="taxmod-conflict">' . DialogMarkup::of(
            self::SWITCH,
            '',
            RenderResult::escape($head),
            '<span class="taxmod-dialog-body"><p>' . RenderResult::escape($text) . '</p></span>',
            $anyway . '<label class="button button-primary taxmod-dialog-ok" for="' . self::SWITCH . '">' . RenderResult::escape($current) . '</label>',
            'taxmod-conflict-opener',
            open: true,
        ) . '</div>';
    }
}
