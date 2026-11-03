<?php declare(strict_types=1);

namespace Taxmod\WordPress\Printing;

use Taxmod\Core\Printing\LabelContent;
use Taxmod\Core\Printing\LabelDocument;
use Taxmod\Core\Printing\LabelFormat;
use Taxmod\Core\Printing\LabelPrefix;
use Taxmod\WordPress\Plugin;

/**
 * «Etikett drucken» for any module: the filter {@see self::FILTER} returns a button that opens the shared dialog — choose the
 * format, then send it to the print service or open it as PDF. One button for every module, so a place, a box or a material
 * prints the same way (D-1120).
 *
 * The button brings its own form; place it outside any other form.
 *
 * @see docs/NewConcept/90-decision-log.md D-1120
 */
final class LabelButton
{
    /** Filter: `(string $html, string $prefix 'O'|'M'|'E'|'K', int $id, string $title, string $subtitle): string`. */
    public const FILTER = 'taxmod_label_button';

    public const ACTION = 'taxmod_label_queue';

    private const NONCE = '_taxmod_label_nonce';

    /** The format a label of this kind starts with in the dialog. */
    private const DEFAULT_FORMAT = ['O' => 'holder62', 'K' => 'roll62', 'M' => 'material62', 'E' => 'material62'];

    public static function boot(): void
    {
        add_filter(self::FILTER, static fn (mixed $html, string $prefix = '', int $id = 0, string $title = '', string $subtitle = ''): string => self::render($prefix, $id, $title, $subtitle), 10, 5);
        add_action('admin_post_' . self::ACTION, self::handle(...));
    }

    public static function render(string $prefix, int $id, string $title, string $subtitle = ''): string
    {
        $kind = LabelPrefix::tryFrom($prefix);
        if ($kind === null || $id <= 0 || trim($title) === '' || ! current_user_can(Plugin::CAPABILITY)) {
            return '';
        }
        do_action(Plugin::STYLE_ACTION);

        $label = LabelContent::of($kind, $id, $title, $subtitle);
        $default = self::DEFAULT_FORMAT[$kind->value];
        $options = '';
        foreach (LabelFormat::shipped() as $format) {
            $options .= '<option value="' . esc_attr($format->key) . '"' . selected($format->key, $default, false) . '>'
                . esc_html(PrintTab::formatName($format) . ' · ' . PrintTab::size($format)) . '</option>';
        }
        $service = PrintService::isSetUp();
        $switch = 'taxmod-label-' . strtolower($kind->value) . '-' . $id;
        $queued = (int) ($_GET['taxmod_label_job'] ?? 0);

        $body = '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html__('Format', 'taxmod') . '</span>'
            . '<select name="format">' . $options . '</select></label>'
            . '<span style="display:block">' . esc_html($label->code . ' · ' . $label->title . ($label->subtitle === '' ? '' : ' · ' . $label->subtitle)) . '</span>'
            . ($service ? '' : '<span class="description" style="display:block;margin-top:.5em">' . esc_html__('No print service set up: the label opens as PDF to print from the browser.', 'taxmod') . '</span>');

        $foot = ($service ? '<button type="submit" class="button button-primary" name="how" value="service">' . esc_html__('Print', 'taxmod') . '</button>' : '')
            . '<button type="submit" class="button' . ($service ? '' : ' button-primary') . '" name="how" value="pdf" formtarget="_blank">' . esc_html__('Open as PDF', 'taxmod') . '</button>';

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . wp_nonce_field(self::ACTION, self::NONCE, true, false)
            . '<input type="hidden" name="code" value="' . esc_attr($label->code) . '">'
            . '<input type="hidden" name="title" value="' . esc_attr($label->title) . '">'
            . '<input type="hidden" name="subtitle" value="' . esc_attr($label->subtitle) . '">'
            . \Taxmod\Core\Renderer\DialogMarkup::of(
                $switch,
                esc_html__('Etikett drucken', 'taxmod'),
                esc_html__('Print a label', 'taxmod'),
                '<span class="taxmod-dialog-body" style="display:block;padding:.75em 1em;white-space:normal;text-align:left">' . $body . '</span>',
                $foot,
                'button taxmod-dialog-open',
                '',
                __('Cancel', 'taxmod')
            )
            . '</form>'
            . ($queued > 0 ? ' <span class="description">' . esc_html(sprintf(/* translators: %d: job number */ __('Job %d is waiting for the print service.', 'taxmod'), $queued)) . '</span>' : '');
    }

    /** admin-post: capability, nonce, validate, then queue for the service or hand out the PDF. */
    public static function handle(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You cannot print labels.', 'taxmod'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION, self::NONCE);

        $post = wp_unslash($_POST);
        $formats = LabelFormat::shipped();
        $format = $formats[sanitize_key((string) ($post['format'] ?? ''))] ?? null;
        $label = LabelPrint::labelFrom($post);
        if ($format === null || $label === null) {
            wp_die(esc_html__('A label needs a known format, a code like O-123 and a title.', 'taxmod'), '', ['response' => 400]);
        }

        if (($post['how'] ?? '') === 'service' && PrintService::isSetUp()) {
            $id = (new PrintJobs())->queue($format, $label, false);
            wp_safe_redirect(add_query_arg('taxmod_label_job', $id, wp_get_referer() ?: PrintJobsScreen::url()));
            exit;
        }

        $pdf = LabelDocument::render($format, [$label]);
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . sanitize_file_name('label-' . $label->code . '.pdf') . '"');
        header('Content-Length: ' . (string) strlen($pdf));
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF, not HTML.
        exit;
    }
}
