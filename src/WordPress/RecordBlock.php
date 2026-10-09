<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Admin\SettingsScreen;

/**
 * Der Block `taxmod/record` — ein Satz aus dem Modell im Beitrag, wie die Seite «Anzeige» der Vorschau ihn zeigt ([D-912](../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart LR
 *   B["Block: Satz-Id, Feld-Id"] --> R["render_callback"] --> S["NodesScreen::recordForReaders"]
 *   S --> H["Purpose::Display · Level::FrontEnd"]
 * ```
 *
 * ⚠️ **Der Beitrag hält nur, welcher Satz und welches Feld** (`Level::Block`): *die Werte kommen bei jedem Aufruf aus dem
 * Modell — eine Änderung dort steht sofort in jedem Beitrag, der den Satz zeigt.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class RecordBlock
{
    public const NAME = 'taxmod/record';

    private const SCRIPT = 'taxmod-record-block';

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
                'record' => ['type' => 'integer', 'default' => 0],
                'field'  => ['type' => 'integer', 'default' => 0],
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
        $record = absint($attributes['record'] ?? 0);
        $field  = absint($attributes['field'] ?? 0);

        if ($record === 0) {
            return '';
        }

        // ⚠️ *Ein Satz, der fehlt, ist im Beitrag kein weisser Bildschirm: Lesern zeigt der Block nichts, Bearbeitern den Grund.*
        try {
            $markup = ($this->screen)()->recordForReaders($record, $field, SettingsScreen::neutralLocale());
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

        return '<div ' . get_block_wrapper_attributes(['class' => 'taxmod-record-block']) . '>' . $markup . '</div>';
    }
}
