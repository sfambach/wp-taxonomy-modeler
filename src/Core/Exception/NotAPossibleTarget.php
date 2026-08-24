<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * A node that cannot stand at the far end of an attribute.
 *
 * ⚠️ **The kind of an edge is read off the target's branch and never chosen** (D-161). A target
 * that sits in no branch therefore has no kind to read, and there is nothing sensible to fall
 * back on — inventing one is precisely how a supplier ends up *composed* into an order, which
 * is the error the branch rule exists to prevent.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class NotAPossibleTarget extends DomainError
{
    public static function itSitsInNoBranch(string $name): self
    {
        return new self(sprintf(
            '«%s» sits in no branch, so there is no relation kind to read off it. An attribute points into Model, Compositions, Data Types or Constants.',
            $name
        ));
    }

    /** D-238: everything **but** the branch root is selectable — the root itself names the branch. */
    public static function itIsABranchRoot(string $name): self
    {
        return new self(sprintf('«%s» is the root of its branch and stands for the branch itself, not for a thing in it.', $name));
    }

    public static function itIsInTheTrash(string $name): self
    {
        return new self(sprintf('«%s» is in the trash. Restore it before pointing at it.', $name));
    }
}
