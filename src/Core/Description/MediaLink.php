<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A link to a medium — file, image, private document or address — with the word that names it.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class MediaLink implements Describes
{
    public function __construct(
        public readonly MediaLook $look,
        public readonly string $word,
        public readonly string $url = '',
        public readonly string $thumbnail = '',
        public readonly bool $newTab = true,
        public readonly string $state = '',
        /** Stands as the value of a field. */
        public readonly bool $asValue = false,
    ) {
    }

    public function asValue(): self
    {
        return new self($this->look, $this->word, $this->url, $this->thumbnail, $this->newTab, $this->state, true);
    }

    public function toArray(): array
    {
        return ['media' => $this->look->value, 'word' => $this->word, 'url' => $this->url, 'thumbnail' => $this->thumbnail, 'new_tab' => $this->newTab, 'state' => $this->state, 'as_value' => $this->asValue];
    }
}
