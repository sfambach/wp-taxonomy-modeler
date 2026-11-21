<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\WordPress\ModelDoor;
use Taxmod\WordPress\Plugin;

/**
 * The one rules page (D-1558, his word 21.11.2026 «ein Regelwerk und das ist gleich und du baust zwei unterschiedliche
 * Softwareteile»): the rule types are the children of the rules root, switched at the top; each type's rules are listed in
 * their order, opened in the record editor, created and removed here. What a rule *does* stays with the module that reads it.
 *
 * ```mermaid
 * flowchart LR
 *   S["taxmod_rules_setup: root, name, node, filters"] --> P["one page, a tab per child of the root"]
 *   T["taxmod_rule_types: position, roots, hint per type"] --> P
 *   P -->|"Auge / Neue Regel"| E["record editor"]
 *   P -->|"Papierkorb / Sammel-Löschen"| W["one act, undoable"]
 * ```
 *
 * The module that registers the page answers `taxmod_rules_setup`; each module that reads a type answers `taxmod_rule_types`.
 *
 * @see docs/NewConcept/90-decision-log.md D-1558
 */
final class RulesPage
{
    public const SLUG   = 'taxmod-regeln';
    public const ACTION = 'taxmod_rules';

    public const SETUP_FILTER = 'taxmod_rules_setup';
    public const TYPES_FILTER = 'taxmod_rule_types';

    private const BULK_FORM = 'taxmod-rules-bulk';

    /**
     * @param array<int, array{position?: int, roots?: array<int, list<int>>, hint?: string, one?: string}> $types type node → what its module says of it
     * @param array{kind: int, field: int, operator: int, value: int} $filterFields the filter's fields, «Art · Feld · Operator · Wert»
     */
    public function __construct(
        private readonly int $root,
        private readonly int $nameField,
        private readonly int $nodeField,
        private readonly int $filtersField,
        private readonly array $filterFields,
        private readonly array $types,
        /** The heading names the module that registers the page, with its version. */
        private readonly string $plugin = 'Taxonomy Modeller',
        private readonly string $version = Plugin::VERSION,
        private readonly ModelDoor $model = new ModelDoor(),
    ) {
    }

    /** The page as the registering module set it up; null while nothing is set up. */
    public static function fromSetup(): ?self
    {
        $setup = apply_filters(self::SETUP_FILTER, null);

        if (! is_array($setup) || absint($setup['root'] ?? 0) === 0) {
            return null;
        }

        $root  = absint($setup['root']);
        $types = apply_filters(self::TYPES_FILTER, [], $root);

        $filter = (array) ($setup['filter_fields'] ?? []);

        return new self($root, absint($setup['name'] ?? 0), absint($setup['node'] ?? 0), absint($setup['filters'] ?? 0),
            ['kind' => absint($filter['kind'] ?? 0), 'field' => absint($filter['field'] ?? 0), 'operator' => absint($filter['operator'] ?? 0), 'value' => absint($filter['value'] ?? 0)],
            is_array($types) ? $types : [], (string) ($setup['plugin'] ?? 'Taxonomy Modeller'), (string) ($setup['version'] ?? Plugin::VERSION));
    }

    public static function boot(): void
    {
        add_action('admin_post_' . self::ACTION, static function (): void {
            $page = self::fromSetup();

            if ($page === null) {
                ListPage::fail(__('No rules are set up.', 'taxmod'));
            }

            $page->handle();
        });
    }

    /** @param array<string, int|string> $args */
    public static function url(int $type = 0, array $args = []): string
    {
        return add_query_arg(['page' => self::SLUG] + ($type > 0 ? ['tab' => $type] : []) + $args, admin_url('admin.php'));
    }

    /** @return array<int, string> type node → its name, in the tree's order */
    public function types(): array
    {
        return $this->model->children($this->root);
    }

