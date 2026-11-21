<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\ReferenceSpace;
use Taxmod\Core\Model\RelationRecord;

/**
 * The numbers of unit-value parts in their base unit, for a module that calculates with them (Prüftor 21.11., Feldkatalog 9/10/12).
 *
 * ```mermaid
 * flowchart LR
 *   R[record] -->|field| P[unit-value part]
 *   P -->|one number| N[number]
 *   P -->|prefix · unit with conversion| F["× factor + offset"]
 *   N --> F --> B[base unit]
 * ```
 *
 * ⚠️ *INFERRED (Druck-Thread 21.11.): only a unit-value part answers. A plain number is left out, because «62» (mm, old) and
 * «0.062» (m, new) could not be told apart; the module reads its old number as before. A part with no number, or with more than
 * one, is left out too. The folding is the one of D-1540 ({@see KeyValues}): a prefix first, then a unit with an offset.*
 *
 * @see docs/NewConcept/90-decision-log.md D-1540
 */
final class Amounts
{
    /**
     * @param array<int, list<RelationRecord>>                  $rows        record → its rows
     * @param array<int, list<RelationRecord>>                  $partRows    part record → its rows
     * @param array<int, array{factor: string, offset: string}> $conversions node → its conversion
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $partRows,
        private readonly array $conversions,
    ) {
    }

    /**
     * @param list<int> $fields the unit-value fields asked for
     * @return array<int, array<int, float>> record → field → number in the base unit; an empty field is absent
     */
    public function of(array $fields): array
    {
        $wanted = array_flip($fields);
        $out    = [];

        foreach ($this->rows as $record => $rows) {
            foreach ($rows as $row) {
                $part = $row->value->reference;

                if (! isset($wanted[$row->relationId]) || $part === null || $row->value->referenceSpace !== ReferenceSpace::Record || ! isset($this->partRows[$part])) {
                    continue;
                }

                $base = self::baseOf($this->partRows[$part], $this->conversions);

                if ($base !== null) {
                    $out[(int) $record][$row->relationId] = (float) $base;
                }
            }
        }

        return $out;
    }

    /**
     * The part's one number folded into its base unit, or `null` when the part has no number or more than one.
     *
     * @param list<RelationRecord>                              $rows
     * @param array<int, array{factor: string, offset: string}> $conversions
     */
    public static function baseOf(array $rows, array $conversions): ?string
    {
        $numbers = array_values(array_filter($rows, static fn (RelationRecord $row): bool => $row->value->decimal !== null));

        if (count($numbers) !== 1) {
            return null;
        }

        return self::folded((string) $numbers[0]->value->decimal, self::conversionsOf($rows, $conversions));
    }

    /**
     * The conversions the part's node references name.
     *
     * @param list<RelationRecord>                              $rows
     * @param array<int, array{factor: string, offset: string}> $conversions
     * @return list<array{factor: string, offset: string}>
     */
    public static function conversionsOf(array $rows, array $conversions): array
    {
        $out = [];

        foreach ($rows as $row) {
            $node = $row->value->reference;

            if ($node !== null && $row->value->referenceSpace === ReferenceSpace::Node && isset($conversions[$node])) {
                $out[] = $conversions[$node];
            }
        }

        return $out;
    }

    /**
     * A prefix has no offset and goes first; a unit with an offset (°C) works on the prefixed number.
     *
     * @param list<array{factor: string, offset: string}> $conversions
     */
    public static function folded(string $number, array $conversions): string
    {
        usort($conversions, static fn (array $a, array $b): int => (KeyValues::normal($a['offset']) === '0' ? 0 : 1) <=> (KeyValues::normal($b['offset']) === '0' ? 0 : 1));

        foreach ($conversions as $conversion) {
            $number = KeyValues::add(KeyValues::multiply($number, $conversion['factor']), $conversion['offset']);
        }

        return KeyValues::normal($number);
    }
}
