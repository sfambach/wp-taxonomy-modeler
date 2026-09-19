<?php declare(strict_types=1);

namespace Taxmod\Core\Page;

/**
 * Ein Abschnitt einer Seitenvorlage: Überschrift, Ebene, Hilfetext und was darunter vorgegeben ist (Blockmarkup).
 *
 * @see StarterPattern
 */
final class PatternSection
{
    public function __construct(
        public readonly string $heading,
        public readonly int $level = 2,
        public readonly string $hint = '',
        /** Das Blockmarkup unter der Überschrift, wie die Vorlage es vorgibt — leer: ein Absatz mit dem Hilfetext als Platzhalter. */
        public readonly string $preset = '',
    ) {
    }
}
