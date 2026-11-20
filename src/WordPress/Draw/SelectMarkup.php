<?php declare(strict_types=1);

namespace Taxmod\WordPress\Draw;

use Taxmod\Core\Description\AddChosen;
use Taxmod\Core\Description\Choosing;

/**
 * **Das eine Auswahlfeld** — jedes `<select>` der Oberfläche wird hier gezeichnet, damit es überall gleich aussieht und gleich
 * gesperrt ist. Ob es bedienbar ist, rechnet der Kern ({@see Choosing::offer()}, D-380); hier wird es nur gezeichnet.
 *
 * ⚠️ *Sein Wort: «können wir alle select felder durch eins ersetzen das wir definieren damit es überall gleich ist.»*
 *
 * ```mermaid
 * flowchart LR
 *   A["Angebot + «nichts ist erlaubt?»"] --> C["Kern: Choosing::offer"]
 *   C --> S["Rand: SelectMarkup::select"]
 * ```
 */
final class SelectMarkup
{
    /** Wie ein gesperrtes Auswahlfeld aussieht — derselbe Wert wie seit D-380. */
    public const GREYED = 'opacity:.55';

    /**
     * Für die Seiten am Rand, die ihr Auswahlfeld selbst zusammenstellen.
     *
     * @param array<int|string, string> $options    Wert => Wort, in der Reihenfolge der Anzeige.
     * @param array<string, string>     $attributes Weitere Merkmale (`class`, `title`, `style`, `data-…`); `class` wird angehängt.
     * @param list<int|string>          $locked     D-1504: Werte, die zu sehen, aber nicht zu wählen sind.
     */
    public static function of(
        string $name,
        array $options,
        ?string $now = null,
        bool $mayBeNothing = true,
        string $formId = '',
        array $attributes = [],
        bool $editable = true,
        string $nothingWord = '',
        array $locked = [],
    ): string {
        $wahl   = Choosing::offer($name, $options, $now, $mayBeNothing, $formId, editable: $editable, nothingWord: $nothingWord);
        $markup = self::select($wahl, $attributes, $locked);

        // D-1504: a page's select with exactly one answer stands greyed and disabled (R30), and a disabled select sends nothing.
        // The hand-written select it replaces sent that answer, so the page still gets it — as a hidden field beside it.
        if ($name !== '' && ! $wahl->operable && $wahl->decided && ! $mayBeNothing && count($options) === 1) {
            $markup .= '<input type="hidden" name="' . self::e($name) . '"' . ($formId === '' ? '' : ' form="' . self::e($formId) . '"')
                . ' value="' . self::e((string) array_key_first($options)) . '">';
        }

        return $markup;
    }

    /** @param array<int|string, string> $options */
    public static function operable(array $options, bool $mayBeNothing = true): bool
    {
        return Choosing::offer('', $options, null, $mayBeNothing)->operable;
    }

    /** @param array<int|string, string> $options */
    public static function addButton(array $options, string $label, string $class = 'taxmod-list-add'): string
    {
        return self::add(AddChosen::beside($options, $label), $class);
    }

    public static function add(AddChosen $add, string $class): string
    {
        return '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' ' . self::e($class) . '"'
            . ($add->operable ? ' style="color:#1d2327"' : ' disabled style="color:#1d2327;opacity:.35"') . '>'
            . IconMarkup::dashicon('plus-alt2', $add->label) . '</button>';
    }

    /** @param array<string, string> $attributes */
    /** @param list<int|string> $locked D-1504: options shown but not to be taken */
    public static function select(Choosing $wahl, array $attributes = [], array $locked = []): string
    {
        $now    = $wahl->now;
        $klasse = trim('taxmod-choice ' . ($attributes['class'] ?? '') . ($wahl->unsatisfiable ? ' taxmod-unsatisfiable' : ''));
        $stil   = trim(($attributes['style'] ?? '') . ($wahl->decided ? ';' . self::GREYED : ''), ';');
        unset($attributes['class'], $attributes['style']);

        $markup = '<select'
            . ($wahl->name === '' ? '' : ' name="' . self::e($wahl->name) . '"')
            . ($wahl->form === '' ? '' : ' form="' . self::e($wahl->form) . '"')
            . ' class="' . self::e($klasse) . '"'
            . ($stil === '' ? '' : ' style="' . self::e($stil) . '"');

        foreach ($attributes as $merkmal => $wert) {
            $markup .= ' ' . self::e($merkmal) . '="' . self::e($wert) . '"';
        }

        $markup .= ($wahl->operable ? '' : ' disabled') . '>';

        if ($wahl->mayBeNothing) {
            $markup .= '<option value=""' . ($now === null || $now === '' ? ' selected' : '') . '>' . self::e($wahl->nothingWord) . '</option>';
        }

        $gesperrt = array_map('strval', $locked);

        foreach ($wahl->options as $wert => $wort) {
            $wert = (string) $wert;
            // *R30: mit genau einer Möglichkeit ist sie die Antwort und steht gewählt da.*
            $gewaehlt = $now === $wert || ($wahl->decided && ! $wahl->mayBeNothing && count($wahl->options) === 1);
            $markup  .= '<option value="' . self::e($wert) . '"' . ($gewaehlt ? ' selected' : '') . (in_array($wert, $gesperrt, true) ? ' disabled' : '') . '>' . self::e($wort) . '</option>';
        }

        return $markup . '</select>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
