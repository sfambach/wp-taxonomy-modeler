<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Das eine Stück Code, das weiss, wie ein Schiebeschalter aussieht.
 *
 * ⚠️ **Es gab ihn schon, aber nur als Teil eines Renderers**
 * ([D-695](../../../docs/NewConcept/90-decision-log.md)). *{@see ToggleRenderer} zeichnet einen
 * `bool` **aus dem Modell** — Feldname, Formular, Vorschau, der verborgene `0` davor. Die
 * Konfigurationsseite hat kein Modell: ihre Schalter sind WordPress-Optionen
 * ([D-389](../../../docs/NewConcept/90-decision-log.md)), und ein Schalter, der dort anders aussähe
 * als im Baum, wäre derselbe Schalter mit zwei Gesichtern.*
 *
 * ⚠️ **Also wandert das Aussehen hierher und beide fragen es**, statt dass der Rand das Markup ein
 * zweites Mal hinschreibt. *Das ist `CD-1` und das Verbot doppelter Tatsachen in einem: **eine
 * Stelle weiss, wie ein Schalter aussieht**, und die Farbe darauf ist weiter Sache der Oberfläche.*
 *
 * ```mermaid
 * flowchart LR
 *   R["ToggleRenderer · ein bool aus dem Modell"] --> M[this]
 *   S["die Konfigurationsseite · eine Option"] --> M
 *   M --> C["assets/admin.css · die Farbe"]
 * ```
 *
 * ⚠️ *Kein `$this`: die Klasse hält keinen Zustand und wird nie gebaut — wie {@see HintMarkup} und
 * {@see IconMarkup}, neben denen sie steht.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ToggleMarkup
{
    /**
     * Die Schiene mit ihrem Knopf.
     *
     * ⚠️ *`$live` sagt, ob jemand ihn bewegen darf. Ein fester Schalter ist keine ausgegraute
     * Schaltfläche, sondern eine **Anzeige** — er sagt einen Zustand und lädt nicht dazu ein.*
     */
    public static function track(bool $on, bool $live = true): string
    {
        return '<span class="taxmod-toggle-track' . ($on ? ' is-on' : '')
            . ($live ? '' : ' is-fixed') . '"><span class="taxmod-toggle-knob"></span></span>';
    }

    /**
     * Ein ganzer Schalter: die verborgene Null, das Kästchen, die Schiene.
     *
     * ⚠️ **Die verborgene `0` ist es, die *aus* zu *falsch* macht statt zu *abwesend***
     * ([D-315](../../../docs/NewConcept/90-decision-log.md), [D-232](../../../docs/NewConcept/90-decision-log.md)).
     * *PHP behält bei zwei gleichen Namen den letzten, also übersteuert der Schalter sie, wenn er an
     * ist. **Ein Kästchen allein schickt im Aus-Zustand gar nichts** — und «nichts geschickt» sähe
     * genauso aus wie «niemand hat je etwas gesagt».*
     *
     * @param string $formId Leer, wo das Element ohnehin im Formular steht.
     */
    public static function input(string $name, bool $on, string $formId = ''): string
    {
        return '<label class="taxmod-toggle">'
            . RenderResult::htmlTag('input', [
                'type'  => 'hidden',
                'name'  => $name,
                // ⚠️ *Eine Tabellenzeile ist ein `<tr>`, und ein Formular darf keine Zellen
                // umschliessen; ein Element in einer anderen Zelle steht draussen. Leer wird das
                // Attribut weggelassen ({@see RenderResult::htmlTag()}), also kostet es nichts, wo
                // es keins gibt.*
                'form'  => $formId,
                'value' => '0',
            ])
            . RenderResult::htmlTag('input', [
                'type'    => 'checkbox',
                'class'   => 'taxmod-toggle-input',
                'name'    => $name,
                // ⚠️ *Beide Eingaben brauchen es — die verborgene **und** das Kästchen. Eine allein
                // reicht nicht: dann käme beim Speichern immer «aus» an.*
                'form'    => $formId,
                'value'   => '1',
                'checked' => $on,
            ])
            . self::track($on)
            . '</label>';
    }
}
