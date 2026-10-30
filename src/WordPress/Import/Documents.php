<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Exception\SecretDoesNotOpen;
use Taxmod\Core\Import\SecretBox;
use Taxmod\Core\Model\Type\MediaType;
use Taxmod\Core\Port\MediaFile;
use Taxmod\WordPress\Plugin;

/**
 * The encrypted document store (D-1023): invoices, later labels and data sheets — files with personal data that do not
 * belong in the public media library. Each file is sealed with the vault (D-951) in a folder under wp-content that
 * the web server may not serve; it opens only through a download for a logged-in user with the capability and a nonce.
 * A field holds it as `vault:<key>`; the key comes from the content, so the same file read twice is stored once.
 *
 * ```mermaid
 * flowchart LR
 *   M["module: put(bytes, name)"] --> S["seal (vault)"] --> F["wp-content/taxmod-documents/<key>.bin"]
 *   U["admin: link in a field"] --> D["admin-post: capability, nonce"] --> O["open, send"]
 * ```
 *
 * ⚠️ *Changing the salts of wp-config.php makes every file unreadable, as with the mail password; the original stays in the
 * mailbox and can be read in again.*
 *
 * @see docs/pakete/import/module-schnitt.md
 */
final class Documents
{
    public const ACTION = 'taxmod_document';
    private const FOLDER = 'taxmod-documents';
    private const CONTEXT = 'documents';

    public static function boot(): void
    {
        add_action('admin_post_' . self::ACTION, self::handleDownload(...));
    }

    /** Stores a file and gives the address a media field holds. */
    public static function put(string $content, string $fileName, string $mimeType): string
    {
        $key = substr(hash_hmac('sha256', $content, wp_salt('auth')), 0, 32);
        $folder = self::folder();

        if (! is_file($folder . '/' . $key . '.bin')) {
            $box = self::box();
            $meta = (string) wp_json_encode(['name' => sanitize_file_name($fileName), 'type' => $mimeType, 'size' => strlen($content), 'stored' => gmdate('c')]);
            file_put_contents($folder . '/' . $key . '.meta', $box->seal($meta));
            file_put_contents($folder . '/' . $key . '.bin', $box->seal($content));
        }

        return MediaType::VAULT_SCHEME . $key;
    }

    /** @return array{name: string, type: string, size: int, stored: string}|null */
    public static function meta(string $key): ?array
    {
        $file = self::folder() . '/' . $key . '.meta';

        if (! self::isKey($key) || ! is_file($file)) {
            return null;
        }

        try {
            $meta = json_decode(self::box()->open((string) file_get_contents($file)), true);
        } catch (SecretDoesNotOpen) {
            return null;
        }

        return is_array($meta) ? [
            'name'   => (string) ($meta['name'] ?? ''),
            'type'   => (string) ($meta['type'] ?? ''),
            'size'   => (int) ($meta['size'] ?? 0),
            'stored' => (string) ($meta['stored'] ?? ''),
        ] : null;
    }

    public static function delete(string $key): void
    {
        if (self::isKey($key)) {
            wp_delete_file(self::folder() . '/' . $key . '.bin');
            wp_delete_file(self::folder() . '/' . $key . '.meta');
        }
    }

    /** The file as a link for the one who may open it; null for anyone else (the field then shows its name). */
    public static function linkFor(string $key): ?MediaFile
    {
        $meta = self::meta($key);

        if ($meta === null) {
            return null;
        }

        if (! current_user_can(Plugin::CAPABILITY)) {
            return new MediaFile(0, '', $meta['name']);
        }

        // Not wp_nonce_url(): it escapes for HTML, and the renderer escapes once more.
        $url = add_query_arg(['action' => self::ACTION, 'key' => $key, '_wpnonce' => wp_create_nonce(self::ACTION . '_' . $key)], admin_url('admin-post.php'));

        return new MediaFile(0, $url, $meta['name']);
    }

    public static function handleDownload(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You may not open this document.', 'taxmod'), '', ['response' => 403]);
        }

        $key = sanitize_key((string) wp_unslash($_GET['key'] ?? ''));
        check_admin_referer(self::ACTION . '_' . $key);
        $meta = self::meta($key);

        if ($meta === null) {
            wp_die(esc_html__('This document is not there, or it no longer opens with this site\'s keys.', 'taxmod'), '', ['response' => 404]);
        }

        try {
            $content = self::box()->open((string) file_get_contents(self::folder() . '/' . $key . '.bin'));
        } catch (SecretDoesNotOpen) {
            wp_die(esc_html__('This document is not there, or it no longer opens with this site\'s keys.', 'taxmod'), '', ['response' => 404]);
        }

        nocache_headers();
        header('Content-Type: ' . ($meta['type'] === 'application/pdf' ? 'application/pdf' : 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $meta['name']) . '"');
        header('Content-Length: ' . strlen($content));
        header('X-Content-Type-Options: nosniff');
        // A download sends bytes; there is nothing to return a string to.
        print $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private static function isKey(string $key): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $key) === 1;
    }

    private static function box(): SecretBox
    {
        return Vault::for(self::CONTEXT);
    }

    /** The folder, created with what keeps a web server from serving or listing it. */
    private static function folder(): string
    {
        $folder = WP_CONTENT_DIR . '/' . self::FOLDER;

        if (! is_dir($folder)) {
            wp_mkdir_p($folder);
        }

        foreach ([
            '.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'index.php'  => "<?php\n// Silence.\n",
            'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        ] as $name => $content) {
            if (! is_file($folder . '/' . $name)) {
                file_put_contents($folder . '/' . $name, $content);
            }
        }

        return $folder;
    }
}
