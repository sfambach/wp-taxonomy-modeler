<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RelationRepository;
use Taxmod\Core\Repository\TypeNodes;

/**
 * Several records of one node become the variants of a new parent (D-1482, D-1208 step 6): the parent takes the values they
 * share, each variant keeps its own «Je Variante» values, its id, its stock and its labels.
 *
 * ```mermaid
 * flowchart LR
 *   R["records of one node"] --> C{"each other field: the same value in all, or empty in all?"}
 *   C -->|no| X["refused: field and records named"]
 *   C -->|yes| P["new parent holds the value"] --> V["value cleared at the variants, «Variante von» set"]
 * ```
 *
 * The caller brackets the call in one act, so undo takes it back as a whole.
 *
 * @see docs/pakete/varianten/plan.md
 */
final class VariantMaker
{
    public function __construct(
        private readonly DataEntry $data,
        private readonly RelationRepository $relations,
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
        private readonly TypeNodes $typeNodes,
        private readonly Rendering $rendering,
    ) {
    }

    /**
     * @param list<int> $recordIds
     * @return int the new parent's id
     */
    public function make(array $recordIds): int
    {
        $recordIds = array_values(array_unique(array_filter(array_map('intval', $recordIds), static fn (int $id): bool => $id > 0)));
        $records   = $this->data->recordsByIds($recordIds);

        if ($recordIds === [] || count($records) !== count($recordIds)) {
            throw NotYetStorable::thatCannotMakeVariants('every record named must exist.');
        }

        $nodeIds = array_unique(array_map(static fn ($record): int => $record->nodeId, $records));

        if (count($nodeIds) !== 1) {
            throw NotYetStorable::thatCannotMakeVariants('the records stand in different groups.');
        }

        $nodeId = (int) reset($nodeIds);
        ['variantOf' => $variantOf, 'own' => $own] = $this->data->variantFieldsOf($nodeId);

        if ($variantOf === 0) {
            throw NotYetStorable::thatCannotMakeVariants('the group has no field «Variante von».');
        }

        $values = $this->data->valuesOfMany($recordIds);
        $this->refuseLinked($recordIds, $values, $variantOf);

        $fields  = $this->sharedFields($nodeId, $variantOf, $own);
        // D-1481: a quantity is a part of its own in each record — the same when it holds the same.
        $compare = new ValueComparison($this->nodes, $fields);
        $compare->load(fn (array $ids): array => $this->data->valuesOfMany($ids), $values);
        $shared  = [];
        $copied  = [];

        foreach ($fields as $field) {
            $shared[$field->id] = $this->sharedRows($field, $recordIds, $values, $compare);
            $copied[$field->id] = $compare->byContent($field);
        }

        $parent = $this->data->create($nodeId)->id;

        foreach ($shared as $fieldId => $rows) {
            if ($copied[$fieldId] && $rows !== []) {
                // A new record may start with an empty part where one is required; the copy takes its place.
                foreach ($this->data->valuesOf($parent) as $row) {
                    if ($row->relationId === $fieldId && $row->value->reference !== null) {
                        $this->data->removeRecord($row->value->reference);
                    }
                }
            }

            foreach ($rows as $index => $row) {
                if ($copied[$fieldId] && $row->value->reference !== null) {
                    $this->data->attachPart($parent, $fieldId, $this->data->copyPart($row->value->reference));

                    continue;
                }

                $index === 0 || $this->isFirstOfLocale($rows, $index)
                    ? $this->data->put($parent, $fieldId, $row->value, $row->locale)
                    : $this->data->appendValue($parent, $fieldId, $row->value, $row->locale);
            }
        }

        foreach ($recordIds as $variant) {
            foreach ($shared as $fieldId => $rows) {
                if ($copied[$fieldId]) {
                    // The variant's own copy goes, and with it the row that held it.
                    foreach ($values[$variant] ?? [] as $row) {
                        if ($row->relationId === $fieldId && $row->value->reference !== null) {
                            $this->data->removeRecord($row->value->reference);
                        }
                    }

                    continue;
                }

                foreach (array_unique(array_map(static fn (RelationRecord $row): string => $row->locale, $rows)) as $locale) {
                    $this->data->clear($variant, $fieldId, $locale);
                }
            }

            $this->data->put($variant, $variantOf, \Taxmod\Core\Model\TypedValue::ofRecordReference($parent));
        }

        return $parent;
    }

