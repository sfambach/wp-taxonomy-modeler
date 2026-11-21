<?php declare(strict_types=1);

namespace Taxmod\WordPress\Draw;


/**
 * Das eine Stück Code, das weiss, wie ein Icon geschrieben wird.
 *
 * ⚠️ **Es gibt es, weil der Eigentümer dasselbe **mehrfach** bemängelt hat** (2026-08-29): *«Icons
 * sind irgendwie nicht richtig aligned, die Grösse stimmt nicht … das Speichern-Symbol ist wieder
 * nach oben verschoben.»* **Ein Fehler, der wiederkommt, nachdem er einzeln behoben wurde, ist kein
 * Serienfehler, sondern ein fehlender Ort** — dieselbe Diagnose, aus der
 * {@see \Taxmod\Core\Renderer\Html::tag()} entstand ([D-465](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **Seine Vermutung war nachprüfbar falsch, und das ist der Grund, warum hier gemessen und nicht
 * getauscht wurde.** *Er hielt unterschiedliche `viewBox`-Werte oder ein schlechtes Icon-Set für die
 * Ursache. **Gemessen: es gibt in diesem Plugin kein einziges SVG und keine einzige Bilddatei** — 0
 * Treffer in `src/` und `assets/`. Ein Set zu tauschen hätte kein einziges der Symptome berührt.*
 *
 * ⚠️ **Die wirkliche Ursache, gemessen:** *vier Techniken nebeneinander, das Symbol an **sechs**
 * Stellen von Hand hingeschrieben, **fünf** konkurrierende Grössenregeln (zweimal hart 16px, einmal
 * gar keine und damit die 20px-Vorgabe von WordPress) und **drei** Ausrichtungsmechanismen. Auf einer
 * Seite standen 16, 17 und 20 Pixel gleichzeitig.*
 *
 * ```mermaid
 * flowchart LR
 *   B["der Knopf"] --> I["this · IconMarkup"]
 *   T["der Baum"] --> I
 *   A["die Auswahl"] --> I
 *   S["die Schirme"] --> I
 *   I --> C[".taxmod-icon · eine CSS-Regel"]
 * ```
 *
 * ⚠️ **Die gemeinsame Klasse ist der eigentliche Bau, nicht diese Methoden.** *Vorher war das einzig
 * Gemeinsame `dashicons` — die Klasse von WordPress, die 20px vorgibt, worauf jeder Zusammenhang sie
 * neu verkleinerte. Jetzt trägt jedes Icon `taxmod-icon`, und **eine** Regel bestimmt Kasten, Grösse
 * und Ausrichtung.*
 *
 * ⚠️ *`$this` gibt es hier nicht: die Klasse hält keinen Zustand und wird nie gebaut. Sie steht neben
 * {@see ControlMarkup}, die dasselbe für den Knopf tut — und die diese hier benutzt, statt ihr
 * Symbol selbst hinzuschreiben. Der Eigentümer hat genau das verlangt: «vielleicht könnte man den
 * Icon-Renderer auslagern, und der Button benutzt den».*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class IconMarkup
{
    /**
     * Die Klasse, die jedes Icon trägt.
     *
     * ⚠️ *Sie steht **neben** `dashicons` und ersetzt es nicht: `dashicons` liefert die Schrift und
     * das Zeichen, diese hier den Kasten. Die beiden zu vermischen hiesse, die Zeichentabelle von
     * WordPress zu unserer zu erklären.*
     */
    public const NAME = 'taxmod-icon';

    /** Ein Zeichen, das keine Icon-Schrift liefert — heute nur die Diskette. */
    public const GLYPH = 'taxmod-icon-glyph';

    /**
     * Ein Icon aus der Zeichenschrift von WordPress.
     *
     * @param string $key   Der Dashicon-Schlüssel **ohne** das `dashicons-` davor.
     * @param string $label Der zugängliche Name. **Leer heisst schmückend** und setzt
     *                      `aria-hidden` — eine der beiden Angaben muss fallen, denn ein Icon ohne
     *                      Namen und ohne `aria-hidden` ist für einen Screenreader eine Form ohne
     *                      Bedeutung, und eines mit beidem sagt seinen Namen zweimal.
     */
    public static function dashicon(string $key, string $label = ''): string
    {
        if ($key === '') {
            return '';
        }

        return '<span class="' . self::NAME . ' dashicons dashicons-' . Html::escape($key) . '"'
            . self::naming($label) . '></span>';
    }

    /**
     * Ein Icon, das ein gewöhnliches Zeichen ist.
     *
     * ⚠️ **Es ist der Fall, an dem der Eigentümer die Verschiebung immer wieder gesehen hat**, und
     * er war messbar anders als alle anderen: *das Speichern-Symbol war das **einzige Icon ohne
     * `width` und `height`.* Seine Nachbarn hatten einen festen Kasten, es hatte nur eine
     * Schriftgrösse — und eine Emoji-Schrift bringt ihre eigenen Ober- und Unterlängen mit, so dass
     * dieselbe Schriftgrösse weder dieselbe Höhe noch dieselbe optische Mitte bedeutet.*
     *
     * ⚠️ *Warum überhaupt ein Zeichen: die Icon-Schrift hat keine Diskette, und `dashicons-saved`
     * ist ein Haken — vom Eigentümer selbst bemerkt
     * ([D-486](../../../docs/NewConcept/90-decision-log.md)).*
     */
    public static function glyph(string $character, string $label = ''): string
    {
        if ($character === '') {
            return '';
        }

        return '<span class="' . self::NAME . ' ' . self::GLYPH . '"' . self::naming($label) . '>'
            . Html::escape($character) . '</span>';
    }

    /**
     * Der Name eines Symbols zieht an das Element, das man bedient (Prüftor zu Live-Check 19): der Knopf trägt `aria-label`, das
     * Symbol darin wird stumm. *Sonst nennt der Vorleser ein Bild in einem namenlosen Knopf.* Der Name kommt vom `aria-label`
     * des Symbols oder aus dem Vorlesetext (`screen-reader-text`) daneben; der Vorlesetext fällt dann weg, sonst hiesse es zweimal.
     *
     * @return array{0: string, 1: string} das Attribut für den Knopf (mit führendem Leerzeichen, oder leer) und das stumme Gesicht
     */
    public static function liftName(string $face): array
    {
        $name = '';

        if (preg_match('~<span class="screen-reader-text">([^<]*)</span>~', $face, $read) === 1) {
            $name = $read[1];
            $face = str_replace($read[0], '', $face);
        }

        if (preg_match('/ aria-label="([^"]*)"/', $face, $own) === 1) {
            $name = $name === '' ? $own[1] : $name;
            $face = preg_replace('/ aria-label="[^"]*"/', ' aria-hidden="true"', $face, 1) ?? $face;
        }

        return [$name === '' ? '' : ' aria-label="' . $name . '"', $face];
    }

    /**
     * Ein ganzer Knopf oder Link, der nur ein Symbol zeigt, mit dem Namen an sich selbst ({@see self::liftName()}); ohne Namen
     * im Inneren nimmt er seinen Tooltip. Ein Element mit eigenem Namen oder mit sichtbaren Worten bleibt, wie es ist.
     */
    public static function lifted(string $control): string
    {
        if (preg_match('~^(<(a|button|label)\b)([^>]*)>(.*)(</\2>)$~s', $control, $part) !== 1 || str_contains($part[3], ' aria-label=')) {
            return $control;
        }

        [$named, $face] = self::liftName($part[4]);

        if (trim(strip_tags($face)) !== '') {
            return $control;
        }

        if ($named === '' && preg_match('/ title="([^"]*)"/', $part[3], $title) === 1 && $title[1] !== '') {
            $named = ' aria-label="' . $title[1] . '"';
        }

        return $part[1] . $part[3] . $named . '>' . $face . $part[5];
    }

    /**
     * Benannt oder ausdrücklich stumm — nie beides und nie keins.
     *
     * ⚠️ *Vorher war es an jeder der sechs Stellen anders geregelt: zweimal `aria-label`, zweimal
     * `aria-hidden`, zweimal **gar nichts**. Das ist kein Geschmack, sondern der Unterschied
     * zwischen «Papierkorb» und «Grafik» im Screenreader.*
     */
    private static function naming(string $label): string
    {
        return $label === ''
            ? ' aria-hidden="true"'
            : ' aria-label="' . Html::escape($label) . '"';
    }
}
