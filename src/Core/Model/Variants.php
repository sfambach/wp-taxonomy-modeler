<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * Records with variants (D-1208): which record is whose variant, whether a new parent link may stand, and the values a
 * variant shows — its own on «Je Variante» fields (its parent's where it has none), its parent's on every other field.
 *
 * ```mermaid
 * flowchart LR
 *   V["variant values"] --> R{"field flagged «Je Variante»?"}
 *   R -->|yes| O["own value, else parent's"]
 *   R -->|no| P["parent's value"]
 * ```
 *
 * Pure: the caller reads the variants and their parents in one go (CD-7) and hands both in.
 *
 * @see docs/pakete/varianten/plan.md
 */
final class Variants
{
    /**
     * @param array<int, array<int, list<string>>> $records record → relation → values
     * @return array<int, int> variant → parent, for the records whose parent field names one
     */
    public static function parents(array $records, int $variantOf): array
    {
        $out = [];

        foreach ($records as $id => $values) {
            $parent = (int) ($values[$variantOf][0] ?? 0);

            if ($parent > 0) {
                $out[$id] = $parent;
            }
        }

        return $out;
    }

    /**
     * Why `$record` may not become a variant of `$parent`; empty when it may.
     *
     * @param array<int, int> $known variant → parent, as stored now
     * @return list<VariantComplaint>
     */
    public static function complaints(int $record, int $parent, array $known): array
    {
        if ($parent === 0) {
            return [];
        }

        $out = [];

        if ($parent === $record) {
            $out[] = VariantComplaint::OfItself;
        }

        if (isset($known[$parent])) {
            $out[] = VariantComplaint::ParentIsVariant;
        }

        if (in_array($record, $known, true)) {
            $out[] = VariantComplaint::HasVariants;
        }

        return $out;
    }

    /**
     * The values each variant shows; a record that is no variant keeps its own.
     *
     * @param array<int, array<int, list<string>>> $records the records asked for: record → relation → values
     * @param array<int, array<int, list<string>>> $parents their parents' values, read beforehand
     * @param list<int>                            $flagged relations carrying «Je Variante»
     * @return array<int, array<int, list<string>>>
     */
    public static function shown(array $records, array $parents, int $variantOf, array $flagged): array
    {
        $own = array_flip($flagged);
        $out = [];

        foreach ($records as $id => $values) {
            $parent = (int) ($values[$variantOf][0] ?? 0);

            if ($parent === 0 || ! isset($parents[$parent])) {
                $out[$id] = $values;

                continue;
            }

            $shown = [];

            foreach ($parents[$parent] as $relation => $inherited) {
                // The parent's own parent link is no value of the variant.
                if ($relation !== $variantOf && ! isset($own[$relation])) {
                    $shown[$relation] = $inherited;
                }
            }

            foreach ($flagged as $relation) {
                $mine = $values[$relation] ?? [];
                $from = $mine !== [] ? $mine : ($parents[$parent][$relation] ?? []);

                if ($from !== []) {
                    $shown[$relation] = $from;
                }
            }

            $shown[$variantOf] = $values[$variantOf];
            $out[$id]          = $shown;
        }

        return $out;
    }
}
