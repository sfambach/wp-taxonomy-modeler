<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

/**
 * The «Import» tab on the Configuration page (D-945, D-960): only the targets every source writes to, because each
 * installation numbers its nodes differently; the duty table is code (D-961). Capability and nonce are checked by the
 * page before `save()` runs; this class validates and sanitises its own fields (CD-5) and returns escaped HTML (CD-8).
 *
 * @see docs/pakete/import/konzept.md §3.6
 */
final class ImportTab
{
    public const SLUG = 'import';

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
        return '<h2>' . esc_html__('Where taken orders go', 'taxmod') . '</h2><p>'
            . esc_html__('Node and field ids for orders and purchases, the same for every module. Change them only when the tree was rebuilt.', 'taxmod') . '</p>'
            . '<textarea name="targets" rows="16" class="large-text code" aria-label="' . esc_attr__('Targets', 'taxmod') . '">'
            . esc_textarea((string) wp_json_encode(Targets::raw(), JSON_PRETTY_PRINT)) . '</textarea>';
    }

    /** @param array<string, mixed> $post already unslashed */
    public function save(array $post): void
    {
        $targets = json_decode(sanitize_textarea_field((string) ($post['targets'] ?? '')), true);

        if (is_array($targets)) {
            Targets::save($targets);
        }
    }
}
