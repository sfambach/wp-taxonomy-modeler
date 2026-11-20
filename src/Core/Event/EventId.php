<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

/**
 * The identity of one concrete event (event concept §8): two events are two ids, even with the same content.
 *
 * @see docs/pakete/ereignisse/konzept.md
 */
trait EventId
{
    private ?string $eventId = null;

    /** Given on first asking and kept; 16 hex digits, random, so two processes do not hand out the same one. */
    public function eventId(): string
    {
        return $this->eventId ??= bin2hex(random_bytes(8));
    }
}