    public function render(): string
    {
        $types = $this->types();

        if ($types === []) {
            return ListPage::page(__('Rules', 'taxmod'), [], [['html' => '<div class="notice notice-warning inline"><p>' . esc_html__('The rules node has no rule types yet.', 'taxmod') . '</p></div>']], $this->version, $this->plugin);
        }

        $type   = absint($_GET['tab'] ?? 0);
        $type   = isset($types[$type]) ? $type : (int) array_key_first($types);
        $tabs   = ListPage::tabs(array_map(static fn (int $id, string $name): array => [(string) $id, $name], array_keys($types), $types), (string) $type, static fn (string $id): string => self::url((int) $id));
        $rules  = $this->rulesOf($type);
        $hint   = (string) ($this->types[$type]['hint'] ?? '');
        $one    = (string) ($this->types[$type]['one'] ?? $types[$type]);
        $chosen = absint($_GET['satz'] ?? 0);

        if (isset($rules[$chosen])) {
            $word = $rules[$chosen]['word'];
            // His answer 21.11.2026 07:19 «Es muss ein Knoten ausgewählt werden» — said after a save without one, not at creation.
            $missing = $rules[$chosen]['node'] > 0 || empty($_GET['gespeichert']) ? '' : '<div class="notice notice-error inline"><p>' . esc_html__('A node must be chosen. Without a node the rule does not apply.', 'taxmod') . '</p></div>';
            [$bin, $binForm] = $this->removeDialog($type, $chosen, $word, true);

            return ListPage::page($types[$type], [], [['html' => $missing . ListPage::recordHead($one, $word, self::url($type), $types[$type], $hint)
                . (string) apply_filters(Plugin::RECORD_EDITOR_FILTER, '', $chosen, self::url($type, ['satz' => $chosen, 'gespeichert' => 1]), [[$bin]], $this->types[$type]['roots'] ?? []) . $binForm]], $this->version, $this->plugin, tabs: $tabs);
        }

        $own     = $this->ownFields($type);
        $columns = [[__('No.', 'taxmod'), '4em'], [__('Rule', 'taxmod'), '14em'], [__('Node', 'taxmod'), ''], [__('Filters', 'taxmod'), '']];

        foreach ($own as $name) {
            $columns[] = [$name, ''];
        }

        $removed = absint($_GET['entfernt'] ?? 0);
        $notice  = $removed === 0 ? '' : '<div class="notice notice-success inline"><p>' . esc_html(sprintf(/* translators: %d: number of rules */ _n('%d rule removed.', '%d rules removed.', $removed, 'taxmod'), $removed)) . '</p></div>';

        return ListPage::page($types[$type], [], [['html' => $notice . ListPage::listHead($types[$type], count($rules), $hint)
            . ListPage::bulkBar(self::BULK_FORM, self::ACTION, ['remove' => __('Delete', 'taxmod')], '', '<div class="alignleft actions">' . $this->createButton($type) . '</div><input type="hidden" form="' . esc_attr(self::BULK_FORM) . '" name="typ" value="' . esc_attr((string) $type) . '">', false, route: self::ACTION)
            . ListPage::table($columns, $this->rows($type, $rules, array_keys($own)))]], $this->version, $this->plugin, tabs: $tabs);
    }

    /** admin-post «Neue Regel» and «Löschen»: capability → nonce → validate → act (CD-5, D-1254). */
    public function handle(): void
    {
        $type = absint($_POST['typ'] ?? 0);
        $back = self::url($type);

        if (! current_user_can(Plugin::CAPABILITY)) {
            ListPage::fail(__('You are not allowed to do this.', 'taxmod'), $back);
        }

        ListPage::nonce(self::ACTION);

        if (! isset($this->types()[$type])) {
            ListPage::fail(__('Unknown rule type.', 'taxmod'), self::url());
        }

        $do = sanitize_key(wp_unslash((string) ($_POST['do'] ?? ''))) ?: sanitize_key(wp_unslash((string) ($_POST['bulk'] ?? '')));

        ListPage::act(function () use ($do, $type): string {
            if ($do === 'create') {
                return self::url($type, ['satz' => $this->create($type)]);
            }

            if ($do === 'remove') {
                $chosen = array_values(array_unique(array_filter(array_map('absint', (array) wp_unslash($_POST['selected'] ?? [])))));
                $this->remove($type, $chosen);

                return self::url($type, ['entfernt' => count($chosen)]);
            }

            throw new \RuntimeException(__('Unknown action.', 'taxmod'));
        }, $back);
    }

