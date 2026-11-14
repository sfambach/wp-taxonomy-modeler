<?php declare(strict_types=1);
/**
 * D-1357: a search term's «*» stands for any characters in the database search too, and a «%» or «_» the user types is a
 * plain character there — never a LIKE wildcard. His word 2026-11-14: «Als Wildcard hätte ich gerne das Sternchen.»
 *
 *     WP_ROOT=/path/to/wordpress php scripts/dev/wildcard-check.php
 *
 * @see src/Core/Listing/Wildcard.php
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

use Taxmod\WordPress\Persistence\WpdbRecordRepository;

global $wpdb;
// Any record with a text value of at least eight letters, so a term can skip its middle.
$row = $wpdb->get_row(
    "SELECT n.id AS record, n.node_id AS node, v.value_text AS text FROM {$wpdb->prefix}taxmod_relation_records v"
    . " JOIN {$wpdb->prefix}taxmod_node_records n ON n.id = v.node_record_id"
    . " WHERE n.relation_id = 0 AND CHAR_LENGTH(v.value_text) >= 8 AND v.value_text REGEXP '^[A-Za-z]{8}' LIMIT 1"
);

if ($row === null) {
    echo "SKIP no record with a text value of eight letters\n";
    exit(0);
}

$text  = (string) $row->text;
$found = static fn (string $term): bool => in_array((int) $row->record, (new WpdbRecordRepository())->recordsMatching([(int) $row->node], [['text' => $term, 'nodes' => []]], 1000), true);
$skip  = mb_substr($text, 0, 2) . '*' . mb_substr($text, 5, 3);

$claims = [
    'a plain piece is found as before'                    => $found(mb_substr($text, 1, 4)),
    "«*» skips the middle ({$skip})"                       => $found($skip),
    'the pieces must stand in this order'                 => ! $found(mb_substr($text, 5, 3) . '*' . mb_substr($text, 0, 2)),
    'without «*» the skipped middle is not found'         => ! $found(mb_substr($text, 0, 2) . mb_substr($text, 5, 3)),
    '«%» typed is a plain character, not «any»'           => ! $found(mb_substr($text, 0, 2) . '%' . mb_substr($text, 5, 3)),
    '«_» typed is a plain character, not «one»'           => ! $found(mb_substr($text, 0, 1) . '_' . mb_substr($text, 2, 3)),
];

$failed = 0;

foreach ($claims as $claim => $holds) {
    echo ($holds ? '  ok   ' : '  FAIL ') . $claim . "\n";
    $failed += $holds ? 0 : 1;
}

echo $failed === 0 ? "wildcard-check: all held\n" : "wildcard-check: {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
