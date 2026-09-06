<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Renderer\IconMarkup;

/**
 * Das eine Stück Code, das weiss, wie eine Erklärung hinter ein Fragezeichen kommt.
 *
 * ⚠️ **Sein Beschluss** ([D-661](../../../docs/NewConcept/90-decision-log.md)): *«nett die
 * Erklärung, aber bitte dahinter mit Fragezeichen oder als Tooltip; sollte generelle Lösung
 * sein.»* **Der Ton liegt auf *generell*:** *das Muster gab es schon, aber nur in
 * `NodesScreen::heading()`, und daneben standen zwölf Erklärungen als `class="description"` im
 * Fliesstext. Derselbe Bildschirm sagte dieselbe Art Sache auf zwei Arten.*
 *
 * ⚠️ **Warum hinter das Fragezeichen und nicht daneben:** *eine Erklärung, die immer sichtbar ist,
 * wird nach dem dritten Mal nicht mehr gelesen und kostet trotzdem jedes Mal Platz. Sie ist für
 * den, der sie sucht — und der findet sie am Fragezeichen, weil dort schon alle anderen stehen.*
 *
 * ⚠️ **Und der Preis, den D-661 ausdrücklich nennt: ein Tooltip allein ist auf einem
 * Berührungsbildschirm und für eine Vorlesehilfe nicht erreichbar.** *Darum steht der Satz **im
 * Markup** und nicht bloss im `title`. Drei Wege führen zu ihm, und keiner braucht ein Skript:*
 *
 * ```mermaid
 * flowchart LR
 *   M["Maus"] --> T["title · der native Tooltip"]
 *   K["Tastatur · Tab"] --> F[":focus — die Sprechblase klappt auf"]
 *   B["Berührung · Tippen"] --> F
 *   V["Vorlesehilfe"] --> S["span.taxmod-hint-text · steht immer im Markup"]
 * ```
 *
 * ⚠️ *Die Hülle trägt `tabindex`, nicht das Icon: der zugängliche Name des fokussierbaren Dings ist
 * dann der Satz selbst. Das Icon bleibt `aria-hidden` — sonst sagte es seinen Namen zweimal.*
 *
 * ⚠️ *Was **keine** Erklärung ist, kommt hier nicht her: eine Meldung, ein Befund, eine Zahl bleiben
 * sichtbar. Es geht um den erklärenden Nebensatz, nicht um Auskunft.*
 *
 * ⚠️ *Kein `$this`: die Klasse hält keinen Zustand und wird nie gebaut — wie {@see IconMarkup},
 * neben der sie steht.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class HintMarkup
{
    /** Die Hülle, die das Fragezeichen und seinen Satz zusammenhält. */
    public const NAME = 'taxmod-hint';

    /** Das Fragezeichen selbst. */
    public const ICON = 'taxmod-hint-icon';

    /** Der Satz — im Markup, nicht nur im `title`. */
    public const TEXT = 'taxmod-hint-text';

    /**
     * Ein Fragezeichen, hinter dem dieser Satz steht.
     *
     * @param string $hint Der erklärende Satz, **schon übersetzt** (`AR-2`). Leer heisst: kein
     *                     Fragezeichen — ein Zeichen ohne Erklärung wäre ein Versprechen ohne
     *                     Inhalt.
     */
    public static function icon(string $hint): string
    {
        if (trim($hint) === '') {
            return '';
        }

        return '<span class="' . self::NAME . '" tabindex="0" title="' . esc_attr($hint) . '">'
            . '<span class="' . self::ICON . '">'
            . IconMarkup::dashicon('editor-help')
            . '</span>'
            . '<span class="' . self::TEXT . '">' . esc_html($hint) . '</span>'
            . '</span>';
    }

    /**
     * Sichtbarer Text, und dahinter das Fragezeichen.
     *
     * ⚠️ *Der Aufrufer gibt den sichtbaren Teil **fertig entschärft** — es ist mal ein Name, mal
     * eine Überschrift, mal eine Zeile mit Auszeichnung darin.*
     */
    public static function behind(string $escapedText, string $hint): string
    {
        return $escapedText . self::icon($hint);
    }
}
