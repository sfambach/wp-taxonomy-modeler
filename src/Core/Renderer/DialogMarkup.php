<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Die eine Form eines Dialogs: ein geklipptes Häkchen als Schalter, ein Öffner, eine Schattenfläche, die Tafel —
 * ohne Skript.
 *
 * ⚠️ **Einmal, nicht je Aufrufer.** *Der Wähler ({@see ChooserRenderer}) und ein Bedienelement mit Dialog
 * ({@see ControlMarkup::button()}) zeichneten sonst dieselben sieben Tags zweimal — und das Stylesheet
 * kennt genau diese Klassen.*
 *
 * @see docs/NewConcept/90-decision-log.md D-730
 */
final class DialogMarkup
{
    /**
     * @param string $switch Die `id` des Schalters — eindeutig auf der Seite.
     * @param string $opener Was als Öffner steht (Wort, Icon, der gewählte Name).
     * @param string $head   Was links im Kopf steht; das Schliessen-Kreuz kommt von hier.
     * @param string $body   Der Inhalt der Tafel, fertig gezeichnet.
     * @param string $foot   Bestätigen und Abbruch — leer, wo der Dialog nur zeigt.
     */
    public static function of(
        string $switch,
        string $opener,
        string $head,
        string $body,
        string $foot,
        string $openerClass = 'button taxmod-icon-button taxmod-dialog-open',
        /** Das Wort für «OK», vom Rand — gezeichnet, wo der Aufrufer keinen eigenen Fuss gibt (D-804). */
        string $ok = '',
        /** Das Wort für «Abbrechen», vom Rand — in jedem Fuss, der noch keines hat (D-804). */
        string $cancel = '',
    ): string {
        $id = RenderResult::escape($switch);

        // ⚠️ **Die Regel für jeden Dialog** ([D-804](../../../docs/NewConcept/90-decision-log.md)) — *sein Wort: «they should have buttons
        // ok/confirm, cancel if not a button or the cross … is pressed then the dialog does not close».* Die Schattenfläche schliesst nicht
        // mehr; «OK» und «Abbrechen» sind Beschriftungen des Schalters, also schliessen sie ohne Skript. «Abbrechen» und das ✕ tragen die
        // Klasse, an der das Skript die Wahl von vorher zurücklegt.
        if ($foot === '' && $ok !== '') {
            $foot = '<label class="button button-primary taxmod-dialog-ok" for="' . $id . '">' . RenderResult::escape($ok) . '</label>';
        }

        if ($cancel !== '' && ! str_contains($foot, 'taxmod-dialog-cancel')) {
            $foot .= '<label class="button taxmod-dialog-cancel" for="' . $id . '">' . RenderResult::escape($cancel) . '</label>';
        }

        return '<span class="taxmod-chooser">'
            . RenderResult::htmlTag('input', [
                'type'  => 'checkbox',
                'class' => 'taxmod-dialog-switch',
                'id'    => $switch,
            ])
            . '<label class="' . RenderResult::escape($openerClass) . '" for="' . $id . '">' . $opener . '</label>'
            . '<span class="taxmod-dialog">'
            . '<span class="taxmod-dialog-shade"></span>'
            . '<span class="taxmod-dialog-panel">'
            . '<span class="taxmod-dialog-head">'
            . '<span class="taxmod-chooser-current">' . $head . '</span>'
            . '<label class="taxmod-dialog-close taxmod-dialog-cancel" for="' . $id . '"' . ($cancel === '' ? '' : ' title="' . RenderResult::escape($cancel) . '"') . '>&times;</label>'
            . '</span>'
            . $body
            . ($foot === '' ? '' : '<span class="taxmod-dialog-foot">' . $foot . '</span>')
            . '</span></span></span>';
    }
}
