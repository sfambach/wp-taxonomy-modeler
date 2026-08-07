<?php
/**
 * Sync Lieferanten seeds on Fallstudie (wtt_fs).
 *
 * @package WTT
 */

$tax = WTT\Taxonomy::FS;
WTT\Demo_Data::ensure_lieferanten_catalog(
	$tax,
	array( 'Fallstudie', 'Implementation' ),
	array( 'Fallstudie', 'Implementation', 'Bauteile' )
);

$list = WTT\Composition::list_all_collections();
echo 'collections=' . count( $list ) . PHP_EOL;
foreach ( $list as $c ) {
	echo $c['taxonomy'] . ' [' . $c['kind'] . '] ' . $c['path'] . ' cols=' . $c['columnCount'] . PHP_EOL;
}

$lief_id = WTT\Demo_Data::find_term_by_path( $tax, array( 'Fallstudie', 'Implementation', 'Lieferanten' ) );
$schema  = WTT\Composition::get_schema( $tax, $lief_id );
echo 'Lieferanten kind=' . ( $schema['kind'] ?? '?' ) . ' rows=' . count( $schema['rows'] ?? array() ) . PHP_EOL;
