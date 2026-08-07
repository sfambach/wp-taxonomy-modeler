<?php
/**
 * Sync Demo_Data blueprint into legacy wtt_tree (non-destructive).
 *
 * Product scaffold uses Case_Data / wtt_fs. This script remains for Demo_Data helpers only.
 *
 * Usage (from WordPress root):
 *   php wp-cli.phar --user=admin eval-file path/to/sync-demo-tree.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file from the WordPress install.\n" );
	exit( 1 );
}

if ( ! class_exists( 'WTT\\Demo_Data' ) || ! class_exists( 'WTT\\Taxonomy' ) ) {
	fwrite( STDERR, "PLUGIN_INACTIVE\n" );
	exit( 1 );
}

\WTT\Taxonomy::register_taxonomies();
$taxonomy = \WTT\Taxonomy::TREE;

$result = \WTT\Demo_Data::install( $taxonomy );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, $result->get_error_message() . "\n" );
	exit( 1 );
}

printf(
	"synced taxonomy=%s created=%d existing=%d\n",
	$result['taxonomy'],
	(int) $result['created'],
	(int) $result['existing']
);
