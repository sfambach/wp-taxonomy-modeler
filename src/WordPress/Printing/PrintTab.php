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

    public const LOGISTICS_SLUG = 'taxmod-printing';

    /** admin-post action of the page's own form under Logistik. */
    public const ACTION = 'taxmod_print_service';

    /** The submit button's name; its value says what to do. */
    public const DO = 'taxmod_print_do';

    private const NONCE = '_taxmod_print_nonce';

    private const NEW_TOKEN = 'taxmod_print_token_';

    private const MESSAGE = 'taxmod_print_message_';

    public static function boot(): void
    {
        add_filter(\Taxmod\WordPress\Admin\SettingsScreen::TABS_FILTER, self::register(...), 2);
        // His word 2026-11-02: «hänge das aber auch nochmal ins logistik menüs einstellungen -> druck, gezeigt auf der rechten seite»;
        // 2026-11-03 its own area: «Würde menü druck machen dann unterpunkte aufträge, etiketten, ...» (D-1115).
        add_filter(self::LOGISTICS_FILTER, self::registerInLogistics(...));
        add_action('admin_post_' . self::ACTION, self::handle(...));
        // The logistics frame is another plugin's page: our dialogs need our stylesheet there ({@see Plugin::STYLE_ACTION}).
        add_action('admin_enqueue_scripts', static function (): void {
            $module = sanitize_text_field(wp_unslash((string) ($_GET['module'] ?? $_GET['page'] ?? '')));
            if (in_array($module, [self::LOGISTICS_SLUG, PrintJobsScreen::PAGE], true)) {
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

        if ($doing === 'renew') {
            set_transient(self::NEW_TOKEN . $user, PrintService::renewAccess(), 15 * MINUTE_IN_SECONDS);
            self::say(__('New access created. Enter it in the print service now; it is shown only this once. The old access no longer works.', 'taxmod'));

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
     * The label formats with their test prints under Logistik › Druck › Etiketten, drawn inside the logistics frame (D-1103, D-1115, D-1117).
     *
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    public static function registerInLogistics(array $pages): array
    {
        $pages[] = [
            'slug'   => self::LOGISTICS_SLUG,
            'label'  => __('Etiketten', 'taxmod'),
            'area'   => 'print',
            'render' => static fn (): string => '<div class="wrap"><h1>' . esc_html__('Etiketten', 'taxmod') . '</h1>'
                . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
                . wp_nonce_field(self::ACTION, self::NONCE, true, false) . (new self())->render()
                . '<p class="description">' . esc_html(self::status()) . ' <a href="' . esc_url(self::configurationUrl()) . '">' . esc_html__('Access of the print service', 'taxmod') . '</a></p></form></div>',
        ];

        return $pages;
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
            'render'  => static fn (): string => '<p class="description">' . esc_html__('Label formats and test prints: Logistik › Druck › Etiketten; the jobs: Logistik › Druck › Aufträge.', 'taxmod')
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

    public function render(): string
    {
        $service = PrintService::isSetUp();
        $rows = '';
        foreach (LabelFormat::shipped() as $format) {
            $rows .= '<tr><td>' . esc_html(self::formatName($format)) . '</td>'
                . '<td>' . esc_html(sprintf('%s × %s mm', self::millimetres($format->widthMm), self::millimetres($format->heightMm))) . '</td>'
                . '<td>' . esc_html(sprintf('%s mm', self::millimetres($format->rollMm))) . '</td>'
                . '<td class="taxmod-actions" style="text-align:right"><a class="button" target="_blank" rel="noopener" href="' . esc_url(LabelPrint::url($format->key)) . '">'
                . esc_html__('Test print', 'taxmod') . '</a>'
                . ($service ? ' <button type="submit" class="button" name="' . esc_attr(self::DO) . '" value="' . esc_attr('test:' . $format->key) . '">' . esc_html__('Test via print service', 'taxmod') . '</button>' : '')
                . '</td></tr>';
        }

        return self::message()
            . '<p class="description">' . esc_html__('«Test print» opens the label as PDF in a new tab, to print from the browser. «Test via print service» sends it to the print service at home. Test prints use made-up values and change nothing.', 'taxmod') . '</p>'
            . '<table class="widefat striped taxmod-print-formats"><caption class="screen-reader-text">' . esc_html__('Label formats', 'taxmod') . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('Label', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('Size', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('Roll width', 'taxmod') . '</th>'
            . '<th scope="col" style="text-align:right">' . esc_html__('Actions', 'taxmod') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /** One sentence on the print service: not set up, not heard of yet, or when it was last heard of, with what. Also «Test connection». */
    public static function status(): string
    {
        $settings = PrintService::settings();

        return $settings['token_hash'] === ''
            ? __('Print service not set up. Labels print through the browser.', 'taxmod')
            : ($settings['last_seen'] === ''
                ? __('Print service set up, not heard of yet.', 'taxmod')
                : sprintf(
                    /* translators: 1: date and time UTC, 2: service version, 3: printers */
                    __('Print service last heard of %1$s UTC · %2$s · printers: %3$s', 'taxmod'),
                    $settings['last_seen'],
                    $settings['agent'] !== '' ? $settings['agent'] : '?',
                    $settings['printers'] !== [] ? implode(', ', $settings['printers']) : '—'
                ));
    }

    private static function configurationUrl(): string
    {
        return add_query_arg(['page' => 'taxmod-settings', 'tab' => self::SLUG], admin_url('admin.php'));
    }

    /** The print service's access: its state, «Set up» / «Renew», and the JSON for its config once after renewing. */
    private function servicePart(): string
    {
        $settings = PrintService::settings();
        $token = (string) get_transient(self::NEW_TOKEN . get_current_user_id());
        if ($token !== '') {
            delete_transient(self::NEW_TOKEN . get_current_user_id());
        }
        $state = esc_html(self::status());

        $renew = ListPage::dialogAction(
            'taxmod-print-renew',
            $settings['token_hash'] === '' ? __('Set up access', 'taxmod') : __('Renew access', 'taxmod'),
            __('New access for the print service', 'taxmod'),
            esc_html__('A new token, push topic and wake file are made and shown once. A print service with the old access stops at once until it gets the new one.', 'taxmod'),
            self::DO,
            'renew',
            __('Create access', 'taxmod')
        );

        $config = $token === '' ? '' : '<p><strong>' . esc_html__('For the print service — copy into its config.json now:', 'taxmod') . '</strong></p>'
            . '<textarea readonly rows="7" class="large-text code" onclick="this.select()">' . esc_textarea((string) wp_json_encode([
                'rest'     => rest_url(PrintRest::ROUTES . '/'),
                'token'    => $token,
                'ntfy'     => PrintService::NTFY_SERVER . '/' . $settings['topic'],
                'wake_url' => PrintService::wakeUrl(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</textarea>';

        return self::message()
            . '<table class="widefat striped taxmod-print-service"><tbody><tr>'
            . '<td>' . $state . '</td>'
            . '<td style="text-align:right;white-space:nowrap">' . $renew . '</td>'
            . '</tr></tbody></table>' . $config;
    }

    /** The setup of the print service at home, step by step, in the box beside the tab (D-963, D-1116). */
    public static function guide(): string
    {
        $raw = 'https://raw.githubusercontent.com/sfambach/wp-taxonomy-modeler/main/tools/print-agent';
        $code = static fn (string $text): string => '<pre style="white-space:pre-wrap;font-size:.85em;background:#f6f7f7;padding:.5em">' . esc_html($text) . '</pre>';
        $steps = [
            esc_html__('Labels print through the browser without any of this. The print service is a small program on a PC at home with CUPS: it fetches the jobs and prints them with the right size and orientation, without a print dialog.', 'taxmod'),
            esc_html__('Click «Set up access» below and copy the JSON it shows. It is shown only once; never post the token in a chat.', 'taxmod'),
            esc_html__('On the PC, fetch the program:', 'taxmod') . $code("mkdir -p ~/.local/share/taxmod-print-agent ~/.config/taxmod-print-agent ~/.config/systemd/user\ncd ~/.local/share/taxmod-print-agent\ncurl -fsSLO {$raw}/taxmod_print_agent.py\ncurl -fsSL {$raw}/taxmod-print-agent.service -o ~/.config/systemd/user/taxmod-print-agent.service"),
            esc_html__('Create ~/.config/taxmod-print-agent/config.json: the four lines from step 2, then the CUPS queue per roll width and, where the queue names a size differently, its PageSize (lpoptions -p <queue> -l | grep PageSize). Then chmod 600 on the file.', 'taxmod')
                . $code("{\n  \"rest\": \"…\", \"token\": \"…\", \"ntfy\": \"…\", \"wake_url\": \"…\",\n  \"printers\": {\"62\": \"QL-810W_take\", \"102\": \"QL-1100\"},\n  \"formats\": {\"holder62\": {\"PageSize\": \"BrL053E03262D56\", \"orientation-requested\": \"4\"}}\n}"),
            esc_html__('Start it, and keep it running without a login:', 'taxmod') . $code("systemctl --user daemon-reload\nsystemctl --user enable --now taxmod-print-agent\nloginctl enable-linger \"\$USER\""),
            esc_html__('Click «Test via print service» at a format. The status above shows when the service was last heard of; the job list shows each job with the message of CUPS. Log: journalctl --user -u taxmod-print-agent -f', 'taxmod'),
            esc_html__('After «Renew access» enter the new values and restart: systemctl --user restart taxmod-print-agent. Run only one print service per access; moving it to another PC means stopping it on the old one (systemctl --user disable --now taxmod-print-agent).', 'taxmod'),
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

    private static function formatName(LabelFormat $format): string
    {
        return match ($format->key) {
            'roll62'  => __('Place and box label, 62 mm roll (QL-810)', 'taxmod'),
            'roll102' => __('Place and box label, 102 mm roll (QL-1100)', 'taxmod'),
            'holder62' => __('Place label for the label holders, 62 mm roll', 'taxmod'),
            'material62' => __('Material label, 62 mm roll', 'taxmod'),
            default   => $format->key,
        };
    }

    private static function millimetres(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