    /**
     * The rules of a type in the order they are checked — by position where the type has one, then by id.
     *
     * @return array<int, array{position: int, word: string, node: int, node_word: string, filters: string}> record → its words
     */
    private function rulesOf(int $type): array
    {
        $records  = $this->model->records($type);
        $position = (int) ($this->types[$type]['position'] ?? 0);
        $ids      = array_keys($records);
        $words    = $this->model->fieldWords($ids, array_values(array_filter([$this->nameField, $this->nodeField])));
        $filterOf = [];

        foreach ($records as $id => $values) {
            foreach ($values[$this->filtersField] ?? [] as $filter) {
                $filterOf[$id][] = (int) $filter;
            }
        }

        $filterIds = array_values(array_unique(array_merge([], ...array_values($filterOf))));
        $sentences = $this->filterSentences($filterIds);
        $out       = [];

        foreach ($records as $id => $values) {
            $node     = (int) ($values[$this->nodeField][0] ?? 0);
            $nodeWord = $node > 0 ? (string) ($words[$this->nodeField][$id] ?? '#' . $node) : __('no node – does not apply', 'taxmod');
            $name     = trim((string) ($words[$this->nameField][$id] ?? ''));
            $filters  = array_map(static fn (int $f): string => $sentences[$f] ?? '', $filterOf[$id] ?? []);
            // His word 21.11.2026: the name is optional — without one the rule is called by its node.
            $out[$id] = ['position' => $position > 0 ? (int) ($values[$position][0] ?? 0) : 0, 'word' => $name !== '' ? $name : $nodeWord, 'node' => $node, 'node_word' => $nodeWord,
                'filters' => implode(' · ', array_filter($filters, static fn (string $w): bool => $w !== ''))];
        }

        uksort($out, static fn (int $a, int $b): int => [$out[$a]['position'], $a] <=> [$out[$b]['position'], $b]);

        return $out;
    }

    /**
     * Each filter as one line, «Art Feld Operator Wert»: the kind as the list names it, the field by its name, the operator in
     * the rule book's words — an empty one is «gleich» (D-1541) — and the value as the option it names (D-1552).
     *
     * @param list<int> $filters
     * @return array<int, string> filter record → its line
     */
    private function filterSentences(array $filters): array
    {
        if ($filters === []) {
            return [];
        }

        $raw   = $this->model->valuesOf($filters);
        $kinds = $this->filterFields['kind'] > 0 ? $this->model->fieldWords($filters, [$this->filterFields['kind']]) : [];
        $asked = array_map(fn (int $f): array => ['field' => (int) ($raw[$f][$this->filterFields['field']][0] ?? 0), 'value' => (string) ($raw[$f][$this->filterFields['value']][0] ?? '')], $filters);
        $words = $this->model->filterWords($asked);
        $ops   = Plugin::operatorWords();
        $out   = [];

        foreach ($filters as $i => $f) {
            $operator = (string) ($raw[$f][$this->filterFields['operator']][0] ?? '');
            $out[$f]  = implode(' ', array_filter([
                trim((string) ($kinds[$this->filterFields['kind']][$f] ?? '')),
                $words[$i][0] ?? '',
                $ops[$operator === '' ? \Taxmod\Core\Rules\RuleBook::EQUALS : $operator] ?? $operator,
                $words[$i][1] ?? '',
            ], static fn (string $w): bool => $w !== ''));
        }

        return $out;
    }

    /**
     * The type's own fields — those it adds to the rules root — without its position, which stands in «Nr.».
     *
     * @return array<int, string> relation id → name
     */
    private function ownFields(int $type): array
    {
        $shared = $this->model->fields($this->root);
        $own    = [];

        foreach ($this->model->fields($type) as $relation => $field) {
            if (! isset($shared[$relation]) && $relation !== (int) ($this->types[$type]['position'] ?? 0) && $field['kind'] !== 'setting') {
                $own[$relation] = $field['name'];
            }
        }

        return $own;
    }

