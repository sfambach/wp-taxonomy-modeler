<?php declare(strict_types=1);

namespace Taxmod\WordPress\Printing;

use Taxmod\Core\Printing\PrintJobStatus;
use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Admin\Tiles;
use Taxmod\WordPress\Plugin;

/**
 * The list of print jobs in the shared list template (D-989): what waits, what the service took, what it printed or
 * could not. «Cancel» and «Print again» undo each other (every step undoable, his rule of 2026-10-28). Under the Taxmod
 * menu, and inside the logistics navigation when the stock module runs (D-1085).
 *
 * @see docs/NewConcept/90-decision-log.md D-1113
 */
final class PrintJobsScreen
{
    public const PAGE = 'taxmod-print-jobs';

    /** The nonce of every job action; the admin-post actions are this plus «_cancel» or «_again». */
    public const ACTION = 'taxmod_print_jobs';

    private const DOINGS = ['cancel', 'again'];

    private const FORM = 'taxmod-print-jobs-bulk';

    private const LIMIT = 200;

    /** The filter of the tile «Active»: waiting or fetched, not yet answered. */
    private const ACTIVE = 'active';

    public static function boot(): void
    {
        add_action('admin_menu', static function (): void {
            add_submenu_page('taxmod', __('Print jobs', 'taxmod'), __('Print jobs', 'taxmod'), Plugin::CAPABILITY, self::PAGE, static function (): void {
                echo (new self(new PrintJobs()))->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes its parts.
            });
        }, 20);
        foreach (self::DOINGS as $doing) {
            add_action('admin_post_' . self::ACTION . '_' . $doing, static fn () => (new self(new PrintJobs()))->handle($doing));
        }
        // Before «Etiketten» in the area «Druck» (D-1115).
        add_filter(PrintTab::LOGISTICS_FILTER, self::registerInLogistics(...), 5);
    }

    public function __construct(private readonly PrintJobs $jobs)
    {
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    public static function registerInLogistics(array $pages): array
    {
        $pages[] = [
            'slug'   => self::PAGE,
            'label'  => __('Aufträge', 'taxmod'),
            'area'   => 'print',
            'render' => static fn (): string => (new self(new PrintJobs()))->render(),
        ];

        return $pages;
    }

    public static function url(): string
    {
        return admin_url('admin.php?page=' . self::PAGE);
    }

    public function render(): string
    {
        $status = sanitize_key((string) ($_GET['status'] ?? ''));
        $status = $status === self::ACTIVE ? $status : (PrintJobStatus::tryFrom($status)?->value ?? '');
        $counts = $this->jobs->counts();
        $rows = '';

        foreach ($this->jobs->latest(self::LIMIT, ...($status === self::ACTIVE ? self::activeStatuses() : [$status])) as $job) {
            $rows .= $this->row($job);
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="9">' . esc_html__('No print jobs.', 'taxmod') . '</td></tr>';
        }

        $active = array_sum(array_map(static fn (string $s): int => $counts[$s] ?? 0, self::activeStatuses()));
        $options = ['' => __('All statuses', 'taxmod'), self::ACTIVE => __('Active', 'taxmod') . ' (' . $active . ')'];
        foreach (PrintJobStatus::cases() as $case) {
            $options[$case->value] = self::statusLabel($case) . ' (' . ($counts[$case->value] ?? 0) . ')';
        }

        $search = '<form method="get" id="taxmod-print-jobs-filter" action="' . esc_url(admin_url('admin.php')) . '">'
            . '<input type="hidden" name="page" value="' . esc_attr(sanitize_key((string) ($_GET['page'] ?? self::PAGE))) . '">'
            . (isset($_GET['module']) ? '<input type="hidden" name="module" value="' . esc_attr(sanitize_key((string) $_GET['module'])) . '">' : '')
            . '</form>';

        return ListPage::page(__('Print jobs', 'taxmod'), $this->tiles($counts, $active), [['html' => self::notice()
            . '<p class="description">' . esc_html__('Jobs for the print service at home. A job waits until the service fetches it; «Print again» sends a printed, failed or cancelled job once more.', 'taxmod') . '</p>'
            . $search
            . ListPage::bulkBar(self::FORM, self::ACTION, [
                self::ACTION . '_cancel' => __('Cancel', 'taxmod'),
                self::ACTION . '_again'  => __('Print again', 'taxmod'),
            ], sprintf(/* translators: %d: number of jobs */ _n('%d job', '%d jobs', array_sum($counts), 'taxmod'), array_sum($counts)),
                ListPage::filters('taxmod-print-jobs-filter', [['status', $options, $status, __('Status', 'taxmod')]]))
            . ListPage::table([
                [__('No.', 'taxmod'), '5em'],
                [__('Format', 'taxmod'), '9em'],
                [__('Code', 'taxmod'), '10em'],
                [__('Title', 'taxmod')],
                [__('Status', 'taxmod'), '9em'],
                [__('Queued', 'taxmod'), '11em'],
                [__('Message', 'taxmod')],
            ], $rows)]]);
    }

    /**
     * D-1198, his word 2026-11-06: «druck auftäge oben wie überall tiles aufträge aktive, gedruckt, fehler mit anzahl» —
     * a click filters the list below.
     *
     * @param array<string, int> $counts
     * @return list<\Taxmod\Core\Tile\Tile>
     */
    private function tiles(array $counts, int $active): array
    {
        $filter = static fn (string $status): string => add_query_arg('status', $status, remove_query_arg(['taxmod_print_done', 'taxmod_print_n']));
        $failed = $counts[PrintJobStatus::Failed->value] ?? 0;

        return [
            Tiles::counted(__('Active', 'taxmod'), $active, $filter(self::ACTIVE)),
            Tiles::counted(__('Printed', 'taxmod'), $counts[PrintJobStatus::Printed->value] ?? 0, $filter(PrintJobStatus::Printed->value)),
            Tiles::counted(__('Failed', 'taxmod'), $failed, $filter(PrintJobStatus::Failed->value), [], $failed > 0),
        ];
    }

    /** @return list<string> */
    private static function activeStatuses(): array
    {
        return [PrintJobStatus::Queued->value, PrintJobStatus::Claimed->value, PrintJobStatus::Sent->value];
    }

    /** admin-post: a bulk action on `selected[]`, or one row's button on `job`. */
    public function handle(string $doing): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You cannot manage print jobs.', 'taxmod'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);

        $ids = isset($_REQUEST['job'])
            ? [absint(wp_unslash((string) $_REQUEST['job']))]
            : array_map('absint', (array) wp_unslash($_POST['selected'] ?? []));
        $done = $doing === 'cancel' ? $this->jobs->cancel($ids) : $this->jobs->queueAgain($ids);

        wp_safe_redirect(add_query_arg(['taxmod_print_done' => $doing, 'taxmod_print_n' => $done], wp_get_referer() ?: self::url()));
        exit;
    }

