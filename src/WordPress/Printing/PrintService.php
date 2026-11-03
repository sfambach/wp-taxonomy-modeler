<?php declare(strict_types=1);

namespace Taxmod\WordPress\Printing;

use Taxmod\Core\Printing\PrintServiceToken;

/**
 * The access of the print service at home (D-1085: technical access lives in the Configuration) and how it is woken
 * (D-1100): a new job raises a counter in a small static file and sends a push without content to an ntfy topic. The
 * service listens to the topic and looks at the file now and then — neither costs WordPress a request; it calls
 * WordPress only to fetch jobs and to answer.
 *
 * ```mermaid
 * flowchart LR
 *   J["job queued"] --> F["static file: counter + 1"]
 *   J --> N["ntfy topic: «1»"]
 *   N --> S["print service at home"]
 *   F -. "every few minutes" .-> S
 *   S -- "one pull" --> W["WordPress REST"]
 *   S -- "lp" --> C["CUPS"]
 *   S -- "result" --> W
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1113
 */
final class PrintService
{
    public const OPTION = 'taxmod_print_service';

    /** Public ntfy server; the topic is a long random name, so only who knows it hears the push. */
    public const NTFY_SERVER = 'https://ntfy.sh';

    public static function boot(): void
    {
        add_action(PrintJobs::QUEUED_ACTION, static fn (): bool => self::wake());
    }

    /**
     * @return array{token_hash: string, topic: string, wake: string, last_seen: string, agent: string, printers: list<string>}
     */
    public static function settings(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'token_hash' => (string) ($stored['token_hash'] ?? ''),
            'topic'      => (string) ($stored['topic'] ?? ''),
            'wake'       => (string) ($stored['wake'] ?? ''),
            'last_seen'  => (string) ($stored['last_seen'] ?? ''),
            'agent'      => (string) ($stored['agent'] ?? ''),
            'printers'   => array_values(array_map('strval', (array) ($stored['printers'] ?? []))),
        ];
    }

    public static function isSetUp(): bool
    {
        return self::settings()['token_hash'] !== '';
    }

    /**
     * A new access: a new token (shown once), a new topic and a new file name. The old token stops working at once.
     *
     * @return string the token, in clear, for the service's configuration
     */
    public static function renewAccess(): string
    {
        $token = PrintServiceToken::generate();
        $settings = self::settings();
        $old = self::wakeFile($settings['wake']);
        if ($old !== '' && is_file($old)) {
            wp_delete_file($old);
        }
        $settings['token_hash'] = PrintServiceToken::hash($token);
        $settings['topic'] = 'taxmod-' . bin2hex(random_bytes(12));
        $settings['wake'] = bin2hex(random_bytes(12));
        update_option(self::OPTION, $settings, false);
        self::wake();

        return $token;
    }

    public static function revokeAccess(): void
    {
        $file = self::wakeFile(self::settings()['wake']);
        if ($file !== '' && is_file($file)) {
            wp_delete_file($file);
        }
        delete_option(self::OPTION);
    }

    public static function accepts(string $token): bool
    {
        return PrintServiceToken::matches($token, self::settings()['token_hash']);
    }

    /**
     * What the service says about itself on each pull: its version and the printers it serves.
     *
     * @param list<string> $printers
     */
    public static function seen(string $agent, array $printers): void
    {
        $settings = self::settings();
        $settings['last_seen'] = current_time('mysql', true);
        $settings['agent'] = substr($agent, 0, 64);
        $settings['printers'] = array_slice($printers, 0, 8);
        update_option(self::OPTION, $settings, false);
    }

    /** Raises the counter in the static file and sends the push; true when the file was written. */
    public static function wake(): bool
    {
        $settings = self::settings();
        $file = self::wakeFile($settings['wake']);
        if ($file === '') {
            return false;
        }
        wp_mkdir_p(dirname($file));
        $counter = is_file($file) ? (int) file_get_contents($file) : 0;
        $written = file_put_contents($file, (string) ($counter + 1)) !== false;

        if ($settings['topic'] !== '') {
            wp_remote_post(self::NTFY_SERVER . '/' . rawurlencode($settings['topic']), ['body' => '1', 'blocking' => false, 'timeout' => 2]);
        }

        return $written;
    }

    public static function wakeUrl(): string
    {
        $wake = self::settings()['wake'];

        return $wake === '' ? '' : trailingslashit(wp_upload_dir()['baseurl']) . 'taxmod-print/' . $wake . '.txt';
    }

    private static function wakeFile(string $wake): string
    {
        return preg_match('/^[0-9a-f]{24}$/', $wake) === 1 ? trailingslashit(wp_upload_dir()['basedir']) . 'taxmod-print/' . $wake . '.txt' : '';
    }
}
