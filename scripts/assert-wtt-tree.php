<?php
/**
 * Quick assert: wtt_tree has BOM root and Simple/media.
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file.\n" );
	exit( 1 );
}

if ( ! class_exists( 'WTT\\Taxonomy' ) ) {
	fwrite( STDERR, "PLUGIN_INACTIVE\n" );
	exit( 1 );
}

\WTT\Taxonomy::register_taxonomies();
$tax = \WTT\Taxonomy::TREE;
$media = \WTT\Demo_Data::ensure_media_type( $tax );
$term  = $media > 0 ? get_term( $media, $tax ) : null;
$tree  = \WTT\Tree_Model::get_tree( $tax );
$roots = array_map(
	static function ( $n ) {
		return isset( $n['name'] ) ? (string) $n['name'] : '';
	},
	$tree
);

printf(
	"taxonomy=%s roots=%d names=%s media_id=%d media_name=%s\n",
	$tax,
	count( $tree ),
	implode( ',', $roots ),
	$media,
	( $term instanceof WP_Term ) ? $term->name : 'MISSING'
);

if ( count( $tree ) !== 1 || ( $roots[0] ?? '' ) !== 'BOM Testprojekt' ) {
	fwrite( STDERR, "FAIL: expected single root BOM Testprojekt\n" );
	exit( 1 );
}
if ( $media <= 0 || ! ( $term instanceof WP_Term ) || 'media' !== $term->name ) {
	fwrite( STDERR, "FAIL: media type missing\n" );
	exit( 1 );
}

fwrite( STDOUT, "OK\n" );
