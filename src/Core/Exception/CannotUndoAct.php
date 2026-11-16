<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * An act that cannot be taken back as a whole — and the reason, so nothing is undone in part without a word.
 *
 * ⚠️ **The guard is the point** ([D-1410](../../../docs/NewConcept/90-decision-log.md), OQ-137): *taking an act back is right only
 * if nothing else wrote the same rows since. If another act did, the undo is refused and names that act — undo it first, or leave
 * both.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class CannotUndoAct extends DomainError
{
    public static function unknown(int $act): self
    {
        return new self(sprintf('There is no change number %d.', $act));
    }

    public static function stillOpen(int $act): self
    {
        return new self(sprintf('Change %d is the one running now; it can be taken back once it is done.', $act));
    }

    public static function nothingToTakeBack(int $act): self
    {
        return new self(sprintf('Change %d left nothing that can be taken back — it is older than undo (before 0.2.121), or it wrote nothing.', $act));
    }

    /** @param list<int> $laterActs */
    public static function writtenSince(int $act, array $laterActs): self
    {
        $named = array_filter($laterActs, static fn (int $later): bool => $later > 0);

        return new self($named === []
            ? sprintf('Change %d cannot be taken back: the same data was changed since, by a change without a number.', $act)
            : sprintf('Change %d cannot be taken back: the same data was changed since by change %s. Take that back first.', $act, implode(', ', $named)));
    }

    public static function touchesTheModel(int $act): self
    {
        return new self(sprintf('Change %d changed the model, not only records; it can be taken back only with the right to change the model.', $act));
    }
}
