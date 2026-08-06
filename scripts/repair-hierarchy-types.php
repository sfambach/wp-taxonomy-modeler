<?php
/**
 * Repair Q88 hierarchy datatype inheritance on wtt_fs (type_id = parent).
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file.\n" );
	exit( 1 );
}

if ( ! class_exists( 'WTT\\Case_Data' ) || ! class_exists( 'WTT\\Node_Type' ) ) {
	fwrite( STDERR, "PLUGIN_INACTIVE\n" );
	exit( 1 );
}

$tax = \WTT\Taxonomy::FS;
\WTT\Taxonomy::register_taxonomies();
\WTT\Case_Data::ensure_knoten_datatype( $tax );
\WTT\Case_Data::ensure_root_typed_knoten( $tax );
$stats = \WTT\Node_Type::ensure_hierarchy_datatype_inheritance( $tax );

printf(
	"taxonomy=%s updated=%d skipped=%d errors=%d\n",
	$tax,
	(int) $stats['updated'],
	(int) $stats['skipped'],
	(int) $stats['errors']
);

$paths = array(
	array( 'Fallstudie' ),
	array( 'Fallstudie', 'Definition' ),
	array( 'Fallstudie', 'Implementation' ),
	array( 'Fallstudie', 'Definition', 'Data Types' ),
	array( 'Fallstudie', 'Implementation', 'BOM' ),
	array( 'Fallstudie', 'Implementation', 'Bauteile' ),
);

foreach ( $paths as $path ) {
	$id = \WTT\Case_Data::find_term_by_path( $tax, $path );
	if ( $id <= 0 ) {
		printf( "MISSING %s\n", implode( '/', $path ) );
		continue;
	}
	$term   = get_term( $id, $tax );
	$type_id = \WTT\Node_Type::get_effective_type_id( $tax, $id );
	$type    = $type_id > 0 ? get_term( $type_id, $tax ) : null;
	$own     = \WTT\Node_Type::get_type_id( $id );
	printf(
		"%s id=%d parent=%d ownTypeId=%d typeId=%d typeName=%s typeIsParent=%s\n",
		implode( '/', $path ),
		$id,
		$term instanceof WP_Term ? (int) $term->parent : 0,
		$own,
		$type_id,
		$type instanceof WP_Term ? $type->name : '(none)',
		\WTT\Node_Type::is_typed_as_parent( $tax, $id ) ? '1' : '0'
	);
}

fwrite( STDOUT, "OK\n" );
