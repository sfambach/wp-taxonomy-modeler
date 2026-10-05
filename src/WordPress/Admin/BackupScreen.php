<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\WordPress\Persistence\Backup;
use Taxmod\WordPress\Plugin;

/**
 * `Backup` — das ganze Modell samt Dateien herunterladen und wieder einspielen ([D-908](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart LR
 *   D["Herunterladen"] --> X["Backup::export"] --> Z["ZIP an den Browser"]
 *   U["ZIP hochladen + bestätigen"] --> R["Backup::restore"] --> B["Bericht"]
 * ```
 *
 * ⚠️ **Die Seite hängt an keinem Teil des Modells.** *Sie ist der Rückweg, wenn der Umbau der Tabellen
 * scheiterte ([D-909](../../../docs/NewConcept/90-decision-log.md)) — darum baut sie weder Baum noch Saat und
 * fragt die Tabellen erst, wenn jemand einen Knopf drückt.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class BackupScreen
{
    public const PAGE = 'taxmod-backup';

    public const DOWNLOAD_ACTION = 'taxmod_backup_download';

    public const RESTORE_ACTION = 'taxmod_backup_restore';

    /** Der Bericht des Einspielens überlebt die Weiterleitung hier — für eine Adresszeile ist er zu lang. */
    private const REPORT = 'taxmod_backup_report_';

    public function render(): string
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            return '';
        }

        $post = esc_url(admin_url('admin-post.php'));

        return '<div class="wrap"><h1>' . esc_html__('Backup', 'taxmod') . '</h1>'
            . $this->report()
            . '<h2>' . esc_html__('Download', 'taxmod') . '</h2>'
            . '<p>' . esc_html__('One ZIP file with every table of the model, its settings and every media file a value points at.', 'taxmod') . '</p>'
            . '<form method="post" action="' . $post . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::DOWNLOAD_ACTION) . '">'
            . wp_nonce_field(self::DOWNLOAD_ACTION, '_taxmod_nonce', true, false)
            . get_submit_button(__('Download backup', 'taxmod'), 'primary', 'submit', false)
            . '</form>'
            . '<h2>' . esc_html__('Restore', 'taxmod') . '</h2>'
            . '<p>' . esc_html__('Replaces the whole model on this site. The media files are added to the media library and the values are pointed at them. The current state is saved first into the folder uploads/taxmod-backups.', 'taxmod') . '</p>'
            . '<form method="post" action="' . $post . '" enctype="multipart/form-data">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::RESTORE_ACTION) . '">'
            . wp_nonce_field(self::RESTORE_ACTION, '_taxmod_nonce', true, false)
            . '<p><input type="file" name="taxmod_backup" accept=".zip,application/zip" required></p>'
            . '<p><label><input type="checkbox" name="taxmod_confirm" value="1" required> '
            . esc_html__('Yes, replace the model on this site.', 'taxmod') . '</label></p>'
            . get_submit_button(__('Restore backup', 'taxmod'), 'delete', 'submit', false)
            . '</form></div>';
    }

    public function handleDownload(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You cannot download this backup.', 'taxmod'), '', ['response' => 403]);
        }

        check_admin_referer(self::DOWNLOAD_ACTION, '_taxmod_nonce');

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $file = wp_tempnam('taxmod-backup');

        try {
            (new Backup())->export($file);
        } catch (\Throwable $e) {
            @unlink($file);
            $this->redirectWith(['error' => $e->getMessage()]);
        }

        $name = 'taxmod-backup-' . sanitize_file_name((string) wp_parse_url(home_url(), PHP_URL_HOST)) . '-' . gmdate('Y-m-d-His') . '.zip';

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($file));

        readfile($file);
        @unlink($file);

        exit;
    }

    public function handleRestore(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You cannot restore a backup.', 'taxmod'), '', ['response' => 403]);
        }

        check_admin_referer(self::RESTORE_ACTION, '_taxmod_nonce');

        if (! isset($_POST['taxmod_confirm']) || sanitize_key(wp_unslash($_POST['taxmod_confirm'])) !== '1') {
            $this->redirectWith(['error' => __('Nothing was restored: the confirmation was not ticked.', 'taxmod')]);
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- eine Datei; geprüft werden Fehlercode und Herkunft.
        $upload = $_FILES['taxmod_backup'] ?? null;

        if (! is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ! is_uploaded_file((string) $upload['tmp_name'])) {
            $this->redirectWith(['error' => __('No backup file arrived. The file may be larger than the server accepts (upload_max_filesize).', 'taxmod')]);
        }

        // ⚠️ *Ein Einspielen mit vielen Dateien braucht länger als eine gewöhnliche Anfrage.*
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        try {
            $result = (new Backup())->restore((string) $upload['tmp_name']);
        } catch (\Throwable $e) {
            $this->redirectWith(['error' => $e->getMessage()]);
        }

        $this->redirectWith([
            'rows'          => array_sum($result['tables']),
            'tables'        => count($result['tables']),
            'media_new'     => $result['media_new'],
            'media_reused'  => $result['media_reused'],
            'media_missing' => $result['media_missing'],
            'safety'        => $result['safety'],
        ]);
    }

    /** @param array<string, int|string> $report */
    private function redirectWith(array $report): never
    {
        set_transient(self::REPORT . get_current_user_id(), $report, HOUR_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['page' => self::PAGE], admin_url('admin.php')));

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

        if (isset($report['error'])) {
            return '<div class="notice notice-error"><p>' . esc_html((string) $report['error']) . '</p></div>';
        }

        return '<div class="notice notice-success"><p>' . esc_html(sprintf(
            /* translators: 1: rows, 2: tables, 3: new media files, 4: reused media files, 5: missing media files */
            __('Restored: %1$d rows in %2$d tables, %3$d new media files, %4$d already present, %5$d missing.', 'taxmod'),
            (int) $report['rows'],
            (int) $report['tables'],
            (int) $report['media_new'],
            (int) $report['media_reused'],
            (int) $report['media_missing']
        )) . '</p><p>' . esc_html(sprintf(
            /* translators: %s: path of the safety copy */
            __('The previous state is saved in %s', 'taxmod'),
            (string) $report['safety']
        )) . '</p></div>';
    }
}
