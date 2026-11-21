<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\Choice;

/**
 * A choice from an offer — the one select of the surface. Whether it can be used at all is decided here, from the offer
 * ([D-380](../../../docs/NewConcept/90-decision-log.md), {@see Choice}); a drawer only shows it.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class Choosing implements Describes
{
    /**
     * @param array<int|string, string> $options  value ⇒ word, in the order shown
     * @param array<int, string>        $swatches node id ⇒ colour, for a colour choice
     */
    public function __construct(
        public readonly string $name,
        public readonly array $options,
        public readonly ?string $now,
        public readonly bool $mayBeNothing,
        public readonly string $form,
        public readonly ChoicePurpose $purpose,
        /** The one option is chosen by the circumstances (D-050): shown greyed. */
        public readonly bool $decided,
        public readonly bool $operable,
        /** Nothing to choose and nothing is no answer: a fault of the model, shown as one. */
        public readonly bool $unsatisfiable,
        /** Width in characters (D-659), a wish. */
        public readonly ?int $width = null,
        public readonly ?string $hint = null,
        public readonly array $swatches = [],
        public readonly string $nothingWord = '',
        /** D-1550: the options are the fields of the node chosen in this field (a relation id) — refilled when that choice changes. */
        public readonly int $fieldsOf = 0,
    ) {
    }

    /**
     * @param array<int|string, string> $options
     * @param array<int, string>        $swatches
     */
    public static function offer(
        string $name,
        array $options,
        ?string $now = null,
        bool $mayBeNothing = true,
        string $form = '',
        ChoicePurpose $purpose = ChoicePurpose::Value,
        bool $editable = true,
        ?int $width = null,
        ?string $hint = null,
        array $swatches = [],
        string $nothingWord = '',
        int $fieldsOf = 0,
    ): self {
        $wahl = Choice::forSetting($options, ! $mayBeNothing, $now === null || $now === '' ? null : TypedValue::ofText($now), $editable);

        return new self(
            $name,
            $options,
            $now,
            $mayBeNothing,
            $form,
            $purpose,
            $wahl->isDecided(),
            $wahl->isOperable(),
            $wahl->isUnsatisfiable(),
            $width,
            $hint,
            $swatches,
            $nothingWord,
            $fieldsOf,
        );
    }

    public function toArray(): array
    {
        return [
            'choosing'      => $this->purpose->value,
            'name'          => $this->name,
            'options'       => array_map(static fn (int|string $v, string $w): array => [(string) $v, $w], array_keys($this->options), $this->options),
            'now'           => $this->now,
            'may_be_nothing' => $this->mayBeNothing,
            'form'          => $this->form,
            'decided'       => $this->decided,
            'operable'      => $this->operable,
            'unsatisfiable' => $this->unsatisfiable,
            'width'         => $this->width,
            'hint'          => $this->hint,
            'swatches'      => $this->swatches,
            'nothing_word'  => $this->nothingWord,
        ] + ($this->fieldsOf > 0 ? ['fields_of' => $this->fieldsOf] : []);
    }
}
