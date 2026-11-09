<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Renderer\SummaryRenderer;

/**
 * The modeller's record choice for a module that draws its own form: greyed field, tree icon, dialog with the tree on the left,
 * the node's records on the right and «New here …» below them (D-1182).
 *
 * @see docs/NewConcept/90-decision-log.md D-1182
 */
final class RecordPick
{
    private static bool $wordsDrawn = false;

    /**
     * @param list<array{depth: int, name: string, records: array<int, string>, node?: int}> $tree Pre-order rows under the root, each with its own records.
     */
    public static function field(string $name, array $tree, ?int $chosen, string $formId = '', bool $mayBeNothing = true): string
    {
        $newWord    = __('New here …', 'taxmod');
        $createWord = __('Create', 'taxmod');

        foreach ($tree as $i => $row) {
            if ((int) ($row['node'] ?? 0) > 0) {
                $tree[$i]['newWord']    = $newWord;
                $tree[$i]['createWord'] = $createWord;
            }
        }

        return SummaryRenderer::pick($name, $formId, array_values($tree), $chosen, [
            'ok'     => __('OK', 'taxmod'),
            'cancel' => __('Cancel', 'taxmod'),
            'tree'   => __('Show the tree', 'taxmod'),
            'clear'  => __('Remove this choice', 'taxmod'),
            'search' => __('Search', 'taxmod'),
        ], $mayBeNothing) . self::words();
    }

    /**
     * The modeller's script and stylesheet, and where the script creates a record from the dialog — once per page. Every module
     * form that can draw a record dialog draws this carrier (D-1271): without it «Create» in the dialog did nothing at all.
     */
    public static function words(): string
    {
        if (self::$wordsDrawn) {
            return '';
        }

        self::$wordsDrawn = true;

        $base = WP_PLUGIN_DIR . '/' . basename(dirname(__DIR__, 3)) . '/x.php';
        wp_enqueue_style('taxmod-admin', plugins_url('assets/admin.css', $base), ['dashicons', 'buttons'], \Taxmod\WordPress\Plugin::VERSION);
        wp_enqueue_script('taxmod-admin', plugins_url('assets/admin.js', $base), [], \Taxmod\WordPress\Plugin::VERSION, true);

        // *Kein zweites `#taxmod-words`: ein Modul hat oft schon eines für seine eigene Suche; das Skript sucht die Adresse an jedem Träger.*
        return '<span hidden class="taxmod-pick-words" data-record-create="' . esc_url(add_query_arg([
            'action'      => NodesScreen::RECORD_CREATE_ACTION,
            '_ajax_nonce' => wp_create_nonce(NodesScreen::RECORD_CREATE_ACTION),
        ], admin_url('admin-post.php'))) . '"'
            . ' data-reload-list="' . esc_attr__('This list is no longer available. Please reload the page.', 'taxmod') . '"'
            . ' data-save-failed="' . esc_attr__('Not saved — the connection failed. Your input is still here.', 'taxmod') . '"></span>';
    }
}
