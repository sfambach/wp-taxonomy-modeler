<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * The day a status step (shipped, delivered) happened, or none: the date the message names, else the moment its source
 * received it, else the moment the inbox filed it — but only for a live source. A message read from an archive (an
 * uploaded order export) was filed long after the fact, so its filing day is not a date of the order, and the field stays
 * empty (D-1250/A8, D-1350).
 *
 * @see docs/pakete/import/konzept.md §3.4
 */
final class StatusDate
{
    /** The evidence key a source sets on a message it read from an archive rather than as it happened. */
    public const ARCHIVE = 'archive';

    /**
     * @param ?string $named      the date the message itself names, «Y-m-d…»
     * @param ?string $receivedAt when the source received the message, «Y-m-d H:i:s»
     * @param string  $filedAt    when the inbox filed it
     * @return ?string «Y-m-d», or null when there is no true date
     */
    public static function of(?string $named, ?string $receivedAt, string $filedAt, bool $archive): ?string
    {
        foreach ([$named, $receivedAt, $archive ? null : $filedAt] as $moment) {
            if ($moment !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $moment) === 1) {
                return substr($moment, 0, 10);
            }
        }

        return null;
    }
}
