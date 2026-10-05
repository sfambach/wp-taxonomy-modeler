<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * What was handed in cannot be written as a changelog state and read back unchanged.
 *
 * ⚠️ **Refused rather than escaped**, for the same reason
 * {@see NotAValueOfThatType} refuses rather than coerces: a state column is read again by a replay
 * ([D-427](../../../docs/NewConcept/90-decision-log.md)), and a field that comes back glued to its
 * neighbour is a loss nobody can see afterwards.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class NotAFrozenState extends DomainError
{
    public static function withoutFields(): self
    {
        return new self('A frozen state needs at least one field.');
    }

    public static function withTheKey(string $key): self
    {
        return new self(sprintf(
            'The field name "%s" is not a lowercase token, so it could not be read back.',
            $key
        ));
    }

    public static function withWhitespaceBeforeTheLastField(string $key): self
    {
        return new self(sprintf(
            'The field "%s" holds whitespace and is not the last one, so it could not be read back.',
            $key
        ));
    }
}
