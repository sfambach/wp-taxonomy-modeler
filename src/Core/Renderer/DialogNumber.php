<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * A whole number a dialog asks for (how many labels, …), drawn by the model's own integer field, the spinner.
 *
 * His word 2026-11-08 on the label count: «kein Standard-Int-Feld. Sondern wieder was Eigenes.» A dialog's number is not a
 * field of a record, so it has no relation; this is the one place that hands such a number to {@see SpinnerRenderer}, so it
 * looks and behaves like every integer in a record form.
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

        return (new SpinnerRenderer())->render(new self($label), new RenderContext(
            purpose: Purpose::Edit,
            value: $value === null ? TypedValue::nothing() : TypedValue::ofInt($value),
            settings: ['min' => $bound('min', $min), 'max' => $bound('max', $max)],
            fieldName: $name,
            type: SimpleType::Int,
            surroundings: new Surroundings(formId: $formId),
        ))->markup;
    }
}
