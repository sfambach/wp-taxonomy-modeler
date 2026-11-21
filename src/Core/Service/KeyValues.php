<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\ReferenceSpace;
use Taxmod\Core\Model\RelationRecord;

/**
 * Whether two records carry the same key values of their category (D-1540): the fields the category names in its computed
 * name, a quantity with unit compared in its base unit — 100 Ω and 0,1 kΩ are the same, 100 Ω and 100 kΩ are not.
 *
 * ```mermaid
 * flowchart LR
 *   R[record] -->|key fields| V[values]
 *   V -->|part| P[part values]
 *   P -->|node with conversion| F["× factor + offset"]
 *   F --> K[key]
 * ```
 *
 * ⚠️ *INFERRED (Prüftor 21.11.): the key fields are the parts of the field the category computes its name from
 * (`composed_of`), so maker and part number never count. A category that names none has no key, and a record without a key
 * matches nothing. A node a part points at that carries a conversion (a prefix, a unit with a factor) is folded into the
 * part's number and leaves the key, so «100 Ω» and «0,1 kΩ» meet; a unit without a conversion (Ohm) stays in the key.*
 *
 * @see docs/NewConcept/90-decision-log.md D-1540
 */
final class KeyValues
{
    /**
     * @param array<int, list<int>>                                         $keyFields   record → its key fields
     * @param array<int, list<RelationRecord>>                              $rows        record → its rows
     * @param array<int, list<RelationRecord>>                              $partRows    part record → its rows
     * @param array<int, array{factor: string, offset: string}> $conversions node → its conversion
     */
    public function __construct(
        private readonly array $keyFields,
        private readonly array $rows,
        private readonly array $partRows,
        private readonly array $conversions,
    ) {
    }

    /**
     * The candidates that carry the record's key — in the order they were asked.
     *
     * @param array<int, list<int>> $candidates record → candidates
     * @return array<int, list<int>> record → matching candidates; a record without match is absent
     */
    public function matching(array $candidates): array
    {
        $out = [];

        foreach ($candidates as $record => $others) {
            $key = $this->keyOf((int) $record);

            if ($key === null) {
                continue;
            }

            $alike = array_values(array_filter(array_map('intval', $others), fn (int $other): bool => $this->keyOf($other) === $key));

            if ($alike !== []) {
                $out[(int) $record] = $alike;
            }
        }

        return $out;
    }

    /** The record's key, or `null` when its category names no key field. */
    public function keyOf(int $record): ?string
    {
        $fields = $this->keyFields[$record] ?? [];

        if ($fields === []) {
            return null;
        }

        sort($fields);
        $keys = [];

        foreach ($fields as $field) {
            $values = [];

            foreach ($this->rows[$record] ?? [] as $row) {
                if ($row->relationId === $field) {
                    $values[] = $row->locale . "\x1F" . $this->valueKey($row);
                }
            }

            sort($values);
            $keys[] = $field . "\x1D" . implode("\x1E", $values);
        }

        return implode("\x1C", $keys);
    }

    private function valueKey(RelationRecord $row): string
    {
        $value = $row->value;

        if ($value->reference !== null && $value->referenceSpace === ReferenceSpace::Record && isset($this->partRows[$value->reference])) {
            return 'part:' . $this->partKey($this->partRows[$value->reference]);
        }

        return json_encode($value->rawValue()) ?: '';
    }

    /** @param list<RelationRecord> $rows */
    private function partKey(array $rows): string
    {
        $numbers = array_values(array_filter($rows, static fn (RelationRecord $row): bool => $row->value->decimal !== null));

        // Only a part with one number is a quantity to fold; anything else compares as stored.
        if (count($numbers) !== 1) {
            return self::plain($rows);
        }

        $others = [];

        foreach ($rows as $row) {
            $value = $row->value;

            if ($row === $numbers[0] || ($value->reference !== null && $value->referenceSpace === ReferenceSpace::Node && isset($this->conversions[$value->reference]))) {
                continue;
            }

            $others[] = $row->relationId . "\x1D" . $row->locale . "\x1D" . json_encode($value->rawValue());
        }

        $number = Amounts::folded((string) $numbers[0]->value->decimal, Amounts::conversionsOf($rows, $this->conversions));
        $others[] = $numbers[0]->relationId . "\x1D" . 'number:' . self::normal($number);
        sort($others);

        return implode("\x1C", $others);
    }

