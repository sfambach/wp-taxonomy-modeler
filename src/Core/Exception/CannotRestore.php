<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * A parked node that cannot be put back where it came from.
 *
 * ⚠️ **Not a dead end.** Restoring is a convenience — it reads the old place out of the
 * changelog (D-065) and moves the node there. When that place is gone or itself parked, the
 * node stays in the trash and can still be moved anywhere by hand, like any other node.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class CannotRestore extends DomainError
{
    public static function itWasNeverParked(string $name): self
    {
        return new self(sprintf('«%s» is not in the trash.', $name));
    }

    public static function theOldPlaceIsGone(string $name): self
    {
        return new self(sprintf(
            'Where «%s» came from no longer exists. Move it out of the trash by hand.',
            $name
        ));
    }

    public static function theOldPlaceIsAlsoParked(string $name, string $parent): self
    {
        return new self(sprintf(
            '«%s» came from «%s», which is itself in the trash. Restore that one first.',
            $name,
            $parent
        ));
    }
}
