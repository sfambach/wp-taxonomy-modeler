<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * A stored secret of an import source cannot be sealed or opened — no key, damaged text, or a different key.
 *
 * @see docs/pakete/import/konzept.md §3.1
 */
final class SecretDoesNotOpen extends DomainError
{
    public static function withoutKey(): self
    {
        return new self('No key material to seal secrets with.');
    }

    public static function damaged(): self
    {
        return new self('The sealed secret is damaged.');
    }

    public static function wrongKey(): self
    {
        return new self('The sealed secret does not open with this key — were the salts in wp-config.php changed?');
    }
}
