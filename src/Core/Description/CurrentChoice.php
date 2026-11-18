<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * What a chooser's opener shows of the current choice: its word, the number of a target that is gone, or nothing.
 *
 * ⚠️ *With nothing chosen the opener carries no readable word, so the name of what is being chosen stands beside it for a screen
 * reader (`icon-button-check`, measured 2026-09-12).*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class CurrentChoice implements Describes
{
    private function __construct(
        public readonly ?string $word,
        public readonly ?int $dangling,
        public readonly string $forReader,
    ) {
    }

    public static function named(string $word): self
    {
        return new self($word, null, '');
    }

    public static function dangling(int $reference): self
    {
        return new self(null, $reference, '');
    }

    public static function nothing(string $forReader): self
    {
        return new self(null, null, $forReader);
    }

    public function toArray(): array
    {
        return ['current' => $this->word, 'dangling' => $this->dangling, 'for_reader' => $this->forReader];
    }
}
