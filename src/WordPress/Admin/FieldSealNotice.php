<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Privacy\FieldCipher;
use Taxmod\WordPress\Plugin;
use Taxmod\WordPress\Privacy\FieldSeal;

/**
 * The warning an administrator sees while fields are confidential and wp-config.php holds no data key (D-1514): such
 * fields are not written until the key is there, and the warning says how, with a fresh key to copy.
 *
 * @see docs/pakete/vertraulich/konzept.md
 */
final class FieldSealNotice
{
    public static function show(): void
    {
        echo self::markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in markup()
    }

    public static function markup(): string
    {
        if (! current_user_can(Plugin::CAPABILITY) || FieldSeal::confidentialRelations() === [] || FieldSeal::cipher() !== null) {
            return '';
        }

        $line = "define( '" . FieldSeal::KEY_CONSTANT . "', '" . FieldCipher::newKeyText() . "' );";

        return sprintf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s</p><p><code>%s</code></p><p>%s</p></div>',
            esc_html__('Taxonomy Modeller:', 'taxmod'),
            esc_html__('Some fields are confidential, but wp-config.php holds no data key, so they cannot be saved. Add this line to wp-config.php, above «That\'s all, stop editing»:', 'taxmod'),
            esc_html($line),
            esc_html__('Keep a copy of the key outside this server, e.g. in a password manager. Without the key the confidential data cannot be read again — not even from a backup.', 'taxmod')
        );
    }
}
