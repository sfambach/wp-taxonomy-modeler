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
    public static function of(string $switch, string $opener, string $head, string $body, string $foot, string $openerClass = 'button taxmod-icon-button taxmod-dialog-open'): string
    {
        $id = RenderResult::escape($switch);

        return '<span class="taxmod-chooser">'
            . RenderResult::htmlTag('input', [
                'type'  => 'checkbox',
                'class' => 'taxmod-dialog-switch',
                'id'    => $switch,
            ])
            . '<label class="' . RenderResult::escape($openerClass) . '" for="' . $id . '">' . $opener . '</label>'
            . '<span class="taxmod-dialog">'
            . '<label class="taxmod-dialog-shade" for="' . $id . '"></label>'
            . '<span class="taxmod-dialog-panel">'
            . '<span class="taxmod-dialog-head">'
            . '<span class="taxmod-chooser-current">' . $head . '</span>'
            . '<label class="taxmod-dialog-close" for="' . $id . '">&times;</label>'
            . '</span>'
            . $body
            . ($foot === '' ? '' : '<span class="taxmod-dialog-foot">' . $foot . '</span>')
            . '</span></span></span>';
    }
}
