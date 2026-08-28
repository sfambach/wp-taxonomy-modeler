<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SimpleType;

/**
 * A point in time, drawn as much of itself as the model asked for.
 *
 * ⚠️ **One type, three granularities** ([D-291](90-decision-log.md)): date, time, and both
 * together. That is why the granularity is a **setting** and not three types — a birthday and an
 * appointment are the same kind of thing stored the same way, and splitting them would put the
 * same rules in three places.
 *
 * ⚠️ **The setting is called `date_precision`, not `precision`.** The bare word is already
 * spoken for in a different sense — [OQ-085](91-open-questions.md) asks how many places a
 * **decimal** carries — and two meanings of one setting name is the kind of collision that is
 * found by a wrong value on a screen rather than by a test.
 *
 * ⚠️ **The control's wire format is not a notation choice.** Slicing `2026-08-25 14:32:00` down
 * to what `<input type="date">` accepts is what the control requires to work at all; how a person
 * *reads* a date — `25.08.` against `08/25` — is the converter's question
 * ([R35a](30-renderer.md#r35a--notation-is-not-structure)) and is not answered here.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class DateTimeRenderer extends TypedFieldRenderer
{
    public const NAME = 'datetime';

    /** The granularity setting — a free key, deliberately named apart from decimal precision. */
    public const PRECISION = 'date_precision';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return [SimpleType::DateTime];
    }

    protected function display(RenderContext $context): string
    {
        return $this->createHtmlValueSpan(RenderResult::escape($this->forControl($context, ' ')));
    }

    protected function input(RenderContext $context): string
    {
        return RenderResult::htmlTag('input', [
            'type'  => $this->controlType($context),
            'name'  => $context->fieldName,
            'value' => $this->forControl($context, 'T'),
        ]);
    }

    /**
     * ⚠️ **Silence means the whole thing.** The type is called `datetime`; showing less than was
     * stored because nobody configured anything would hide a value that is there. Cutting it down
     * is the deliberate act, not the default.
     */
    private function controlType(RenderContext $context): string
    {
        return match ($context->setting(self::PRECISION)?->text) {
            'date' => 'date',
            'time' => 'time',
            default => 'datetime-local',
        };
    }

    /** @param string $separator What sits between date and time — a space to read, a `T` to submit. */
    private function forControl(RenderContext $context, string $separator): string
    {
        $stored = $this->outputValue($context);

        if ($stored === '') {
            return '';
        }

        $date = substr($stored, 0, 10);
        $time = substr($stored, 11, 5);

        return match ($this->controlType($context)) {
            'date'  => $date,
            'time'  => $time,
            default => $time === '' ? $date : $date . $separator . $time,
        };
    }
}
