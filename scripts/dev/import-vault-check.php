<?php declare(strict_types=1);

/**
 * The vault of the import modules seals with the auth salt of this site and opens again.
 *
 *     php scripts/dev/import-vault-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-951](../../docs/NewConcept/90-decision-log.md), his word:** *«keys und passwörter immer verschlüsselt speichern»*.
 * *What a module sealed before the vault moved into the modeller (D-952) must still open under its old context.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Exception\SecretDoesNotOpen;
use Taxmod\WordPress\Import\Vault;

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$sealed = Vault::for('__iv')->seal('geheim-123');
$pruefe(! str_contains($sealed, 'geheim-123'), 'the sealed text does not carry the secret');
$pruefe(Vault::for('__iv')->open($sealed) === 'geheim-123', 'the same context opens it again');

try {
    Vault::for('__other')->open($sealed);
    $pruefe(false, 'another context does not open it');
} catch (SecretDoesNotOpen) {
    $pruefe(true, 'another context does not open it');
}

$legacyKey = sodium_crypto_generichash('taxmail-secret|' . wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$legacy = 'sb1:' . base64_encode($nonce . sodium_crypto_secretbox('imap', $nonce, $legacyKey));
$pruefe(Vault::for('taxmail-secret')->open($legacy) === 'imap', 'what tax_mail sealed before opens under its old context');

echo $fehler === 0 ? "all green\n" : "{$fehler} failed\n";
exit($fehler === 0 ? 0 : 1);
