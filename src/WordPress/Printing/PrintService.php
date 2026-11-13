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
 * @see docs/NewConcept/90-decision-log.md D-1113, D-1336
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

    /** D-1336: at most this many print services, one per computer. */
    public const MAX_SERVICES = 8;

    /**
     * The shared push topic and wake file, and every print service with its own token (D-1336). `token_hash`, `last_seen`,
     * `agent` and `printers` are those of the service heard of last, for the callers that know only one.
     *
     * @return array{token_hash: string, topic: string, wake: string, last_seen: string, agent: string, printers: list<string>,
     *     services: array<string, array{name: string, token_hash: string, last_seen: string, agent: string, host: string,
     *     printers: list<string>, queues: list<string>}>}
     */
    public static function settings(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $services = [];
        foreach ((array) ($stored['services'] ?? []) as $key => $service) {
            if (is_array($service) && preg_match('/^[0-9a-f]{12}$/', (string) $key) === 1) {
                $services[(string) $key] = self::service($service);
            }
        }
        // Before D-1336 there was one token at the top level; it becomes the first service, named by the host it reports.
        if ($services === [] && (string) ($stored['token_hash'] ?? '') !== '') {
            $services[substr(hash('sha256', (string) $stored['token_hash']), 0, 12)] = self::service($stored);
        }
        $last = [];
        foreach ($services as $service) {
            if ($last === [] || strcmp($service['last_seen'], $last['last_seen']) > 0) {
                $last = $service;
            }
        }

        return [
            'token_hash' => $services === [] ? '' : (string) ($last['token_hash'] ?? reset($services)['token_hash']),
            'topic'      => (string) ($stored['topic'] ?? ''),
            'wake'       => (string) ($stored['wake'] ?? ''),
            'last_seen'  => (string) ($last['last_seen'] ?? ''),
            'agent'      => (string) ($last['agent'] ?? ''),
            'printers'   => $last['printers'] ?? [],
            'services'   => $services,
        ];
    }

    public static function isSetUp(): bool
    {
        return self::settings()['services'] !== [];
    }

    /**
     * D-1336: a new print service, for one computer, with its own token (shown once). The push topic and the wake file are
     * made with the first one and shared.
     *
     * @return array{0: string, 1: string} the service's key and its token, in clear
     */
    public static function addService(string $name): array
    {
        $settings = self::settings();
        if (count($settings['services']) >= self::MAX_SERVICES) {
            throw new \RuntimeException('Too many print services.');
        }
        if ($settings['topic'] === '' || $settings['wake'] === '') {
            $settings['topic'] = 'taxmod-' . bin2hex(random_bytes(12));
            $settings['wake'] = bin2hex(random_bytes(12));
        }
        $key = bin2hex(random_bytes(6));
        $token = PrintServiceToken::generate();
        $settings['services'][$key] = self::service(['name' => $name, 'token_hash' => PrintServiceToken::hash($token)]);
        self::store($settings);
        self::wake();

        return [$key, $token];
    }

    /** A new token for one service; its old one stops working at once. Null when there is no such service. */
    public static function renewService(string $key): ?string
    {
        $settings = self::settings();
        if (! isset($settings['services'][$key])) {
            return null;
        }
        $token = PrintServiceToken::generate();
        $settings['services'][$key]['token_hash'] = PrintServiceToken::hash($token);
        self::store($settings);

        return $token;
    }

    public static function removeService(string $key): bool
    {
        $settings = self::settings();
        if (! isset($settings['services'][$key])) {
            return false;
        }
        unset($settings['services'][$key]);
        if ($settings['services'] === []) {
            self::revokeAccess();

            return true;
        }
        self::store($settings);

        return true;
    }

    /**
     * Before D-1336: one access for one service. Now the first service, or a new token for the only one.
     *
     * @return string the token, in clear, for the service's configuration
     */
    public static function renewAccess(): string
    {
        $services = self::settings()['services'];
        if (count($services) === 1) {
            return (string) self::renewService((string) array_key_first($services));
        }

        return self::addService('')[1];
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
        return self::serviceOf($token) !== null;
    }

    /** The key of the service this token belongs to, or null. */
    public static function serviceOf(string $token): ?string
    {
        foreach (self::settings()['services'] as $key => $service) {
            if (PrintServiceToken::matches($token, $service['token_hash'])) {
                return (string) $key;
            }
        }

        return null;
    }

    /** What a service is called: the name given at setup, else the host it reports, else «?». */
    public static function nameOf(array $service): string
    {
        return $service['name'] !== '' ? $service['name'] : ($service['host'] !== '' ? $service['host'] : '?');
    }

    /**
     * What the service says about itself on each pull: its version, its host, the printers it serves and (D-1336) its CUPS
     * queues.
     *
     * @param list<string> $printers
     * @param list<string> $queues
     */
    public static function seen(string $agent, array $printers, string $service = '', string $host = '', array $queues = []): void
    {
        $settings = self::settings();
        $service = $service !== '' ? $service : (string) array_key_first($settings['services']);
        if (! isset($settings['services'][$service])) {
            return;
        }
        $settings['services'][$service] = [
            'last_seen' => current_time('mysql', true),
            'agent'     => substr($agent, 0, 64),
            'host'      => substr($host, 0, 64),
            'printers'  => array_slice($printers, 0, 8),
            'queues'    => array_slice($queues, 0, 32),
        ] + $settings['services'][$service];
        self::store($settings);
    }

    /**
     * D-1336: every CUPS queue some print service has reported; null when a service reports none (before 0.5.0) and so
     * takes every job.
     *
     * @return list<string>|null
     */
    public static function servedQueues(): ?array
    {
        $queues = [];
        foreach (self::settings()['services'] as $service) {
            if ($service['last_seen'] !== '' && $service['queues'] === []) {
                return null;
            }
            array_push($queues, ...$service['queues']);
        }

        return array_values(array_unique($queues));
    }

    /**
     * @param array<string, mixed> $service
     * @return array{name: string, token_hash: string, last_seen: string, agent: string, host: string, printers: list<string>, queues: list<string>}
     */
    private static function service(array $service): array
    {
        return [
            'name'       => (string) ($service['name'] ?? ''),
            'token_hash' => (string) ($service['token_hash'] ?? ''),
            'last_seen'  => (string) ($service['last_seen'] ?? ''),
            'agent'      => (string) ($service['agent'] ?? ''),
            'host'       => (string) ($service['host'] ?? ''),
            'printers'   => array_values(array_map('strval', (array) ($service['printers'] ?? []))),
            'queues'     => array_values(array_map('strval', (array) ($service['queues'] ?? []))),
        ];
    }

    /** @param array<string, mixed> $settings */
    private static function store(array $settings): void
    {
        update_option(self::OPTION, ['topic' => $settings['topic'], 'wake' => $settings['wake'], 'services' => $settings['services']], false);
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
