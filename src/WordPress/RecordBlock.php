<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Renderer\ComparisonPosition;
use Taxmod\Core\Renderer\HintMarkup;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\Query;
use Taxmod\WordPress\Persistence\Schema;

/**
 * Der Block `taxmod/record` — ein Satz aus dem Modell im Beitrag, wie die Seite «Anzeige» der Vorschau ihn zeigt ([D-912](../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart LR
 *   B["Block: Satz-Id, Feld-Id"] --> R["render_callback"] --> S["NodesScreen::recordForReaders"]
 *   S --> H["Purpose::Display · Level::FrontEnd"]
 * ```
 *
 * ⚠️ **Der Beitrag hält nur, welche Sätze und Felder** (`Level::Block`): *die Werte kommen aus dem Modell.* Die fertige Ausgabe
 * wird gepuffert und neu gebaut, sobald sich die Daten oder die Einstellungen des Blocks ändern
 * ([D-919](../../docs/NewConcept/90-decision-log.md)) — der Schlüssel enthält beides.
 *
 * Anzeigen ([D-916](../../docs/NewConcept/90-decision-log.md)): «Satz» und «Vergleich» ({@see NodesScreen::comparisonForReaders()}).
 * ⚠️ *«Rückverweise» ist nicht gebaut.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class RecordBlock
{
    public const NAME = 'taxmod/record';

    private const SCRIPT = 'taxmod-record-block';
    private const VIEW_SCRIPT = 'taxmod-record-block-view';

    public const COMPARISON = 'comparison';

    /** Die Sätze am Ende eines Weges als Tabelle — die Datenblätter einer Stückliste (D-955). */
    public const COLLECTED = 'collected';

    /** The records of another node whose field `via` points at this record, as a table with `rowFields` as columns (D-916). */
    public const REFERRERS = 'referrers';
    /** One table: the record itself as one row, or with `via` the records pointing here through it (D-991). */
    public const TABLE = 'table';

    private const CACHE = 'taxmod_block_';

    private const ROUTES = 'taxmod/v1';

    /** @param \Closure(): NodesScreen $screen Erst beim Zeichnen gebaut — die meisten Seiten zeigen keinen Satz. */
    public function __construct(
        private readonly \Closure $screen,
        private readonly string $pluginFile,
    ) {
    }

    public function register(): void
    {
        wp_register_script(
            self::SCRIPT,
            plugins_url('assets/record-block.js', $this->pluginFile),
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n', 'wp-api-fetch', 'wp-data'],
            Plugin::VERSION,
            true
        );

        wp_set_script_translations(self::SCRIPT, Plugin::TEXT_DOMAIN);

        // *Sein Wunsch am 2026-10-19: «sichtbar machen das es noch nach rechts oder links geht» — the scrollbar is easy to miss.*
        wp_register_script(self::VIEW_SCRIPT, plugins_url('assets/record-block-view.js', $this->pluginFile), [], Plugin::VERSION, ['in_footer' => true, 'strategy' => 'defer']);

        wp_register_style(self::SCRIPT, plugins_url('assets/record-block.css', $this->pluginFile), [], Plugin::VERSION);

        register_block_type(self::NAME, [
            'api_version'     => 3,
            'title'           => __('Model record', 'taxmod'),
            'description'     => __('Shows one record of the Taxonomy Modeller, as the display side of the preview draws it.', 'taxmod'),
            'category'        => 'widgets',
            'icon'            => 'networking',
            'keywords'        => ['taxmod'],
            'attributes'      => [
                'display'   => ['type' => 'string', 'default' => 'record'],
                'record'    => ['type' => 'integer', 'default' => 0],
                'field'     => ['type' => 'integer', 'default' => 0],
                // *Listen als Text «36064, 36065» — so tippt man sie in ein Feld der Seitenleiste.*
                'records'   => ['type' => 'string', 'default' => ''],
                'node'        => ['type' => 'integer', 'default' => 0],
                'position'    => ['type' => 'string', 'default' => ComparisonPosition::Listed->value],
                'columnLabel' => ['type' => 'integer', 'default' => 0],
                'via'       => ['type' => 'integer', 'default' => 0],
                'rowKey'    => ['type' => 'integer', 'default' => 0],
                'rowFields' => ['type' => 'string', 'default' => ''],
                'value'     => ['type' => 'integer', 'default' => 0],
                'groupBy'   => ['type' => 'integer', 'default' => 0],
                // *Der Weg der Sammeltabelle als Feld-Ids, und der Filter am Ende (D-955).*
                'path'       => ['type' => 'string', 'default' => ''],
                'whereField' => ['type' => 'integer', 'default' => 0],
                'whereValue' => ['type' => 'integer', 'default' => 0],
            ],
            'supports'        => ['html' => false, 'align' => ['wide', 'full']],
            'editor_script'   => self::SCRIPT,
            'style'           => self::SCRIPT,
            'view_script'     => self::VIEW_SCRIPT,
            'render_callback' => $this->render(...),
        ]);
    }

    /**
     * What the block's sidebar asks for: nodes by name, and a node's records and fields with their names ([D-924](../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Only for people who may write posts — the answers name the model's records. The cookie nonce is checked by
     * WordPress before the callback runs.*
     */
    public function registerRoutes(): void
    {
        $mayEdit = static fn (): bool => current_user_can('edit_posts');

        register_rest_route(self::ROUTES, '/block-nodes', [
            'methods'             => \WP_REST_Server::READABLE,
            'permission_callback' => $mayEdit,
            'args'                => [
                'search' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
            'callback'            => static fn (\WP_REST_Request $request): \WP_REST_Response => new \WP_REST_Response(
                self::nodesNamed((string) $request->get_param('search'))
            ),
        ]);

        // *Blocks written before 0.1.10 hold no node — the sidebar finds it from their main record.*
        register_rest_route(self::ROUTES, '/block-record-node/(?P<id>\\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'permission_callback' => $mayEdit,
            'args'                => ['id' => ['type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint']],
            'callback'            => static function (\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
                global $wpdb;

                $node = (int) Query::value('Knoten des Satzes lesen', $wpdb->prepare(
                    'SELECT node_id FROM ' . Schema::table('node_records') . ' WHERE id = %d',
                    absint($request->get_param('id'))
                ));

                return $node === 0
                    ? new \WP_Error('taxmod_no_record', __('No such record.', 'taxmod'), ['status' => 404])
                    : new \WP_REST_Response(['node' => $node]);
            },
        ]);

        register_rest_route(self::ROUTES, '/block-node/(?P<id>\\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'permission_callback' => $mayEdit,
            'args'                => [
                'id'      => ['type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint'],
                'records' => ['type' => 'boolean', 'default' => true],
                'label'   => ['type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'],
            ],
            'callback'            => function (\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
                try {
                    return new \WP_REST_Response(($this->screen)()->blockEditorNode(
                        absint($request->get_param('id')),
                        (bool) $request->get_param('records'),
                        absint($request->get_param('label'))
                    ));
                } catch (\InvalidArgumentException $e) {
                    return new \WP_Error('taxmod_no_node', $e->getMessage(), ['status' => 404]);
                }
            },
        ]);
    }

    /** @return list<array{id: int, name: string, parent: string, records: int}> */
    private static function nodesNamed(string $search): array
    {
        global $wpdb;

        $search = trim($search);

        if ($search === '') {
            return [];
        }

        $nodes   = Schema::table('nodes_named');
        $records = Schema::table('node_records');
        $rows    = Query::rows('Knoten fuer den Block suchen', $wpdb->prepare(
            "SELECT n.id, n.name, p.name AS parent, COALESCE(c.records, 0) AS records
               FROM {$nodes} n
               LEFT JOIN {$nodes} p ON p.id = n.parent_node_id
               LEFT JOIN (SELECT node_id, COUNT(*) AS records FROM {$records}
                           WHERE relation_id = 0 AND record_type = 'user' GROUP BY node_id) c ON c.node_id = n.id
              WHERE n.name LIKE %s
              ORDER BY records = 0, n.name
              LIMIT 25",
            '%' . $wpdb->esc_like($search) . '%'
        ));

        return array_map(static fn (array $row): array => [
            'id'      => (int) $row['id'],
            'name'    => (string) $row['name'],
            'parent'  => (string) ($row['parent'] ?? ''),
            'records' => (int) $row['records'],
        ], $rows);
    }

    /** @param array<string, mixed> $attributes */
    public function render(array $attributes): string
    {
        $comparison = ($attributes['display'] ?? '') === self::COMPARISON;
        $record     = absint($attributes['record'] ?? 0);

        if ($record === 0 && (!$comparison || self::ids((string) ($attributes['records'] ?? '')) === [])) {
            return '';
        }

        // ⚠️ *Der Stand des Modells ist die letzte Zeile des Änderungsbuchs: jede Änderung schreibt eine, also ändert jede den
        // Schlüssel (D-919). Ein veralteter Eintrag wird nie wieder gefragt und läuft ab.*
        $key    = self::CACHE . md5((string) wp_json_encode([$attributes, SettingsScreen::neutralLocale(), determine_locale(), wp_date('Y-m-d'), Plugin::VERSION, self::dataStamp()]));
        $cached = get_transient($key);

        if (is_string($cached)) {
            return $cached;
        }

        // ⚠️ *Ein Satz, der fehlt, ist im Beitrag kein weisser Bildschirm: Lesern zeigt der Block nichts, Bearbeitern den Grund.*
        try {
            // ⚠️ *His word of 2026-10-25 (D-991): «dass man Display von dem trennt … wenn da nichts ausgewählt ist, dann nimmt er das
            // eigentliche Rekord. Und ansonsten bezieht er sich auf den Referring-Rekord». Display says how, `via` says which:
            // empty is the record itself, a field the records pointing here through it. The old «referrers» is a table with `via`.*
            $display = (string) ($attributes['display'] ?? '');
            $via     = absint($attributes['via'] ?? 0);
            $fields  = self::ids((string) ($attributes['rowFields'] ?? ''));
            $markup = $display === self::REFERRERS || ($display === self::TABLE && $via !== 0)
                ? ($this->screen)()->referrersForReaders($record, $via, $fields, SettingsScreen::neutralLocale())
                : ($display === self::TABLE
                ? ($this->screen)()->recordAsTableForReaders($record, $fields, SettingsScreen::neutralLocale())
                : (($display === '' || $display === 'record') && $via !== 0
                ? ($this->screen)()->referrerFormsForReaders($record, $via, $fields, SettingsScreen::neutralLocale())
                : (($attributes['display'] ?? '') === self::COLLECTED
                ? ($this->screen)()->collectedForReaders(
                    $record,
                    self::ids((string) ($attributes['path'] ?? '')),
                    self::ids((string) ($attributes['rowFields'] ?? '')),
                    absint($attributes['whereField'] ?? 0),
                    absint($attributes['whereValue'] ?? 0),
                    SettingsScreen::neutralLocale()
                )
                : ($comparison
                ? ($this->screen)()->comparisonForReaders(
                    self::ids((string) ($attributes['records'] ?? '')),
                    $record,
                    ComparisonPosition::fromWord((string) ($attributes['position'] ?? '')),
                    absint($attributes['via'] ?? 0),
                    absint($attributes['rowKey'] ?? 0),
                    self::ids((string) ($attributes['rowFields'] ?? '')),
                    absint($attributes['value'] ?? 0),
                    // ⚠️ *Keine Wörter über den Spalten: das Plugin hat keine deutsche Übersetzung, ein «In this post» stünde englisch
                    // im deutschen Beitrag. Die Farbe trägt die Rolle (D-920, sein Wort: «das mit der farblichen Darstellung finde ich gut»).*
                    '',
                    '',
                    absint($attributes['groupBy'] ?? 0),
                    absint($attributes['columnLabel'] ?? 0),
                )
                : ($this->screen)()->recordForReaders($record, absint($attributes['field'] ?? 0), SettingsScreen::neutralLocale(), $fields)))));
        } catch (\Throwable $e) {
            return current_user_can('edit_posts')
                ? '<p class="taxmod-record-missing">' . esc_html(sprintf(
                    /* translators: 1: record id, 2: reason */
                    __('Model record %1$d cannot be shown: %2$s', 'taxmod'),
                    $record,
                    $e->getMessage()
                )) . '</p>'
                : '';
        }

        // *Ein breiter Vergleich scrollt in sich, nicht die Seite.*
        $inner = $comparison ? '<div class="taxmod-comparison-wrap">' . $markup . '</div>' : self::withoutHints($markup);
        $html  = '<div ' . get_block_wrapper_attributes(['class' => 'taxmod-record-block']) . '>' . $inner . '</div>';
        set_transient($key, $html, DAY_IN_SECONDS);

        return $html;
    }

    /** @return list<int> */
    private static function ids(string $list): array
    {
        return array_values(array_filter(array_map('absint', preg_split('/[^0-9]+/', $list) ?: [])));
    }

    private static function dataStamp(): string
    {
        return (string) Query::value('Stand des Modells lesen', 'SELECT MAX(id) FROM ' . Schema::table('changelog'));
    }

    /**
     * Ohne die Fragezeichen der Maske: Hilfetexte sind für Bearbeiter, nicht für Leser (D-912).
     *
     * ⚠️ *Das Stylesheet blendete sie nur aus — im Markup standen sie weiter und kamen in Textauszügen, Feeds und beim
     * Kopieren mit (gemeldet am 2026-10-11 an Beitrag 14256). Die Hülle hat genau eine Form ({@see HintMarkup::icon()}),
     * und ihr Satz ist entschärft, enthält also kein `<`.*
     */
    private static function withoutHints(string $markup): string
    {
        $pattern = '#<span class="' . HintMarkup::NAME . '" tabindex="0"><span class="' . HintMarkup::ICON . '">.*?</span></span>'
            . '<span class="' . HintMarkup::TEXT . '">[^<]*</span></span>#s';

        return (string) preg_replace($pattern, '', $markup);
    }
}
