<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * A confidential field cannot be sealed or opened — no key, a damaged value, or a value sealed with another key (D-1514).
 *
 * @see docs/pakete/vertraulich/konzept.md
 */
final class FieldDoesNotOpen extends DomainError
{
    public static function withoutKey(): self
    {
        return new self('No data key is configured (TAXMOD_DATA_KEY in wp-config.php), so a confidential field cannot be written.');
    }

    public static function badKey(): self
    {
        return new self('The data key must be 32 bytes, written as 64 hex digits or as base64.');
    }

    public static function damaged(): self
    {
        return new self('The sealed value is damaged.');
    }

    public static function unknownKey(string $keyId): self
    {
        return new self(sprintf('The value was sealed with the data key «%s», which is not configured.', $keyId));
    }

    public static function wrongKey(): self
    {
        return new self('The sealed value does not open with the configured data key.');
    }
}
