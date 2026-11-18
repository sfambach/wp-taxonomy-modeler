<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * The two buttons that fill a media field: from the media library, or a link (D-858). The script fills the fields named.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class MediaButtons implements Describes
{
    public function __construct(
        /** The field that takes the address; empty: the field for the next value. */
        public readonly string $addressField,
        public readonly string $captionField,
        public readonly string $libraryWord,
        public readonly string $linkWord,
        public readonly bool $newTab,
    ) {
    }

    public function toArray(): array
    {
        return ['media_buttons' => $this->addressField, 'caption_field' => $this->captionField, 'library' => $this->libraryWord, 'link' => $this->linkWord, 'new_tab' => $this->newTab];
    }
}
