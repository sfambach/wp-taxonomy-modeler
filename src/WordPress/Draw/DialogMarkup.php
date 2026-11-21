<?php declare(strict_types=1);

namespace Taxmod\WordPress\Draw;


/**
 * Die eine Form eines Dialogs: ein geklipptes Häkchen als Schalter, ein Öffner, eine Schattenfläche, die Tafel —
 * ohne Skript.
 *
 * ⚠️ **Einmal, nicht je Aufrufer.** *Der Wähler ({@see \Taxmod\Core\Renderer\ChooserRenderer}) und ein Bedienelement mit Dialog
 * ({@see ControlMarkup::button()}) zeichneten sonst dieselben sieben Tags zweimal — und das Stylesheet
 * kennt genau diese Klassen.*
 *
 * ⚠️ **Jeder Dialog sagt, was er tut** ([D-1436](../../../docs/NewConcept/90-decision-log.md)) — *sein Wort 18.11.: «jeder Dialog sollte eine
 * Überschrift haben, damit der Benutzer genau weiß, was er jetzt macht».* Der Kopf ist eine farbige Titelleiste; ein Dialog ohne Titel
 * trägt {@see self::UNTITLED}, an der der Wächter `dialog-title-check` ihn findet.
 *
 * @see docs/NewConcept/90-decision-log.md D-730
 */
final class DialogMarkup
{
    /** Die Klasse eines Dialogs ohne Titel — gezählt vom Wächter, nie gestaltet. */
    public const UNTITLED = 'taxmod-dialog-untitled';

    /** The one wider size of a dialog (D-1539). */
    public const WIDE = 'taxmod-dialog-wide';

    /**
     * @param string $switch Die `id` des Schalters — eindeutig auf der Seite.
     * @param string $opener Was als Öffner steht (Wort, Icon, der gewählte Name).
     * @param string $head   Der Titel, fertig gezeichnet — oder, wo `$title` gesetzt ist, die aktuelle Wahl unter der Titelleiste.
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
        /** Open when the page arrives — a dialog that answers what just happened, like a conflict (D-1148). */
        bool $open = false,
        /** Name of the tree button beside a chooser's field; set, the opener looks like a locked field and the tree opens the dialog (D-1163). */
        ?string $tree = null,
        /** The opener's description for its tooltip; empty: an icon-only opener shows its name (D-847). */
        string $openerTitle = '',
        /** The title as text (verb + subject, D-1436), where `$head` is not the title but the current choice of a chooser. */
        string $title = '',
        /** D-1539: a dialog whose content is a list to choose from (stock, substitutes) opens wide — one size more, the same everywhere. */
        bool $wide = false,
    ): string {
        $id = Html::escape($switch);

        // ⚠️ **Die Regel für jeden Dialog** ([D-804](../../../docs/NewConcept/90-decision-log.md)) — *sein Wort: «they should have buttons
        // ok/confirm, cancel if not a button or the cross … is pressed then the dialog does not close».* Die Schattenfläche schliesst nicht
        // mehr; «OK» und «Abbrechen» sind Beschriftungen des Schalters, also schliessen sie ohne Skript. «Abbrechen» und das ✕ tragen die
        // Klasse, an der das Skript die Wahl von vorher zurücklegt.
        if ($foot === '' && $ok !== '') {
            $foot = '<label class="button button-primary taxmod-dialog-ok" for="' . $id . '">' . Html::escape($ok) . '</label>';
        }

        if ($cancel !== '' && ! str_contains($foot, 'taxmod-dialog-cancel')) {
            $foot .= '<label class="button taxmod-dialog-cancel" for="' . $id . '">' . Html::escape($cancel) . '</label>';
        }

        // ⚠️ **Einfachauswahl: ein ausgegrautes Feld, rechts das Baumsymbol** ([D-1163](../../../docs/NewConcept/90-decision-log.md)) — *sein Wort am
        // 2026-11-04: «bei einfacher auswahl ein ausgegrautes feld erwarten und recht daneben … das baum symbol das öffnet den dialog». Beide
        // beschriften den Schalter, also öffnet auch ein Klick ins Feld.*
        $baum = $tree === null ? '' : '<label class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-pick-tree" for="' . $id . '"'
            . ($tree === '' ? '' : ' title="' . Html::escape($tree) . '" aria-label="' . Html::escape($tree) . '"') . '>' . IconMarkup::dashicon('networking') . '</label>';

        // ⚠️ *Der Name des Öffners sitzt am Öffner, das Symbol darin ist stumm (Prüftor zu Live-Check 19).*
        $iconOnly         = str_contains($openerClass, ControlMarkup::ICON_ONLY) || str_contains($openerClass, 'taxmod-square');
        [$named, $opener] = $iconOnly && trim(strip_tags(IconMarkup::liftName($opener)[1])) === '' ? IconMarkup::liftName($opener) : ['', $opener];

        return '<span class="taxmod-chooser' . ($tree === null ? '' : ' taxmod-pick') . '">'
            . Html::tag('input', [
                'type'  => 'checkbox',
                'class'   => 'taxmod-dialog-switch',
                'id'      => $switch,
                'checked' => $open,
            ])
            // ⚠️ *Ein Öffner, der nur ein Symbol ist, trägt dessen Namen als Tooltip (D-847).*
            . '<label class="' . Html::escape($openerClass) . '" for="' . $id . '"'
            . ($openerTitle !== '' ? ' title="' . Html::escape($openerTitle) . '"'
                : ($iconOnly && preg_match('/aria-label="([^"]*)"/', $named, $name) === 1 ? ' title="' . $name[1] . '"' : ''))
            . $named . '>' . $opener . '</label>'
            . $baum
            . '<span class="taxmod-dialog' . (trim(strip_tags($title !== '' ? $title : $head)) === '' ? ' ' . self::UNTITLED : '') . ($wide ? ' ' . self::WIDE : '') . '">'
            . '<span class="taxmod-dialog-shade"></span>'
            . '<span class="taxmod-dialog-panel">'
            // *Die Wahl bleibt in `.taxmod-chooser-current`, denn dort schreibt das Skript eine neue hinein.*
            . '<span class="taxmod-dialog-head">'
            . '<span class="taxmod-dialog-title" role="heading" aria-level="2">'
            . ($title !== '' ? Html::escape($title) : '<span class="taxmod-chooser-current">' . $head . '</span>') . '</span>'
            . '<label class="taxmod-dialog-close taxmod-dialog-cancel" for="' . $id . '"' . ($cancel === '' ? '' : ' title="' . Html::escape($cancel) . '"') . '>&times;</label>'
            . '</span>'
            . ($title !== '' ? '<span class="taxmod-dialog-current"><span class="taxmod-chooser-current">' . $head . '</span></span>' : '')
            . $body
            . ($foot === '' ? '' : '<span class="taxmod-dialog-foot">' . $foot . '</span>')
            . '</span></span></span>';
    }
}
