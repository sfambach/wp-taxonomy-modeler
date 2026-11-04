<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * Somebody else changed this object since it was read.
 *
 * `version` is the guard and it is deliberately coarse (D-P4c): the whole object is the unit,
 * so two people editing different fields of the same node still collide. That is the intended
 * trade — a collision the author can see beats a silent merge.
 *
 * ⚠️ *Since [D-1148](../../../docs/NewConcept/90-decision-log.md) the same refusal guards records: the record and its parts are the
 * unit, and `records` names which of them moved on, so the conflict dialog can say it. `busy` is the other half of that decision —
 * a booking that waited too long for another one to finish.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class ConcurrentChange extends DomainError
{
    /**
     * @param array<int, int|null> $records record id ⇒ the version found now; `null` when the record is gone.
     */
    private function __construct(string $message, public readonly array $records = [])
    {
        parent::__construct($message);
    }

    public static function on(int $id, int $expected, int $found): self
    {
        return new self(sprintf(
            'Node %d was read at version %d but is now at version %d.',
            $id,
            $expected,
            $found
        ));
    }

    /**
     * @param array<int, int|null> $found record id ⇒ the version now, `null` when it was removed meanwhile.
     */
    public static function onRecords(array $found): self
    {
        $teile = [];

        foreach ($found as $id => $version) {
            $teile[] = $version === null ? sprintf('record %d was removed', $id) : sprintf('record %d is now at version %d', $id, $version);
        }

        return new self('Somebody else saved in the meantime: ' . implode(', ', $teile) . '. Nothing was written.', $found);
    }

    public static function busy(string $what): self
    {
        return new self(sprintf('Another booking (%s) is still being written. Nothing was written; please try again.', $what));
    }
}
