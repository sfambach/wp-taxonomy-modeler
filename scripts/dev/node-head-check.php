<?php declare(strict_types=1);

/**
 * The node page's head is one toolbar line, then id · path · version; name and own class stand in «Labels», the changelog at the bottom,
 * and an inherited field's name leads to the node that declares it (D-1453).
 *
 * ⚠️ *His words 18.11. 18:17: «die Buttonleiste kommt einzeln oben drüber, so als Toolbar … Der Mülleimer ganz nach rechts und das
 * Speichern ganz nach links» — «Als erstes ID, Pfad und Version» — «created und changed … ganz runter … Changelog», and 18:19 «wenn man …
 * auf den Namen [klickt], dass man auf dem Knoten landet, der es definiert».* Checked:
 *
 * ```text
 * CONTRACT
 * toolbar    the first button is Save, the last is the bin; no name field in the head
 * system     exactly Id, Path, Version, in this order
 * labels     the name field and «Class of this node» stand in the box «Labels»
 * changelog  a node with history shows the box «Changelog» after everything else
 * fields     every inherited field's name links to its owner; no own field's name does
 * ```
 *
 * Usage: php scripts/dev/node-head-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1453
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

wp_set_current_user(1);

$failed = 0;
$say    = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
    $failed += $ok ? 0 : 1;
};

global $wpdb;
$p = $wpdb->prefix;

// A node under Model with a parent that declares a field, so it inherits one; and one with history in the changelog.
$erbt = (int) $wpdb->get_var("SELECT n.id FROM {$p}taxmod_nodes n JOIN {$p}taxmod_relations r ON r.from_node_id = n.parent_node_id
    WHERE r.kind IN ('composition','aggregation') ORDER BY n.id LIMIT 1");
$alt  = (int) $wpdb->get_var("SELECT c.owner_id FROM {$p}taxmod_changelog c JOIN {$p}taxmod_nodes n ON n.id = c.owner_id
    WHERE c.owner_kind = 'node' AND n.parent_node_id IS NOT NULL ORDER BY c.owner_id LIMIT 1");
$say($erbt > 0, 'a node that inherits a field' . ($erbt > 0 ? ' (#' . $erbt . ')' : ' — none, nothing checked'));

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

$page = static function (int $node, array $more = []) use ($plugin): string {
    $_GET = ['page' => 'taxmod', 'taxmod_collapsed' => 'none', 'taxmod_node' => (string) $node] + $more;

    return (string) $plugin->screen()->render();
};

if ($erbt > 0) {
    $html = $page($erbt, ['taxmod_inherited' => '1']);
    $doc  = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $x    = new DOMXPath($doc);

    echo "\n== Toolbar ==\n";
    $bar     = $x->query('//*[contains(@class,"taxmod-head-toolbar")]')->item(0);
    $buttons = $bar === null ? [] : iterator_to_array($x->query('.//button[@name="do"] | .//label[contains(@class,"taxmod-dialog-open")]', $bar));
    $visible = array_values(array_filter($buttons, static fn (DOMElement $b): bool => ! str_contains((string) $b->getAttribute('class'), 'taxmod-pick-submit')
        && $x->query('ancestor::*[contains(@class,"taxmod-dialog-panel")]', $b)->length === 0));
    $first   = $visible[0] ?? null;
    $last    = $visible === [] ? null : $visible[count($visible) - 1];
    $say($bar !== null, 'the head has a toolbar line');
    $say($first !== null && $first->getAttribute('value') === \Taxmod\Core\Renderer\SettingsRenderer::WRITE, 'Save is the first button');
    $say($last !== null && in_array($last->getAttribute('value'), ['trash', 'trash_node'], true) || ($last !== null && str_contains($last->getAttribute('for'), 'taxmod-trash-')), 'the bin is the last button');
    $say($x->query('//table[contains(@class,"taxmod-head")]//input[@name="name"][not(ancestor::*[contains(@class,"taxmod-dialog")])]')->length === 0, 'no name field in the head (a dialog\'s own field aside)');

    echo "\n== System ==\n";
    $labels = array_map(static fn (DOMNode $n): string => trim($n->textContent), iterator_to_array($x->query('//*[contains(@class,"taxmod-head-system")]//*[contains(@class,"taxmod-chip-label")]')));
    $say($labels === [__('Id', 'taxmod'), __('Path', 'taxmod'), __('Version', 'taxmod')], 'Id, Path, Version and nothing else (' . implode(', ', $labels) . ')');

    echo "\n== Labels ==\n";
    $say($x->query('//*[contains(@class,"taxmod-name-row")]//input[@name="name"]')->length === 1, 'the name field stands in «Labels»');
    $say($x->query('//*[contains(@class,"taxmod-name-row-class")]')->length === 1, '«Class of this node» stands beside it');

    echo "\n== Fields ==\n";
    $rows   = iterator_to_array($x->query('//tr[contains(@class,"taxmod-field") and @data-taxmod-relation]'));
    $falsch = 0;

    foreach ($rows as $row) {
        $geerbt = $x->query('.//td[contains(@class,"taxmod-field-from")]/em', $row)->length > 0;
        $link   = $x->query('.//td[contains(@class,"taxmod-field-name")]//a[contains(@class,"taxmod-field-owner-link")]', $row)->length > 0;
        $falsch += $geerbt === $link ? 0 : 1;
    }

    $say($rows !== [], 'field rows to check (' . count($rows) . ')');
    $say($falsch === 0, 'inherited names link to their owner, own names do not (' . $falsch . ' wrong)');
}

echo "\n== Changelog ==\n";
$say($alt > 0, 'a node with history' . ($alt > 0 ? ' (#' . $alt . ')' : ' — none, nothing checked'));

if ($alt > 0) {
    $html = $page($alt);
    $log  = strpos($html, 'taxmod-changelog-block');
    $say($log !== false, 'the box «Changelog» is there');
    $say($log !== false && strpos($html, 'taxmod-used-by-block') !== false && $log > strpos($html, 'taxmod-used-by-block'), 'it stands after everything else');
}

echo $failed === 0 ? "\nall green\n" : "\n{$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
