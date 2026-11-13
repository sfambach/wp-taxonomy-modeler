<?php declare(strict_types=1);

namespace Taxmod\WordPress\Printing;

use Taxmod\Core\Printing\LabelContent;
use Taxmod\Core\Printing\LabelFormat;
use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Plugin;

/**
 * Two pages of printing, split like the API modules (D-1117): the «Printing» tab of the Configuration holds only the print
 * service's access — Access, «Test connection», «Delete access data» and the guide in the box (D-1085, D-1116); the label
 * formats with their test prints (D-1096) stand under Logistik › Druck › Etiketten (D-1115).
 *
 * In the Configuration the buttons submit the page's own form and arrive in `save`; under Logistik the page brings a form
 * of its own.
 *
 * @see docs/NewConcept/90-decision-log.md D-1113
 */
final class PrintTab
{
    public const SLUG = 'printing';

    /** The logistics module's filter for pages inside its navigation (wp_tax_stock, D-1085). */
    public const LOGISTICS_FILTER = 'taxmod_logistics_pages';


    /** admin-post action of the page's own form under Logistik. */
    public const ACTION = 'taxmod_print_service';

    /** The submit button's name; its value says what to do. */
    public const DO = 'taxmod_print_do';

    private const NONCE = '_taxmod_print_nonce';

    private const NEW_TOKEN = 'taxmod_print_token_';

    private const NEW_CODE = 'taxmod_print_code_';

    private const MESSAGE = 'taxmod_print_message_';

    /** D-1336: the name of a new print service, e.g. the computer it runs on. */
    private const SERVICE_NAME = 'taxmod_print_service_name';

    public static function boot(): void
    {
        add_filter(Plugin::CONFIG_TABS_FILTER, self::register(...), 2);
        // D-1165, his word 2026-11-04: «immer noch etiketten und etiketten vorlage» — the labels are records now, listed by the module
        // that owns them; the modeller announces no second page «Etiketten» with fixed formats.
        add_action('admin_post_' . self::ACTION, self::handle(...));
        // The logistics frame is another plugin's page: our dialogs need our stylesheet there ({@see Plugin::STYLE_ACTION}).
        add_action('admin_enqueue_scripts', static function (): void {
            $module = sanitize_text_field(wp_unslash((string) ($_GET['module'] ?? $_GET['page'] ?? '')));
            if ($module === PrintJobsScreen::PAGE) {
                do_action(Plugin::STYLE_ACTION);
            }
        });
    }

