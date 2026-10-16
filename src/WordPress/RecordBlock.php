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

    public const COMPARISON = 'comparison';

    private const CACHE = 'taxmod_block_';

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
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n'],
            Plugin::VERSION,
            true
        );

        wp_set_script_translations(self::SCRIPT, Plugin::TEXT_DOMAIN);

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
                'position'  => ['type' => 'string', 'default' => ComparisonPosition::Front->value],
                'via'       => ['type' => 'integer', 'default' => 0],
                'rowKey'    => ['type' => 'integer', 'default' => 0],
                'rowFields' => ['type' => 'string', 'default' => ''],
                'value'     => ['type' => 'integer', 'default' => 0],
                'groupBy'   => ['type' => 'integer', 'default' => 0],
            ],
            'supports'        => ['html' => false, 'align' => ['wide', 'full']],
            'editor_script'   => self::SCRIPT,
            'style'           => self::SCRIPT,
            'render_callback' => $this->render(...),
        ]);
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
        $key    = self::CACHE . md5((string) wp_json_encode([$attributes, SettingsScreen::neutralLocale(), Plugin::VERSION, self::dataStamp()]));
        $cached = get_transient($key);

        if (is_string($cached)) {
            return $cached;
        }

        // ⚠️ *Ein Satz, der fehlt, ist im Beitrag kein weisser Bildschirm: Lesern zeigt der Block nichts, Bearbeitern den Grund.*
        try {
            $markup = $comparison
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
                )
                : ($this->screen)()->recordForReaders($record, absint($attributes['field'] ?? 0), SettingsScreen::neutralLocale());
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
