<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\RecordInTree;

/**
 * What `taxmod/query` was asked, checked once and put into the shape the record table answers — so a reader fetches the
 * records it needs and not a whole node (D-1209).
 *
 * ```mermaid
 * flowchart LR
 *   I["input: where · fields · search · limit"] --> C["this: conditions"]
 *   C --> W["within → ids of the record and all below it (RecordTree)"]
 *   W --> S["one query for ids + total"]
 *   S --> V["words of the asked fields only"]
 * ```
 *
 * ⚠️ *A condition compares the **stored** value: text, number, date or the id a reference points at. A part (a composition)
 * is a record of its own; its inner values are not reached from here.*
 *
 * @see docs/NewConcept/90-decision-log.md D-1209
 */
final class RecordQuery
{
    public const OPS = ['eq', 'in', 'contains', 'empty', 'not_empty', 'within'];

    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 500;

    /**
     * @param list<array{field: int, op: string, values: list<string>, parent_field: int}> $conditions
     * @param list<int|string> $fields ids or names of the fields to answer with
     */
    private function __construct(
        public readonly array $conditions,
        public readonly array $fields,
        public readonly string $search,
        public readonly int $limit,
        public readonly int $offset,
        public readonly bool $subtree,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        $conditions = [];

        foreach (is_array($input['where'] ?? null) ? array_values($input['where']) : [] as $i => $where) {
            if (! is_array($where)) {
                throw new \InvalidArgumentException(sprintf('where[%d] is not an object.', $i));
            }

            $field = (int) ($where['field'] ?? 0);
            $op    = (string) ($where['op'] ?? 'eq');

            if ($field < 1) {
                throw new \InvalidArgumentException(sprintf('where[%d]: field must be a relation id.', $i));
            }

            if (! in_array($op, self::OPS, true)) {
                throw new \InvalidArgumentException(sprintf('where[%d]: op must be one of %s.', $i, implode(', ', self::OPS)));
            }

            $values = array_key_exists('values', $where) && is_array($where['values'])
                ? array_values(array_map('strval', array_filter($where['values'], 'is_scalar')))
                : (array_key_exists('value', $where) && is_scalar($where['value']) ? [(string) $where['value']] : []);
            $values = array_values(array_filter(array_map('trim', $values), static fn (string $v): bool => $v !== ''));

            if ($values === [] && ! in_array($op, ['empty', 'not_empty'], true)) {
                throw new \InvalidArgumentException(sprintf('where[%d]: %s needs a value.', $i, $op));
            }

            if (in_array($op, ['eq', 'contains', 'within'], true) && count($values) > 1) {
                throw new \InvalidArgumentException(sprintf('where[%d]: %s takes one value; use in for several.', $i, $op));
            }

            $parent = (int) ($where['parent_field'] ?? 0);

            if ($op === 'within' && ($parent < 1 || ! ctype_digit($values[0]))) {
                throw new \InvalidArgumentException(sprintf('where[%d]: within needs a record id as value and parent_field, the field its records hang under.', $i));
            }

            $conditions[] = ['field' => $field, 'op' => $op, 'values' => $values, 'parent_field' => $parent];
        }

        $fields = [];

        foreach (is_array($input['fields'] ?? null) ? $input['fields'] : [] as $field) {
            if (is_int($field) || (is_string($field) && trim($field) !== '')) {
                $fields[] = is_string($field) && ctype_digit(trim($field)) ? (int) $field : (is_string($field) ? trim($field) : $field);
            }
        }

        $search = trim((string) ($input['search'] ?? ''));
        $limit  = (int) ($input['limit'] ?? self::DEFAULT_LIMIT);

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(sprintf('limit must be between 1 and %d.', self::MAX_LIMIT));
        }

        if ($conditions === [] && $search === '' && (int) ($input['node'] ?? 0) < 1) {
            throw new \InvalidArgumentException('Name a node, a condition or a search word.');
        }

        return new self($conditions, array_values(array_unique($fields, SORT_REGULAR)), $search, $limit, max(0, (int) ($input['offset'] ?? 0)), ($input['subtree'] ?? true) !== false);
    }

    /**
     * `within` as the plain list it means: the record named and every record below it by the parent field.
     *
     * @param list<RecordInTree> $tree the records of the field's target with their parent
     * @return list<string> record ids as the condition compares them
     */
    public static function within(array $tree, int $record): array
    {
        $ids = [$record];

        foreach (RecordTree::walk($tree, $record) as $row) {
            $ids[] = $row['record']->id;
        }

        return array_map('strval', array_values(array_unique($ids)));
    }

    /**
     * The conditions as the record table reads them: `within` has become `in`, the search word a condition on any text.
     *
     * @param array<int, list<string>> $resolved condition index → ids `within` stands for
     * @return list<array{field: int, op: string, values: list<string>}> field 0 with op contains = any text of the record
     */
    public function forTable(array $resolved = []): array
    {
        $out = [];

        foreach ($this->conditions as $i => $c) {
            $out[] = $c['op'] === 'within'
                ? ['field' => $c['field'], 'op' => 'in', 'values' => $resolved[$i] ?? $c['values']]
                : ['field' => $c['field'], 'op' => $c['op'], 'values' => $c['values']];
        }

        if ($this->search !== '') {
            $out[] = ['field' => 0, 'op' => 'contains', 'values' => [$this->search]];
        }

        return $out;
    }
}
