<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\TypedValue;

/**
 * **Das eine Auswahlfeld** — jedes `<select>` der Oberfläche wird hier gebaut, damit es überall gleich aussieht und gleich gesperrt ist.
 *
 * ⚠️ *Sein Wort: «viel schlimmer auch hier sind die select felder leer aber nicht ausgegraut. können wir alle select felder durch eins
 * ersetzen das wir definieren damit es überall gleich ist.» Die Regel selbst stand längst: [D-380](../../../docs/NewConcept/90-decision-log.md)
 * «select fields always greyed out when there is no entry or only one entry — empty-if-available counts as an entry». Gerechnet wird sie
 * in {@see Choice}; hier wird sie gezeichnet — vorher tat das nur {@see ChoiceRenderer}, und sechs von Hand gebaute Auswahlfelder
 * kannten sie nicht.*
 *
 * ```mermaid
 * flowchart LR
 *   A["Angebot + «nichts ist erlaubt?»"] --> C[Choice]
 *   C -->|"≥ 2 Möglichkeiten"| O["bedienbar"]
 *   C -->|"≤ 1"| G["gesperrt, ausgegraut; die eine Möglichkeit gewählt"]
 * ```
 */
final class SelectMarkup
{
    /** Wie ein gesperrtes Auswahlfeld aussieht — derselbe Wert wie seit D-380. */
    public const GREYED = 'opacity:.55';

    /**
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
        $wahl    = Choice::forSetting($options, ! $mayBeNothing, $now === null || $now === '' ? null : TypedValue::ofText($now), $editable);
        $decided = $wahl->isDecided();
        $klasse  = trim('taxmod-choice ' . ($attributes['class'] ?? '') . ($wahl->isUnsatisfiable() ? ' taxmod-unsatisfiable' : ''));
        $stil    = trim(($attributes['style'] ?? '') . ($decided ? ';' . self::GREYED : ''), ';');
        unset($attributes['class'], $attributes['style']);

        $markup = '<select'
            . ($name === '' ? '' : ' name="' . RenderResult::escape($name) . '"')
            . ($formId === '' ? '' : ' form="' . RenderResult::escape($formId) . '"')
            . ' class="' . RenderResult::escape($klasse) . '"'
            . ($stil === '' ? '' : ' style="' . RenderResult::escape($stil) . '"');

        foreach ($attributes as $merkmal => $wert) {
            $markup .= ' ' . RenderResult::escape($merkmal) . '="' . RenderResult::escape($wert) . '"';
        }

        $markup .= ($wahl->isOperable() ? '' : ' disabled') . '>';

        if ($mayBeNothing) {
            $markup .= '<option value=""' . ($now === null || $now === '' ? ' selected' : '') . '>' . RenderResult::escape($nothingWord) . '</option>';
        }

        foreach ($options as $wert => $wort) {
            $wert = (string) $wert;
            // *R30: mit genau einer Möglichkeit ist sie die Antwort und steht gewählt da.*
            $gewaehlt = $now === $wert || ($decided && ! $mayBeNothing && count($options) === 1);
            $markup  .= '<option value="' . RenderResult::escape($wert) . '"' . ($gewaehlt ? ' selected' : '') . '>' . RenderResult::escape($wort) . '</option>';
        }

        return $markup . '</select>';
    }

    /** Ob mit diesem Angebot etwas zu wählen ist — der «+» neben einer Auswahl folgt derselben Regel (D-370, D-380). */
    public static function operable(array $options, bool $mayBeNothing = true): bool
    {
        return Choice::forSetting($options, ! $mayBeNothing)->isOperable();
    }

    /** Der «+» neben einer Auswahl, ausgegraut, wo sie nichts anbietet. */
    public static function addButton(array $options, string $label, string $class = 'taxmod-list-add'): string
    {
        $geht = self::operable($options);

        return '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' ' . RenderResult::escape($class) . '"'
            . ($geht ? ' style="color:#1d2327"' : ' disabled style="color:#1d2327;opacity:.35"') . '>'
            . IconMarkup::dashicon('plus-alt2', $label) . '</button>';
    }
}
