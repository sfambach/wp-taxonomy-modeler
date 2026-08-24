<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Exception\DomainError;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\RestoreResult;
use Taxmod\Core\Service\Tree;
use Taxmod\WordPress\Plugin;

/**
 * The tree, and what a person may do to it: add, rename, move, reorder, throw away.
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

        $html  = '<div class="wrap">';
        $html .= '<h1>' . esc_html__('Taxonomy Modeller', 'taxmod') . '</h1>';
        $html .= $this->notice();
        $html .= $this->addForm($root, __('Add a node at the top level', 'taxmod'));
        $html .= '<h2>' . esc_html__('The tree', 'taxmod') . '</h2>';
        $html .= $this->table($rows, $root, 'tree', $collapsed);
        $html .= '<h2>' . esc_html__('Trash', 'taxmod') . '</h2>';
        $html .= '<p class="description">'
            . esc_html__('Parked, not gone. A parked node is still a node, so nothing that pointed at it dangles.', 'taxmod')
            . '</p>';
        $html .= $this->table($parked, $root, 'trash', $collapsed);

        $selected = $this->selectedFromRequest();

        if ($selected !== null) {
            $html .= $this->attributesPanel($selected, $rows);
        }

        return $html . '</div>';
    }

    private function selectedFromRequest(): ?Node
    {
        if (! isset($_GET['taxmod_node'])) {
            return null;
        }

        return $this->editor->find(absint($_GET['taxmod_node']));
    }

    /**
     * What the selected node has — its own attributes and the ones it inherits.
     *
     * ⚠️ **Scaffolding, and the seed of the right-hand side** ([D-343](../../../docs/NewConcept/90-decision-log.md)).
     * It draws **no value**: names, targets and the kind, nothing rendered. The moment a value
     * has to appear it goes through a renderer ([R20a](../../../docs/NewConcept/30-renderer.md)),
     * and this panel is deleted rather than grown ([D-344](../../../docs/NewConcept/90-decision-log.md)).
     *
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     */
    private function attributesPanel(Node $selected, array $rows): string
    {
        $own = [];

        foreach ($this->editor->attributesOf($selected->id) as $edge) {
            $target = $this->editor->find($edge->toId);

            $own[] = '<tr>'
                . '<td><strong>' . esc_html($edge->name) . '</strong></td>'
                . '<td>' . esc_html($target?->name ?? '—') . '</td>'
                . '<td><code>' . esc_html($edge->kind->value) . '</code></td>'
                . '<td>' . ($edge->fromId === $selected->id
                    ? esc_html__('its own', 'taxmod')
                    : '<em>' . esc_html__('inherited', 'taxmod') . '</em>')
                . '</td>'
                . '</tr>';
        }

        $html  = '<h2>' . sprintf(
            /* translators: %s is a node name. */
            esc_html__('Attributes of «%s»', 'taxmod'),
            esc_html($selected->name)
        ) . '</h2>';

        $html .= '<p class="description">'
            . esc_html__('The kind is never chosen — it is read off the branch the target sits in.', 'taxmod')
            . '</p>';

        $html .= $own === []
            ? '<p><em>' . esc_html__('None yet.', 'taxmod') . '</em></p>'
            : '<table class="wp-list-table widefat striped"><thead><tr>'
                . '<th>' . esc_html__('Name', 'taxmod') . '</th>'
                . '<th>' . esc_html__('Points at', 'taxmod') . '</th>'
                . '<th style="width:9em">' . esc_html__('Kind', 'taxmod') . '</th>'
                . '<th style="width:7em">' . esc_html__('From', 'taxmod') . '</th>'
                . '</tr></thead><tbody>' . implode('', $own) . '</tbody></table>';

        return $html . $this->attributeForm($selected, $rows);
    }

    /**
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool, isFirst: bool, isLast: bool}> $rows
     */
    private function attributeForm(Node $selected, array $rows): string
    {
        $options = '';

        foreach ($rows as $row) {
            $candidate = $row['node'];

            // Only what could actually be a target: something inside a branch, and not the
            // branch root itself (D-238). The core refuses the rest anyway; offering a choice
            // that always fails is a trap.
            $branch = $this->framework->branchOf($candidate);

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

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:1em 0;display:flex;gap:.5em;align-items:center">'
            . $this->hidden($selected->id)
            . '<input type="text" name="name" placeholder="' . esc_attr__('Name of the attribute', 'taxmod') . '" required style="width:16em">'
            . '<select name="target">' . $options . '</select>'
            . '<button class="button button-primary" name="do" value="add_attribute">'
            . esc_html__('Add attribute', 'taxmod') . '</button>'
            . '</form>';
    }

    /**
     * @param list<array{node: Node, depth: int, hasChildren: bool, collapsed: bool}> $rows
     * @param list<int>                                                               $collapsed
     */
    private function table(array $rows, Node $root, string $mode, array $collapsed = []): string
    {
        if ($rows === []) {
            return '<p><em>' . esc_html__('Nothing here yet.', 'taxmod') . '</em></p>';
        }

        $body = '';

        foreach ($rows as $row) {
            $node   = $row['node'];
            $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $row['depth']);

            $body .= '<tr>';
            $body .= '<td>' . $indent . $this->expander($row, $collapsed)
                . '<a href="' . esc_url(add_query_arg(['page' => 'taxmod', 'taxmod_node' => $node->id], admin_url('admin.php'))) . '"><strong>'
                . esc_html($node->name) . '</strong></a></td>';
            $body .= '<td><code>' . esc_html($node->path) . '</code></td>';
            $body .= '<td title="' . esc_attr__('How often this row has been written. Not a version to return to — see the change group.', 'taxmod') . '">' . (int) $node->version . '</td>';
            $body .= '<td>' . $this->rowActions($row, $rows, $root, $mode) . '</td>';
            $body .= '</tr>';
        }

        return '<table class="wp-list-table widefat striped">'
            . '<thead><tr>'
            . '<th>' . esc_html__('Name', 'taxmod') . '</th>'
            . '<th style="width:10em">' . esc_html__('Path', 'taxmod') . '</th>'
            . '<th style="width:6em">' . esc_html__('Writes', 'taxmod') . '</th>'
            . '<th>' . esc_html__('Actions', 'taxmod') . '</th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    /**
     * ⚠️ **One method for both tables, and that is the point.** The first version of this screen
     * had the trash drawn by a second path, and collapsing worked in one place and not the
     * other within hours — the owner found it. That is exactly what
     * [R18](../../../docs/NewConcept/30-renderer.md) prevents by making a tree row a **rendered
     * node**: one behaviour, one implementation, wherever it appears.
     *
     * @param list<array{node: Node, depth: int}> $rows
     * @param string                              $mode `tree` or `trash`
     */
    private function rowActions(array $row, array $rows, Node $root, string $mode): string
    {
        $node = $row['node'];

        if ($mode === 'trash') {
            return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:.3em">'
                . $this->hidden($node->id)
                . '<button class="button" name="do" value="restore" title="' . esc_attr__('Put it back where it came from', 'taxmod') . '">'
                . esc_html__('Restore', 'taxmod') . '</button>'
                . '</form>';
        }

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:.3em;flex-wrap:wrap;align-items:center">'
            . $this->hidden($node->id)
            . '<input type="text" name="name" value="' . esc_attr($node->name) . '" required style="width:11em">'
            . '<button class="button" name="do" value="rename">' . esc_html__('Rename', 'taxmod') . '</button>'
            . '<button class="button" name="do" value="add_child" title="' . esc_attr__('Add a child under this node', 'taxmod') . '">+</button>'
            // ⚠️ U8: absent, not greyed. A first child has nothing to move up past, and the
            // tree already said so — the screen only has to believe it.
            . ($row['isFirst'] ? '' : '<button class="button" name="do" value="up" title="' . esc_attr__('Move up among its siblings', 'taxmod') . '">&uarr;</button>')
            . ($row['isLast'] ? '' : '<button class="button" name="do" value="down" title="' . esc_attr__('Move down among its siblings', 'taxmod') . '">&darr;</button>')
            . $this->parentChooser($node, $rows, $root)
            . '<button class="button" name="do" value="move">' . esc_html__('Move', 'taxmod') . '</button>'
            . '<button class="button" name="do" value="trash" title="' . esc_attr__('Trash this node and everything under it', 'taxmod') . '">'
            . esc_html__('Trash branch', 'taxmod') . '</button>'
            . '<button class="button" name="do" value="trash_node" title="' . esc_attr__('Trash only this node — its children move up to its parent, and lose what they inherited from it', 'taxmod') . '">'
            . esc_html__('Trash node only', 'taxmod') . '</button>'
            . '</form>';
    }

    /**
     * Where this node could go — everywhere except itself and its own subtree.
     *
     * ⚠️ **The impossible targets are left out rather than refused.** The core still refuses
     * them, because a screen is not a guarantee; but offering a choice that always fails is a
     * trap laid for the person using it.
     *
     * @param list<array{node: Node, depth: int}> $rows
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

        return '<select name="target" style="max-width:12em">' . $options . '</select>';
    }

    /**
     * The expander, or blank space where a node has nothing under it.
     *
     * ⚠️ **Absent, not greyed** ([U8](../../../docs/NewConcept/20-interaction.md)) — a control
     * that cannot do anything is what makes a crowded row unreadable.
     *
     * @param array{node: Node, depth: int, hasChildren: bool, collapsed: bool} $row
     * @param list<int>                                                         $collapsed
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
            ['page' => 'taxmod', 'taxmod_collapsed' => implode(',', $next)],
            admin_url('admin.php')
        );

        return '<a href="' . esc_url($url) . '" style="display:inline-block;width:1.6em;text-decoration:none">'
            . ($row['collapsed'] ? '&#9656;' : '&#9662;') . '</a>';
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

    private function addForm(Node $parent, string $label): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:1.5em 0;display:flex;gap:.5em;align-items:center">'
            . $this->hidden($parent->id)
            . '<input type="text" name="name" placeholder="' . esc_attr($label) . '" required style="width:20em">'
            . '<button class="button button-primary" name="do" value="create">' . esc_html__('Add node', 'taxmod') . '</button>'
            . '</form>';
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
        $target = isset($_POST['target']) ? absint($_POST['target']) : 0;

        try {
            $outcome = match ($do) {
                'create'    => $this->editor->createNode($name, $id),
                'add_child' => $this->editor->createNode($name, $id),
                'rename'    => $this->editor->rename($id, $name),
                'move'      => $this->editor->move($id, $target),
                'up'        => $this->editor->moveUp($id),
                'down'      => $this->editor->moveDown($id),
                'restore'    => $this->editor->restore($id),
                'trash'      => $this->editor->moveToTrash($id),
                'trash_node' => $this->editor->moveToTrashPromotingChildren($id),
                'add_attribute' => $this->editor->addAttribute($id, $target, $name),
                default     => throw new \InvalidArgumentException('Unknown action.'),
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

        $back = ['page' => 'taxmod', 'taxmod_message' => rawurlencode($message)];

        // Adding an attribute happens **at** a node, so the person stays there rather than
        // being sent back to a screen with nothing selected.
        if ($do === 'add_attribute') {
            $back['taxmod_node'] = $id;
        }

        wp_safe_redirect(add_query_arg($back, admin_url('admin.php')));
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
