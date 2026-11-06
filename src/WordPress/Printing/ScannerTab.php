<?php declare(strict_types=1);

namespace Taxmod\WordPress\Printing;

use Taxmod\Core\Printing\LabelPrefix;
use Taxmod\Core\Printing\ScannedCode;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Plugin;

/**
 * The «Scanner» tab of the Configuration (D-1206): a test page with one field. A barcode scanner types the label's code
 * and Enter; the page reads it back ({@see ScannedCode}) and shows the record it names, with the links the modules give
 * for that kind of code (filter {@see self::LINKS_FILTER}).
 *
 * ```mermaid
 * flowchart LR
 *   F["field, focused"] -->|code + Enter| G["GET tab=scanner&code=…"]
 *   G --> C[ScannedCode] --> R["record · node · fields"]
 *   R --> L["Open in the modeller + module links"]
 * ```
 *
 * A page of its own and not a form of settings: the tab gives `page` instead of `render`/`save`, so the Configuration
 * draws neither the settings form nor its «Save» button around it.
 *
 * @see docs/NewConcept/90-decision-log.md D-1206
 */
final class ScannerTab
{
    public const SLUG = 'scanner';

    /**
     * Where a module adds its links for a scanned record: `(array<string, string> $links, string $kind, int $record)`,
     * label → URL; `$kind` is the code's letter (O, M, E, K) or '' for a bare id.
     */
    public const LINKS_FILTER = 'taxmod_scan_links';

    public const FIELD = 'code';

    private const NONCE = '_taxmod_scan_nonce';

    private const ACTION = 'taxmod_scan';

    /** @param \Closure(): NodesScreen $screen */
    public static function boot(\Closure $screen): void
    {
        add_filter(SettingsScreen::TABS_FILTER, static function (array $tabs) use ($screen): array {
            $tabs[self::SLUG] = [
                'title'   => __('Scanner', 'taxmod'),
                'version' => Plugin::VERSION,
                'page'    => static fn (): string => (new self($screen))->page(),
            ];

            return $tabs;
        }, 3);
    }

    /** @param \Closure(): NodesScreen $screen */
    public function __construct(private readonly \Closure $screen)
    {
    }

    public function page(): string
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            return '';
        }

        $typed = isset($_GET[self::FIELD]) ? (string) wp_unslash($_GET[self::FIELD]) : '';
        $asked = $typed !== '' && wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_GET[self::NONCE] ?? ''))), self::ACTION) !== false;

        return '<p class="description">' . esc_html__('Scan a label, or type its code, and press Enter. The scanner works as a keyboard; the field is ready for the next scan straight away.', 'taxmod') . '</p>'
            . '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="taxmod-scan-form">'
            . '<input type="hidden" name="page" value="taxmod-settings">'
            . '<input type="hidden" name="tab" value="' . esc_attr(self::SLUG) . '">'
            . '<input type="hidden" name="' . esc_attr(self::NONCE) . '" value="' . esc_attr(wp_create_nonce(self::ACTION)) . '">'
            . '<label class="screen-reader-text" for="taxmod-scan-code">' . esc_html__('Scanned code', 'taxmod') . '</label>'
            . '<input type="text" id="taxmod-scan-code" name="' . esc_attr(self::FIELD) . '" value="" class="regular-text taxmod-scan-code" autocomplete="off" autofocus placeholder="' . esc_attr__('e.g. M-123', 'taxmod') . '">'
            . get_submit_button(__('Look up', 'taxmod'), 'primary', '', false)
            . '</form>'
            . ($typed === '' ? '' : ($asked ? $this->result($typed) : '<div class="notice notice-error inline"><p>' . esc_html__('The link has expired. Scan again.', 'taxmod') . '</p></div>'));
    }

    /** What the scan names; the raw keystrokes stay visible so a wrong keyboard layout on the scanner shows itself. */
    private function result(string $typed): string
    {
        $shown = sanitize_text_field($typed);
        $html  = '<h2>' . esc_html__('Last scan', 'taxmod') . '</h2>'
            . '<p class="description">' . esc_html(sprintf(
                /* translators: 1: what the scanner typed, 2: number of characters */
                __('Received: «%1$s» (%2$d characters)', 'taxmod'),
                $shown,
                mb_strlen($typed)
            )) . '</p>';
        $code  = ScannedCode::read($typed);

        if ($code === null) {
            return $html . '<div class="notice notice-error inline"><p>' . esc_html__('This is not a Taxmod code. Labels carry O-123 (place), K-123 (box), M-123 (material) or E-123 (copy); a bare record id is taken too.', 'taxmod') . '</p></div>';
        }

        $record = (new WpdbRecordRepository())->find($code->record);

        if ($record === null) {
            return $html . '<div class="notice notice-error inline"><p>' . esc_html(sprintf(
                /* translators: 1: code, 2: record id */
                __('%1$s: there is no record %2$d.', 'taxmod'),
                $code->code(),
                $code->record
            )) . '</p></div>';
        }

        $links = [__('Open in the modeller', 'taxmod') => add_query_arg(['page' => 'taxmod', 'taxmod_open_record' => (string) $code->record], admin_url('admin.php'))];
        $given = apply_filters(self::LINKS_FILTER, [], $code->kind?->value ?? '', $code->record);

        foreach (is_array($given) ? $given : [] as $label => $url) {
            if (is_string($label) && is_string($url) && $url !== '') {
                $links[$label] = $url;
            }
        }

        $buttons = '';

        foreach (array_reverse($links, true) as $label => $url) {
            $buttons .= '<a class="button' . ($buttons === '' ? ' button-primary' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a> ';
        }

        try {
            $body = ($this->screen)()->recordForReaders($code->record, 0, SettingsScreen::neutralLocale());
        } catch (\InvalidArgumentException $e) {
            $body = '<p class="description">' . esc_html($e->getMessage()) . '</p>';
        }

        return $html
            . '<table class="taxmod-config taxmod-scan-result"><tbody>'
            . '<tr><th scope="row">' . esc_html__('Code', 'taxmod') . '</th><td>' . esc_html($code->code()) . '</td><td>' . esc_html(self::kindWord($code->kind)) . '</td></tr>'
            . '<tr><th scope="row">' . esc_html__('Node', 'taxmod') . '</th><td>' . esc_html(self::pathOf($record->nodeId)) . '</td><td>' . esc_html(sprintf(/* translators: %d: record id */ __('record %d', 'taxmod'), $code->record)) . '</td></tr>'
            . '</tbody></table>'
            . '<p class="taxmod-scan-actions">' . trim($buttons) . '</p>'
            . $body;
    }

    private static function kindWord(?LabelPrefix $kind): string
    {
        return match ($kind) {
            LabelPrefix::Place    => __('Storage place', 'taxmod'),
            LabelPrefix::Box      => __('Box', 'taxmod'),
            LabelPrefix::Material => __('Material', 'taxmod'),
            LabelPrefix::Copy     => __('Copy', 'taxmod'),
            null                  => __('Record id', 'taxmod'),
        };
    }

    /** The node's names from below the root down to it, in one read. */
    private static function pathOf(int $node): string
    {
        $nodes = new WpdbNodeRepository();
        $own   = $nodes->find($node);

        if ($own === null) {
            return '#' . $node;
        }

        $ids   = array_map(intval(...), explode('.', $own->path));
        $found = $nodes->byIds($ids);
        $names = [];

        foreach (array_slice($ids, 1) as $id) {
            $names[] = isset($found[$id]) ? $found[$id]->name : '#' . $id;
        }

        return implode(' › ', $names === [] ? [$own->name] : $names);
    }
}
