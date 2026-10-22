<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\WordPress\ModelAbilities;
use Taxmod\WordPress\Persistence\Backup;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Plugin;

/**
 * Änderungslisten als Datei einspielen — eine `.json` oder ein `.zip` voller `.json`, jede Datei eine Handlung wie `taxmod/apply` ([D-973](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart LR
 *   U["Datei hochladen"] --> L["Listen nach Dateiname"]
 *   L --> E{"schon eingespielt?"}
 *   E -- ja --> S["übersprungen, ihre Refs gelten"]
 *   E -- nein --> A["ModelAbilities::applyChangeList"] --> M["als eingespielt vermerkt"]
 *   A -. Zeit um .-> R["Datei bleibt liegen, die Seite macht weiter"]
 * ```
 *
 * ⚠️ **Wiederholbar statt lang.** *Ein grosses Paket braucht länger, als ein Webhoster einer Anfrage gibt. Darum ist jede
 * Datei für sich eine Transaktion, eine eingespielte wird an ihrem Fingerabdruck erkannt und übersprungen, und nach
 * {@see self::TIME_BUDGET} Sekunden beginnt keine neue mehr. Die Datei bleibt im geschützten Sicherungsordner liegen, und
 * die Seite schickt sich selbst wieder ab, bis alles drin ist.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class ChangeListImport
{
    public const ACTION = 'taxmod_change_list_import';

    /** Fingerabdruck einer eingespielten Liste → Name, Zeitpunkt, die Refs, die sie angelegt hat. */
    public const DONE_OPTION = 'taxmod_change_lists_done';

    /** Nach so vielen Sekunden beginnt keine neue Datei mehr. */
    private const TIME_BUDGET = 40;

    private const REPORT = 'taxmod_change_list_report_';

    /** Die hochgeladene Datei, solange noch Listen fehlen — je Benutzer. */
    private const PENDING = 'taxmod_change_list_pending_';

    /** @param \Closure(): ModelAbilities $abilities */
    public function __construct(private readonly \Closure $abilities)
    {
    }

    public function render(): string
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            return '';
        }

        return '<h2>' . esc_html__('Import change lists', 'taxmod') . '</h2>'
            . $this->report()
            . '<p>' . esc_html__('A .json file with a list of changes as taxmod/apply takes it, or a .zip with several of them. The files run in the order of their names, each as one act. A file that already ran is skipped. A large upload runs in steps; the page continues by itself until everything is in. The current state is saved first into the folder uploads/taxmod-backups.', 'taxmod') . '</p>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . wp_nonce_field(self::ACTION, '_taxmod_nonce', true, false)
            . '<p><input type="file" name="taxmod_change_list" accept=".json,.zip,application/json,application/zip" required></p>'
            . get_submit_button(__('Import change lists', 'taxmod'), 'primary', 'submit', false)
            . '</form>'
            . $this->continueForm();
    }

    private function continueForm(): string
    {
        $pending = get_transient(self::PENDING . get_current_user_id());

        if (! is_array($pending)) {
            return '';
        }

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="taxmod-change-list-continue">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="taxmod_continue" value="1">'
            . wp_nonce_field(self::ACTION, '_taxmod_nonce', true, false)
            . '<p>' . esc_html(sprintf(
                /* translators: %s: file name */
                __('%s is not finished yet. The page continues by itself.', 'taxmod'),
                (string) $pending['name']
            )) . '</p>'
            . get_submit_button(__('Continue', 'taxmod'), 'secondary', 'submit', false)
            . '</form>'
            . '<script>setTimeout(function () { document.getElementById("taxmod-change-list-continue").submit(); }, 1500);</script>';
    }

    public function handle(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You cannot import change lists.', 'taxmod'), '', ['response' => 403]);
        }

        check_admin_referer(self::ACTION, '_taxmod_nonce');

        $pending = get_transient(self::PENDING . get_current_user_id());
        $weiter  = isset($_POST['taxmod_continue']) && is_array($pending) && is_readable((string) ($pending['file'] ?? ''));

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- eine Datei; geprüft werden Fehlercode und Herkunft.
        $upload = $_FILES['taxmod_change_list'] ?? null;

        if (! $weiter && (! is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ! is_uploaded_file((string) $upload['tmp_name']))) {
            $this->redirectWith(['error' => __('No file arrived. The file may be larger than the server accepts (upload_max_filesize).', 'taxmod')]);
        }

        if ((int) get_option(Schema::VERSION_OPTION, 0) !== Schema::VERSION) {
            $this->redirectWith(['error' => __('The model is not ready. Open the Taxonomy Modeller once, then try again.', 'taxmod')]);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(self::TIME_BUDGET + 60);
        }

        $file = $weiter ? (string) $pending['file'] : (string) $upload['tmp_name'];
        $name = $weiter ? (string) $pending['name'] : sanitize_file_name((string) ($upload['name'] ?? ''));

        try {
            $report = $this->run($this->lists($file, $name), ! $weiter);
        } catch (\Throwable $e) {
            $report = ['error' => $e->getMessage()];
        }

        $this->keepForLater($report, $file, $name, $weiter);
        $this->redirectWith($report);
    }

    /**
     * ⚠️ *Was nicht fertig wurde, bleibt im geschützten Sicherungsordner liegen, und die Seite macht von selbst weiter —
     * niemand muss dieselbe Datei zehnmal hochladen.*
     *
     * @param array<string, mixed> $report
     */
    private function keepForLater(array $report, string $file, string $name, bool $weiter): void
    {
        $key = self::PENDING . get_current_user_id();

        if (isset($report['error']) || (int) ($report['remaining'] ?? 0) === 0) {
            if ($weiter) {
                @unlink($file);
            }

            delete_transient($key);

            return;
        }

        if ($weiter) {
            return;
        }

        $dir = trailingslashit(wp_upload_dir()['basedir']) . Backup::SAFETY_DIR;

        if (! wp_mkdir_p($dir)) {
            return;
        }

        if (! file_exists($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }

        $ziel = $dir . '/aenderungsliste-' . sha1_file($file) . (str_ends_with(strtolower($name), '.zip') ? '.zip' : '.json');

        if (move_uploaded_file($file, $ziel)) {
            set_transient($key, ['file' => $ziel, 'name' => $name], DAY_IN_SECONDS);
        }
    }

    /**
     * Spielt ein, was noch fehlt — in der Reihenfolge der Namen, bis die Zeit um ist.
     *
     * @param array<string, string> $lists Dateiname → Inhalt
     * @param bool $safetyCopy Vor der ersten Datei das ganze Modell sichern — der Wächter schaltet es ab, er schreibt nichts.
     * @return array<string, mixed>
     */
    public function run(array $lists, bool $safetyCopy = true): array
    {
        $done    = get_option(self::DONE_OPTION, []);
        $done    = is_array($done) ? $done : [];
        $refs    = [];
        $applied = [];
        $skipped = 0;
        $start   = microtime(true);
        $safety  = null;

        ksort($lists, SORT_NATURAL);

        foreach ($lists as $name => $json) {
            $key = sha1($json);

            if (isset($done[$key])) {
                $refs += is_array($done[$key]['refs'] ?? null) ? $done[$key]['refs'] : [];
                ++$skipped;
                continue;
            }

            if ($applied !== [] && microtime(true) - $start > self::TIME_BUDGET) {
                break;
            }

            $changes = $this->changes($name, $json);
            $safety ??= $safetyCopy ? (new Backup())->safetyCopy('vor-aenderungsliste') : '';

            try {
                $result = ($this->abilities)()->applyChangeList($changes, $refs);
            } catch (\Throwable $e) {
                return ['error' => sprintf('%s: %s', $name, $e->getMessage()), 'applied' => $applied, 'skipped' => $skipped, 'remaining' => count($lists) - count($applied) - $skipped, 'safety' => $safety];
            }

            $neu  = array_diff_key($result['refs'], $refs);
            $refs = $result['refs'];

            $done[$key] = ['name' => $name, 'at' => gmdate('c'), 'changes' => count($changes), 'refs' => $neu];
            update_option(self::DONE_OPTION, $done, false);

            $applied[] = $name;
        }

        return ['applied' => $applied, 'skipped' => $skipped, 'remaining' => count($lists) - count($applied) - $skipped, 'safety' => $safety];
    }

    /** @return array<string, string> Dateiname → Inhalt */
    public function lists(string $file, string $name): array
    {
        if (! str_ends_with(strtolower($name), '.zip')) {
            return [$name !== '' ? $name : 'liste.json' => (string) file_get_contents($file)];
        }

        $zip = new \ZipArchive();

        if ($zip->open($file) !== true) {
            throw new \RuntimeException(__('The ZIP file cannot be opened.', 'taxmod'));
        }

        $lists = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entry = (string) $zip->getNameIndex($i);

            if (str_ends_with(strtolower($entry), '.json') && ! str_starts_with(basename($entry), '.')) {
                $lists[$entry] = (string) $zip->getFromIndex($i);
            }
        }

        $zip->close();

        if ($lists === []) {
            throw new \RuntimeException(__('The ZIP file holds no .json file.', 'taxmod'));
        }

        return $lists;
    }

    /** @return list<mixed> */
    private function changes(string $name, string $json): array
    {
        $data    = json_decode($json, true);
        $changes = is_array($data) && array_is_list($data) ? $data : (is_array($data) ? ($data['changes'] ?? null) : null);

        if (! is_array($changes) || $changes === []) {
            throw new \RuntimeException(sprintf(
                /* translators: %s: file name */
                __('%s is not a change list: expected {"changes": [...]}.', 'taxmod'),
                $name
            ));
        }

        return array_values($changes);
    }

    /** @param array<string, mixed> $report */
    private function redirectWith(array $report): never
    {
        set_transient(self::REPORT . get_current_user_id(), $report, HOUR_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['page' => BackupScreen::PAGE], admin_url('admin.php')));

        exit;
    }

    private function report(): string
    {
        $key    = self::REPORT . get_current_user_id();
        $report = get_transient($key);

        if (! is_array($report)) {
            return '';
        }

        delete_transient($key);

        $html = '';

        if (isset($report['error'])) {
            $html .= '<div class="notice notice-error"><p>' . esc_html((string) $report['error']) . '</p></div>';
        }

        if (isset($report['applied'])) {
            $html .= '<div class="notice notice-' . ((int) $report['remaining'] > 0 ? 'warning' : 'success') . '"><p>' . esc_html(sprintf(
                /* translators: 1: applied files, 2: skipped files, 3: files still to run */
                __('Applied %1$d files, %2$d had already run, %3$d still to run.', 'taxmod'),
                count((array) $report['applied']),
                (int) $report['skipped'],
                (int) $report['remaining']
            )) . '</p>';


            if (! empty($report['safety'])) {
                $html .= '<p>' . esc_html(sprintf(
                    /* translators: %s: path of the safety copy */
                    __('The previous state is saved in %s', 'taxmod'),
                    (string) $report['safety']
                )) . '</p>';
            }

            $html .= '</div>';
        }

        return $html;
    }
}