    /**
     * @param array<string, string> $job
     */
    private function row(array $job): string
    {
        $status = PrintJobStatus::tryFrom($job['status']) ?? PrintJobStatus::Failed;
        $buttons = '';
        if ($status->canBeCancelled()) {
            $buttons .= $this->button((int) $job['id'], 'cancel', __('Cancel', 'taxmod'));
        }
        if ($status->canBeQueuedAgain()) {
            $buttons .= $this->button((int) $job['id'], 'again', __('Print again', 'taxmod'));
        }

        $details = '<dl style="display:grid;grid-template-columns:max-content 1fr;gap:.25em 1em;margin:.5em 0">'
            . '<dt>' . esc_html__('Second line', 'taxmod') . '</dt><dd>' . esc_html($job['subtitle'] !== '' ? $job['subtitle'] : '—') . '</dd>'
            . '<dt>' . esc_html__('Printer queue', 'taxmod') . '</dt><dd>' . esc_html(($job['queue'] ?? '') !== '' ? $job['queue'] : '—') . '</dd>'
            . '<dt>' . esc_html__('Fetched by the service', 'taxmod') . '</dt><dd>' . esc_html($job['claimed_at'] !== '' ? $job['claimed_at'] . ' UTC' . (($job['claimed_by'] ?? '') !== '' ? ' · ' . $job['claimed_by'] : '') : '—') . '</dd>'
            . '<dt>' . esc_html__('Finished', 'taxmod') . '</dt><dd>' . esc_html($job['finished_at'] !== '' ? $job['finished_at'] . ' UTC' : '—') . '</dd>'
            . '</dl>';

        return ListPage::row(self::FORM, $job['id'], [
            esc_html($job['id']),
            esc_html($job['format']),
            esc_html($job['code']),
            esc_html($job['title']) . ($job['is_test'] === '1' ? ' <span class="description">(' . esc_html__('test', 'taxmod') . ')</span>' : ''),
            esc_html(self::statusLabel($status)) . self::unserved($status, (string) ($job['queue'] ?? '')),
            esc_html($job['created_at'] . ' UTC'),
            esc_html($job['message']),
        ], $buttons, $details);
    }

    /** D-1336: a waiting job for a queue no print service reports would wait for ever; the list says so. */
    private static function unserved(PrintJobStatus $status, string $queue): string
    {
        $served = PrintService::servedQueues();
        if ($status !== PrintJobStatus::Queued || $queue === '' || $served === null || ! PrintService::isSetUp() || in_array($queue, $served, true)) {
            return '';
        }

        return '<br><span class="description" style="color:#b32d2e">' . esc_html__('no print service for this queue', 'taxmod') . '</span>';
    }

    private function button(int $id, string $doing, string $label): string
    {
        $url = wp_nonce_url(add_query_arg(['action' => self::ACTION . '_' . $doing, 'job' => $id], admin_url('admin-post.php')), self::ACTION);

        return '<a class="button" href="' . esc_url($url) . '">' . esc_html($label) . '</a> ';
    }

    public static function statusLabel(PrintJobStatus $status): string
    {
        return match ($status) {
            PrintJobStatus::Queued    => __('Waiting', 'taxmod'),
            PrintJobStatus::Claimed   => __('Fetched', 'taxmod'),
            PrintJobStatus::Sent      => __('At the printer', 'taxmod'),
            PrintJobStatus::Printed   => __('Printed', 'taxmod'),
            PrintJobStatus::Failed    => __('Failed', 'taxmod'),
            PrintJobStatus::Cancelled => __('Cancelled', 'taxmod'),
        };
    }

    private static function notice(): string
    {
        $done = sanitize_key((string) ($_GET['taxmod_print_done'] ?? ''));
        if ($done === '') {
            return '';
        }
        $n = (int) ($_GET['taxmod_print_n'] ?? 0);
        $text = match ($done) {
            'cancel' => sprintf(/* translators: %d: number */ __('%d job(s) cancelled. «Print again» undoes it.', 'taxmod'), $n),
            'again'  => sprintf(/* translators: %d: number */ __('%d job(s) queued again.', 'taxmod'), $n),
            default  => '',
        };
        if ($text === '') {
            return '';
        }

        return '<div class="notice notice-' . ($n > 0 ? 'success' : 'warning') . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }
}
