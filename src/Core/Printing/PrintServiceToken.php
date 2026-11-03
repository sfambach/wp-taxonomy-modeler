<?php declare(strict_types=1);

namespace Taxmod\Core\Printing;

/**
 * The secret the print service at home shows on every call. Only its hash is stored, so a database copy cannot pose as the service.
 *
 * @see docs/NewConcept/90-decision-log.md D-1113
 */
final class PrintServiceToken
{
    private const BYTES = 24;

    public static function generate(): string
    {
        return bin2hex(random_bytes(self::BYTES));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function matches(string $token, string $storedHash): bool
    {
        return $token !== '' && $storedHash !== '' && hash_equals($storedHash, self::hash($token));
    }
}
