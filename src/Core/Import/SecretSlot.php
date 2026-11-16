<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

use Taxmod\Core\Exception\SecretDoesNotOpen;

/**
 * One secret of a module as it stands: a constant from wp-config.php goes first (D-944), else the sealed text from the
 * database opened with the vault (D-951). Was a copy in each of DHL, GLS, JLCPCB and Mouser (plan-dubletten row 6).
 *
 * @see docs/pakete/import/konzept.md §3.1
 */
final class SecretSlot
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $constant,
        private readonly string $sealed,
        private readonly ?SecretBox $box,
    ) {
    }

    public function fromConstant(): bool
    {
        return trim($this->constant) !== '';
    }

    public function isStored(): bool
    {
        return $this->sealed !== '';
    }

    /** The secret, or null when there is none or the stored one does not open. */
    public function value(): ?string
    {
        if ($this->fromConstant()) {
            return trim($this->constant);
        }

        return $this->opened();
    }

    /** true when a secret is stored but no longer opens — the salts in wp-config.php changed. */
    public function broken(): bool
    {
        return $this->isStored() && $this->box !== null && $this->opened() === null;
    }

    private function opened(): ?string
    {
        if ($this->sealed === '' || $this->box === null) {
            return null;
        }

        try {
            $secret = $this->box->open($this->sealed);

            return $secret === '' ? null : $secret;
        } catch (SecretDoesNotOpen) {
            return null;
        }
    }
}
