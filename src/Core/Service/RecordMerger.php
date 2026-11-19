<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Port\RecordMergeParticipant;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RecordRepository;
use Taxmod\Core\Repository\RelationRepository;
use Taxmod\Core\Repository\TypeNodes;

/**
 * A doubled record merged into the one that stays (D-1480, pattern of D-1458): every value pointing at `from` points at `into`, a
 * value that would then stand twice falls, the modules' own records of `from` go to the trash, and `from` goes too — one act.
 *
 * ```mermaid
 * flowchart LR
 *   C{"same node, nothing of from's own lost?"} -->|no| X["refused, fields named"]
 *   C -->|yes| M{"modules: veto?"} -->|yes| X
 *   M -->|no| T["module records to the trash"] --> R["pointers repointed, doubles dropped"] --> F["from to the trash"]
 * ```
 */
final class RecordMerger
{
    /** @param list<RecordMergeParticipant> $participants */
    public function __construct(
        private readonly RecordRepository $records,
        private readonly DataEntry $data,
        private readonly RelationRepository $relations,
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
        private readonly TypeNodes $typeNodes,
        private readonly Rendering $rendering,
        private readonly Changelog $changelog,
        private readonly array $participants = [],
    ) {
    }

    /** @return array{moved: array<int, int>, dropped: int, trashed: list<int>} moved per field id, rows dropped as doubles, ids into the trash */
    public function merge(int $fromId, int $intoId): array
    {
        $from = $this->records->find($fromId) ?? throw NotYetStorable::noSuchRecord($fromId);
        $into = $this->records->find($intoId) ?? throw NotYetStorable::noSuchRecord($intoId);

        if ($from->id === $into->id) {
            throw NotYetStorable::thatCannotMergeRecords($from->id, $into->id, 'it is the same record.');
        }

        if ($from->nodeId !== $into->nodeId) {
            throw NotYetStorable::thatCannotMergeRecords($from->id, $into->id, 'they stand in different groups.');
        }

        $this->refuseLostValues($from->id, $into->id, $from->nodeId);

        $trash = [];

        foreach ($this->participants as $participant) {
            $answer = $participant->onMerge($from->id, $into->id);

            if (($answer['veto'] ?? '') !== '') {
                throw NotYetStorable::thatCannotMergeRecords($from->id, $into->id, (string) $answer['veto']);
            }

            foreach ($answer['trash'] ?? [] as $id) {
                $trash[(int) $id] = true;
            }
        }

        $this->changelog->beginAct();

        try {
            foreach (array_keys($trash) as $id) {
                $this->data->removeRecord($id);
            }

            $schon = [];

            foreach ($this->records->referrersOf($into->id) as $row) {
                $schon[$row->recordId . "\0" . $row->relationId . "\0" . $row->locale] = true;
            }

            $moved   = [];
            $dropped = 0;

            foreach ($this->records->referrersOf($from->id) as $row) {
                if (isset($trash[$row->recordId])) {
                    continue;
                }

                $key = $row->recordId . "\0" . $row->relationId . "\0" . $row->locale;

                if (isset($schon[$key]) && $row->id !== null) {
                    $this->records->forgetValueById($row->id);
                    $dropped++;
                    continue;
                }

                $this->records->putValue(new RelationRecord($row->recordId, $row->relationId, $row->locale, TypedValue::ofRecordReference($into->id), $row->id, $row->position));
                $schon[$key]               = true;
                $moved[$row->relationId] = ($moved[$row->relationId] ?? 0) + 1;
            }

            $this->data->removeRecord($from->id);

            return ['moved' => $moved, 'dropped' => $dropped, 'trashed' => [...array_keys($trash), $from->id]];
        } finally {
            $this->changelog->endAct();
        }
    }

    /** Refused, naming the fields, where `from` holds a value `into` lacks or holds differently — names and derived fields aside. */
    private function refuseLostValues(int $from, int $into, int $nodeId): void
    {
        $node    = $this->nodes->find($nodeId);
        $fields  = $node === null ? [] : $this->relations->fieldRelationsOf($this->framework->inheritanceOwnersOf($node));
        $addons  = $this->rendering->addonNamesAt(array_values($fields));
        $values  = $this->records->valuesOfMany([$from, $into]);
        $lost    = [];

        foreach ($fields as $field) {
            if ($field->isSetting() || $this->typeNodes->typeOf($field->toNodeId) === SimpleType::Summary
                || $this->rendering->zusammengesetztHier($field, $nodeId) || in_array('calculate', $addons[$field->id] ?? [], true)) {
                continue;
            }

            $own = self::keyOf($values[$from] ?? [], $field->id);

            if ($own !== '' && $own !== self::keyOf($values[$into] ?? [], $field->id)) {
                $lost[] = '«' . $field->name . '»';
            }
        }

        if ($lost !== []) {
            throw NotYetStorable::thatCannotMergeRecords($from, $into, sprintf('#%d holds values #%d lacks or holds differently: %s — make them equal first.', $from, $into, implode(', ', $lost)));
        }
    }

    /** @param list<RelationRecord> $rows */
    private static function keyOf(array $rows, int $field): string
    {
        $keys = [];

        foreach ($rows as $row) {
            if ($row->relationId === $field) {
                $keys[] = $row->locale . "\x1F" . json_encode($row->value->rawValue());
            }
        }

        sort($keys);

        return implode("\x1E", $keys);
    }
}
