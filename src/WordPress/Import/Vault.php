<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\SecretBox;

/**
 * The vault an import module asks for: a `SecretBox` keyed with the auth salt of wp-config.php, so a backup or a
 * database dump does not carry a secret readable (D-951). The context names the module.
 *
 * @see docs/pakete/import/konzept.md §3.1
 */
final class Vault
{
    /** Bumped when the vault's contract changes; a module checks it instead of the plugin version. */
    public const API = 1;

    public static function for(string $context): SecretBox
    {
        return new SecretBox(wp_salt('auth'), $context);
    }
}
