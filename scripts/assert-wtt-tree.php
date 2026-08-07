<?php
/**
 * Quick assert: wtt_fs (Fallstudie) has root and Simple/media.
 * Legacy name kept; product tree is Fallstudie only (wtt_tree retired).
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
$tax = \WTT\Taxonomy::FS;
\WTT\Case_Data::maybe_install( $tax );
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
	$term instanceof WP_Term ? $term->name : '?'
);

if ( ! in_array( 'Fallstudie', $roots, true ) ) {
	fwrite( STDERR, "Fallstudie root missing\n" );
	exit( 1 );
}
echo "OK wtt_fs scaffold\n";
exit( 0 );
