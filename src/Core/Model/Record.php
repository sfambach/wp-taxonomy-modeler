<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * One thing somebody entered against a model node.
 *
 * ⚠️ **Its id comes from the record space, not the model's** (D-164). The two halves do not
 * share a number space: between nodes and edges the ambiguity is real, and there it must be one
 * space; between model and data it is not, because the target's **branch** decides which sort a
 * reference resolves to, deterministically and per edge (D-131).
 *
 * ⚠️ **It keeps the model version it was written against** (D-060, D-210) — *written against*,
 * not *checked against*. Records at several versions are a normal steady state, and only what
 * actually conflicted is ever touched.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class Record
{
    public function __construct(
        public readonly int $id,
        public readonly int $modelId,
        public readonly int $modelVersion,
        public readonly string $createdAt,
    ) {
    }
}
