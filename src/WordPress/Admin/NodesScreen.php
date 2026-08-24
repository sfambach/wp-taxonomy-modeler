<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Exception\DomainError;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Service\ModelEditor;
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
        $rows   = $this->tree->rowsUnder($root, [$trash->id]);
        $parked = $this->tree->rowsUnder($trash);

        $html  = '<div class="wrap">';
        $html .= '<h1>' . esc_html__('Taxonomy Modeller', 'taxmod') . '</h1>';
        $html .= $this->notice();
        $html .= $this->addForm($root, __('Add a node at the top level', 'taxmod'));
        $html .= '<h2>' . esc_html__('The tree', 'taxmod') . '</h2>';
        $html .= $this->table($rows, $root, true);
        $html .= '<h2>' . esc_html__('Trash', 'taxmod') . '</h2>';
        $html .= '<p class="description">'
            . esc_html__('Parked, not gone. A parked node is still a node, so nothing that pointed at it dangles.', 'taxmod')
            . '</p>';
        $html .= $this->table($parked, $root, false);

        return $html . '</div>';
    }

    /**
     * @param list<array{node: Node, depth: int}> $rows
     */
    private function table(array $rows, Node $root, bool $withActions): string
    {
        if ($rows === []) {
            return '<p><em>' . esc_html__('Nothing here yet.', 'taxmod') . '</em></p>';
        }

        $body = '';

        foreach ($rows as $row) {
            $node   = $row['node'];
            $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $row['depth']);

            $body .= '<tr>';
            $body .= '<td>' . $indent . '<strong>' . esc_html($node->name) . '</strong></td>';
            $body .= '<td><code>' . esc_html($node->path) . '</code></td>';
            $body .= '<td>' . (int) $node->version . '</td>';
            $body .= '<td>' . ($withActions ? $this->rowActions($node, $rows, $root) : '') . '</td>';
            $body .= '</tr>';
        }

        return '<table class="wp-list-table widefat striped">'
            . '<thead><tr>'
            . '<th>' . esc_html__('Name', 'taxmod') . '</th>'
            . '<th style="width:10em">' . esc_html__('Path', 'taxmod') . '</th>'
            . '<th style="width:5em">' . esc_html__('Version', 'taxmod') . '</th>'
            . '<th>' . esc_html__('Actions', 'taxmod') . '</th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    /**
     * @param list<array{node: Node, depth: int}> $rows
     */
    private function rowActions(Node $node, array $rows, Node $root): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:.3em;flex-wrap:wrap;align-items:center">'
            . $this->hidden($node->id)
            . '<input type="text" name="name" value="' . esc_attr($node->name) . '" required style="width:11em">'
            . '<button class="button" name="do" value="rename">' . esc_html__('Rename', 'taxmod') . '</button>'
            . '<button class="button" name="do" value="add_child" title="' . esc_attr__('Add a child under this node', 'taxmod') . '">+</button>'
            . '<button class="button" name="do" value="up" title="' . esc_attr__('Move up among its siblings', 'taxmod') . '">&uarr;</button>'
            . '<button class="button" name="do" value="down" title="' . esc_attr__('Move down among its siblings', 'taxmod') . '">&darr;</button>'
            . $this->parentChooser($node, $rows, $root)
            . '<button class="button" name="do" value="move">' . esc_html__('Move', 'taxmod') . '</button>'
            . '<button class="button" name="do" value="trash">' . esc_html__('Trash', 'taxmod') . '</button>'
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
            match ($do) {
                'create'    => $this->editor->createNode($name, $id),
                'add_child' => $this->editor->createNode($name, $id),
                'rename'    => $this->editor->rename($id, $name),
                'move'      => $this->editor->move($id, $target),
                'up'        => $this->editor->moveUp($id),
                'down'      => $this->editor->moveDown($id),
                'trash'     => $this->editor->moveToTrash($id),
                default     => throw new \InvalidArgumentException('Unknown action.'),
            };

            $message = 'ok';
        } catch (DomainError $error) {
            // Exceptions inside the core, translated at the boundary (`CD-10`). The message is
            // the domain's own words, so it survives the redirect rather than being replaced by
            // a generic failure the person cannot act on.
            $message = $error->getMessage();
        } catch (\InvalidArgumentException) {
            $message = __('Unknown action.', 'taxmod');
        }

        wp_safe_redirect(add_query_arg(
            ['page' => 'taxmod', 'taxmod_message' => rawurlencode($message)],
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
