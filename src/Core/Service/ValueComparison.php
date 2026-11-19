<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\NodeClass\UnitValue;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Repository\NodeRepository;

/**
 * When two records hold «the same» in a field (D-1481): a quantity with its unit is a part of its own in every record, so two
 * records saying «0,91″» point at two different parts — they are compared by what the part holds, not by its id. Other parts
 * (a pin assignment) and every plain value compare as stored.
 *
 * @see docs/pakete/varianten/plan.md
 */
final class ValueComparison
{
    /** @var array<int, string> part id → its content */
    private array $content = [];

    /** @var array<int, bool> target node id → compared by content */
    private array $targets = [];

    /** @param list<Relation> $fields */
    public function __construct(private readonly NodeRepository $nodes, private readonly array $fields)
    {
    }

    /** Whether the field's parts are compared by content — a composition onto a quantity with unit. */
    public function byContent(Relation $field): bool
    {
        return $field->kind === RelationKind::Composition
            && ($this->targets[$field->toNodeId] ??= $this->nodes->find($field->toNodeId)?->klasse === UnitValue::class);
    }

    /**
     * Reads the content of every part these rows hold in such fields, in one read (CD-7).
     *
     * @param array<int, list<RelationRecord>>                          $values     record id → its rows
     * @param \Closure(list<int>): array<int, list<RelationRecord>>     $valuesOfMany the rows of several records in one read
     */
    public function load(\Closure $valuesOfMany, array $values): void
    {
        $content = [];

        foreach ($this->fields as $field) {
            if (! $this->byContent($field)) {
                continue;
            }

            foreach ($values as $rows) {
                foreach ($rows as $row) {
                    if ($row->relationId === $field->id && $row->value->reference !== null) {
                        $content[$row->value->reference] = true;
                    }
                }
            }
        }

        foreach ($content === [] ? [] : $valuesOfMany(array_keys($content)) as $part => $rows) {
            $this->content[(int) $part] = self::plain($rows);
        }

        foreach (array_keys($content) as $part) {
            $this->content[$part] ??= '';
        }
    }

    /**
     * The field's rows as one comparable string; empty when the record holds nothing there.
     *
     * @param list<RelationRecord> $rows
     */
    public function keyOf(array $rows, Relation $field): string
    {
        $content = $this->byContent($field);
        $keys    = [];

        foreach ($rows as $row) {
            if ($row->relationId !== $field->id) {
                continue;
            }

            $keys[] = $row->locale . "\x1F" . ($content && $row->value->reference !== null
                ? 'part:' . ($this->content[$row->value->reference] ?? '')
                : json_encode($row->value->rawValue()));
        }

        sort($keys);

        return implode("\x1E", $keys);
    }

    /** @param list<RelationRecord> $rows */
    private static function plain(array $rows): string
    {
        $keys = array_map(static fn (RelationRecord $row): string => $row->relationId . "\x1D" . $row->locale . "\x1D" . json_encode($row->value->rawValue()), $rows);
        sort($keys);

        return implode("\x1C", $keys);
    }
}
