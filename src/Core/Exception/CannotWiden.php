<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

use Taxmod\Core\Model\SettingKey;

/**
 * A bounding setting asked to allow more than it inherited.
 *
 * ⚠️ **Refused rather than reported afterwards** (D-312). A restriction that may be reopened
 * anywhere **says nothing when it is read**: to know what is allowed you would have to inspect
 * every use site. And the deeper danger the owner named with his whale: whoever *can* widen,
 * widens — instead of cutting the tree differently.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class CannotWiden extends DomainError
{
    public static function bound(SettingKey $key, string $inherited, string $attempted): self
    {
        return new self(sprintf(
            '«%s» is inherited as %s and may only be narrowed, not set to %s.',
            $key->value,
            $inherited,
            $attempted
        ));
    }

    /**
     * ⚠️ **Two of the four multiplicities are incomparable**, so this message deliberately does
     * not say *wider*. Going from `0..1` to `1..*` trades *may be absent* for *may be several* —
     * a different bound, not a looser one, and under D-312 a different bound is refused just the
     * same.
     */
    public static function notNarrower(string $inherited, string $attempted): self
    {
        return new self(sprintf(
            'The multiplicity here is %s, and %s does not fit inside it.',
            $inherited,
            $attempted
        ));
    }

    public static function mandatoryStays(SettingKey $key): self
    {
        return new self(sprintf(
            'An ancestor declares «%s», and what an ancestor declares stays for every descendant.',
            $key->value
        ));
    }
}
