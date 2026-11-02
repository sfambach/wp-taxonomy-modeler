<?php declare(strict_types=1);

namespace Taxmod\WordPress\Printing;

use Taxmod\Core\Printing\LabelFormat;

/**
 * The «Printing» tab on the Configuration page (D-1085): the label formats, each with its test print (D-1096).
 *
 * Nothing is stored yet — in stage 1 the browser's print dialog chooses the printer; printers and
 * the print service come with stage 2.
 *
 * @see docs/NewConcept/90-decision-log.md D-1032
 */
final class PrintTab
{
    public const SLUG = 'printing';

    /** The logistics module's filter for pages inside its navigation (wp_tax_stock, D-1085). */
    public const LOGISTICS_FILTER = 'taxmod_logistics_pages';

    public const LOGISTICS_SLUG = 'taxmod-printing';

    public static function boot(): void
    {
        add_filter(\Taxmod\WordPress\Admin\SettingsScreen::TABS_FILTER, self::register(...), 2);
        // His word 2026-11-02: «hänge das aber auch nochmal ins logistik menüs einstellungen -> druck, gezeigt auf der rechten seite».
        add_filter(self::LOGISTICS_FILTER, self::registerInLogistics(...));
    }

    /**
     * The same page under Logistik › Einstellungen › Druck, drawn inside the logistics frame (D-1103).
     *
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    public static function registerInLogistics(array $pages): array
    {
        $pages[] = [
            'slug'   => self::LOGISTICS_SLUG,
            'label'  => __('Druck', 'taxmod'),
            'area'   => 'settings',
            'render' => static fn (): string => '<div class="wrap"><h1>' . esc_html__('Druck', 'taxmod') . '</h1>' . (new self())->render() . '</div>',
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
            'render'  => static fn (): string => (new self())->render(),
            'save'    => static function (array $post): void {
            },
        ];

        return $tabs;
    }

    public function render(): string
    {
        $rows = '';
        foreach (LabelFormat::shipped() as $format) {
            $rows .= '<tr><td>' . esc_html(self::formatName($format)) . '</td>'
                . '<td>' . esc_html(sprintf('%s × %s mm', self::millimetres($format->widthMm), self::millimetres($format->heightMm))) . '</td>'
                . '<td>' . esc_html(sprintf('%s mm', self::millimetres($format->rollMm))) . '</td>'
                . '<td class="taxmod-actions" style="text-align:right"><a class="button" target="_blank" rel="noopener" href="' . esc_url(LabelPrint::url($format->key)) . '">'
                . esc_html__('Test print', 'taxmod') . '</a></td></tr>';
        }

        return '<p class="description">' . esc_html__('Labels open as PDF in a new tab; print them from there on the label printer. The test print uses made-up values and changes nothing.', 'taxmod') . '</p>'
            . '<table class="widefat striped taxmod-print-formats"><caption class="screen-reader-text">' . esc_html__('Label formats', 'taxmod') . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('Label', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('Size', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('Roll width', 'taxmod') . '</th>'
            . '<th scope="col" style="text-align:right">' . esc_html__('Actions', 'taxmod') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
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