    /** @param list<RelationRecord> $rows */
    private static function plain(array $rows): string
    {
        $keys = array_map(static fn (RelationRecord $row): string => $row->relationId . "\x1D" . $row->locale . "\x1D" . json_encode($row->value->rawValue()), $rows);
        sort($keys);

        return implode("\x1C", $keys);
    }

    /** @return array{0: bool, 1: string, 2: int} negative, digits, places after the point */
    private static function parts(string $decimal): array
    {
        $decimal  = trim($decimal);
        $negative = str_starts_with($decimal, '-');
        $decimal  = ltrim($decimal, '+-');
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');

        return [$negative, ltrim($whole . $fraction, '0') ?: '0', strlen($fraction)];
    }

    private static function compose(bool $negative, string $digits, int $places): string
    {
        $digits = str_pad($digits, $places + 1, '0', STR_PAD_LEFT);
        $whole  = ltrim(substr($digits, 0, strlen($digits) - $places), '0') ?: '0';
        $text   = $places === 0 ? $whole : $whole . '.' . substr($digits, -$places);

        return self::normal(($negative ? '-' : '') . $text);
    }

    /** Without trailing zeros after the point and without «-0». */
    public static function normal(string $decimal): string
    {
        [$negative, $digits, $places] = self::parts($decimal);
        $digits = str_pad($digits, $places + 1, '0', STR_PAD_LEFT);
        $whole  = ltrim(substr($digits, 0, strlen($digits) - $places), '0') ?: '0';
        $part   = rtrim($places === 0 ? '' : substr($digits, -$places), '0');
        $text   = $part === '' ? $whole : $whole . '.' . $part;

        return $negative && $text !== '0' ? '-' . $text : $text;
    }

    public static function multiply(string $a, string $b): string
    {
        [$negA, $digitsA, $placesA] = self::parts($a);
        [$negB, $digitsB, $placesB] = self::parts($b);
        $product = array_fill(0, strlen($digitsA) + strlen($digitsB), 0);

        for ($i = strlen($digitsA) - 1; $i >= 0; $i--) {
            for ($j = strlen($digitsB) - 1; $j >= 0; $j--) {
                $product[$i + $j + 1] += (int) $digitsA[$i] * (int) $digitsB[$j];
            }
        }

        for ($k = count($product) - 1; $k > 0; $k--) {
            $product[$k - 1] += intdiv($product[$k], 10);
            $product[$k] %= 10;
        }

        return self::compose($negA !== $negB, ltrim(implode('', $product), '0') ?: '0', $placesA + $placesB);
    }

    public static function add(string $a, string $b): string
    {
        [$negA, $digitsA, $placesA] = self::parts($a);
        [$negB, $digitsB, $placesB] = self::parts($b);
        $places  = max($placesA, $placesB);
        $digitsA = $digitsA . str_repeat('0', $places - $placesA);
        $digitsB = $digitsB . str_repeat('0', $places - $placesB);
        $length  = max(strlen($digitsA), strlen($digitsB));
        $digitsA = str_pad($digitsA, $length, '0', STR_PAD_LEFT);
        $digitsB = str_pad($digitsB, $length, '0', STR_PAD_LEFT);

        if ($negA === $negB) {
            return self::compose($negA, self::sum($digitsA, $digitsB), $places);
        }

        $order = strcmp($digitsA, $digitsB);

        if ($order === 0) {
            return '0';
        }

        return $order > 0
            ? self::compose($negA, self::difference($digitsA, $digitsB), $places)
            : self::compose($negB, self::difference($digitsB, $digitsA), $places);
    }

    private static function sum(string $a, string $b): string
    {
        $out   = '';
        $carry = 0;

        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            $digit = (int) $a[$i] + (int) $b[$i] + $carry;
            $out   = ($digit % 10) . $out;
            $carry = intdiv($digit, 10);
        }

        return ltrim(($carry > 0 ? (string) $carry : '') . $out, '0') ?: '0';
    }

    /** `$a - $b` for `$a ≥ $b`, both of one length. */
    private static function difference(string $a, string $b): string
    {
        $out    = '';
        $borrow = 0;

        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            $digit  = (int) $a[$i] - (int) $b[$i] - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $out    = ($digit + 10 * $borrow) . $out;
        }

        return ltrim($out, '0') ?: '0';
    }
}
