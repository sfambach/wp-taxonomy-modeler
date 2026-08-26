<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

/**
 * A decimal as it comes **back** out of the database — the padding taken off again.
 *
 * ⚠️ **`decimal(30,10)` pads on read and that is a storage artefact, not a value.** Store `2.7` and
 * MySQL returns `2.7000000000`. *Found by storing a real unit value: `2.7 kΩ` read back as
 * `2.7000000000 k Ω`.* Three things go wrong at once if it is left alone — a field shows ten zeros
 * nobody typed, a save that changed nothing counts as a change, and
 * {@see \Taxmod\Core\Model\TypedValue::equals()} says two identical numbers differ.
 *
 * ⚠️ **Here, and deliberately not in `TypedValue::ofDecimal()`, which is where I put it first.** A
 * test caught that within a minute: *`2.50` must not come back as `2.5`*
 * ([D-057](../../../docs/NewConcept/90-decision-log.md)). **What somebody typed is theirs.** Only the
 * string that arrives from a `decimal` column is padded, so only that string is trimmed — and this
 * class exists so both repositories do it the same way rather than each remembering.
 *
 * ⚠️ *That the **scale** a person typed is not preserved through storage at all is true and separate:
 * `2.50` and `2.5` are one row once written, which is
 * [OQ-085](../../../docs/NewConcept/91-open-questions.md)'s precision question and stays open.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class StoredDecimal
{
    /**
     * @param string|int|float|null $raw Whatever the driver handed back for the column.
     */
    public static function read(string|int|float|null $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);

        // No point, no padding — an integer-looking decimal is already canonical.
        if ($value === '' || ! str_contains($value, '.')) {
            return $value === '' ? null : $value;
        }

        $value = rtrim(rtrim($value, '0'), '.');

        // ⚠️ `0.0000000000` trims to nothing and `-0.50` to `-` — neither is a number. *Zero is a
        // value and has to survive being written as ten zeros.*
        return $value === '' || $value === '-' ? $value . '0' : $value;
    }
}
