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
 * when the thing has that value in that field. Every condition and every filter must hold. A filter may name its operator
 * (D-1552): «gleich», «ungleich», «größer», «kleiner», «enthält»; an operator not built never holds, so a rule is not applied
 * wider than it says. «größer»/«kleiner» compare plain decimal numbers as decimals, never as floats (D-051) — a value with a
 * unit or anything not a number never holds until values are compared in their base unit (D-1555). «liegt unter» (D-1563) holds
 * when one of the thing's values is the node named or lies below it: the caller hands in each value with the nodes above it,
 * read once (CD-7); a value it hands in nothing for is only itself.
 *
 * @see docs/NewConcept/90-decision-log.md D-1541
 */
final class RuleBook
{
    public const EQUALS = 'equals';

    /** D-1552: the further operators, «ungleich · größer · kleiner · enthält». */
    public const NOT_EQUALS = 'not_equals';
    public const GREATER    = 'greater';
    public const LESS       = 'less';
    public const CONTAINS   = 'contains';

    /** D-1563: the value is the node named, or a node below it. */
    public const BELOW = 'below';

    /** The operators built, in the order a choice shows them. */
    public const OPERATORS = [self::EQUALS, self::NOT_EQUALS, self::GREATER, self::LESS, self::CONTAINS, self::BELOW];

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
     * @param array<string, list<string>> $above value → the nodes above it, for «liegt unter» ({@see self::fieldsBelow()})
     */
    public function first(array $context, array $values = [], ?\Closure $usable = null, array $above = []): ?int
    {
        foreach ($this->rules as $rule) {
            if ($this->holds($rule['conditions'], $context) && $this->passes($rule['filters'] ?? [], $values, $above) && ($usable === null || $usable($rule['id']))) {
                return $rule['id'];
            }
        }

        return null;
    }

    /** @return list<int> D-1563: the fields a filter «liegt unter» reads — only their values need the nodes above them */
    public function fieldsBelow(): array
    {
        $out = [];
        foreach ($this->rules as $rule) {
            foreach ($rule['filters'] ?? [] as $filter) {
                if (($filter['operator'] ?? self::EQUALS) === self::BELOW) {
                    $out[$filter['field']] = true;
                }
            }
        }

        return array_keys($out);
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
     * @param array<string, list<string>>            $above
     */
    private function passes(array $filters, array $values, array $above): bool
    {
        foreach ($filters as $filter) {
            if (! $this->passesOne($filter['operator'] ?? self::EQUALS, $filter['value'], $values[$filter['field']] ?? [], $above)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string>                $have  the thing's values in the filter's field
     * @param array<string, list<string>> $above value → the nodes above it
     */
    private function passesOne(string $operator, string $want, array $have, array $above): bool
    {
        return match ($operator) {
            self::EQUALS     => in_array($want, $have, true),
            // «ungleich»: the thing has the field and none of its values is the one named.
            self::NOT_EQUALS => $have !== [] && ! in_array($want, $have, true),
            self::GREATER    => $this->anyCompares($have, $want, 1),
            self::LESS       => $this->anyCompares($have, $want, -1),
            self::CONTAINS   => $want !== '' && array_filter($have, static fn (string $v): bool => mb_stripos($v, $want) !== false) !== [],
            self::BELOW      => $want !== '' && array_filter($have, static fn (string $v): bool => $v === $want || in_array($want, $above[$v] ?? [], true)) !== [],
            default          => false,
        };
    }

    /** @param list<string> $have */
    private function anyCompares(array $have, string $want, int $sign): bool
    {
        foreach ($have as $value) {
            if (self::compareDecimal($value, $want) === $sign) {
                return true;
            }
        }

        return false;
    }

    /** -1, 0, 1 for two plain decimal numbers («3», «-0.5», «1,25»); null when either is no plain number. */
    public static function compareDecimal(string $a, string $b): ?int
    {
        $parse = static function (string $text): ?array {
            $text = str_replace(',', '.', trim($text));

            if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $text, $m) !== 1) {
                return null;
            }

            $whole = ltrim($m[2], '0');
            $part  = rtrim($m[3] ?? '', '0');

            return [$whole === '' && $part === '' ? 1 : ($m[1] === '-' ? -1 : 1), $whole, $part];
        };
        $x = $parse($a);
        $y = $parse($b);

        if ($x === null || $y === null) {
            return null;
        }

        if ($x[0] !== $y[0]) {
            return $x[0] <=> $y[0];
        }

        $width = max(strlen($x[2]), strlen($y[2]));
        $left  = str_pad($x[1], strlen($y[1]), '0', STR_PAD_LEFT) . str_pad($x[2], $width, '0');
        $right = str_pad($y[1], strlen($x[1]), '0', STR_PAD_LEFT) . str_pad($y[2], $width, '0');

        return $x[0] * (strcmp($left, $right) <=> 0);
    }
}
