<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Exception\DomainError;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Plugin;

/**
 * The one screen Package 1 delivers: a node can be made, renamed and thrown away.
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
        private readonly FrameworkNodes $framework,
    ) {
    }

    public function render(): string
    {
        $root  = $this->framework->root();
        $trash = $this->framework->trash();

        $html  = '<div class="wrap">';
        $html .= '<h1>' . esc_html__('Taxonomy Modeller', 'taxmod') . '</h1>';
        $html .= $this->notice();
        $html .= $this->addForm($root);

        $children = array_values(array_filter(
            $this->editor->childrenOf($root->id),
            static fn (Node $n): bool => $n->id !== $trash->id
        ));

        $html .= '<h2>' . esc_html__('Nodes', 'taxmod') . '</h2>';
        $html .= $this->table($children, true);

        $parked = $this->editor->childrenOf($trash->id);

        $html .= '<h2>' . esc_html__('Trash', 'taxmod') . '</h2>';
        $html .= '<p class="description">'
            . esc_html__('Parked, not gone. A parked node is still a node, so nothing that pointed at it dangles.', 'taxmod')
            . '</p>';
        $html .= $this->table($parked, false);

        return $html . '</div>';
    }

    /**
     * @param list<Node> $nodes
     */
    private function table(array $nodes, bool $withActions): string
    {
        if ($nodes === []) {
            return '<p><em>' . esc_html__('Nothing here yet.', 'taxmod') . '</em></p>';
        }

        $rows = '';

        foreach ($nodes as $node) {
            $rows .= '<tr>';
            $rows .= '<td>' . (int) $node->id . '</td>';
            $rows .= '<td>' . esc_html($node->name) . '</td>';
            $rows .= '<td><code>' . esc_html($node->path) . '</code></td>';
            $rows .= '<td>' . (int) $node->version . '</td>';
            $rows .= '<td>' . ($withActions ? $this->rowActions($node) : '') . '</td>';
            $rows .= '</tr>';
        }

        return '<table class="wp-list-table widefat striped">'
            . '<thead><tr>'
            . '<th style="width:5em">' . esc_html__('Id', 'taxmod') . '</th>'
            . '<th>' . esc_html__('Name', 'taxmod') . '</th>'
            . '<th>' . esc_html__('Path', 'taxmod') . '</th>'
            . '<th style="width:6em">' . esc_html__('Version', 'taxmod') . '</th>'
            . '<th style="width:26em">' . esc_html__('Actions', 'taxmod') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function rowActions(Node $node): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:.4em">'
            . $this->hidden($node->id)
            . '<input type="text" name="name" value="' . esc_attr($node->name) . '" required>'
            . '<button class="button" name="do" value="rename">' . esc_html__('Rename', 'taxmod') . '</button>'
            . '<button class="button" name="do" value="trash">' . esc_html__('Trash', 'taxmod') . '</button>'
            . '</form>';
    }

    private function addForm(Node $parent): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:1.5em 0;display:flex;gap:.5em">'
            . $this->hidden($parent->id)
            . '<input type="text" name="name" placeholder="' . esc_attr__('Name of the new node', 'taxmod') . '" required>'
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

        $do   = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';

        try {
            match ($do) {
                'create' => $this->editor->createNode($name, $id),
                'rename' => $this->editor->rename($id, $name),
                'trash'  => $this->editor->moveToTrash($id),
                default  => throw new \InvalidArgumentException('Unknown action.'),
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
