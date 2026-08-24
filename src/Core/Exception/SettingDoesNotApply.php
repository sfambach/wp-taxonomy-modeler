<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

use Taxmod\Core\Model\SettingKey;

/**
 * A setting written where it has nothing to say.
 *
 * ⚠️ **The asymmetry runs one way.** Everything sayable about a node is also sayable about one
 * use of it; the reverse is not true. A node describes a *thing*, an edge describes a *use of a
 * thing* — so a thing has no multiplicity while a use of it does.
 *
 * ⚠️ **Refused in the core, not merely hidden in the screen.** A key that cannot be reached
 * through the interface but can still be written by an import or a data pack is a key that will
 * one day be written.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class SettingDoesNotApply extends DomainError
{
    public static function toANode(SettingKey $key): self
    {
        return new self(sprintf(
            '«%s» belongs to a use of a node, not to the node itself — set it on the attribute.',
            $key->value
        ));
    }

    public static function notOneOfTheFour(string $attempted): self
    {
        return new self(sprintf(
            'A multiplicity is one of 0..1, 1..1, 0..* or 1..*, and «%s» is none of them.',
            $attempted
        ));
    }
}