    /**
     * @param list<int>                               $recordIds
     * @param array<int, list<RelationRecord>>        $values
     */
    private function refuseLinked(array $recordIds, array $values, int $variantOf): void
    {
        foreach ($recordIds as $id) {
            foreach ($values[$id] ?? [] as $row) {
                if ($row->relationId === $variantOf && $row->value->reference !== null) {
                    throw NotYetStorable::thatCannotMakeVariants(sprintf('#%d is already a variant of #%d.', $id, $row->value->reference));
                }
            }
        }

        foreach ($this->data->referrersOfMany($recordIds) as $id => $rows) {
            foreach ($rows as $row) {
                if ($row->relationId === $variantOf) {
                    throw NotYetStorable::thatCannotMakeVariants(sprintf('#%d already has variants.', $id));
                }
            }
        }
    }

    /**
     * The fields the parent takes over: every field but settings, «Variante von», the «Je Variante» ones and what the model
     * derives (a composed name, a summary, a calculation).
     *
     * @param array<int, true> $own
     * @return list<Relation>
     */
    private function sharedFields(int $nodeId, int $variantOf, array $own): array
    {
        $node   = $this->nodes->find($nodeId);
        $fields = $node === null ? [] : $this->relations->fieldRelationsOf($this->framework->inheritanceOwnersOf($node));
        $addons = $this->rendering->addonNamesAt(array_values($fields));
        $out    = [];

        foreach ($fields as $field) {
            if ($field->isSetting() || $field->id === $variantOf || isset($own[$field->id])
                || $this->typeNodes->typeOf($field->toNodeId) === SimpleType::Summary
                || $this->rendering->zusammengesetztHier($field, $nodeId)
                || in_array('calculate', $addons[$field->id] ?? [], true)) {
                continue;
            }

            $out[] = $field;
        }

        return $out;
    }

    /**
     * The rows all records agree on; refused where two records hold different values, or where a record holds parts here.
     *
     * @param list<int>                        $recordIds
     * @param array<int, list<RelationRecord>> $values
     * @return list<RelationRecord>
     */
    private function sharedRows(Relation $field, array $recordIds, array $values, ValueComparison $compare): array
    {
        $parts = $field->kind === RelationKind::Composition && $this->typeNodes->typeOf($field->toNodeId) === null && ! $compare->byContent($field);
        $seen  = null;
        $from  = 0;
        $empty = 0;

        foreach ($recordIds as $id) {
            $rows = array_values(array_filter($values[$id] ?? [], static fn (RelationRecord $row): bool => $row->relationId === $field->id));

            if ($rows === []) {
                $empty = $empty === 0 ? $id : $empty;

                continue;
            }

            if ($parts) {
                throw NotYetStorable::thatCannotMakeVariants(sprintf('«%s» holds parts at #%d — mark the field «Je Variante» first.', $field->name, $id));
            }

            $key = $compare->keyOf($rows, $field);

            if ($seen !== null && $key !== $compare->keyOf($seen, $field)) {
                throw NotYetStorable::thatCannotMakeVariants(sprintf('«%s» differs between #%d and #%d — make them equal, or mark the field «Je Variante».', $field->name, $from, $id));
            }

            $seen ??= $rows;
            $from = $from === 0 ? $id : $from;
        }

        // Architecture gate 19.11. (PR-4): an empty record would inherit the other's value without anyone saying so — refused.
        if ($seen !== null && $empty > 0) {
            throw NotYetStorable::thatCannotMakeVariants(sprintf('«%s» is filled at #%d and empty at #%d — make them equal first, or mark the field «Je Variante».', $field->name, $from, $empty));
        }

        return $seen ?? [];
    }

    /** @param list<RelationRecord> $rows */
    private function isFirstOfLocale(array $rows, int $index): bool
    {
        for ($before = 0; $before < $index; $before++) {
            if ($rows[$before]->locale === $rows[$index]->locale) {
                return false;
            }
        }

        return true;
    }
}
