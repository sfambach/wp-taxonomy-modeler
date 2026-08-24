<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Exception\DomainError;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\RestoreResult;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\Settings;
use Taxmod\Core\Service\Tree;
use Taxmod\WordPress\Plugin;

/**
 * The modelling screen: the tree on the left, the selected node on the right.
 *
 * ⚠️ **The split is [D-343](../../../docs/NewConcept/90-decision-log.md)**, and the layout table
 * holding it is scaffolding — a borderless table because that is the cheapest thing that will be
 * thrown away ([D-344](../../../docs/NewConcept/90-decision-log.md)). What survives is the split
 * itself, which the tree renderer will express properly.
 *
 * ⚠️ **It renders by returning a string** (`CD-8`). Nothing here echoes, which is what keeps the
 * output testable and stops half a page from being sent before an error is noticed.
 *
 * The order in {@see handlePost()} is the one the code standard prescribes and never varies:
 * **capability → nonce → validate → sanitize → act → escape on output** (`CD-5`).
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class NodesScreen
{
    private const ACTION = 'taxmod_node';

    public function __construct(
        private readonly ModelEditor $editor,
        private readonly Tree $tree,
        private readonly Settings $settings,
        private readonly Labels $labels,
        private readonly DataEntry $data,
        private readonly FrameworkNodes $framework,
    ) {
    }

    public function render(): string
    {
        $root  = $this->framework->root();
        $trash = $this->framework->trash();

        // Two queries for the whole tree, whatever its depth — the traversal is solved once,
        // in Tree, and this screen only draws what comes back (`CD-7`).
        $collapsed = $this->collapsedFromRequest();
        $rows      = $this->tree->rowsUnder($root, [$trash->id], $collapsed);
        $parked    = $this->tree->rowsUnder($trash, [], $collapsed);
        $selected  = $this->selectedFromRequest();

        $left  = '<h2>' . esc_html__('The tree', 'taxmod') . '</h2>';
        $left .= $this->addForm($root, __('Add a node at the top level', 'taxmod'));
        $left .= $this->table($rows, 'tree', $collapsed, $selected);
        $left .= '<h2>' . esc_html__('Trash', 'taxmod') . '</h2>';
        $left .= '<p class="description">'
            . esc_html__('Parked, not gone. A parked node is still a node, so nothing that pointed at it dangles.', 'taxmod')
            . '</p>';
        $left .= $this->table($parked, 'trash', $collapsed, $selected);

        $html  = '<div class="wrap">';
        $html .= '<h1>' . esc_html__('Taxonomy Modeller', 'taxmod') . '</h1>';
        $html .= $this->notice();
        $html .= '<table style="width:100%;border:0"><tr style="vertical-align:top">'
            . '<td style="width:55%;padding:0 1.5em 0 0">' . $left . '</td>'
            . '<td style="width:45%;padding:0">' . $this->detail($selected, $rows, $root) . '</td>'
            . '</tr></table>';

        return $html . '</div>';
    }

    // ---------------------------------------------------------------- the tree

    /**
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     * @param list<int>                                                                                           $collapsed
     */
    private function table(array $rows, string $mode, array $collapsed, ?Node $selected): string
    {
        if ($rows === []) {
            return '<p><em>' . esc_html__('Nothing here yet.', 'taxmod') . '</em></p>';
        }

        $body = '';

        foreach ($rows as $row) {
            $node   = $row['node'];
            $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $row['depth']);
            $here   = $selected !== null && $selected->id === $node->id;

            $body .= '<tr' . ($here ? ' style="background:#e8f0fb"' : '') . '>';
            $body .= '<td>' . $indent . $this->expander($row, $collapsed) . $this->nameLink($node, $here) . '</td>';
            $body .= '<td title="' . esc_attr__('How often this row has been written. Not a version to return to — see the change group.', 'taxmod') . '">'
                . (int) $node->version . '</td>';
            $body .= '<td>' . $this->rowActions($row, $mode) . '</td>';
            $body .= '</tr>';
        }

        return '<table class="wp-list-table widefat striped">'
            . '<thead><tr>'
            . '<th>' . esc_html__('Name', 'taxmod') . '</th>'
            . '<th style="width:5em">' . esc_html__('Writes', 'taxmod') . '</th>'
            . '<th style="width:12em">' . esc_html__('Actions', 'taxmod') . '</th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    private function nameLink(Node $node, bool $selected): string
    {
        $url = add_query_arg(
            ['page' => 'taxmod', 'taxmod_node' => $node->id],
            admin_url('admin.php')
        );

        return '<a href="' . esc_url($url) . '" title="' . esc_attr__('Show it on the right', 'taxmod') . '">'
            . ($selected ? '<strong>' : '') . esc_html($node->name) . ($selected ? '</strong>' : '')
            . '</a>';
    }

    /**
     * ⚠️ **Only what is used constantly stays in the row** ([U1](../../../docs/NewConcept/20-interaction.md)).
     * Renaming and moving moved to the right, where there is room for a field — the owner's own
     * call, and it is also what stops the row from carrying seven controls again.
     *
     * @param array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool} $row
     */
    private function rowActions(array $row, string $mode): string
    {
        $node = $row['node'];

        if ($mode === 'trash') {
            return $this->form($node->id, [
                ['restore', esc_html__('Restore', 'taxmod'), __('Put it back where it came from', 'taxmod')],
            ]);
        }

        $buttons = [['add_child_here', '+', __('Add a child under this node', 'taxmod')]];

        // U8: absent, not greyed — the tree already said which rows cannot move.
        if (! $row['isFirst']) {
            $buttons[] = ['up', '&uarr;', __('Move up among its siblings', 'taxmod')];
        }

        if (! $row['isLast']) {
            $buttons[] = ['down', '&darr;', __('Move down among its siblings', 'taxmod')];
        }

        return $this->form($node->id, $buttons);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $buttons value, label, title
     */
    private function form(int $id, array $buttons, string $extra = ''): string
    {
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:.3em;flex-wrap:wrap;align-items:center">'
            . $this->hidden($id) . $extra;

        foreach ($buttons as [$value, $label, $title]) {
            $html .= '<button class="button" name="do" value="' . esc_attr($value) . '" title="' . esc_attr($title) . '">'
                . $label . '</button>';
        }

        return $html . '</form>';
    }

    /**
     * @param array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool} $row
     * @param list<int>                                                                                     $collapsed
     */
    private function expander(array $row, array $collapsed): string
    {
        if (! $row['hasChildren']) {
            return '<span style="display:inline-block;width:1.6em"></span>';
        }

        $id   = $row['node']->id;
        $next = $row['collapsed']
            ? array_values(array_diff($collapsed, [$id]))
            : array_values(array_unique([...$collapsed, $id]));

        // ⚠️ The set lives in the address, not in a stored preference. Whether it should be
        // remembered is OQ-082 and is not answered by a scaffolding screen.
        $url = add_query_arg(
            array_filter([
                'page'             => 'taxmod',
                'taxmod_collapsed' => implode(',', $next),
                'taxmod_node'      => isset($_GET['taxmod_node']) ? absint($_GET['taxmod_node']) : null,
            ]),
            admin_url('admin.php')
        );

        return '<a href="' . esc_url($url) . '" style="display:inline-block;width:1.6em;text-decoration:none">'
            . ($row['collapsed'] ? '&#9656;' : '&#9662;') . '</a>';
    }

    private function addForm(Node $parent, string $label): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:1em 0;display:flex;gap:.5em">'
            . $this->hidden($parent->id)
            . '<input type="text" name="name" placeholder="' . esc_attr($label) . '" required style="flex:1">'
            . '<button class="button button-primary" name="do" value="create">' . esc_html__('Add', 'taxmod') . '</button>'
            . '</form>';
    }

    // ------------------------------------------------------------- the details

    /**
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     */
    private function detail(?Node $selected, array $rows, Node $root): string
    {
        if ($selected === null) {
            return '<h2>' . esc_html__('Nothing selected', 'taxmod') . '</h2>'
                . '<p class="description">'
                . esc_html__('Click a name in the tree and it appears here.', 'taxmod')
                . '</p>';
        }

        $html  = '<h2>' . esc_html($selected->name) . '</h2>';
        $html .= '<p class="description"><code>' . esc_html($selected->path) . '</code></p>';

        $html .= '<h3>' . esc_html__('Name', 'taxmod') . '</h3>';
        $html .= $this->form(
            $selected->id,
            [['rename', esc_html__('Rename', 'taxmod'), __('Give it another name', 'taxmod')]],
            '<input type="text" name="name" value="' . esc_attr($selected->name) . '" required style="flex:1">'
        );

        $html .= '<h3>' . esc_html__('Add a child', 'taxmod') . '</h3>';
        $html .= $this->form(
            $selected->id,
            [['add_child', esc_html__('Add', 'taxmod'), __('Add a child under this node', 'taxmod')]],
            '<input type="text" name="name" placeholder="' . esc_attr__('Name of the new child', 'taxmod') . '" required style="flex:1">'
        );

        $html .= '<h3>' . esc_html__('Place', 'taxmod') . '</h3>';
        $html .= $this->form(
            $selected->id,
            [
                ['move', esc_html__('Move', 'taxmod'), __('Hang it under the chosen node', 'taxmod')],
                ['trash', esc_html__('Trash branch', 'taxmod'), __('Trash this node and everything under it', 'taxmod')],
                ['trash_node', esc_html__('Trash node only', 'taxmod'), __('Its children move up to its parent, and lose what they inherited from it', 'taxmod')],
            ],
            $this->parentChooser($selected, $rows, $root)
        );

        $html .= $this->attributes($selected, $rows);

        $html .= $this->settingsPanel($selected);

        $html .= $this->labelsPanel($selected);

        return $html . $this->recordsPanel($selected);
    }

    /**
     * Where this node could go — everywhere except itself and its own subtree.
     *
     * ⚠️ **The impossible targets are left out rather than refused.** The core still refuses
     * them, because a screen is not a guarantee; but offering a choice that always fails is a
     * trap laid for the person using it.
     *
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     */
    private function parentChooser(Node $node, array $rows, Node $root): string
    {
        $options = '<option value="' . (int) $root->id . '">' . esc_html__('— top level —', 'taxmod') . '</option>';

        foreach ($rows as $row) {
            $candidate = $row['node'];

            if ($candidate->id === $node->id || $candidate->isDescendantOf($node)) {
                continue;
            }

            $options .= '<option value="' . (int) $candidate->id . '">'
                . esc_html(str_repeat('· ', $row['depth']) . $candidate->name)
                . '</option>';
        }

        return '<select name="target" style="flex:1">' . $options . '</select>';
    }

    /**
     * What the selected node has — its own attributes and the ones it inherits.
     *
     * ⚠️ **It draws no value**: names, targets and the kind, nothing rendered. The moment a
     * value has to appear it goes through a renderer
     * ([R20a](../../../docs/NewConcept/30-renderer.md)), and this panel is deleted rather than
     * grown ([D-344](../../../docs/NewConcept/90-decision-log.md)).
     *
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     */
    private function attributes(Node $selected, array $rows): string
    {
        $body  = '';
        $edges = $this->editor->attributesOf($selected->id);

        // Two queries for every attribute's whole chain, however many there are (`CD-7`).
        $resolved = $this->settings->resolveForUseSites($edges);
        $targets  = $this->editor->targetsOf($edges);

        foreach ($edges as $edge) {
            $target = $targets[$edge->toId] ?? null;
            $here   = $edge->fromId === $selected->id;

            $body .= '<tr>'
                . '<td><strong>' . esc_html($edge->name) . '</strong></td>'
                . '<td>' . esc_html($target?->name ?? '—') . '</td>'
                . '<td><code>' . esc_html($edge->kind->value) . '</code></td>'
                . '<td>' . ($here
                    ? esc_html__('own', 'taxmod')
                    : '<em>' . esc_html__('inherited', 'taxmod') . '</em>')
                . '</td>'
                . '<td>' . $this->multiplicityControl($edge, $resolved[$edge->id] ?? [], $here) . '</td>'
                . '</tr>';
        }

        $html  = '<h3>' . esc_html__('Attributes', 'taxmod') . '</h3>';
        $html .= '<p class="description">'
            . esc_html__('The kind is never chosen — it is read off the branch the target sits in.', 'taxmod')
            . '</p>';

        $html .= $body === ''
            ? '<p><em>' . esc_html__('None yet.', 'taxmod') . '</em></p>'
            : '<table class="wp-list-table widefat striped"><thead><tr>'
                . '<th>' . esc_html__('Name', 'taxmod') . '</th>'
                . '<th>' . esc_html__('Points at', 'taxmod') . '</th>'
                . '<th style="width:8em">' . esc_html__('Kind', 'taxmod') . '</th>'
                . '<th style="width:5em">' . esc_html__('From', 'taxmod') . '</th>'
                . '<th style="width:11em">' . esc_html__('How many', 'taxmod') . '</th>'
                . '</tr></thead><tbody>' . $body . '</tbody></table>';

        return $html . $this->attributeForm($selected, $rows);
    }

    /**
     * How often this attribute may occur — four constants, on the edge (D-351).
     *
     * ⚠️ **Only offered on an attribute the node owns.** An inherited attribute belongs to an
     * ancestor, and where a *subtype's* narrowing of it would hang is not decided
     * ([OQ-086](../../../docs/NewConcept/91-open-questions.md)). Offering a control that writes
     * to the ancestor's edge would change it for every sibling too — quietly, which is the worst
     * way to be wrong.
     *
     * @param array<string, \Taxmod\Core\Model\ResolvedSetting> $resolved
     */
    private function multiplicityControl(\Taxmod\Core\Model\Relation $edge, array $resolved, bool $isOwn): string
    {
        $current = $resolved[SettingKey::Multiplicity->value] ?? null;
        $now     = $current?->value->text;

        if (! $isOwn) {
            return '<em>' . esc_html($now ?? '—') . '</em>';
        }

        $options = '';

        foreach (Multiplicity::cases() as $one) {
            $options .= '<option value="' . esc_attr($one->value) . '"'
                . selected($now, $one->value, false) . '>' . esc_html($one->notation()) . '</option>';
        }

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:.3em">'
            . $this->hidden($edge->fromId)
            . '<input type="hidden" name="edge" value="' . (int) $edge->id . '">'
            . '<select name="setting_value">' . $options . '</select>'
            . '<button class="button" name="do" value="put_multiplicity" title="'
            . esc_attr__('It may only be narrowed to one it contains — 0..1 and 1..* contain neither', 'taxmod')
            . '">' . esc_html__('Set', 'taxmod') . '</button>'
            . '</form>';
    }

    /**
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     */
    private function attributeForm(Node $selected, array $rows): string
    {
        $options = '';

        foreach ($rows as $row) {
            $candidate = $row['node'];
            $branch    = $this->framework->branchOf($candidate);

            // Only what could actually be a target: inside a branch, and not the branch root
            // itself (D-238).
            if ($branch === null || $candidate->id === $this->framework->rootOf($branch)->id) {
                continue;
            }

            $options .= '<option value="' . (int) $candidate->id . '">'
                . esc_html($candidate->name . ' — ' . $branch->value)
                . '</option>';
        }

        if ($options === '') {
            return '<p><em>'
                . esc_html__('Nothing to point at yet: put a node under Model, Compositions, Data Types or Constants first.', 'taxmod')
                . '</em></p>';
        }

        return $this->form(
            $selected->id,
            [['add_attribute', esc_html__('Add attribute', 'taxmod'), __('Point at a target; the kind follows', 'taxmod')]],
            '<input type="text" name="name" placeholder="' . esc_attr__('Name of the attribute', 'taxmod') . '" required style="flex:1">'
            . '<select name="target" style="flex:1">' . $options . '</select>'
        );
    }


    /**
     * What the chain resolves to for this node, and where each value came from.
     *
     * ⚠️ **A diagnostic, not the settings editor.** It prints keys and raw values in a table —
     * it does **not** render them, because a rendered setting is a rendered value and that is
     * the line [R20a](../../../docs/NewConcept/30-renderer.md) draws. The real editor is the
     * frame of attributes under the `edit` purpose, and it arrives with the renderers.
     */
    private function settingsPanel(Node $selected): string
    {
        $chain    = $this->settings->chainFor($selected);
        $resolved = $this->settings->resolve($chain);

        ksort($resolved);

        $body = '';

        foreach ($resolved as $key => $setting) {
            $origin = $setting->setHere
                ? esc_html__('here', 'taxmod')
                : '<em>' . esc_html(sprintf(
                    /* translators: %d is the id of the node or edge the value came from. */
                    __('from #%d', 'taxmod'),
                    $setting->fromOwnerId
                )) . '</em>';

            $body .= '<tr>'
                . '<td><code>' . esc_html($key) . '</code></td>'
                . '<td>' . esc_html($setting->value->describe()) . '</td>'
                . '<td>' . $origin . '</td>'
                . '<td>' . ($setting->setHere
                    ? $this->form($selected->id, [['reset_setting', esc_html__('Reset', 'taxmod'), __('Make it inherited again — not the same as setting it to nothing', 'taxmod')]], '<input type="hidden" name="setting_key" value="' . esc_attr($key) . '">')
                    : '')
                . '</td>'
                . '</tr>';
        }

        $html  = '<h3>' . esc_html__('Settings', 'taxmod') . '</h3>';
        $html .= '<p class="description">'
            . esc_html__('Resolved along the chain: installation → model root → ancestors → node. Raw values — this is a diagnostic, not the editor.', 'taxmod')
            . '</p>';

        $html .= $body === ''
            ? '<p><em>' . esc_html__('Nothing set anywhere along the chain.', 'taxmod') . '</em></p>'
            : '<table class="wp-list-table widefat striped"><thead><tr>'
                . '<th>' . esc_html__('Key', 'taxmod') . '</th>'
                . '<th>' . esc_html__('Value', 'taxmod') . '</th>'
                . '<th style="width:7em">' . esc_html__('From', 'taxmod') . '</th>'
                . '<th style="width:6em"></th>'
                . '</tr></thead><tbody>' . $body . '</tbody></table>';

        return $html . $this->settingForm($selected);
    }

    private function settingForm(Node $selected): string
    {
        $options = '';

        foreach (SettingKey::cases() as $key) {
            // ⚠️ A node describes a thing, and a thing has no multiplicity — that is only
            // sayable about a *use* of it (D-351). The core refuses it here anyway; leaving it
            // in the list would just offer a choice that always fails.
            if ($key->isEdgeOnly()) {
                continue;
            }

            $options .= '<option value="' . esc_attr($key->value) . '">'
                . esc_html($key->value . ($key->isBounding() ? ' — ' . __('bounding', 'taxmod') : ''))
                . '</option>';
        }

        return $this->form(
            $selected->id,
            [
                ['put_setting', esc_html__('Set', 'taxmod'), __('Write it here; a bounding setting may only be narrowed', 'taxmod')],
                ['empty_setting', esc_html__('Set to nothing', 'taxmod'), __('Deliberately nothing here — later changes above will not arrive', 'taxmod')],
            ],
            '<select name="setting_key">' . $options . '</select>'
            . '<input type="text" name="setting_value" placeholder="' . esc_attr__('value', 'taxmod') . '" style="width:8em">'
        );
    }


    /**
     * What this node is called in each seeded role — resolved, with the chain doing its work.
     *
     * ⚠️ **A diagnostic like the settings panel**: it prints the resolved text and whether it
     * came from a stored label or fell through to the node's own name. The real editor offers
     * the roles beside the name, which is what keeps them filled at all (D-196) — and it
     * arrives with the renderers.
     */
    private function labelsPanel(Node $selected): string
    {
        $locale = $this->localeFromRequest();
        $stored = [];

        foreach ($this->labels->storedFor($selected->id) as $label) {
            $stored[$label->roleId . "\0" . $label->locale] = $label->text;
        }

        $body = '';

        foreach (SeededRole::cases() as $role) {
            $roleId  = $this->framework->roleId($role);
            $written = $stored[$roleId . "\0" . $locale] ?? null;

            $body .= '<tr>'
                . '<td><code>' . esc_html($role->value) . '</code></td>'
                . '<td>' . esc_html($this->labels->of($selected, $role, $locale)) . '</td>'
                . '<td>' . ($written !== null
                    ? esc_html__('stored', 'taxmod')
                    : '<em>' . esc_html__('fell through', 'taxmod') . '</em>')
                . '</td>'
                . '<td>' . ($role->translatableByDefault()
                    ? ''
                    : '<span title="' . esc_attr__('A symbol is the same everywhere; translating it invites a wrong entry.', 'taxmod') . '">'
                        . esc_html__('not translated by default', 'taxmod') . '</span>')
                . '</td>'
                . '</tr>';
        }

        $html  = '<h3>' . esc_html__('Labels', 'taxmod') . '</h3>';
        $html .= '<p class="description">'
            . esc_html(sprintf(
                /* translators: %s is a locale code, or the word for none. */
                __('Chain: role → help → the node\'s own name. Locale: %s', 'taxmod'),
                $locale === '' ? __('neutral', 'taxmod') : $locale
            ))
            . '</p>';

        $html .= '<table class="wp-list-table widefat striped"><thead><tr>'
            . '<th style="width:6em">' . esc_html__('Role', 'taxmod') . '</th>'
            . '<th>' . esc_html__('Shows as', 'taxmod') . '</th>'
            . '<th style="width:7em">' . esc_html__('Source', 'taxmod') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table>';

        $options = '';

        foreach (SeededRole::cases() as $role) {
            $options .= '<option value="' . esc_attr($role->value) . '">' . esc_html($role->value) . '</option>';
        }

        return $html . $this->form(
            $selected->id,
            [['put_label', esc_html__('Set label', 'taxmod'), __('Write it for this role and locale', 'taxmod')]],
            '<select name="label_role">' . $options . '</select>'
            . '<input type="text" name="label_locale" value="' . esc_attr($locale) . '" placeholder="' . esc_attr__('locale', 'taxmod') . '" style="width:6em">'
            . '<input type="text" name="label_text" placeholder="' . esc_attr__('text', 'taxmod') . '" style="flex:1">'
        );
    }

    /**
     * Write every field of one record.
     *
     * ⚠️ **The values arrive as plain text and are guessed into a type** — a number is a number,
     * everything else is text. That is the whole of D-350's raw surface, and it is why it must
     * be deleted rather than grown: the real editor knows the type and does not guess.
     */
    private function saveRecord(): void
    {
        $recordId = isset($_POST['record_id']) ? absint($_POST['record_id']) : 0;
        $edgeIds  = isset($_POST['edge_id']) && is_array($_POST['edge_id']) ? $_POST['edge_id'] : [];
        $values   = isset($_POST['value']) && is_array($_POST['value']) ? $_POST['value'] : [];

        foreach (array_values($edgeIds) as $index => $rawEdge) {
            $edgeId = absint($rawEdge);
            $raw    = trim(sanitize_text_field(wp_unslash($values[$index] ?? '')));

            if ($raw === '') {
                // Empty means unanswered — the row goes, which is a third state beside a value
                // and an explicit nothing.
                $this->data->clear($recordId, $edgeId);

                continue;
            }

            $this->data->put($recordId, $edgeId, $this->settingValue($raw));
        }
    }

    private function localeFromRequest(): string
    {
        return isset($_GET['taxmod_locale'])
            ? sanitize_text_field(wp_unslash($_GET['taxmod_locale']))
            : '';
    }


    /**
     * Records entered against this node, and a way to enter one.
     *
     * ⚠️ **The one place the scaffolding accepts a value** ([D-350](../../../docs/NewConcept/90-decision-log.md)),
     * and it does so as a bare `<input>` that knows nothing about the type — no picker, no
     * format, no converter. That is what keeps it from being *the second way to draw a field*
     * [R20a](../../../docs/NewConcept/30-renderer.md) warns about: it has no opinions, so it
     * cannot drift from the renderers. **The moment it grows one, it must go.**
     */
    private function recordsPanel(Node $selected): string
    {
        $branch = $this->framework->branchOf($selected);

        if ($branch === null || ! $branch->holdsData()) {
            return '<h3>' . esc_html__('Records', 'taxmod') . '</h3>'
                . '<p class="description">'
                . esc_html__('Only things under Model and Compositions have records of their own.', 'taxmod')
                . '</p>';
        }

        $attributes = $this->editor->attributesOf($selected->id);
        $records    = $this->data->recordsOf($selected->id);

        $html  = '<h3>' . esc_html__('Records', 'taxmod') . '</h3>';
        $html .= '<p class="description">'
            . esc_html__('Raw entry — one plain field per attribute, no renderers yet. Deleted when they arrive.', 'taxmod')
            . '</p>';

        $html .= $this->form(
            $selected->id,
            [['add_record', esc_html__('New record', 'taxmod'), __('Start a record against this node', 'taxmod')]]
        );

        if ($records === []) {
            return $html . '<p><em>' . esc_html__('None yet.', 'taxmod') . '</em></p>';
        }

        foreach ($records as $record) {
            $held = [];

            foreach ($this->data->valuesOf($record->id) as $value) {
                $held[$value->edgeId] = $value->value->describe();
            }

            $fields = '';

            foreach ($attributes as $edge) {
                $fields .= '<div style="display:flex;gap:.4em;align-items:center;margin:.2em 0">'
                    . '<label style="width:11em">' . esc_html($edge->name) . '</label>'
                    . '<input type="hidden" name="edge_id[]" value="' . (int) $edge->id . '">'
                    . '<input type="text" name="value[]" value="' . esc_attr($held[$edge->id] ?? '') . '" style="flex:1">'
                    . '<code style="opacity:.6">' . esc_html($edge->kind->value) . '</code>'
                    . '</div>';
            }

            $html .= '<div style="border:1px solid #ddd;padding:.6em;margin:.6em 0">'
                . '<strong>' . esc_html(sprintf(
                    /* translators: 1: record id, 2: the model version it was written against. */
                    __('Record #%1$d · written against version %2$d', 'taxmod'),
                    $record->id,
                    $record->modelVersion
                )) . '</strong>'
                . $this->form(
                    $selected->id,
                    [['save_record', esc_html__('Save', 'taxmod'), __('Write these values', 'taxmod')]],
                    '<input type="hidden" name="record_id" value="' . (int) $record->id . '">'
                    . '<div style="flex:1 0 100%">' . $fields . '</div>'
                )
                . '</div>';
        }

        return $html;
    }

    // ------------------------------------------------------------------ acting


    /** The chain a setting written **at this node** belongs to. */
    private function settingChain(int $nodeId): array
    {
        return $this->settings->chainFor($this->editor->find($nodeId) ?? $this->framework->root());
    }

    /**
     * Turn what somebody typed into a typed value.
     *
     * ⚠️ **A scaffolding guess, and deliberately a crude one.** The real editor knows the type
     * of the setting and offers the right control; here a number is a number, everything else
     * is text. Nothing about this survives the renderers.
     */
    private function settingValue(string $raw): TypedValue
    {
        $raw = trim($raw);

        if ($raw === '') {
            return TypedValue::nothing();
        }

        if (preg_match('/^-?\d+$/', $raw) === 1) {
            return TypedValue::ofInt((int) $raw);
        }

        if (preg_match('/^-?\d+\.\d+$/', $raw) === 1) {
            return TypedValue::ofDecimal($raw);
        }

        return TypedValue::ofText($raw);
    }

    private function selectedFromRequest(): ?Node
    {
        if (! isset($_GET['taxmod_node'])) {
            return null;
        }

        return $this->editor->find(absint($_GET['taxmod_node']));
    }

    /** @return list<int> */
    private function collapsedFromRequest(): array
    {
        if (! isset($_GET['taxmod_collapsed'])) {
            return [];
        }

        $raw = sanitize_text_field(wp_unslash($_GET['taxmod_collapsed']));

        return array_values(array_filter(array_map('absint', explode(',', $raw))));
    }

    private function hidden(int $id): string
    {
        return '<input type="hidden" name="action" value="' . self::ACTION . '">'
            . '<input type="hidden" name="id" value="' . (int) $id . '">'
            . wp_nonce_field(self::ACTION . '_' . $id, '_taxmod_nonce', true, false);
    }

    /**
     * ⚠️ **The order below is `CD-5` and does not vary**, not even on a screen only an
     * administrator can reach: capability, nonce, validate, sanitize, act.
     */
    public function handlePost(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to shape the model.', 'taxmod'), '', ['response' => 403]);
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;

        check_admin_referer(self::ACTION . '_' . $id, '_taxmod_nonce');

        $do     = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $name   = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $target       = isset($_POST['target']) ? absint($_POST['target']) : 0;
        $edge         = isset($_POST['edge']) ? absint($_POST['edge']) : 0;
        $settingKey   = isset($_POST['setting_key']) ? sanitize_text_field(wp_unslash($_POST['setting_key'])) : '';
        $settingValue = isset($_POST['setting_value']) ? sanitize_text_field(wp_unslash($_POST['setting_value'])) : '';
        $labelRole    = isset($_POST['label_role']) ? sanitize_key(wp_unslash($_POST['label_role'])) : 'form';
        $labelLocale  = isset($_POST['label_locale']) ? sanitize_text_field(wp_unslash($_POST['label_locale'])) : '';
        $labelText    = isset($_POST['label_text']) ? sanitize_text_field(wp_unslash($_POST['label_text'])) : '';
        $stay   = $id;

        try {
            $outcome = match ($do) {
                'create'         => $stay = $this->editor->createNode($name, $id)->id,
                'add_child'      => $stay = $this->editor->createNode($name, $id)->id,
                'add_child_here' => $this->editor->createNode(__('New node', 'taxmod'), $id),
                'rename'         => $this->editor->rename($id, $name),
                'move'           => $this->editor->move($id, $target),
                'up'             => $this->editor->moveUp($id),
                'down'           => $this->editor->moveDown($id),
                'restore'        => $this->editor->restore($id),
                'trash'          => $this->editor->moveToTrash($id),
                'trash_node'     => $this->editor->moveToTrashPromotingChildren($id),
                'add_attribute'  => $this->editor->addAttribute($id, $target, $name),
                'put_setting'    => $this->settings->put($this->settingChain($id), $settingKey, $this->settingValue($settingValue)),
                'empty_setting'  => $this->settings->put($this->settingChain($id), $settingKey, TypedValue::nothing()),
                'reset_setting'  => $this->settings->reset($id, $settingKey),
                'put_multiplicity' => $this->settings->put(
                    $this->settings->chainForUseSite($this->editor->ownAttribute($id, $edge)),
                    SettingKey::Multiplicity->value,
                    TypedValue::ofText($settingValue)
                ),
                'put_label'      => $this->labels->put(new Label($id, '', $this->framework->roleId(SeededRole::from($labelRole)), Label::BASE_NUMBER, $labelLocale, $labelText)),
                'add_record'     => $this->data->create($id),
                'save_record'    => $this->saveRecord(),
                default          => throw new \InvalidArgumentException('Unknown action.'),
            };

            // ⚠️ A restore that leaves children behind must say so — it is the one outcome
            // where *done* would be a lie (D-347).
            $message = $outcome instanceof RestoreResult && ! $outcome->everythingCameBack()
                ? sprintf(
                    /* translators: %s is a comma-separated list of node names. */
                    __('Restored — but these were left where they are, because they were moved since: %s', 'taxmod'),
                    implode(', ', $outcome->leftBehind)
                )
                : 'ok';
        } catch (DomainError $error) {
            // Exceptions inside the core, translated at the boundary (`CD-10`). The message is
            // the domain's own words, so it survives the redirect rather than being replaced by
            // a generic failure the person cannot act on.
            $message = $error->getMessage();
        } catch (\InvalidArgumentException) {
            $message = __('Unknown action.', 'taxmod');
        }

        // Everything now happens **at** a node, so the person stays there rather than being
        // sent back to a screen with nothing selected.
        wp_safe_redirect(add_query_arg(
            ['page' => 'taxmod', 'taxmod_message' => rawurlencode($message), 'taxmod_node' => $stay],
            admin_url('admin.php')
        ));
        exit;
    }

    private function notice(): string
    {
        if (! isset($_GET['taxmod_message'])) {
            return '';
        }

        $message = sanitize_text_field(wp_unslash($_GET['taxmod_message']));

        if ($message === 'ok') {
            return '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Done.', 'taxmod') . '</p></div>';
        }

        return '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}
