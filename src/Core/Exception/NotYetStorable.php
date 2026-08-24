<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * A value that has nowhere to go — or nowhere it can go **yet**.
 *
 * ⚠️ **The `yet` matters.** One of these is a rule (a data type has no instances, D-183) and one
 * is unfinished work (a composed part needs a record of its own). Refusing both is right;
 * conflating them in the message would not be.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class NotYetStorable extends DomainError
{
    /** A rule: only branches with instances have records (D-183). */
    public static function thatBranchHasNoRecords(string $name): self
    {
        return new self(sprintf(
            '«%s» has no records of its own — only things under Model and Compositions do.',
            $name
        ));
    }

    /** Unfinished work, and said so plainly rather than stored in the wrong place. */
    public static function compositionsNeedTheirOwnRecords(string $attribute): self
    {
        return new self(sprintf(
            '«%s» points into Compositions, and a composed part is a record of its own — not built yet.',
            $attribute
        ));
    }

    public static function noSuchRecord(int $id): self
    {
        return new self(sprintf('There is no record %d.', $id));
    }

    public static function notAnAttributeOfThisModel(int $edgeId, string $model): self
    {
        return new self(sprintf('Attribute %d does not belong to «%s» or anything it inherits from.', $edgeId, $model));
    }
}
