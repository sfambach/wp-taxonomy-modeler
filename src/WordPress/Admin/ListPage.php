<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Renderer\HintMarkup;
use Taxmod\Core\Listing\ListColumn;
use Taxmod\Core\Renderer\DialogMarkup;
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
 *   R --> D["details row · opens below · drawn when opened"]
 * ```
 *
 * D-1147: a long list waits for a search before it draws a row ({@see self::waitsForSearch()}), and a row's details may be
 * drawn only when they are opened ({@see self::lazyDetails()}): the page asks admin-ajax, the module answers through
 * {@see self::DETAILS_FILTER}.
 *
 * @see docs/NewConcept/90-decision-log.md D-989
 */
final class ListPage
{
    /** The admin-post action that keeps a user's column choice (D-1054). */
    public const COLUMNS_ACTION = 'taxmod_list_columns';

    /** The admin-ajax action that draws one row's details when it is opened (D-1147). */
    public const DETAILS_ACTION = 'taxmod_list_details';

    /** The filter a module answers to draw the details of its rows: `(null, string $source, array<string, string> $args)` → HTML or null. */
    public const DETAILS_FILTER = 'taxmod_list_details';

    /** The query value that asks a list waiting for a search to show everything, page by page (D-1147). */
    public const SHOW_ALL = 'all';

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

    /** Whether the request asks a list waiting for a search to show all of it (D-1147). */
    public static function showsAll(): bool
    {
        return sanitize_key((string) ($_GET[self::SHOW_ALL] ?? '')) === '1';
    }

    /**
     * D-1147: a list that waits for a search draws no row until the user searched, set a column filter, narrowed it with a
     * filter of the page (`$narrowed`) or asked for everything. ⚠️ *His word, 2026-11-04: «umso mehr Materialien wir brauchen,
     * umso länger braucht es das erste Mal, diese Materialliste zu öffnen … dass man einen Suchbegriff eingibt und dass dann nur
     * das Gesuchte kommt, anstatt alles».*
     */
    public static function waitsForSearch(ListView $view, bool $narrowed = false): bool
    {
        return ! $view->narrowed() && ! $narrowed && ! self::showsAll();
    }

    /**
     * The one row a waiting list shows: what to do, and «Show all» for the mass view, which stays paged.
     *
     * @param int      $columns the shown columns, without checkbox and actions
     * @param int|null $total   how many entries «Show all» brings, when that is known without drawing them
     */
    public static function searchPrompt(int $columns, string $allUrl, ?int $total = null): string
    {
        $all = $total === null ? __('Show all', 'taxmod') : sprintf(/* translators: %d: number of entries */ __('Show all (%d)', 'taxmod'), $total);

        return '<tr class="no-items taxmod-list-prompt"><td class="colspanchange" colspan="' . esc_attr((string) ($columns + 2)) . '">'
            . '<span class="dashicons dashicons-search" aria-hidden="true"></span> '
            . esc_html__('Enter a search term or set a filter: only what matches is loaded.', 'taxmod')
            . ' <a class="button" href="' . esc_url($allUrl) . '">' . esc_html($all) . '</a></td></tr>';
    }

    /**
     * The details of a row, drawn when the row is opened (D-1147): a placeholder the page fills from admin-ajax. The module
     * answers {@see self::DETAILS_FILTER} for `$source`, checks its own capability, and returns the HTML.
     *
     * @param array<string, string|int> $args what the module needs to draw this row's details again
     */
    public static function lazyDetails(string $source, array $args): string
    {
        return '<div class="taxmod-list-lazy" data-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-source="' . esc_attr(sanitize_key($source)) . '"'
            . ' data-args="' . esc_attr((string) wp_json_encode(array_map('strval', $args))) . '" data-nonce="' . esc_attr(wp_create_nonce(self::DETAILS_ACTION)) . '">'
            . '<span class="spinner is-active" aria-hidden="true"></span> <span class="description">' . esc_html__('Loading …', 'taxmod') . '</span></div>';
    }

    /** admin-ajax: capability → nonce → validate → sanitize → the module draws → JSON. */
    public static function details(): void
    {
        if (! current_user_can('read')) {
            wp_send_json_error(['message' => __('Not allowed.', 'taxmod')], 403);
        }

        check_ajax_referer(self::DETAILS_ACTION, 'nonce');

        $source  = sanitize_key((string) wp_unslash($_POST['source'] ?? ''));
        $decoded = json_decode((string) wp_unslash($_POST['args'] ?? '{}'), true);
        $args    = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_scalar($value)) {
                $args[sanitize_key((string) $key)] = sanitize_text_field((string) $value);
            }
        }

        try {
            $html = $source === '' ? null : apply_filters(self::DETAILS_FILTER, null, $source, $args);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }

        if (! is_string($html)) {
            wp_send_json_error(['message' => __('These details are not available.', 'taxmod')], 404);
        }

        wp_send_json_success(['html' => $html]);
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

    /**
     * The heading: the plugin, then the screen one is on, then the version — the only `<h1>` a Taxmod screen draws (D-989,
     * D-1186). A `$hint` is the screen's explanation behind the question mark (D-661).
     */
    public static function heading(string $screen, string $version = Plugin::VERSION, string $plugin = 'Taxonomy Modeller', string $hint = ''): string
    {
        $title = esc_html($plugin) . ' › ' . esc_html($screen);

        return '<h1 class="wp-heading-inline" style="font-size:1.8em">' . ($hint === '' ? $title : HintMarkup::behind($title, $hint)) . '</h1>'
            . ' <span class="taxmod-version" style="display:inline-block;vertical-align:middle;margin-left:.5em;padding:2px 8px;border-radius:10px;background:#2271b1;color:#fff;font-size:12px;font-weight:600">v' . esc_html($version) . '</span>'
            . self::messages() . '<hr class="wp-header-end">';
    }

    /**
     * The pictures of a list's rows (D-1204): nothing when the Configuration has them off; else each media id as a small
     * thumbnail that loads only when scrolled to. The attachments are read in one go first, so a long list costs one
     * query, not one per row (CD-7).
     *
     * @param array<int, int> $media row key → attachment id (0: none)
     * @return array<int, string> row key → `<img>` or ''
     */
    public static function thumbnails(array $media): array
    {
        if (! SettingsScreen::showsListImages()) {
            return array_map(static fn (): string => '', $media);
        }

        $ids = array_values(array_unique(array_filter($media)));

        if ($ids !== []) {
            _prime_post_caches($ids, false, true);
        }

        return array_map(static fn (int $id): string => $id > 0 ? (string) wp_get_attachment_image($id, [40, 40], false, ['class' => 'taxmod-list-thumb', 'loading' => 'lazy', 'decoding' => 'async', 'alt' => '']) : '', $media);
    }

    /** Whether lists draw a picture column at all (D-1204). */
    public static function showsImages(): bool
    {
        return SettingsScreen::showsListImages();
    }

    /**
     * A form or detail with its facts in a box to the right (D-1220) — from 1100 px wide; narrower, the box stands above.
     * ⚠️ *His word, 2026-11-07: «die erklärbox ginge locker rechts hin».* The one exception to «sections one below the
     * other» (D-1196): the box belongs to the section, it is not a section of its own.
     *
     * @param string $main  the form or detail, already escaped
     * @param string $facts the box's content, already escaped
     */
    public static function beside(string $main, string $facts): string
    {
        return '<div class="taxmod-beside"><div class="taxmod-beside-facts">' . $facts . '</div><div class="taxmod-beside-main">' . $main . '</div></div>';
    }

    /**
     * The message field beside the heading (D-1203): one line with the last message, the recent ones open below it —
     * written by `assets/messages.js`, so an act that does not reload the page still says what it did. ⚠️ *His word,
     * 2026-11-06: «oben neben die Überschrift, aber so, dass es nicht verzerrt … dass man die letzten Nachrichten noch sehen kann».*
     */
    public static function messages(): string
    {
        return '<span class="taxmod-messages" role="status" aria-live="polite"><button type="button" class="taxmod-messages-last" aria-expanded="false"></button>'
            . '<ol class="taxmod-messages-list" hidden></ol></span>';
    }

    /**
     * The standard page (D-1194, his word 2026-11-06 «kacheln oben dann untereinander die seiten sections»): the heading, the
     * tabs if any, the tiles, then the sections one below the other — a detail, a list, another list. Each section is drawn by
     * its own building block; this only orders them, so every Taxmod page reads the same from top to bottom.
     *
     * @param list<\Taxmod\Core\Tile\Tile>        $tiles
     * @param list<array{title?: string, html: string}> $sections `html` already escaped (CD-8)
     */
    public static function page(string $screen, array $tiles, array $sections, string $version = Plugin::VERSION, string $plugin = 'Taxonomy Modeller', string $hint = '', string $tabs = ''): string
    {
        $html = '<div class="wrap taxmod-page">' . self::heading($screen, $version, $plugin, $hint) . $tabs . ($tiles === [] ? '' : Tiles::grid($tiles));

        foreach ($sections as $section) {
            $title = (string) ($section['title'] ?? '');
            $html .= '<section class="taxmod-page-section">' . ($title === '' ? '' : '<h2>' . esc_html($title) . '</h2>') . $section['html'] . '</section>';
        }

        return $html . '</div>';
    }

    /**
     * Bulk actions on the left, «Expand all» beside them, the count on the right. The form stands alone, because each
     * row's details may hold a form of their own and forms do not nest; each row's checkbox joins it through `form=`.
     *
     * @param array<string, string> $actions action value => label
     * @param string $filters from {@see self::filters()}, beside the bulk actions
     * @param bool $expandable false for a list whose rows open their own view instead (D-1183): no «Expand all»
     * @param array<string, array{0: string, 1: string}> $asking dialog switch → choice label, the dialog ({@see self::dialogAction()} joining
     *                                                     `$formId`): chosen and run, the dialog opens for the rows ticked
     */
    public static function bulkBar(string $formId, string $nonceAction, array $actions, string $count, string $filters = '', bool $expandable = true, array $asking = []): string
    {
        // His word 2026-11-06: «-- , drucken ... und was wir noch für aktionen haben in das selekt und dann bulk ation asuführen
        // button (nimmt nur selektierte einträge» — every action of the list stands in the one choice (D-1202).
        $options = '<option value="">--</option>';
        $dialogs = '';

        foreach ($actions as $value => $label) {
            $options .= '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }

        foreach ($asking as $switch => [$label, $dialog]) {
            $options .= '<option value="ask:' . esc_attr($switch) . '" data-taxmod-ask="' . esc_attr($switch) . '">' . esc_html($label) . '</option>';
            $dialogs .= $dialog;
        }

        return '<div class="tablenav top"><form method="post" id="' . esc_attr($formId) . '" action="' . esc_url(admin_url('admin-post.php')) . '" class="alignleft actions bulkactions" onsubmit="' . esc_attr(self::confirmBulk($formId)) . '">'
            . wp_nonce_field($nonceAction, '_wpnonce', true, false)
            . '<label class="screen-reader-text" for="' . esc_attr($formId) . '-action">' . esc_html__('Bulk actions', 'taxmod') . '</label>'
            . '<select name="action" id="' . esc_attr($formId) . '-action">' . $options . '</select> '
            . get_submit_button(__('Run bulk action', 'taxmod'), 'action', '', false) . '</form>'
            . ($dialogs === '' ? '' : '<div class="taxmod-bulk-asks">' . $dialogs . '</div>')
            . $filters
            . (! $expandable ? '' : '<div class="alignleft actions">'
            . '<button type="button" class="button" onclick="' . esc_attr(self::toggleAll(false)) . '">' . esc_html__('Expand all', 'taxmod') . '</button> '
            . '<button type="button" class="button" onclick="' . esc_attr(self::toggleAll(true)) . '">' . esc_html__('Collapse all', 'taxmod') . '</button></div>')
            . '<div class="tablenav-pages one-page"><span class="displaying-num">' . esc_html($count) . '</span></div><br class="clear"></div>';
    }

    /**
     * The stages of a process as arrow tiles that grip into each other, in their order, each with its count (D-1197);
     * views beside the process (archive, all) follow as plain tabs.
     *
     * @param list<array{0: string, 1: string, 2?: int|null}> $steps slug, label, optional count — in process order
     * @param list<array{0: string, 1: string, 2?: int|null}> $beside slug, label, optional count
     * @param callable(string): string $url the address of a step or tab
     */
    public static function steps(array $steps, string $active, callable $url, array $beside = []): string
    {
        $html = '<nav class="taxmod-steps" aria-label="' . esc_attr__('Process', 'taxmod') . '"><ol>';

        foreach ($steps as $step) {
            $html .= '<li><a class="taxmod-step' . ($step[0] === $active ? ' is-active' : '') . (($step[2] ?? null) === 0 ? ' is-empty' : '') . '" href="' . esc_url($url($step[0])) . '"'
                . ($step[0] === $active ? ' aria-current="step"' : '') . '><span class="taxmod-step-label">' . esc_html($step[1]) . '</span>'
                . (isset($step[2]) ? '<span class="taxmod-step-count">' . esc_html(number_format_i18n((int) $step[2])) . '</span>' : '') . '</a></li>';
        }

        $html .= '</ol>';

        foreach ($beside as $tab) {
            $html .= '<a class="nav-tab taxmod-steps-beside' . ($tab[0] === $active ? ' nav-tab-active' : '') . '" href="' . esc_url($url($tab[0])) . '">' . esc_html($tab[1])
                . (isset($tab[2]) ? ' <span class="count">(' . (int) $tab[2] . ')</span>' : '') . '</a>';
        }

        return $html . '</nav>';
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

        // D-1227: every list sorts, groups and filters by the same head, drawn by one script over the rows drawn here.
        wp_enqueue_script(Plugin::LIST_HEAD_SCRIPT);

        return '<style>.taxmod-list-toggle{display:inline-flex;align-items:center;justify-content:center;vertical-align:middle;padding:0 6px}.taxmod-list-toggle .dashicons{width:20px;height:20px;font-size:20px;line-height:1;transition:transform .15s}.taxmod-list-toggle[aria-expanded="true"] .dashicons{transform:rotate(180deg)}'
            // D-1068: one height for every button in a list, his word 2026-11-01 «button höhen us sollten generell definiert werden damit sie übgerall gleich aussehen».
            . '.wp-core-ui .taxmod-list .button{display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;height:30px;min-height:30px;line-height:1;padding-top:0;padding-bottom:0;vertical-align:middle;font-size:13px}'
            // D-1230: a row opened by its link (#id) stands out, e.g. a lot from the Platzbestand.
            . '.taxmod-list tr:target > *{background:#fff8c5}'
            . self::detailsStyle() . '</style>' . self::detailsScript()
            . '<table class="wp-list-table widefat fixed striped table-view-list taxmod-list"><thead>' . $head . $filterRow . '</thead><tbody>' . $rows . '</tbody><tfoot>' . $head . '</tfoot></table>';
    }

    /**
     * The icons of the recurring acts, one place for all lists ([D-1230](../../../docs/NewConcept/90-decision-log.md)) — his word
     * 2026-11-08: «generell drucken knopf durch icon drucker ersetzten wie löschen durch papierkorb und vielleicht gibts ja auch was
     * für umbuchen zwei gegenläufige pfeile», «dann gleich noch ein icon für ausbuchen». Dashicon keys without their prefix.
     */
    public const ICON_PRINT = 'printer';
    public const ICON_TRANSFER = 'leftright';
    public const ICON_BOOK_OUT = 'migrate';
    public const ICON_DELETE = 'trash';

    /** An icon in place of a button's word; the word stays for the screen reader and as the tooltip. */
    public static function icon(string $icon, string $label): string
    {
        return '<span title="' . esc_attr($label) . '">' . \Taxmod\Core\Renderer\IconMarkup::dashicon($icon) . '</span>'
            . '<span class="screen-reader-text">' . esc_html($label) . '</span>';
    }

    /** A button that is a link, drawn as its icon. */
    public static function iconLink(string $icon, string $label, string $url): string
    {
        return '<a class="button" href="' . esc_url($url) . '" title="' . esc_attr($label) . '">' . self::icon($icon, $label) . '</a>';
    }

    /**
     * A row or bar action that asks in a dialog before it is sent: the button opens the dialog, its fields go with the form
     * the dialog sits in (or with `$form`), and only its own confirm button submits. Every list draws its dialog actions here,
     * so they look the same everywhere ([D-1063](../../../docs/NewConcept/90-decision-log.md)). ⚠️ *His word, 2026-11-01:
     * «wir sollten generell dialog aktionen einführen damit die immer gleich aussehen».*
     *
     * @param string $switch the dialog's id, unique on the page
     * @param string $body   the dialog's fields, already escaped
     */
    public static function dialogAction(
        string $switch,
        string $opener,
        string $title,
        string $body,
        string $submitName,
        string $submitValue,
        string $submitLabel,
        string $form = '',
        string $icon = '',
    ): string {
        $submit = '<button type="submit" class="button button-primary" name="' . esc_attr($submitName) . '" value="' . esc_attr($submitValue) . '"'
            . ($form === '' ? '' : ' form="' . esc_attr($form) . '"') . '>' . esc_html($submitLabel) . '</button>';

        return DialogMarkup::of(
            $switch,
            $icon === '' ? esc_html($opener) : self::icon($icon, $opener),
            esc_html($title),
            '<span class="taxmod-dialog-body" style="display:block;padding:.75em 1em;white-space:normal;text-align:left">' . $body . '</span>',
            $submit,
            'button taxmod-dialog-open',
            '',
            __('Cancel', 'taxmod')
        );
    }

    /**
     * One row: its checkbox joins the bulk form, the buttons stand on the right after the toggle, the details open below —
     * already open when the module's setting says so («Automatisch aufklappen», his wish of 2026-10-25). `$details` may be
     * {@see self::lazyDetails()}: then they are drawn when opened (D-1147).
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

        // His word 2026-11-05: «die aufklapp logik soll es nicht mehr geben sondern man klickt auf das item und bekommt eine
        // teilview des listeneintrags» — a row without details has no toggle; its link opens the entry's own view.
        if ($details === '') {
            return $html . '<td style="text-align:right;white-space:nowrap">' . $buttons . '</td></tr>';
        }

        return $html . '<td style="text-align:right;white-space:nowrap">'
            . '<button type="button" class="button taxmod-list-toggle" aria-expanded="' . ($expanded ? 'true' : 'false') . '" title="' . esc_attr__('Details', 'taxmod') . '" aria-label="' . esc_attr__('Details', 'taxmod') . '" onclick="taxmodListShow(this)">'
            // ⚠️ *His words: 2026-10-25 «als richtigen Button aber ruhig mit diesen Pfeil nach unten», 2026-10-28 «Der Pfeil im Button
            // ist immer noch komisch. Kannst du nicht irgendwie ein Icon nehmen?» — so the button is the icon alone; it turns when open.*
            . '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button> ' . $buttons . '</td></tr>'
            . '<tr class="taxmod-list-details"' . ($expanded ? '' : ' hidden') . '><td colspan="' . (count($cells) + 2) . '">'
            . (trim($details) === '' ? '' : '<div class="taxmod-list-panel">' . $details . '</div>') . '</td></tr>';
    }

    /**
     * The attribute that makes a form, button or link ask first in the shared dialog (`assets/confirm-dialog.js`) — the one
     * way a Taxmod page asks before it acts; never `window.confirm` (D-804, D-1063, D-1186).
     */
    public static function confirm(string $message): string
    {
        // A module page that is not the modeller's own still gets the dialog's script and look (as `LabelButton` does).
        if (! wp_script_is('taxmod-confirm')) {
            do_action(Plugin::STYLE_ACTION);
        }

        return ' data-taxmod-confirm="' . esc_attr($message) . '"';
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

        // A dialog's own confirm button has a name and has already asked; only the bar's button asks here.
        return "if(this.dataset.taxmodOk||(event.submitter&&event.submitter.name)){delete this.dataset.taxmodOk;return true}var f=this,n=0;document.querySelectorAll('input[name=\"selected[]\"][form=\"" . esc_js($formId) . "\"]:checked').forEach(function(c){n+=c.value.split(';').length});"
            . "var s=this.querySelector('select');if(!s.value){return false}"
            . 'if(n===0){alert(' . $none . ');return false}'
            . "var o=s.options[s.selectedIndex];if(o.dataset.taxmodAsk){document.getElementById(o.dataset.taxmodAsk).checked=true;return false}"
            . 'taxmodConfirm(' . $message . ".replace('%1\$s',s.options[s.selectedIndex].text).replace('%2\$d',n),function(){f.dataset.taxmodOk=1;f.requestSubmit()});return false";
    }

    private static function toggleAll(bool $hidden): string
    {
        return "this.closest('.wrap').querySelectorAll('.taxmod-list-toggle').forEach(function(b){taxmodListShow(b," . ($hidden ? 'false' : 'true') . ')})';
    }

    /**
     * D-1147: the details read as a panel under their row, the open row stands out, and the stripes count a row with its
     * details as one. ⚠️ *His word, 2026-11-04: «meist sieht es nicht so gut aus».* *Measured: with a details row after every
     * row, WordPress' `nth-child(odd)` striped every visible row alike.*
     */
    private static function detailsStyle(): string
    {
        return '.taxmod-list.striped>tbody:has(>tr.taxmod-list-details)>tr{background:transparent}'
            . '.taxmod-list.striped>tbody:has(>tr.taxmod-list-details)>tr:nth-child(4n+1),.taxmod-list.striped>tbody:has(>tr.taxmod-list-details)>tr:nth-child(4n+2){background:#f6f7f7}'
            . '.taxmod-list>tbody>tr.taxmod-list-open>*,.taxmod-list>tbody>tr.taxmod-list-open+tr.taxmod-list-details>td{background:#f0f6fc}'
            . '.taxmod-list>tbody>tr.taxmod-list-open>th{box-shadow:inset 4px 0 0 #2271b1}'
            . '.taxmod-list>tbody>tr.taxmod-list-details>td{padding:0 12px 12px 12px;border-top:0}'
            . '.taxmod-list-panel{background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;border-radius:4px;padding:12px 16px;box-shadow:0 1px 2px rgba(0,0,0,.05);overflow-x:auto}'
            . '.taxmod-list-panel>:first-child{margin-top:0}.taxmod-list-panel>:last-child{margin-bottom:0}'
            . '.taxmod-list-panel h3,.taxmod-list-panel h4{margin:.8em 0 .4em;font-size:13px;text-transform:uppercase;letter-spacing:.03em;color:#50575e}'
            . '.taxmod-list-lazy{display:flex;align-items:center;gap:6px;min-height:30px}.taxmod-list-lazy .spinner{float:none;margin:0}'
            . '.taxmod-list-lazy[data-state="done"]{display:block;min-height:0}'
            . '.taxmod-list-prompt>td{padding:28px 12px!important;text-align:center;color:#50575e;font-size:14px}.taxmod-list-prompt .dashicons{vertical-align:middle;color:#8c8f94}'
            . '.taxmod-list-prompt .button{margin-left:8px}'
            // D-1227: the bar above every list, and the group rows.
            . '.taxmod-list-head{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:6px 0}.taxmod-list-head-search{min-width:16em}'
            . '.taxmod-list-head .displaying-num{margin-left:auto;color:#50575e}.taxmod-list-head-group select{vertical-align:middle}'
            . '.taxmod-filter-row-client input{width:100%;min-width:4em}'
            . '.taxmod-list>tbody>tr.taxmod-list-group>td{background:#f0f0f1;padding:4px 10px;border-top:1px solid #dcdcde}'
            . '.taxmod-list-group-toggle{background:none;border:0;padding:2px 0;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-size:13px}'
            . '.taxmod-list-group-toggle .dashicons{transition:transform .15s}.taxmod-list-group-toggle[aria-expanded="false"] .dashicons{transform:rotate(-90deg)}'
            . '.taxmod-list-group-count{color:#50575e}';
    }

    /**
     * Opening a row, once for the page: a row's details drawn on opening are fetched then, at most four at a time, and their
     * scripts run (D-1147). Rows the page opens on its own («Automatisch aufklappen») load as soon as the page stands.
     */
    private static function detailsScript(): string
    {
        $failed = (string) wp_json_encode(__('The details could not be loaded.', 'taxmod'));
        $retry  = (string) wp_json_encode(__('Try again', 'taxmod'));

        return '<script>window.taxmodListShow||(function(){var queue=[],running=0;'
            . 'function next(){if(running>=4||!queue.length){return}var el=queue.shift();running++;'
            . 'var body=new URLSearchParams({action:' . wp_json_encode(self::DETAILS_ACTION) . ',source:el.dataset.source,args:el.dataset.args,nonce:el.dataset.nonce});'
            . 'fetch(el.dataset.url,{method:"POST",credentials:"same-origin",body:body}).then(function(r){return r.json()}).then(function(j){'
            . 'if(!j||!j.success){throw new Error(j&&j.data&&j.data.message||"")}el.innerHTML=j.data.html;el.dataset.state="done";'
            . 'el.querySelectorAll("script").forEach(function(o){var n=document.createElement("script");n.textContent=o.textContent;o.replaceWith(n)});'
            . 'el.dispatchEvent(new CustomEvent("taxmod:details",{bubbles:true}))}).catch(function(e){el.dataset.state="failed";'
            . 'el.innerHTML="";var p=document.createElement("span");p.className="description";p.textContent=' . $failed . '+(e&&e.message?" ("+e.message+")":"");'
            . 'var b=document.createElement("button");b.type="button";b.className="button button-small";b.textContent=' . $retry . ';'
            . 'b.onclick=function(){delete el.dataset.state;load(el)};el.append(p," ",b)}).finally(function(){running--;next()})}'
            . 'function load(el){if(el.dataset.state){return}el.dataset.state="loading";queue.push(el);next()}'
            . 'window.taxmodListShow=function(b,show){var row=b.closest("tr"),d=row.nextElementSibling;if(!d||!d.classList.contains("taxmod-list-details")){return}'
            . 'if(show===undefined){show=d.hidden}d.hidden=!show;b.setAttribute("aria-expanded",String(show));row.classList.toggle("taxmod-list-open",show);'
            . 'if(show){d.querySelectorAll(".taxmod-list-lazy").forEach(load)}};'
            . 'function start(){document.querySelectorAll(".taxmod-list-toggle[aria-expanded=true]").forEach(function(b){taxmodListShow(b,true)})}'
            . 'document.readyState==="loading"?document.addEventListener("DOMContentLoaded",start):setTimeout(start)})();</script>';
    }
}
