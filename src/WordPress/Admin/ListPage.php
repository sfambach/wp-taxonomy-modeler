<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Listing\ListColumn;
use Taxmod\Core\Listing\ListView;
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
    /** The admin-post action that keeps a user's column choice (D-1054). */
    public const COLUMNS_ACTION = 'taxmod_list_columns';

    /** User meta: list id => the non-fixed column keys the user chose. */
    private const COLUMNS_META = 'taxmod_list_columns';

    /**
     * The user's column choice for a list, or null for its starting set.
     *
     * @return list<string>|null
     */
    public static function chosenColumns(string $listId): ?array
    {
        $all = get_user_meta(get_current_user_id(), self::COLUMNS_META, true);

        return is_array($all) && isset($all[$listId]) && is_array($all[$listId]) ? array_values(array_map('strval', $all[$listId])) : null;
    }

    /**
     * A list as the request asks for it: the user's columns, the search `s`, the column filters `f[key]`, `orderby` and `order`.
     *
     * @param list<ListColumn> $columns
     */
    public static function view(string $listId, array $columns, string $defaultOrder = ''): ListView
    {
        $filters = [];

        foreach ((array) ($_GET['f'] ?? []) as $key => $text) {
            if (is_string($text)) {
                $filters[sanitize_key((string) $key)] = sanitize_text_field(wp_unslash($text));
            }
        }

        $orderBy = sanitize_key((string) ($_GET['orderby'] ?? ''));

        return new ListView(
            $columns,
            self::chosenColumns($listId),
            sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? ''))),
            $filters,
            $orderBy === '' ? $defaultOrder : $orderBy,
            sanitize_key((string) ($_GET['order'] ?? '')) === 'desc'
        );
    }

    /**
     * The button «Spalten»: a small form with one box per column that is not fixed, «Apply» and «Starting set». The choice is the
     * user's own and stays for this list (design 7.2, 7.9).
     */
    public static function columnMenu(string $listId, ListView $view, string $returnUrl): string
    {
        $shown = array_map(static fn (ListColumn $c): string => $c->key, $view->visible());
        $boxes = '';

        foreach ($view->columns() as $column) {
            if ($column->fixed) {
                continue;
            }

            $boxes .= '<label style="display:block;white-space:nowrap"><input type="checkbox" name="columns[]" value="' . esc_attr($column->key) . '"'
                . (in_array($column->key, $shown, true) ? ' checked' : '') . '> ' . esc_html($column->label) . '</label>';
        }

        if ($boxes === '') {
            return '';
        }

        return '<details class="taxmod-columns alignleft actions" style="position:relative"><summary class="button">' . esc_html__('Columns', 'taxmod') . '</summary>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="position:absolute;z-index:20;background:#fff;border:1px solid #c3c4c7;padding:8px 12px;margin-top:4px;max-height:60vh;overflow:auto;box-shadow:0 2px 6px rgba(0,0,0,.15)">'
            . wp_nonce_field(self::COLUMNS_ACTION, '_wpnonce', true, false)
            . '<input type="hidden" name="action" value="' . esc_attr(self::COLUMNS_ACTION) . '"><input type="hidden" name="list" value="' . esc_attr($listId) . '">'
            . '<input type="hidden" name="return" value="' . esc_attr($returnUrl) . '">' . $boxes
            . '<p style="margin:8px 0 0;white-space:nowrap">' . get_submit_button(__('Apply', 'taxmod'), 'primary small', 'apply', false) . ' '
            . get_submit_button(__('Starting set', 'taxmod'), 'small', 'reset', false) . '</p></form></details>';
    }

    /** admin-post: capability → nonce → validate → sanitize → keep the choice → back to the list. */
    public static function saveColumns(): void
    {
        if (! current_user_can('read')) {
            wp_die(esc_html__('Not allowed.', 'taxmod'), '', ['response' => 403]);
        }

        check_admin_referer(self::COLUMNS_ACTION);

        $list   = sanitize_key((string) ($_POST['list'] ?? ''));
        $return = wp_validate_redirect(esc_url_raw(wp_unslash((string) ($_POST['return'] ?? ''))), admin_url());
        $all    = get_user_meta(get_current_user_id(), self::COLUMNS_META, true);
        $all    = is_array($all) ? $all : [];

        if ($list !== '') {
            if (isset($_POST['reset'])) {
                unset($all[$list]);
            } else {
                $all[$list] = array_values(array_map('sanitize_key', array_map('strval', (array) wp_unslash($_POST['columns'] ?? []))));
            }

            update_user_meta(get_current_user_id(), self::COLUMNS_META, $all);
        }

        wp_safe_redirect($return);
        exit;
    }

    /**
     * The filter row under the column heads, one «contains» box per shown column, submitting the search form; hidden until
     * «Filter row» opens it, or open while a filter is set (design 7.2).
     */
    public static function filterRow(string $searchFormId, ListView $view): string
    {
        $active = $view->filters();
        $html   = '<tr class="taxmod-filter-row"' . ($active === [] ? ' hidden' : '') . ' style="background:#f6f7f7"><td></td>';

        foreach ($view->visible() as $column) {
            $html .= '<td><input type="search" name="f[' . esc_attr($column->key) . ']" value="' . esc_attr($active[$column->key] ?? '') . '" form="' . esc_attr($searchFormId)
                . '" aria-label="' . esc_attr(sprintf(/* translators: %s: column */ __('Filter %s', 'taxmod'), $column->label)) . '" style="width:100%;min-width:4em"></td>';
        }

        return $html . '<td style="text-align:right">' . get_submit_button(__('Filter', 'taxmod'), 'small', '', false, ['form' => $searchFormId]) . '</td></tr>';
    }

    /**
     * Page links under a list, WordPress style, and how long the page took to draw (design 20: measured as part of stage 1).
     *
     * @param callable(array<string, string>): string $url the list's address with these query values changed
     */
    public static function pagination(int $total, int $page, int $perPage, callable $url): string
    {
        $pages = (int) ceil(max(1, $total) / max(1, $perPage));
        $page  = ListView::clampPage($page, $total, $perPage);
        $took  = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (int) round((microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000) : 0;
        $html  = '<div class="tablenav bottom"><div class="alignleft actions"><span class="description">'
            . esc_html(sprintf(/* translators: %d: milliseconds */ __('Drawn in %d ms', 'taxmod'), $took)) . '</span></div>';

        if ($pages > 1) {
            $link  = static fn (int $to, string $text, string $label): string => $to < 1 || $to > $pages || $to === $page
                ? '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">' . $text . '</span>'
                : '<a class="button" href="' . esc_url($url(['paged' => $to === 1 ? '' : (string) $to])) . '"><span class="screen-reader-text">' . esc_html($label) . '</span><span aria-hidden="true">' . $text . '</span></a>';
            $html .= '<div class="tablenav-pages"><span class="pagination-links">' . $link(1, '«', __('First page', 'taxmod')) . ' ' . $link($page - 1, '‹', __('Previous page', 'taxmod'))
                . ' <span class="paging-input">' . esc_html(sprintf(/* translators: 1: page, 2: pages */ __('%1$d of %2$d', 'taxmod'), $page, $pages)) . '</span> '
                . $link($page + 1, '›', __('Next page', 'taxmod')) . ' ' . $link($pages, '»', __('Last page', 'taxmod')) . '</span></div>';
        }

        return $html . '<br class="clear"></div>';
    }

    /** The button that shows or hides the filter row. */
    public static function filterToggle(): string
    {
        return '<div class="alignleft actions"><button type="button" class="button" onclick="'
            . esc_attr("this.closest('.wrap').querySelectorAll('.taxmod-filter-row').forEach(function(r){r.hidden=!r.hidden})") . '">'
            . esc_html__('Filter row', 'taxmod') . '</button></div>';
    }

    /**
     * The heads of the shown columns for {@see self::table()}: each sortable, a set filter marked with a dot.
     *
     * @param callable(array<string, string>): string $url the list's address with these query values changed
     * @return list<array{0: string, 1: string, 2: string, 3: ''|'asc'|'desc'}>
     */
    public static function heads(ListView $view, callable $url): array
    {
        $active = $view->filters();
        $heads  = [];

        foreach ($view->visible() as $column) {
            $current = $view->orderBy === $column->key;
            $next    = $current && ! $view->descending ? 'desc' : 'asc';
            $heads[] = [$column->label . (isset($active[$column->key]) ? ' •' : ''), $column->width, $url(['orderby' => $column->key, 'order' => $next === 'desc' ? 'desc' : '']),
                $current ? ($view->descending ? 'desc' : 'asc') : ''];
        }

        return $heads;
    }

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
     * @param string $filterRow from {@see self::filterRow()}, under the heads
     */
    public static function table(array $columns, string $rows, string $filterRow = ''): string
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
            . '<table class="wp-list-table widefat fixed striped table-view-list taxmod-list"><thead>' . $head . $filterRow . '</thead><tbody>' . $rows . '</tbody><tfoot>' . $head . '</tfoot></table>';
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
