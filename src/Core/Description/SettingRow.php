<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Renderer\Control;

/**
 * One setting of a node: its key, its control, where its value came from, and the acts about it alone (D-266, D-392).
 *
 * ⚠️ *Inherited is locked until someone says «I override here», and in a conflict the system says it itself (D-689).*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class SettingRow implements Describes
{
    /** @param list<Control>|null $acts `null`: not editing, no column for them */
    public function __construct(
        public readonly string $key,
        /** The control; `null`: none could be drawn, and `undrawn` says the value. */
        public readonly ?Describes $control,
        public readonly string $undrawn,
        public readonly string $hint,
        public readonly SettingOrigin $origin,
        public readonly string $originText,
        public readonly bool $locked = false,
        public readonly bool $automatic = false,
        /** Where the «I override here» tick writes; empty: no tick. */
        public readonly string $overrideName = '',
        public readonly string $overrideWord = '',
        public readonly string $form = '',
        public readonly ?array $acts = null,
        /** Neighbours with the same band stand together (D-736); `null`: none. */
        public readonly ?string $band = null,
    ) {
    }

    public function toArray(): array
    {
        // In reading order (A22): the key with its hint, then the control.
        return [
            'setting'       => $this->key,
            'hint'          => $this->hint,
            'control'       => $this->control?->toArray(),
            'undrawn'       => $this->undrawn,
            'origin'        => $this->origin->value,
            'origin_text'   => $this->originText,
            'locked'        => $this->locked,
            'automatic'     => $this->automatic,
            'override_name' => $this->overrideName,
            'override_word' => $this->overrideWord,
            'form'          => $this->form,
            'acts'          => $this->acts === null ? null : array_map(static fn (Control $c): array => $c->toArray(), $this->acts),
            'band'          => $this->band,
        ];
    }
}
