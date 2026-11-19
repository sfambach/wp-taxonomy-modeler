<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Port\RecordMergeParticipant;

/**
 * The modules' say in a record merge (D-1480): the filter `taxmod_merge_record` answers a veto or the ids of their own records
 * to the trash; the modeller applies them in its own act, so one undo takes everything back.
 *
 * @see docs/NewConcept/90-decision-log.md D-1480
 */
final class FilterMergeParticipant implements RecordMergeParticipant
{
    public const FILTER = 'taxmod_merge_record';

    public function onMerge(int $from, int $into): array
    {
        $answer = apply_filters(self::FILTER, [], $from, $into);
        $answer = is_array($answer) ? $answer : [];
        $out    = [];

        if (is_string($answer['veto'] ?? null) && $answer['veto'] !== '') {
            $out['veto'] = sanitize_text_field($answer['veto']);
        }

        if (is_array($answer['trash'] ?? null)) {
            $out['trash'] = array_values(array_filter(array_map('absint', $answer['trash'])));
        }

        return $out;
    }
}
