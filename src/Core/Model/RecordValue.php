<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * One value inside a record, addressed by the path that reaches it.
 *
 * ```mermaid
 * flowchart LR
 *   R["record"] --> P["path · 100.101"] --> V["value"]
 *   P --> E["edge_id · 101 · the last step"]
 * ```
 *
 * ⚠️ **The last edge is kept alongside the path** (D-134), and that is what makes the data
 * searchable at all: `WHERE edge_id = … AND value_decimal > 1000` finds every price over a
 * thousand **wherever it sits**, and adding the path narrows it to one attribute. Without the
 * separate column the same question would need a `LIKE` over a text path.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class RecordValue
{
    /**
     * @param string $path   Edge ids from the record's model down to this value, `.`-separated.
     * @param int    $edgeId The last step of that path, kept apart so it can be indexed.
     * @param string $locale Empty unless the attribute is declared translatable (D-317).
     */
    public function __construct(
        public readonly int $recordId,
        public readonly string $path,
        public readonly int $edgeId,
        public readonly string $locale,
        public readonly TypedValue $value,
    ) {
    }

    /** A value reached by one edge from the record's own model. */
    public static function direct(int $recordId, int $edgeId, TypedValue $value, string $locale = ''): self
    {
        return new self($recordId, (string) $edgeId, $edgeId, $locale, $value);
    }
}
