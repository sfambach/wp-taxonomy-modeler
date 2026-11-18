<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * Help text behind a question mark beside what it explains; empty, nothing is shown.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class Hint implements Describes
{
    /** Between several hints that share one mark. */
    public const JOIN = ' · ';

    public function __construct(
        public readonly string $text,
        /** Small beside what it explains, as text — not behind a question mark. */
        public readonly bool $beside = false,
    ) {
    }

    /** @param list<string> $hints the non-empty ones, joined */
    public static function combined(array $hints): self
    {
        return new self(implode(self::JOIN, array_values(array_filter(
            array_map(static fn (string $one): string => trim($one), $hints),
            static fn (string $one): bool => $one !== ''
        ))));
    }

    public function toArray(): array
    {
        return ['hint' => $this->text, 'beside' => $this->beside];
    }
}
