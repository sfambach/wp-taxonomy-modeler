<?php declare(strict_types=1);
/**
 * Several record choosers for the same field on one page each get their own dialog switch: the Lager page carries an
 * «Umbuchen» dialog with a place chooser on every lot row, and with one shared id every opener and «OK» worked the first
 * dialog (report 2026-11-13 «auswahl ziel/nach nicht möglich»).
 *
 *     WP_ROOT=/path/to/wordpress php scripts/dev/chooser-switch-check.php
 *
 * @see docs/einstellungen-anforderungen.md §3.6.3
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: '';

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Pass the WordPress folder as the first argument or WP_ROOT.\n");
    exit(2);
}

define('WP_USE_THEMES', false);

require $root . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\WordPress\Plugin;

wp_set_current_user((int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 0));

global $wpdb;
// Any node whose records form a tree through a field of its own, with records to choose from.
$tree = $wpdb->get_row(
    "SELECT r.from_node_id AS node, r.id AS parent FROM {$wpdb->prefix}taxmod_relations r"
    . " WHERE r.from_node_id = r.to_node_id AND EXISTS (SELECT 1 FROM {$wpdb->prefix}taxmod_node_records n WHERE n.node_id = r.from_node_id) LIMIT 1"
);

if ($tree === null) {
    echo "SKIP no node with a tree of records\n";
    exit(0);
}

$render = static fn (): string => (string) apply_filters(Plugin::RECORD_CHOOSER_FILTER, '', ['name' => 'location', 'node' => (int) $tree->node, 'parent_field' => (int) $tree->parent]);
$first  = $render();
$second = $render();
$switch = static fn (string $html): string => preg_match('/class="taxmod-dialog-switch" id="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
$opens  = static fn (string $html, string $id): bool => $id !== '' && str_contains($html, 'class="button taxmod-icon-button taxmod-pick-tree" for="' . $id . '"') && str_contains($html, 'taxmod-dialog-ok" for="' . $id . '"');

$claims = [
    'the chooser is a dialog with a switch'                => $switch($first) !== '',
    'a second chooser for the same field has its own'      => $switch($second) !== '' && $switch($second) !== $switch($first),
    'its tree button and «OK» work its own switch'         => $opens($second, $switch($second)),
];
$bad = 0;

foreach ($claims as $claim => $holds) {
    echo ($holds ? 'ok   ' : 'FAIL ') . $claim . "\n";
    $bad += $holds ? 0 : 1;
}

exit($bad === 0 ? 0 : 1);
