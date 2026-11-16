<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\SecretSlot;

/**
 * A module's secret in one option, always sealed with the vault (D-951, the project rule «Schlüssel und Passwörter nur
 * verschlüsselt»), a constant in wp-config.php going first. The one store instead of a copy per module (plan-dubletten
 * row 6). A module with several secrets takes one per secret.
 *
 * @see docs/pakete/import/konzept.md §3.1
 */
final class StoredSecret
{
    public function __construct(private readonly string $option, private readonly string $context, private readonly string $constant = '')
    {
    }

    public function slot(): SecretSlot
    {
        $constant = $this->constant !== '' && defined($this->constant) && is_string(constant($this->constant)) ? (string) constant($this->constant) : '';
        $sealed   = get_option($this->option, '');

        return new SecretSlot($constant, is_string($sealed) ? $sealed : '', Vault::for($this->context));
    }

    public function value(): ?string
    {
        return $this->slot()->value();
    }

    /** An empty secret keeps the stored one. Caller has checked capability and nonce and sanitized. */
    public function save(#[\SensitiveParameter] string $secret): void
    {
        if ($secret !== '') {
            update_option($this->option, Vault::for($this->context)->seal($secret), false);
        }
    }

    /** Deletes the stored secret; a constant in wp-config.php stays, it is not a page's to delete. */
    public function forget(): void
    {
        delete_option($this->option);
    }
}