    /** admin-post from the page under Logistik: capability, nonce, then the same step as in the Configuration. */
    public static function handle(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You cannot change the printing settings.', 'taxmod'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION, self::NONCE);
        self::act(wp_unslash($_POST));
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    /**
     * One step of the page; capability and nonce are the caller's. The message waits for the next page view.
     *
     * @param array<string, mixed> $post unslashed
     */
    public static function act(array $post): void
    {
        $doing = sanitize_text_field((string) ($post[self::DO] ?? ''));
        $user = get_current_user_id();

        // D-1207: only the Configuration's form carries the switch; the page under Logistik leaves it as it is.
        if (isset($post[LabelButton::PREVIEW_OPTION])) {
            update_option(LabelButton::PREVIEW_OPTION, (string) $post[LabelButton::PREVIEW_OPTION] === '1' ? '1' : '0', false);
        }
        // D-1307: likewise only the Configuration's form carries the days.
        if (isset($post[PrintJobRetention::OPTION]) && is_numeric($post[PrintJobRetention::OPTION])) {
            PrintJobRetention::save(absint($post[PrintJobRetention::OPTION]));
        }

        // D-1336: one print service per computer, each with its own token.
        if ($doing === 'add') {
            try {
                [, $token] = PrintService::addService(substr(sanitize_text_field((string) ($post[self::SERVICE_NAME] ?? '')), 0, 64));
            } catch (\RuntimeException) {
                self::say(sprintf(/* translators: %d: number */ __('At most %d print services.', 'taxmod'), PrintService::MAX_SERVICES));

                return;
            }
            set_transient(self::NEW_TOKEN . $user, $token, 15 * MINUTE_IN_SECONDS);
            set_transient(self::NEW_CODE . $user, (string) PrintService::pairingCode((string) array_key_last(PrintService::settings()['services'])), 15 * MINUTE_IN_SECONDS);
            self::say(__('New print service added. Enter its pairing code on that computer now (or the JSON below); both are shown only this once.', 'taxmod'));

            return;
        }
        // D-1351: a one-time code to couple the print service from its own page.
        if (str_starts_with($doing, 'pair:')) {
            $code = PrintService::pairingCode(substr($doing, 5));
            if ($code !== null) {
                set_transient(self::NEW_CODE . $user, $code, 15 * MINUTE_IN_SECONDS);
                self::say(__('Pairing code created. Enter it in «Taxmod Druckdienst» on that computer within 15 minutes; it works once.', 'taxmod'));
            }

            return;
        }
        if (str_starts_with($doing, 'renew:')) {
            $token = PrintService::renewService(substr($doing, 6));
            if ($token !== null) {
                set_transient(self::NEW_TOKEN . $user, $token, 15 * MINUTE_IN_SECONDS);
                self::say(__('New access created. Enter it in the print service now; it is shown only this once. The old access no longer works.', 'taxmod'));
            }

            return;
        }
        if (str_starts_with($doing, 'remove:')) {
            if (PrintService::removeService(substr($doing, 7))) {
                self::say(__('Print service removed; its access no longer works.', 'taxmod'));
            }

            return;
        }
        if (str_starts_with($doing, 'test:')) {
            $formats = LabelFormat::shipped();
            $key = sanitize_key(substr($doing, 5));
            if (! isset($formats[$key]) || ! PrintService::isSetUp()) {
                self::say(__('No test print: unknown format or no print service set up.', 'taxmod'));

                return;
            }
            $id = (new PrintJobs())->queue($formats[$key], LabelContent::sample(), true);
            self::say(sprintf(/* translators: %d: job number */ __('Test print queued as job %d. The print service fetches it as soon as it is woken.', 'taxmod'), $id));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $tabs
     * @return array<string, array<string, mixed>>
     */
    public static function register(array $tabs): array
    {
        $tabs[self::SLUG] = [
            'title'   => __('Printing', 'taxmod'),
            'version' => \Taxmod\WordPress\Plugin::VERSION,
            // His word 2026-11-03: «ich würde auch hier trennen die einstellungen bleiben im admin config die label haben wir ja jetzt im logistik menü» (D-1117).
            'render'  => static fn (): string => self::previewSetting() . self::retentionSetting() . '<p class="description">' . esc_html__('Labels with preview and test print: Logistik › Druck › Etiketten; the jobs: Logistik › Druck › Aufträge.', 'taxmod')
                . ' <a href="' . esc_url(PrintJobsScreen::url()) . '">' . esc_html__('Print jobs', 'taxmod') . '</a></p>',
            'credentials' => static fn (): string => (new self())->servicePart(),
            'test'    => self::status(...),
            'forget'  => PrintService::revokeAccess(...),
            'save'    => self::act(...),
            // His word 2026-11-03: «Die anleitung gehört eigentlich in eine box in das config modul» (D-1116).
            'guide'   => self::guide(...),
        ];

        return $tabs;
    }

    /** D-1307: «Druckaufträge löschen nach … Tagen», 7 by default, 0 never. */
    private static function retentionSetting(): string
    {
        return '<p><label>' . esc_html__('Erledigte Druckaufträge löschen nach', 'taxmod')
            . ' <input type="number" class="small-text" min="0" max="' . esc_attr((string) PrintJobRetention::MAX_DAYS) . '" step="1" name="' . esc_attr(PrintJobRetention::OPTION) . '" value="' . esc_attr((string) PrintJobRetention::days()) . '"> '
            . esc_html__('Tagen (0 = nie)', 'taxmod') . '</label></p>';
    }

    /** D-1207: «Vorschau im Druckdialog», on by default. */
    private static function previewSetting(): string
    {
        return '<p><input type="hidden" name="' . esc_attr(LabelButton::PREVIEW_OPTION) . '" value="0"><label><input type="checkbox" name="' . esc_attr(LabelButton::PREVIEW_OPTION) . '" value="1"'
            . checked(LabelButton::previewShown(), true, false) . '> ' . esc_html__('Vorschau des Etiketts im Druckdialog zeigen', 'taxmod') . '</label></p>';
    }

    /** One sentence per print service: not set up, not heard of yet, or when it was last heard of, with what. Also «Test connection». */
    public static function status(): string
    {
        $services = PrintService::settings()['services'];
        if ($services === []) {
            return __('Print service not set up. Labels print through the browser.', 'taxmod');
        }

        return implode(' · ', array_map(static fn (array $service): string => self::serviceState($service), $services));
    }

    /** @param array{name: string, last_seen: string, agent: string, host: string, printers: list<string>, queues: list<string>} $service */
    private static function serviceState(array $service): string
    {
        return $service['last_seen'] === ''
            ? sprintf(/* translators: %s: name */ __('%s: set up, not heard of yet.', 'taxmod'), PrintService::nameOf($service))
            : sprintf(
                /* translators: 1: name, 2: date and time UTC, 3: service version, 4: printers */
                __('%1$s: last heard of %2$s UTC · %3$s · printers: %4$s', 'taxmod'),
                PrintService::nameOf($service),
                $service['last_seen'],
                $service['agent'] !== '' ? $service['agent'] : '?',
                $service['queues'] !== [] ? implode(', ', $service['queues']) : ($service['printers'] !== [] ? implode(', ', $service['printers']) : '—')
            );
    }

    /** The print services, one row each with «New access» and «Remove», «Add print service», and the JSON once after either. */
    private function servicePart(): string
    {
        $settings = PrintService::settings();
        $token = (string) get_transient(self::NEW_TOKEN . get_current_user_id());
        if ($token !== '') {
            delete_transient(self::NEW_TOKEN . get_current_user_id());
        }

        $code = (string) get_transient(self::NEW_CODE . get_current_user_id());
        if ($code !== '') {
            delete_transient(self::NEW_CODE . get_current_user_id());
        }

        $rows = '';
        foreach ($settings['services'] as $key => $service) {
            $name = PrintService::nameOf($service);
            $rows .= '<tr><td>' . esc_html(self::serviceState($service)) . '</td><td style="text-align:right;white-space:nowrap">'
                . ListPage::dialogAction(
                    'taxmod-print-pair-' . $key,
                    __('Pairing code', 'taxmod'),
                    sprintf(/* translators: %s: name */ __('Pairing code for %s', 'taxmod'), $name),
                    esc_html__('A code for the page «Taxmod Druckdienst» on that computer, valid 15 minutes and once. Pairing gives the service a new token; its old one stops working.', 'taxmod'),
                    self::DO,
                    'pair:' . $key,
                    __('Create code', 'taxmod')
                ) . ' ' . ListPage::dialogAction(
                    'taxmod-print-renew-' . $key,
                    __('New access', 'taxmod'),
                    sprintf(/* translators: %s: name */ __('New access for %s', 'taxmod'), $name),
                    esc_html__('A new token is made and shown once. This print service stops at once until it gets the new one; the others go on.', 'taxmod'),
                    self::DO,
                    'renew:' . $key,
                    __('Create access', 'taxmod')
                ) . ' ' . ListPage::dialogAction(
                    'taxmod-print-remove-' . $key,
                    __('Remove', 'taxmod'),
                    sprintf(/* translators: %s: name */ __('Remove %s', 'taxmod'), $name),
                    esc_html__('Its access stops working at once. Jobs for its printers wait until another print service serves them.', 'taxmod'),
                    self::DO,
                    'remove:' . $key,
                    __('Remove', 'taxmod')
                ) . '</td></tr>';
        }
        $add = ListPage::dialogAction(
            'taxmod-print-add',
            __('Add print service', 'taxmod'),
            __('New print service', 'taxmod'),
            esc_html__('One print service per computer with printers. It fetches only the jobs for the printers CUPS has on that computer.', 'taxmod')
                . '<br><label>' . esc_html__('Name, e.g. the computer', 'taxmod') . ' <input type="text" name="' . esc_attr(self::SERVICE_NAME) . '" maxlength="64"></label>',
            self::DO,
            'add',
            __('Create access', 'taxmod')
        );

        $config = $token === '' ? '' : '<p><strong>' . esc_html__('For the print service — copy into its config.json now:', 'taxmod') . '</strong></p>'
            . '<textarea readonly rows="7" class="large-text code" onclick="this.select()">' . esc_textarea((string) wp_json_encode([
                'rest'     => rest_url(PrintRest::ROUTES . '/'),
                'token'    => $token,
                'ntfy'     => PrintService::NTFY_SERVER . '/' . $settings['topic'],
                'wake_url' => PrintService::wakeUrl(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</textarea>';

        $pairing = $code === '' ? '' : '<p><strong>' . esc_html__('Pairing code — enter it in «Taxmod Druckdienst» on that computer within 15 minutes:', 'taxmod') . '</strong> '
            . '<code style="font-size:1.4em;letter-spacing:.1em">' . esc_html($code) . '</code></p>';

        return self::message() . $pairing
            . '<table class="widefat striped taxmod-print-service"><tbody>' . $rows
            . '<tr><td>' . ($rows === '' ? esc_html(self::status()) : '') . '</td><td style="text-align:right;white-space:nowrap">' . $add . '</td></tr>'
            . '</tbody></table>' . $config;
    }

    /** The setup of the print service at home, step by step, in the box beside the tab (D-963, D-1116). */
    public static function guide(): string
    {
        $code = static fn (string $text): string => '<pre style="white-space:pre-wrap;font-size:.85em;background:#f6f7f7;padding:.5em">' . esc_html($text) . '</pre>';
        $release = 'https://github.com/sfambach/taxmod_printservice/releases/latest';
        $steps = [
            esc_html__('Labels print through the browser without any of this. The print service is a small program on a Linux PC with CUPS: it fetches the jobs and prints them with the right size and orientation, without a print dialog. Each PC with printers runs its own and fetches only the jobs for its own printers (D-1336).', 'taxmod'),
            esc_html__('Click «Add print service» below and name it after the PC. It shows a pairing code (and the JSON for config.json), each only once; never post the token in a chat.', 'taxmod'),
            sprintf(esc_html__('On the PC, install the package from %s:', 'taxmod'), '<a href="' . esc_url($release) . '">taxmod_printservice</a>') . $code("sudo apt install ./taxmod-printservice_<version>_all.deb\ntaxmod-printservice setup"),
            esc_html__('Open «Taxmod Druckdienst» from the menu (on a computer without a desktop: taxmod-printservice pair https://… CODE) and enter the pairing code from step 2. Then run taxmod-printservice setup once: it starts the service and keeps it running without a login.', 'taxmod'),
            esc_html__('The printers, their CUPS queues and options stand in the model (Logistik › Drucker). A job goes to the PC whose CUPS has its queue; a queue no service reports is marked in the job list.', 'taxmod'),
            esc_html__('Log: journalctl --user -u taxmod-printservice -f. Update: taxmod-printservice update (asks for the Linux password).', 'taxmod'),
        ];

        return '<ol>' . implode('', array_map(static fn (string $step): string => '<li>' . $step . '</li>', $steps)) . '</ol>';
    }

    private static function say(string $message): void
    {
        set_transient(self::MESSAGE . get_current_user_id(), $message, MINUTE_IN_SECONDS);
    }

    private static function message(): string
    {
        $message = (string) get_transient(self::MESSAGE . get_current_user_id());
        if ($message === '') {
            return '';
        }
        delete_transient(self::MESSAGE . get_current_user_id());

        return '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>';
    }

    public static function formatName(LabelFormat $format): string
    {
        return match ($format->key) {
            'roll62'  => __('Place and box label, 62 mm roll (QL-810)', 'taxmod'),
            'roll102' => __('Place and box label, 102 mm roll (QL-1100)', 'taxmod'),
            'holder62' => __('Place label for the label holders, 62 mm roll', 'taxmod'),
            'material62' => __('Small label for materials, places and drawers, 62 mm roll', 'taxmod'),
            default   => $format->key,
        };
    }

    public static function size(LabelFormat $format): string
    {
        return sprintf('%s × %s mm', self::millimetres($format->widthMm), self::millimetres($format->heightMm));
    }

    private static function millimetres(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
