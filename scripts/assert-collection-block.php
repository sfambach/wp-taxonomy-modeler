<?php
/**
 * Smoke: list table Collections + render sample HTML for first with columns.
 *
 * Usage: wp eval-file …/scripts/assert-collection-block.php
 *
 * @package WP_Taxonomy_Tree
 */

$tax = \WTT\Taxonomy::TREE;
\WTT\Demo_Data::ensure_media_type( $tax );
\WTT\Demo_Data::ensure_bom_columns( $tax );

$list = \WTT\Composition::list_table_collections( $tax );
echo 'Collections: ' . count( $list ) . PHP_EOL;
foreach ( $list as $c ) {
	echo ' - ' . $c['path'] . ' id=' . $c['id'] . ' cols=' . $c['columnCount'] . PHP_EOL;
}

$with_cols = null;
foreach ( $list as $c ) {
	if ( $c['columnCount'] > 0 ) {
		$with_cols = $c;
		break;
	}
}
if ( null === $with_cols ) {
	fwrite( STDERR, "No collection with columns\n" );
	exit( 1 );
}

$schema = \WTT\Composition::get_schema( $tax, (int) $with_cols['id'] );
$rows   = array(
	array(
		'id'    => 'r-smoke-1',
		'cells' => array(),
	),
);
if ( $schema && ! empty( $schema['columns'] ) ) {
	$col0 = (string) (int) $schema['columns'][0]['id'];
	$rows[0]['cells'][ $col0 ] = 'smoke';
}

$html = \WTT\Blocks::render_collection_table(
	array(
		'collectionTermId' => (int) $with_cols['id'],
		'rows'             => $rows,
	)
);

if ( false === strpos( $html, 'wtt-collection-table' ) || false === strpos( $html, 'smoke' ) ) {
	fwrite( STDERR, "Render failed\n$html\n" );
	exit( 1 );
}

$all              = \WTT\Composition::list_all_collections();
$has_lieferanten = false;
foreach ( $all as $c ) {
	if ( ( $c['kind'] ?? '' ) === 'catalog' && false !== stripos( $c['name'], 'Lieferanten' ) ) {
		$has_lieferanten = true;
		break;
	}
}
if ( ! $has_lieferanten ) {
	fwrite( STDERR, "Lieferanten catalog missing from picker\n" );
	exit( 1 );
}

$registered = \WP_Block_Type_Registry::get_instance()->is_registered( 'taxo/collection-table' );
echo 'Block registered: ' . ( $registered ? 'yes' : 'no' ) . PHP_EOL;
echo 'All pickable: ' . count( $all ) . PHP_EOL;
echo "OK collection-table smoke\n";
exit( 0 );
