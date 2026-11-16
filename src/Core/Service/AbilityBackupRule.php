<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

/**
 * Whether a change list asked to be backed up needs a new safety copy or may stand on the last one (D-1406).
 *
 * ```mermaid
 * flowchart LR
 *   A["apply with backup"] --> D{"remove, merge or trash?"}
 *   D -->|yes| N["new copy"]
 *   D -->|no| L{"last copy younger than the limit and still there?"}
 *   L -->|yes| K["keep the last one"]
 *   L -->|no| N
 * ```
 *
 * ⚠️ *A full copy took 31–39 s on the live site and the agents asked for one nearly every minute (16.11.2026), which held
 * the few PHP processes the host gives. A change without a new copy is still one act and can be undone by its change number.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class AbilityBackupRule
{
    /** The ops that throw something away; they are copied before every time, whatever the age of the last copy. */
    public const ALWAYS = ['remove_record', 'remove_field', 'merge_field', 'trash_node'];

    public const DEFAULT_MINUTES = 30;

    /**
     * @param list<string> $ops       The ops of the change list.
     * @param int|null     $lastAt    Unix time of the last copy made for an ability, null when there is none.
     * @param int          $minutes   The limit; 0 copies every time.
     */
    public static function needsNewCopy(array $ops, ?int $lastAt, bool $lastStillThere, int $now, int $minutes): bool
    {
        if (array_intersect($ops, self::ALWAYS) !== []) {
            return true;
        }

        if ($lastAt === null || ! $lastStillThere || $minutes <= 0) {
            return true;
        }

        return $now - $lastAt >= $minutes * 60;
    }
}
