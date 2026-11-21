<?php declare(strict_types=1);

namespace Taxmod\Core\Privacy;

use Taxmod\Core\Exception\FieldDoesNotOpen;

/**
 * Seals the text of a confidential field for storage and opens it again (D-1514): XChaCha20-Poly1305, the key from
 * outside the database, a key id in front so a key can be replaced later.
 *
 * ```mermaid
 * flowchart LR
 *   P["Klartext"] -->|seal| S["enc1:&lt;kid&gt;:&lt;base64 nonce+chiffrat&gt;"]
 *   S -->|open| P
 *   K["Schlüssel je kid"] -.-> S
 * ```
 *
 * ⚠️ *The associated data is a fixed context and not the record or field id: undo, merge and «move field» copy stored
 * rows to another record or field as they are, and a value bound to its old place would no longer open there.*
 *
 * @see docs/pakete/vertraulich/konzept.md
 */
final class FieldCipher
{
    public const PREFIX = 'enc1:';

    private const CONTEXT = 'taxmod-field-v1';

    /** @var array<string, string> key id → raw key; the first one seals */
    private readonly array $keys;

    private readonly string $sealingKeyId;

    /** @param array<string, string> $keys key id → 32 raw bytes, the first entry is the one that seals */
    public function __construct(array $keys)
    {
        if ($keys === []) {
            throw FieldDoesNotOpen::withoutKey();
        }

        foreach ($keys as $id => $key) {
            if (! is_string($id) || preg_match('/^[a-z0-9]{1,16}$/', $id) !== 1 || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw FieldDoesNotOpen::badKey();
            }
        }

        $this->keys         = $keys;
        $this->sealingKeyId = (string) array_key_first($keys);
    }

    /** The raw 32 bytes of a key written as 64 hex digits or as base64 — how it stands in wp-config.php. */
    public static function keyFromText(string $text): string
    {
        $text = trim($text);
        $raw  = preg_match('/^[0-9a-fA-F]{64}$/', $text) === 1 ? hex2bin($text) : base64_decode($text, true);

        if (! is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw FieldDoesNotOpen::badKey();
        }

        return $raw;
    }

    /** A new key as 64 hex digits, for the owner to put into wp-config.php and keep a copy of. */
    public static function newKeyText(): string
    {
        return bin2hex(sodium_crypto_aead_xchacha20poly1305_ietf_keygen());
    }

    public static function isSealed(?string $stored): bool
    {
        return $stored !== null && str_starts_with($stored, self::PREFIX);
    }

    /** A sealed text stays as it is, so sealing twice is sealing once. */
    public function seal(string $plain): string
    {
        if (self::isSealed($plain)) {
            return $plain;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $box   = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, self::CONTEXT, $nonce, $this->keys[$this->sealingKeyId]);

        return self::PREFIX . $this->sealingKeyId . ':' . base64_encode($nonce . $box);
    }

    /** A text that was never sealed comes back unchanged — values written before the field became confidential. */
    public function open(string $stored): string
    {
        if (! self::isSealed($stored)) {
            return $stored;
        }

        $rest  = substr($stored, strlen(self::PREFIX));
        $colon = strpos($rest, ':');

        if ($colon === false) {
            throw FieldDoesNotOpen::damaged();
        }

        $keyId = substr($rest, 0, $colon);
        $raw   = base64_decode(substr($rest, $colon + 1), true);

        if (! isset($this->keys[$keyId])) {
            throw FieldDoesNotOpen::unknownKey($keyId);
        }

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw FieldDoesNotOpen::damaged();
        }

        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
            self::CONTEXT,
            substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
            $this->keys[$keyId]
        );

        if ($plain === false) {
            throw FieldDoesNotOpen::wrongKey();
        }

        return $plain;
    }

    /** Whether a stored text was sealed with another key than the sealing one — what a re-keying job looks for. */
    public function needsResealing(string $stored): bool
    {
        return self::isSealed($stored) && ! str_starts_with($stored, self::PREFIX . $this->sealingKeyId . ':');
    }
}
