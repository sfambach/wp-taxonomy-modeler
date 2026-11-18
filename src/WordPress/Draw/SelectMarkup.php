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
    ): string {
        return self::select(Choosing::offer($name, $options, $now, $mayBeNothing, $formId, editable: $editable, nothingWord: $nothingWord), $attributes);
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
    public static function select(Choosing $wahl, array $attributes = []): string
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

        foreach ($wahl->options as $wert => $wort) {
            $wert = (string) $wert;
            // *R30: mit genau einer Möglichkeit ist sie die Antwort und steht gewählt da.*
            $gewaehlt = $now === $wert || ($wahl->decided && ! $wahl->mayBeNothing && count($wahl->options) === 1);
            $markup  .= '<option value="' . self::e($wert) . '"' . ($gewaehlt ? ' selected' : '') . '>' . self::e($wort) . '</option>';
        }

        return $markup . '</select>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
