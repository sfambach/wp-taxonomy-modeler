<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

use Taxmod\Core\Port\RecordMergeParticipant;

/**
 * Asks the {@see SharedListeners} about a merge by sending {@see RecordsMerging} — the modules' say without a WordPress filter.
 *
 * @see docs/pakete/ereignisse/konzept.md
 */
final class SharedMergeParticipant implements RecordMergeParticipant
{
    public function onMerge(int $from, int $into): array
    {
        $event = new RecordsMerging($from, $into);
        SharedListeners::dispatcher()->dispatch($event);

        $answer = [];

        if ($event->vetoes() !== []) {
            $answer['veto'] = implode(' ', $event->vetoes());
        }

        if ($event->trashed() !== []) {
            $answer['trash'] = $event->trashed();
        }

        return $answer;
    }
}
