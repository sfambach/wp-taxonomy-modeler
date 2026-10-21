<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\DutyRole;
use Taxmod\Core\Import\DutyTable;

/**
 * The «Import» tab on the Configuration page (D-945): the duty table (D-953, S4) and the targets every source writes
 * to (S5). Capability and nonce are checked by the page before `save()` runs; this class validates and sanitises its
 * own fields (CD-5) and returns escaped HTML (CD-8).
 *
 * @see docs/pakete/import/konzept.md §3.4, §3.6
 */
final class ImportTab
{
    public const SLUG = 'import';
    private const EMPTY_ROWS = 3;

    /**
     * @param array<string, array<string, mixed>> $tabs
     * @return array<string, array<string, mixed>>
     */
    public static function register(array $tabs): array
    {
        $tabs[self::SLUG] = [
            'title'  => __('Import', 'taxmod'),
            'render' => static fn (): string => (new self())->render(),
            'save'   => static function (array $post): void {
                (new self())->save($post);
            },
        ];

        return $tabs;
    }

    public function render(): string
    {
        $sources = Sources::all();
        $known = $sources === [] ? esc_html__('none', 'taxmod') : esc_html(implode(', ', array_keys($sources)));
        $html = '<h2>' . esc_html__('Who brings what', 'taxmod') . '</h2><p>'
            . esc_html__('Per platform, which module brings new orders, status (shipped, delivered) and prices. An empty cell: every module may. A module that is not active hands its part to the others.', 'taxmod')
            . ' ' . esc_html__('Active modules:', 'taxmod') . ' ' . $known . '</p>'
            . '<table class="widefat striped" style="max-width:50em"><caption class="screen-reader-text">' . esc_html__('Duty table', 'taxmod') . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('Platform', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('New orders', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('Status', 'taxmod') . '</th>'
            . '<th scope="col">' . esc_html__('Prices', 'taxmod') . '</th></tr></thead><tbody>';
        $rows = Duties::load()->toArray();
        $index = 0;

        foreach ([...array_map(null, array_keys($rows), array_values($rows)), ...array_fill(0, self::EMPTY_ROWS, ['', []])] as [$platform, $roles]) {
            $html .= '<tr><td><input type="text" name="duties[' . $index . '][platform]" value="' . esc_attr((string) $platform) . '" aria-label="' . esc_attr__('Platform', 'taxmod') . '"></td>';

            foreach (DutyRole::cases() as $role) {
                $html .= '<td><input type="text" name="duties[' . $index . '][' . esc_attr($role->value) . ']" value="' . esc_attr((string) ($roles[$role->value] ?? '')) . '" aria-label="' . esc_attr($role->value) . '"></td>';
            }

            $html .= '</tr>';
            $index++;
        }

        return $html . '</tbody></table>'
            . '<h2>' . esc_html__('Where taken orders go', 'taxmod') . '</h2><p>'
            . esc_html__('Node and field ids for orders and purchases, the same for every module. Change them only when the tree was rebuilt.', 'taxmod') . '</p>'
            . '<textarea name="targets" rows="16" class="large-text code" aria-label="' . esc_attr__('Targets', 'taxmod') . '">'
            . esc_textarea((string) wp_json_encode(Targets::raw(), JSON_PRETTY_PRINT)) . '</textarea>';
    }

    /** @param array<string, mixed> $post already unslashed */
    public function save(array $post): void
    {
        $rows = [];

        foreach ((array) ($post['duties'] ?? []) as $row) {
            $platform = sanitize_text_field((string) ($row['platform'] ?? ''));

            if ($platform === '') {
                continue;
            }

            foreach (DutyRole::cases() as $role) {
                $source = sanitize_key((string) ($row[$role->value] ?? ''));

                if ($source !== '') {
                    $rows[$platform][$role->value] = $source;
                }
            }
        }

        Duties::save(new DutyTable($rows));
        $targets = json_decode(sanitize_textarea_field((string) ($post['targets'] ?? '')), true);

        if (is_array($targets)) {
            Targets::save($targets);
        }
    }
}
