<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

use Taxmod\Core\Exception\SecretDoesNotOpen;

/**
 * Seals a secret of an import source — key, password, token — for storage and opens it again (D-951). One vault for
 * every module instead of a copy each (D-952): libsodium secretbox, the key derived from material that lives outside
 * the database plus a context per source.
 *
 * The context keeps what a module sealed before the vault moved here readable: tax_mail used `taxmail-secret`,
 * tax_ebay `taxebay-token`, and tax_ebay wrote no prefix.
 *
 * @see docs/pakete/import/konzept.md §3.1
 */
final class SecretBox
{
    private const PREFIX = 'sb1:';

    private readonly string $key;

    public function __construct(string $keyMaterial, string $context)
    {
        if ($keyMaterial === '' || $context === '') {
            throw SecretDoesNotOpen::withoutKey();
        }

        $this->key = sodium_crypto_generichash($context . '|' . $keyMaterial, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function seal(string $secret): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($secret, $nonce, $this->key));
    }

    /** @throws SecretDoesNotOpen when the text was not sealed with this key — e.g. after the salts changed */
    public function open(string $sealed): string
    {
        $encoded = str_starts_with($sealed, self::PREFIX) ? substr($sealed, strlen(self::PREFIX)) : $sealed;
        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw SecretDoesNotOpen::damaged();
        }

        $secret = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );

        if ($secret === false) {
            throw SecretDoesNotOpen::wrongKey();
        }

        return $secret;
    }

    public static function isSealed(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }
}
