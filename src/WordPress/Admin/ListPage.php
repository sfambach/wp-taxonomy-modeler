<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\WordPress\Plugin;

/**
 * One look for every list screen of the modeller and its modules (D-989): a big heading saying where one is, bulk actions
 * above, a WordPress list table, the row's buttons on the right, its details in a full-width row below that opens in
 * place, and «Expand all» above. Returns strings (CD-8); escaping of the parts passed in as HTML is the caller's.
 *
 * ```mermaid
 * flowchart TB
 *   H["heading · where one is · version"] --> B["bulk actions · expand all · count"]
 *   B --> T["list table"]
 *   T --> R["row · cells · buttons right"]
 *   R --> D["details row · opens below"]
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-989
 */
final class ListPage
{
    /** The heading: the plugin, then the screen one is on, then the version. */
    public static function heading(string $screen, string $version = Plugin::VERSION, string $plugin = 'Taxonomy Modeller'): string
    {
        return '<h1 class="wp-heading-inline" style="font-size:1.8em">' . esc_html($plugin) . ' › ' . esc_html($screen) . '</h1>'
            . ' <span class="title-count" style="color:#646970">v' . esc_html($version) . '</span><hr class="wp-header-end">';
    }

    /**
     * Bulk actions on the left, «Expand all» beside them, the count on the right. The form stands alone, because each
     * row's details may hold a form of their own and forms do not nest; each row's checkbox joins it through `form=`.
     *
     * @param array<string, string> $actions action value => label
     */
    public static function bulkBar(string $formId, string $nonceAction, array $actions, string $count): string
    {
        $options = '<option value="">' . esc_html__('Bulk actions', 'taxmod') . '</option>';

        foreach ($actions as $value => $label) {
            $options .= '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }

        return '<div class="tablenav top"><form method="post" id="' . esc_attr($formId) . '" action="' . esc_url(admin_url('admin-post.php')) . '" class="alignleft actions bulkactions">'
            . wp_nonce_field($nonceAction, '_wpnonce', true, false)
            . '<label class="screen-reader-text" for="' . esc_attr($formId) . '-action">' . esc_html__('Bulk actions', 'taxmod') . '</label>'
            . '<select name="action" id="' . esc_attr($formId) . '-action">' . $options . '</select> '
            . get_submit_button(__('Apply', 'taxmod'), 'action', '', false) . '</form>'
            . '<div class="alignleft actions">'
            . '<button type="button" class="button" onclick="' . esc_attr(self::toggleAll(false)) . '">' . esc_html__('Expand all', 'taxmod') . '</button> '
            . '<button type="button" class="button" onclick="' . esc_attr(self::toggleAll(true)) . '">' . esc_html__('Collapse all', 'taxmod') . '</button></div>'
            . '<div class="tablenav-pages one-page"><span class="displaying-num">' . esc_html($count) . '</span></div><br class="clear"></div>';
    }

    /**
     * The table: a checkbox column first, the given columns, the actions column last.
     *
     * @param list<array{0: string, 1?: string}> $columns label, optional CSS width
     */
    public static function table(array $columns, string $rows): string
    {
        $head = '<tr><td class="manage-column column-cb check-column"><input type="checkbox" aria-label="' . esc_attr__('Select all', 'taxmod') . '"></td>';

        foreach ($columns as $column) {
            $head .= '<th scope="col" class="manage-column"' . (isset($column[1]) ? ' style="width:' . esc_attr($column[1]) . '"' : '') . '>' . esc_html($column[0]) . '</th>';
        }

        $head .= '<th scope="col" class="manage-column" style="width:16em;text-align:right">' . esc_html__('Actions', 'taxmod') . '</th></tr>';

        return '<table class="wp-list-table widefat fixed striped table-view-list taxmod-list"><thead>' . $head . '</thead><tbody>' . $rows . '</tbody><tfoot>' . $head . '</tfoot></table>';
    }

    /**
     * One row: its checkbox joins the bulk form, the buttons stand on the right after the toggle, the details open below.
     *
     * @param list<string> $cells HTML, escaped by the caller
     */
    public static function row(string $formId, string $value, array $cells, string $buttons, string $details): string
    {
        $html = '<tr><th scope="row" class="check-column"><input type="checkbox" name="selected[]" value="' . esc_attr($value)
            . '" form="' . esc_attr($formId) . '" aria-label="' . esc_attr__('Select', 'taxmod') . '"></th>';

        foreach ($cells as $cell) {
            $html .= '<td>' . $cell . '</td>';
        }

        return $html . '<td style="text-align:right;white-space:nowrap">'
            . '<button type="button" class="button-link taxmod-list-toggle" aria-expanded="false" onclick="var d=this.closest(\'tr\').nextElementSibling;d.hidden=!d.hidden;this.setAttribute(\'aria-expanded\',String(!d.hidden))">'
            . esc_html__('Details', 'taxmod') . ' ▾</button> ' . $buttons . '</td></tr>'
            . '<tr class="taxmod-list-details" hidden><td colspan="' . (count($cells) + 2) . '" style="padding:0 1em 1em">' . $details . '</td></tr>';
    }

    private static function toggleAll(bool $hidden): string
    {
        $flag = $hidden ? 'true' : 'false';

        return "this.closest('.wrap').querySelectorAll('.taxmod-list-details').forEach(function(d){d.hidden=" . $flag . '});'
            . "this.closest('.wrap').querySelectorAll('.taxmod-list-toggle').forEach(function(b){b.setAttribute('aria-expanded','" . ($hidden ? 'false' : 'true') . "')})";
    }
}
