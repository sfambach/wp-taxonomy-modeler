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
            '«%s» belongs to a use of a node, not to the node itself — set it on the field.',
            $key->value
        ));
    }

    /**
     * A renderer nobody registered.
     *
     * ⚠️ **Refused rather than stored and discovered later.** Such a name resolves to the fallback
     * at render time, which shows as *no renderer* on a node that has one — a fault two steps away
     * from its cause. Checking at the write puts the complaint where the mistake was made.
     *
     * ⚠️ **It does not refuse an *unusual* choice, only an *absent* one** (D-360). Which renderers
     * make sense for a type is what the screen offers; a deliberate exception is somebody's special
     * case and not an error.
     */
    public static function thatRendererCannotDrawThis(string $attempted, string $node): self
    {
        return new self(sprintf(
            'No renderer answers to «%s», so «%s» would end up with none.',
            $attempted,
            $node
        ));
    }

    /**
     * An empty value written to a switch — the third state a switch does not have.
     *
     * ⚠️ **Refused because an empty switch row does not read as «nothing», it reads as «false, set
     * here».** *Measured on 2026-08-27: writing `nothing` to `hide` stored `value_int = NULL`, and
     * resolving it came back `false (hier gesetzt)` — so the row **stops the chain**, and an ancestor
     * saying `hide = true` is silently overruled by a row that says nothing at all.*
     *
     * ⚠️ *[D-401](../../../docs/NewConcept/90-decision-log.md) already decided this — «a `bool`
     * setting has exactly two states, and «not set» is not one of them» — and
     * [D-429](../../../docs/NewConcept/90-decision-log.md) removed the `empty` button because
     * «a switch has no third state at all». What was missing is that the **column allows it**:
     * `value_int` is `bigint DEFAULT NULL`, so only the core can hold that line. To unset a switch is
     * `reset`, which pulls what the chain says above ([D-423](../../../docs/NewConcept/90-decision-log.md)).*
     */
    public static function hasNoEmptyState(SettingKey $key): self
    {
        return new self(sprintf(
            '«%s» is either true or false — to unset it, reset it to what the chain says above.',
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
