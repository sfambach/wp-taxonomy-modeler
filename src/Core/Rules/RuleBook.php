<?php declare(strict_types=1);

namespace Taxmod\Core\Rules;

/**
 * Rules checked in order: the first whose every set condition holds wins — one technique for several purposes, printing and
 * testing (D-1541, his word 20.11. «ein regel werk gleiche technik anderer zweck»).
 *
 * ```mermaid
 * flowchart LR
 *   C["context: condition → what counts"] --> R{"rules by position"}
 *   R -->|"every set condition is in its list"| W["this rule"]
 *   R -->|none| N["null: the caller's default"]
 * ```
 *
 * A condition names a node; it holds when that node is in the context's list for the condition — the caller lists a thing
 * with every node above it, so a rule on «Widerstände» holds for each resistor below. An empty condition always holds.
 * A filter narrows further, «field · value» (his word 20.11. «was ein Feld beinhalten muss, damit die Regel zieht»): it holds
 * when the thing has that value in that field. Every condition and every filter must hold. A filter may name its operator;
 * only «equals» is built — «≥», «enthält» are the next stage (his word 20.11. 23:40), and an operator not built yet never
 * holds, so a rule is not applied wider than it says.
 *
 * @see docs/NewConcept/90-decision-log.md D-1541
 */
final class RuleBook
{
    public const EQUALS = 'equals';

    /** @var list<array{id: int, position: int, conditions: array<int, int>, filters?: list<array{field: int, value: string, operator?: string}>}> */
    private readonly array $rules;

    /** @param list<array{id: int, position: int, conditions: array<int, int>, filters?: list<array{field: int, value: string, operator?: string}>}> $rules condition key → node, 0 for «any» */
    public function __construct(array $rules)
    {
        usort($rules, static fn (array $a, array $b): int => [$a['position'], $a['id']] <=> [$b['position'], $b['id']]);
        $this->rules = $rules;
    }

    /**
     * @param array<int, list<int>>    $context condition key → the nodes that count for it; a key left out knows nothing
     * @param array<int, list<string>> $values  field → the thing's values there, for the filters
     * @param ?\Closure(int): bool     $usable  a further test the caller adds, e.g. «its printer can print here»
     */
    public function first(array $context, array $values = [], ?\Closure $usable = null): ?int
    {
        foreach ($this->rules as $rule) {
            if ($this->holds($rule['conditions'], $context) && $this->passes($rule['filters'] ?? [], $values) && ($usable === null || $usable($rule['id']))) {
                return $rule['id'];
            }
        }

        return null;
    }

    /** @return list<int> the rules in the order they are checked */
    public function order(): array
    {
        return array_column($this->rules, 'id');
    }

    /**
     * @param array<int, int>       $conditions
     * @param array<int, list<int>> $context
     */
    private function holds(array $conditions, array $context): bool
    {
        foreach ($conditions as $key => $node) {
            if ($node !== 0 && ! in_array($node, $context[$key] ?? [], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{field: int, value: string, operator?: string}> $filters
     * @param array<int, list<string>>               $values
     */
    private function passes(array $filters, array $values): bool
    {
        foreach ($filters as $filter) {
            $operator = $filter['operator'] ?? self::EQUALS;

            if ($operator !== self::EQUALS || ! in_array($filter['value'], $values[$filter['field']] ?? [], true)) {
                return false;
            }
        }

        return true;
    }
}
