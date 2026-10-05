<?php declare(strict_types=1);

namespace Taxmod\Core\Port;

/**
 * Eine Datei der Mediathek, wie der Rand sie beschreibt: Adresse, Titel, Bildunterschrift und — bei einem Bild — die Adresse des Vorschaubilds.
 *
 * @see MediaLibrary
 */
final class MediaFile
{
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly string $title,
        /** Leer, wenn die Datei kein Bild ist. */
        public readonly string $thumbnail = '',
        /** Die Bildunterschrift der Mediathek (D-879) — leer, wenn keine gepflegt ist. */
        public readonly string $caption = '',
    ) {
    }

    public function isImage(): bool
    {
        return $this->thumbnail !== '';
    }
}
