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
 *   H["heading · where one is · version"] --> N["tabs · search"] --> B["bulk actions · filters · expand all · count"]
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
            . ' <span class="taxmod-version" style="display:inline-block;vertical-align:middle;margin-left:.5em;padding:2px 8px;border-radius:10px;background:#2271b1;color:#fff;font-size:12px;font-weight:600">v' . esc_html($version) . '</span><hr class="wp-header-end">';
    }

    /**
     * Bulk actions on the left, «Expand all» beside them, the count on the right. The form stands alone, because each
     * row's details may hold a form of their own and forms do not nest; each row's checkbox joins it through `form=`.
     *
     * @param array<string, string> $actions action value => label
     * @param string $filters from {@see self::filters()}, beside the bulk actions
     */
    public static function bulkBar(string $formId, string $nonceAction, array $actions, string $count, string $filters = ''): string
    {
        $options = '<option value="">' . esc_html__('Bulk actions', 'taxmod') . '</option>';

        foreach ($actions as $value => $label) {
            $options .= '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }

        return '<div class="tablenav top"><form method="post" id="' . esc_attr($formId) . '" action="' . esc_url(admin_url('admin-post.php')) . '" class="alignleft actions bulkactions" onsubmit="' . esc_attr(self::confirmBulk($formId)) . '">'
            . wp_nonce_field($nonceAction, '_wpnonce', true, false)
            . '<label class="screen-reader-text" for="' . esc_attr($formId) . '-action">' . esc_html__('Bulk actions', 'taxmod') . '</label>'
            . '<select name="action" id="' . esc_attr($formId) . '-action">' . $options . '</select> '
            . get_submit_button(__('Apply', 'taxmod'), 'action', '', false) . '</form>'
            . $filters
            . '<div class="alignleft actions">'
            . '<button type="button" class="button" onclick="' . esc_attr(self::toggleAll(false)) . '">' . esc_html__('Expand all', 'taxmod') . '</button> '
            . '<button type="button" class="button" onclick="' . esc_attr(self::toggleAll(true)) . '">' . esc_html__('Collapse all', 'taxmod') . '</button></div>'
            . '<div class="tablenav-pages one-page"><span class="displaying-num">' . esc_html($count) . '</span></div><br class="clear"></div>';
    }

    /**
     * Tabs with counts, as WordPress draws them.
     *
     * @param list<array{0: string, 1: string, 2?: int|null}> $tabs slug, label, optional count
     * @param callable(string): string $url the address of a tab
     */
    public static function tabs(array $tabs, string $active, callable $url): string
    {
        $html = '<nav class="nav-tab-wrapper">';

        foreach ($tabs as $tab) {
            $html .= '<a class="nav-tab' . ($tab[0] === $active ? ' nav-tab-active' : '') . '" href="' . esc_url($url($tab[0])) . '">' . esc_html($tab[1])
                . (isset($tab[2]) ? ' <span class="count">(' . (int) $tab[2] . ')</span>' : '') . '</a>';
        }

        return $html . '</nav>';
    }

    /**
     * The search box on the right; a GET form of its own that the filters join through `form=`.
     *
     * @param array<string, string> $hidden query arguments to keep (page, tab, orderby …)
     */
    public static function searchBox(string $formId, string $value, string $placeholder, array $hidden, string $label = ''): string
    {
        $label = $label === '' ? __('Search', 'taxmod') : $label;
        $html = '<form method="get" id="' . esc_attr($formId) . '" action="' . esc_url(admin_url('admin.php')) . '">';

        foreach ($hidden as $name => $hiddenValue) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($hiddenValue) . '">';
        }

        return $html . '<p class="search-box"><label class="screen-reader-text" for="' . esc_attr($formId) . '-s">' . esc_html($label) . '</label>'
            . '<input type="search" id="' . esc_attr($formId) . '-s" name="s" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '"> '
            . get_submit_button($label, '', '', false, ['id' => $formId . '-submit']) . '</p></form>';
    }

    /**
     * Filter drop-downs with their button, for {@see self::bulkBar()}; they submit the search form.
     *
     * @param list<array{0: string, 1: array<string, string>, 2: string, 3?: string}> $filters name, value => text, current, label
     */
    public static function filters(string $searchFormId, array $filters): string
    {
        $html = '<div class="alignleft actions">';

        foreach ($filters as $filter) {
            $id = $searchFormId . '-' . $filter[0];
            $html .= '<label class="screen-reader-text" for="' . esc_attr($id) . '">' . esc_html($filter[3] ?? $filter[0]) . '</label>'
                . '<select name="' . esc_attr($filter[0]) . '" id="' . esc_attr($id) . '" form="' . esc_attr($searchFormId) . '">';

            foreach ($filter[1] as $value => $text) {
                $html .= '<option value="' . esc_attr((string) $value) . '"' . ((string) $value === $filter[2] ? ' selected' : '') . '>' . esc_html($text) . '</option>';
            }

            $html .= '</select> ';
        }

        return $html . get_submit_button(__('Filter', 'taxmod'), '', 'filter_action', false, ['form' => $searchFormId]) . '</div>';
    }

    /**
     * The table: a checkbox column first, the given columns, the actions column last. A column with a sort address is
     * sortable; its state says whether the list is sorted by it now.
     *
     * @param list<array{0: string, 1?: string|null, 2?: string, 3?: ''|'asc'|'desc'}> $columns label, CSS width, sort address, state
     */
    public static function table(array $columns, string $rows): string
    {
        $head = '<tr><td class="manage-column column-cb check-column"><input type="checkbox" aria-label="' . esc_attr__('Select all', 'taxmod') . '"></td>';

        foreach ($columns as $column) {
            $width = isset($column[1]) && $column[1] !== '' ? ' style="width:' . esc_attr($column[1]) . '"' : '';

            if (! isset($column[2]) || $column[2] === '') {
                $head .= '<th scope="col" class="manage-column"' . $width . '>' . esc_html($column[0]) . '</th>';

                continue;
            }

            $state = $column[3] ?? '';
            $head .= '<th scope="col" class="manage-column ' . ($state === '' ? 'sortable desc' : 'sorted ' . $state) . '"' . $width . '><a href="' . esc_url($column[2]) . '"><span>'
                . esc_html($column[0]) . '</span><span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span></a></th>';
        }

        $head .= '<th scope="col" class="manage-column" style="width:21em;text-align:right">' . esc_html__('Actions', 'taxmod') . '</th></tr>';

        return '<style>.taxmod-list-toggle{display:inline-flex;align-items:center;justify-content:center;vertical-align:middle;padding:0 6px}.taxmod-list-toggle .dashicons{width:20px;height:20px;font-size:20px;line-height:1;transition:transform .15s}.taxmod-list-toggle[aria-expanded="true"] .dashicons{transform:rotate(180deg)}</style>'
            . '<table class="wp-list-table widefat fixed striped table-view-list taxmod-list"><thead>' . $head . '</thead><tbody>' . $rows . '</tbody><tfoot>' . $head . '</tfoot></table>';
    }

    /**
     * One row: its checkbox joins the bulk form, the buttons stand on the right after the toggle, the details open below —
     * already open when the module's setting says so («Automatisch aufklappen», his wish of 2026-10-25).
     *
     * @param list<string> $cells HTML, escaped by the caller
     */
    public static function row(string $formId, string $value, array $cells, string $buttons, string $details, bool $expanded = false): string
    {
        $html = '<tr><th scope="row" class="check-column"><input type="checkbox" name="selected[]" value="' . esc_attr($value)
            . '" form="' . esc_attr($formId) . '" aria-label="' . esc_attr__('Select', 'taxmod') . '"></th>';

        foreach ($cells as $cell) {
            $html .= '<td>' . $cell . '</td>';
        }

        return $html . '<td style="text-align:right;white-space:nowrap">'
            . '<button type="button" class="button taxmod-list-toggle" aria-expanded="' . ($expanded ? 'true' : 'false') . '" title="' . esc_attr__('Details', 'taxmod') . '" aria-label="' . esc_attr__('Details', 'taxmod') . '" onclick="var d=this.closest(\'tr\').nextElementSibling;d.hidden=!d.hidden;this.setAttribute(\'aria-expanded\',String(!d.hidden))">'
            // ⚠️ *His words: 2026-10-25 «als richtigen Button aber ruhig mit diesen Pfeil nach unten», 2026-10-28 «Der Pfeil im Button
            // ist immer noch komisch. Kannst du nicht irgendwie ein Icon nehmen?» — so the button is the icon alone; it turns when open.*
            . '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button> ' . $buttons . '</td></tr>'
            . '<tr class="taxmod-list-details"' . ($expanded ? '' : ' hidden') . '><td colspan="' . (count($cells) + 2) . '" style="padding:0 1em 1em">' . $details . '</td></tr>';
    }

    /**
     * Asks before a bulk action, naming how many entries it reaches: a row that stands for several («;» between them)
     * counts each. ⚠️ *His report of 2026-10-25: «Diese Sammelaktion hat nicht nur die selektierten Einträge genommen,
     * sondern alle» — the page now says the number before anything happens.*
     */
    private static function confirmBulk(string $formId): string
    {
        $message = (string) wp_json_encode(__('Apply «%1$s» to %2$d entries?', 'taxmod'));
        $none = (string) wp_json_encode(__('Nothing selected.', 'taxmod'));

        return "var n=0;document.querySelectorAll('input[name=\"selected[]\"][form=\"" . esc_js($formId) . "\"]:checked').forEach(function(c){n+=c.value.split(';').length});"
            . "var s=this.querySelector('select[name=action]');if(!s.value){return false}"
            . 'if(n===0){alert(' . $none . ');return false}'
            . 'return confirm(' . $message . ".replace('%1\$s',s.options[s.selectedIndex].text).replace('%2\$d',n))";
    }

    private static function toggleAll(bool $hidden): string
    {
        $flag = $hidden ? 'true' : 'false';

        return "this.closest('.wrap').querySelectorAll('.taxmod-list-details').forEach(function(d){d.hidden=" . $flag . '});'
            . "this.closest('.wrap').querySelectorAll('.taxmod-list-toggle').forEach(function(b){b.setAttribute('aria-expanded','" . ($hidden ? 'false' : 'true') . "')})";
    }
}
