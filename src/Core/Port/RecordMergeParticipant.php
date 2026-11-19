<?php declare(strict_types=1);

namespace Taxmod\Core\Port;

/**
 * What a module says when one record is merged into another (D-1480): records of its own that point at `$from` and go to the
 * trash because `$into` has its own, or a veto with the reason. The modeller applies it in the same act; the module writes nothing.
 *
 * @see docs/NewConcept/90-decision-log.md D-1480
 */
interface RecordMergeParticipant
{
    /** @return array{veto?: string, trash?: list<int>} */
    public function onMerge(int $from, int $into): array;
}
