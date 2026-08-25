<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * What was submitted cannot be a value of the type it was submitted for.
 *
 * ⚠️ **Refused rather than coerced.** `(int) 'abc'` is `0`, and a zero that arrived that way is
 * indistinguishable afterwards from a zero somebody meant — so the value is rejected at the door.
 * That is the same call [D-071](../../../docs/NewConcept/90-decision-log.md) makes about typed
 * columns: nothing is cast quietly in or out.
 *
 * ⚠️ **This is not the converter.** [R36a](../../../docs/NewConcept/30-renderer.md#r36a--the-converter-removes-what-cannot-have-been-meant-the-validator-asks-about-the-rest)
 * gives that job to a converter — *it removes what cannot have been meant*, so `4k7` becomes
 * `4700` and a thousands separator disappears before anything is stored. Until converters exist
 * the submitted characters must already be the type's own form, and anything else is an error
 * rather than a guess.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class NotAValueOfThatType extends DomainError
{
    public static function submitted(string $characters, string $type): self
    {
        return new self(sprintf(
            'The characters "%s" are not a value of type %s.',
            $characters,
            $type
        ));
    }
}
