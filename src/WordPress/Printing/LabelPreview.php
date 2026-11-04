<?php declare(strict_types=1);

namespace Taxmod\WordPress\Printing;

use Taxmod\Core\Printing\FilledLabel;
use Taxmod\Core\Printing\LabelLayout;
use Taxmod\WordPress\Plugin;

/**
 * Draws a label as a preview: hand it a label (size and layout) and, if you like, a record — it shows the PDF and says which
 * fields it could not fill (D-1161).
 *
 * ⚠️ *His word 2026-11-04: «Du übergibst ein Etikett an diesen Renderer und er rendert dir ein Preview.» Without a record the
 * examples written in the layout stand ([D-1154](../../../docs/NewConcept/90-decision-log.md)).*
 *
 * ```mermaid
 * flowchart LR
 *   E["label: format + layout"] --> P["LabelPreview"]
 *   R["record (optional)"] -- "fields by name (D-1160)" --> P
 *   P --> H["PDF in a frame + what stayed empty"]
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1161
 */
final class LabelPreview
{
    /**
     * Filter: `(string $html, array $label, int $record = 0): string` — `$label` as for {@see LabelButton::FILTER}:
     * `['name', 'format' => ['key', 'w', 'h', 'roll'], 'layout' => JSON, 'values' => [field ⇒ text], 'code' => 'O-123']`.
     */
    public const FILTER = 'taxmod_label_preview';

    /** The frame's width on screen; the height follows the label. */
    private const WIDTH_PX = 360;

    public static function boot(): void
    {
        add_filter(self::FILTER, static fn (mixed $html, array $label = [], int $record = 0): string => self::render($label, $record), 10, 3);
    }

    /** @param array<string, mixed> $label */
    public static function render(array $label, int $record = 0): string
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            return '';
        }

        try {
            $layout = LabelLayout::fromJson((string) ($label['layout'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return self::notice('error', __('The layout cannot be drawn:', 'taxmod') . ' ' . $e->getMessage());
        }

        $fields = $layout->fields();
        $read   = LabelButton::recordValues($record, $fields);
        $given  = is_array($label['values'] ?? null) ? array_filter($label['values'], 'is_scalar') : [];
        $values = $record > 0 ? $read['values'] + array_map('strval', $given) : $layout->examples() + array_map('strval', $given);
        $values['code'] ??= (string) ($label['code'] ?? 'O-12345');

        try {
            $filled = FilledLabel::fromJson((string) json_encode([
                'format'   => $label['format'] ?? [],
                'layout'   => json_decode($layout->toJson(), true),
                'values'   => $values,
                'template' => (string) ($label['name'] ?? ''),
            ]));
        } catch (\InvalidArgumentException | \JsonException $e) {
            return self::notice('error', __('The label cannot be drawn:', 'taxmod') . ' ' . $e->getMessage());
        }

        $empty  = array_values(array_filter($fields, static fn (string $f): bool => trim((string) ($values[$f] ?? '')) === ''));
        $height = (int) round(self::WIDTH_PX * $filled->format->heightMm / max(1.0, $filled->format->widthMm)) + 40;
        $source = $record > 0
            ? sprintf(/* translators: %d: record id */ __('Preview with record #%d.', 'taxmod'), $record)
            : __('Preview with the examples written in the layout («example»).', 'taxmod');

        return '<div class="taxmod-label-preview" style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">'
            . '<iframe title="' . esc_attr__('Label preview', 'taxmod') . '" src="data:application/pdf;base64,' . esc_attr(base64_encode($filled->render()))
            . '#toolbar=0&amp;view=Fit" style="width:' . self::WIDTH_PX . 'px;height:' . $height . 'px;border:1px solid #c3c4c7;background:#fff"></iframe>'
            . '<div><p class="description">' . esc_html($source) . ' ' . esc_html(sprintf(/* translators: %s: field names */ __('Fields: %s.', 'taxmod'), implode(', ', $fields))) . '</p>'
            . ($read['unknown'] === [] ? '' : self::notice('warning', sprintf(/* translators: %s: field names */ __('No such field in the record: %s.', 'taxmod'), implode(', ', $read['unknown']))))
            . ($empty === [] ? '' : '<p class="description">' . esc_html(sprintf(/* translators: %s: field names */ __('Empty on this label: %s.', 'taxmod'), implode(', ', $empty))) . '</p>')
            . '</div></div>';
    }

    private static function notice(string $kind, string $text): string
    {
        return '<div class="notice notice-' . esc_attr($kind) . ' inline"><p>' . esc_html($text) . '</p></div>';
    }
}
