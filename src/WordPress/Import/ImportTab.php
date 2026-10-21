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

    /**
     * What each single target is, in the order the tab shows them; `node` picks from the tree, an empty list leaves a bare id.
     *
     * @return array<string, array{0: string, 1: string}> key => [list, label]
     */
    private static function singles(): array
    {
        return [
            'orders_node'       => ['node', __('Orders node', 'taxmod')],
            'purchases_node'    => ['node', __('Purchases node', 'taxmod')],
            'origin_documented' => ['', __('Origin value «documented» (record id)', 'taxmod')],
            'amount_field'      => ['', __('Amount field of a line', 'taxmod')],
            'unit_field'        => ['', __('Unit field of a line', 'taxmod')],
        ];
    }

    public function render(): string
    {
        $targets = Targets::raw();
        $nodes   = $this->nodeNames();
        $lists   = [
            'node'     => $nodes,
            'order'    => $this->fieldNames((int) $targets['orders_node']),
            'purchase' => $this->fieldNames((int) $targets['purchases_node']),
        ];

        $html = '<h2>' . esc_html__('Take automatically', 'taxmod') . '</h2><p><label><input type="hidden" name="auto_take" value="0">'
            . '<input type="checkbox" name="auto_take" value="1"' . checked(AutoTake::user() !== 0, true, false) . '> '
            . esc_html__('Take new orders from the responsible source and status updates of existing orders without review. Anything else stays in the inbox; a status waits there until its order exists.', 'taxmod')
            . '</label></p>';

        $html .= '<h2>' . esc_html__('Where taken orders go', 'taxmod') . '</h2><p>'
            . esc_html__('Node and field ids for orders and purchases, the same for every module. Type an id or pick from the list; change them only when the tree was rebuilt.', 'taxmod') . '</p>';

        foreach ($lists as $list => $names) {
            $html .= '<datalist id="taxmod-import-' . esc_attr($list) . '">';

            foreach ($names as $id => $name) {
                $html .= '<option value="' . esc_attr((string) $id) . '" label="' . esc_attr($name) . '"></option>';
            }

            $html .= '</datalist>';
        }

        $rows = '';

        foreach (self::singles() as $key => [$list, $label]) {
            $rows .= $this->row('target[' . $key . ']', $label, (int) $targets[$key], $list, $lists[$list] ?? []);
        }

        foreach (['order_fields' => ['order', __('Order', 'taxmod')], 'purchase_fields' => ['purchase', __('Purchase', 'taxmod')]] as $group => [$list, $title]) {
            foreach ((array) $targets[$group] as $role => $id) {
                $rows .= $this->row('target[' . $group . '][' . $role . ']', $title . ': ' . $role, (int) $id, $list, $lists[$list]);
            }
        }

        foreach ((array) $targets['currencies'] as $code => $id) {
            $rows .= $this->row('target[currencies][' . $code . ']', __('Currency', 'taxmod') . ' ' . $code . ' ' . __('(record id)', 'taxmod'), (int) $id, '', []);
        }

        $html .= '<table class="form-table" role="presentation"><tbody>' . $rows . '</tbody></table>'
            . '<p><label for="taxmod-import-required"><strong>' . esc_html__('Purchase fields a line must fill (ids, comma separated)', 'taxmod') . '</strong></label><br>'
            . '<input type="text" id="taxmod-import-required" name="target[required_parts]" class="regular-text" value="'
            . esc_attr(implode(', ', array_map('intval', (array) $targets['required_parts']))) . '"></p>';

        return $html . '<details><summary>' . esc_html__('All at once as JSON', 'taxmod') . '</summary><p>'
            . esc_html__('Pasted JSON replaces the fields above. Left empty, the fields count.', 'taxmod') . '</p>'
            . '<textarea name="targets" rows="12" class="large-text code" aria-label="' . esc_attr__('Targets as JSON', 'taxmod') . '" placeholder="'
            . esc_attr((string) wp_json_encode($targets)) . '"></textarea></details>';
    }

    /** @param array<int, string> $names id => name of the list this field picks from */
    private function row(string $name, string $label, int $id, string $list, array $names): string
    {
        $field = 'taxmod-import-' . sanitize_key(str_replace(['[', ']'], '-', $name));
        $known = $id === 0 || $names === [] ? '' : ($names[$id] ?? __('not found', 'taxmod'));

        return '<tr><th scope="row"><label for="' . esc_attr($field) . '">' . esc_html($label) . '</label></th><td>'
            . '<input type="number" min="0" step="1" id="' . esc_attr($field) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $id) . '"'
            . ($list === '' ? '' : ' list="taxmod-import-' . esc_attr($list) . '"') . ' class="regular-text"> '
            . '<span class="description">' . esc_html($known) . '</span></td></tr>';
    }

    /** @return array<int, string> every node of the tree, «name (#id)», read once through the modeller's own ability */
    private function nodeNames(): array
    {
        $tree  = $this->ability('taxmod/read-tree', ['depth' => 12]);
        $names = [];

        foreach ((array) ($tree['rows'] ?? []) as $row) {
            $names[(int) $row['id']] = str_repeat('· ', (int) ($row['depth'] ?? 0)) . (string) $row['name'];
        }

        return $names;
    }

    /** @return array<int, string> the fields of one node, inherited ones included */
    private function fieldNames(int $nodeId): array
    {
        $node  = $nodeId > 0 ? $this->ability('taxmod/read-node', ['node' => $nodeId]) : [];
        $names = [];

        foreach ((array) ($node['fields'] ?? []) as $field) {
            $names[(int) $field['relation_id']] = (string) $field['name'];
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> empty when the ability is missing or refuses; the tab then shows bare ids
     */
    private function ability(string $name, array $input): array
    {
        $ability = function_exists('wp_get_ability') ? wp_get_ability($name) : null;
        $result  = $ability?->execute($input);

        return is_array($result) ? $result : [];
    }

    /** @param array<string, mixed> $post already unslashed */
    public function save(array $post): void
    {
        if (isset($post['auto_take'])) {
            AutoTake::switchTo((string) $post['auto_take'] === '1');
        }

        $json = trim(sanitize_textarea_field((string) ($post['targets'] ?? '')));

        if ($json !== '') {
            $targets = json_decode($json, true);

            if (is_array($targets)) {
                Targets::save($targets);
            }

            return;
        }

        $fields = (array) ($post['target'] ?? []);

        if (isset($fields['required_parts'])) {
            $fields['required_parts'] = array_values(array_filter(array_map('trim', explode(',', (string) $fields['required_parts'])), 'is_numeric'));
        }

        Targets::save($fields);
    }
}