    /**
     * @param array<int, array{position: int, word: string, node: int, node_word: string, filters: string}> $rules
     * @param list<int> $own
     */
    private function rows(int $type, array $rules, array $own): string
    {
        if ($rules === []) {
            return '<tr class="no-items"><td class="colspanchange" colspan="' . (6 + count($own)) . '">' . esc_html__('No rule yet.', 'taxmod') . '</td></tr>';
        }

        $words = $this->model->fieldWords(array_keys($rules), $own);
        $body  = '';

        foreach ($rules as $id => $rule) {
            $url   = self::url($type, ['satz' => $id]);
            $cells = [esc_html($rule['position'] > 0 ? (string) $rule['position'] : '—'), '<a href="' . esc_url($url) . '"><strong>' . esc_html($rule['word']) . '</strong></a>',
                esc_html($rule['node_word']), esc_html($rule['filters'] !== '' ? $rule['filters'] : '—')];

            foreach ($own as $relation) {
                $cells[] = esc_html((string) ($words[$relation][$id] ?? '') ?: '—');
            }

            $body .= ListPage::row(self::BULK_FORM, (string) $id, $cells, ListPage::openButton($url) . ' ' . $this->removeDialog($type, $id, $rule['word'])[0], '');
        }

        return $body;
    }

    private function createButton(int $type): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">' . $this->hidden($type)
            . '<button type="submit" class="button" name="do" value="create">' . esc_html__('New rule', 'taxmod') . '</button></form>';
    }

    private function hidden(int $type): string
    {
        return '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="typ" value="' . esc_attr((string) $type) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', false, false);
    }

    /**
     * His words 21.11.2026 09:02 «Löschen-Button pro Zeile» and «das Löschen noch in der Regel selbst», asking first. In the open
     * rule the bin stands in the editor's toolbar, inside the editor's form; forms do not nest, so its own form stands beside the
     * editor and the dialog's button joins it by `form=`.
     *
     * @return array{0: string, 1: string} the bin with its dialog, and the form it submits (empty when the bin carries its own)
     */
    private function removeDialog(int $type, int $id, string $word, bool $besideForm = false): array
    {
        $formId = 'taxmod-rule-remove-form-' . $id;
        $body   = '<p>' . esc_html(sprintf(/* translators: %s: the rule */ __('Delete the rule «%s»?', 'taxmod'), $word)) . '</p>';
        $fields = $this->hidden($type) . '<input type="hidden" name="selected[]" value="' . esc_attr((string) $id) . '">';
        $dialog = ListPage::dialogAction('taxmod-rule-remove-' . $id, __('Delete', 'taxmod'), __('Delete rule', 'taxmod'), $body, 'do', 'remove', __('Delete', 'taxmod'), $besideForm ? $formId : '', ListPage::ICON_DELETE);
        $form   = static fn (string $inner, string $extra = ''): string => '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . $extra . '>' . $inner . '</form>';

        return $besideForm
            ? [$dialog, $form($fields, ' id="' . esc_attr($formId) . '" hidden')]
            : [$form($fields . $dialog, ' style="display:inline"'), ''];
    }

    /** A new rule of the type, behind the others where the type keeps a position. */
    private function create(int $type): int
    {
        $position = (int) ($this->types[$type]['position'] ?? 0);
        $values   = [];

        if ($position > 0) {
            $last = 0;

            foreach ($this->model->records($type) as $record) {
                $last = max($last, (int) ($record[$position][0] ?? 0));
            }

            $values[(string) $position] = (string) ($last + 1);
        }

        $answer = $this->model->write([['op' => 'create_record', 'ref' => 'regel', 'node' => (string) $type, 'values' => $values]]);

        return (int) ($answer['refs']['regel'] ?? 0);
    }

    /**
     * The ticked rules go in one act (undo by its number, D-1410). Only rules of this type — any other id is refused before
     * anything is written.
     *
     * @param list<int> $rules
     */
    private function remove(int $type, array $rules): void
    {
        if ($rules === [] || array_diff($rules, array_keys($this->model->records($type))) !== []) {
            throw new \RuntimeException(__('Nothing selected.', 'taxmod'));
        }

        $this->model->write(array_map(static fn (int $id): array => ['op' => 'remove_record', 'record' => $id], $rules));
    }
}
