<?php
/**
 * Dump a taxonomy tree snapshot to scripts/fixtures/ (JSON).
 *
 * Usage (from WordPress root):
 *   php wp-cli.phar --user=admin eval-file path/to/dump-tree-snapshot.php
 *   php wp-cli.phar --user=admin eval-file path/to/dump-tree-snapshot.php category
 *   php wp-cli.phar --user=admin eval-file path/to/dump-tree-snapshot.php wtt_fs
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file from the WordPress install.\n" );
	exit( 1 );
}

if ( ! class_exists( 'WTT\\Tree_Model' ) ) {
	fwrite( STDERR, "PLUGIN_INACTIVE\n" );
	exit( 1 );
}

$taxonomy = 'category';
if ( isset( $args ) && is_array( $args ) && isset( $args[0] ) && is_string( $args[0] ) && '' !== $args[0] ) {
	$taxonomy = sanitize_key( $args[0] );
} elseif ( isset( $argv ) && is_array( $argv ) ) {
	foreach ( $argv as $i => $arg ) {
		if ( $i > 0 && is_string( $arg ) && 0 !== strpos( $arg, '-' ) && false === strpos( $arg, '.php' ) ) {
			$taxonomy = sanitize_key( $arg );
			break;
		}
	}
}

if ( ! taxonomy_exists( $taxonomy ) ) {
	fwrite( STDERR, "Taxonomy not found: {$taxonomy}\n" );
	exit( 1 );
}

/**
 * @param array<int, array<string, mixed>> $nodes Nested tree from Tree_Model::get_tree.
 * @return array<int, array<string, mixed>>
 */
function wtt_snapshot_serialize_nodes( array $nodes ): array {
	$out = array();
	foreach ( $nodes as $node ) {
		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
		$out[]    = array(
			'id'               => isset( $node['id'] ) ? (int) $node['id'] : 0,
			'name'             => isset( $node['name'] ) ? (string) $node['name'] : '',
			'slug'             => isset( $node['slug'] ) ? (string) $node['slug'] : '',
			'description'      => isset( $node['description'] ) ? (string) $node['description'] : '',
			'shortDescription' => isset( $node['shortDescription'] ) ? (string) $node['shortDescription'] : '',
			'typeId'           => isset( $node['typeId'] ) ? (int) $node['typeId'] : 0,
			'typeName'         => isset( $node['typeName'] ) ? (string) $node['typeName'] : '',
			'required'         => ! empty( $node['required'] ),
			'position'         => isset( $node['position'] ) ? (int) $node['position'] : 0,
			'children'         => wtt_snapshot_serialize_nodes( $children ),
		);
	}
	return $out;
}

$tree = \WTT\Tree_Model::get_tree( $taxonomy );
$date = gmdate( 'Y-m-d' );
$payload = array(
	'exportedAt' => gmdate( 'c' ),
	'taxonomy'   => $taxonomy,
	'plugin'     => defined( 'WTT_VERSION' ) ? WTT_VERSION : '',
	'tree'       => wtt_snapshot_serialize_nodes( $tree ),
);

$plugin_dir = defined( 'WTT_PLUGIN_DIR' ) ? WTT_PLUGIN_DIR : dirname( __DIR__ ) . '/';
$fixtures   = $plugin_dir . 'scripts/fixtures';
if ( ! is_dir( $fixtures ) && ! wp_mkdir_p( $fixtures ) ) {
	fwrite( STDERR, "Could not create fixtures dir: {$fixtures}\n" );
	exit( 1 );
}

$filename = sprintf( 'tree-snapshot-%s-%s.json', $taxonomy, $date );
$path     = $fixtures . '/' . $filename;
$json     = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
if ( false === $json ) {
	fwrite( STDERR, "JSON encode failed.\n" );
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
if ( false === file_put_contents( $path, $json . "\n" ) ) {
	fwrite( STDERR, "Write failed: {$path}\n" );
	exit( 1 );
}

$count = 0;
$walker = static function ( array $nodes ) use ( &$count, &$walker ): void {
	foreach ( $nodes as $n ) {
		++$count;
		if ( ! empty( $n['children'] ) && is_array( $n['children'] ) ) {
			$walker( $n['children'] );
		}
	}
};
$walker( $payload['tree'] );

printf( "wrote %s nodes=%d taxonomy=%s\n", $path, $count, $taxonomy );
