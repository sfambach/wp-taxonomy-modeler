<?php declare(strict_types=1);

namespace Taxmod\WordPress\Draw;

use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\Renderable;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\SpinnerRenderer;
use Taxmod\Core\Renderer\Surroundings;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * A whole number a dialog asks for (how many labels, …), drawn by the model's own integer field, the spinner.
 *
 * His word 2026-11-08 on the label count: «kein Standard-Int-Feld. Sondern wieder was Eigenes.» A dialog's number is not a
 * field of a record, so it has no relation; this is the one place that hands such a number to {@see SpinnerRenderer}, so it
 * looks and behaves like every integer in a record form. At the edge since renderer step 6c: it hands the modules markup;
 * the old name in the core still finds it (FormerNames, D-1431).
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class DialogNumber implements Renderable
{
    private function __construct(private readonly string $label)
    {
    }

    public function label(): string
    {
        return $this->label;
    }

    public function content(): string
    {
        return '';
    }

    /** The input: named, with its bounds, optionally belonging to a form it does not stand inside. */
    public static function input(string $name, ?int $value, int $min, int $max, string $formId = '', string $label = ''): string
    {
        $bound = static fn (string $key, int $number): ResolvedSetting => new ResolvedSetting($key, TypedValue::ofInt($number), 0, true);

        return Markup::of((new SpinnerRenderer())->render(new self($label), new RenderContext(
            purpose: Purpose::Edit,
            value: $value === null ? TypedValue::nothing() : TypedValue::ofInt($value),
            settings: ['min' => $bound('min', $min), 'max' => $bound('max', $max)],
            fieldName: $name,
            type: SimpleType::Int,
            surroundings: new Surroundings(formId: $formId),
        )));
    }

    /**
     * A quantity with its unit as one piece (D-1436): the number, then the unit — a choice where there are several, the word alone
     * (with its value sent hidden) where there is one, so «Stück» always stands there. A decimal quantity is a text field for decimals.
     *
     * @param array<string, string> $units unit value → its word, in order
     */
    public static function withUnit(
        string $name,
        int|float|null $value,
        string $unitName,
        array $units,
        string $chosen = '',
        string $formId = '',
        string $label = '',
        bool $decimal = false,
        int $min = 0,
        int $max = PHP_INT_MAX,
    ): string {
        $form   = $formId === '' ? '' : ' form="' . Html::escape($formId) . '"';
        $number = $decimal
            ? '<input type="text" inputmode="decimal" class="small-text" name="' . Html::escape($name) . '" value="' . Html::escape($value === null ? '' : (string) $value) . '"'
                . ($label === '' ? '' : ' aria-label="' . Html::escape($label) . '"') . $form . '>'
            : self::input($name, $value === null ? null : (int) $value, $min, $max, $formId, $label);
        $chosen = array_key_exists($chosen, $units) ? $chosen : (string) array_key_first($units);

        if (count($units) <= 1) {
            $unit = '<span class="taxmod-number-unit-fixed">' . Html::escape($units[$chosen] ?? '') . '</span>'
                . '<input type="hidden" name="' . Html::escape($unitName) . '" value="' . Html::escape($chosen) . '"' . $form . '>';
        } else {
            $unit = '<select name="' . Html::escape($unitName) . '"' . $form . '>';

            foreach ($units as $key => $word) {
                $unit .= '<option value="' . Html::escape((string) $key) . '"' . ((string) $key === $chosen ? ' selected' : '') . '>' . Html::escape($word) . '</option>';
            }

            $unit .= '</select>';
        }

        return '<span class="taxmod-number-unit">' . $number . $unit . '</span>';
    }
}
