<?php
/**
 * Sync Lieferanten seeds (Rewe + record values) on both taxonomies.
 *
 * @package WTT
 */

WTT\Demo_Data::ensure_lieferanten_catalog(
	'wtt_tree',
	array( 'BOM Testprojekt' ),
	array( 'BOM Testprojekt', 'Bauteile' )
);
WTT\Demo_Data::ensure_lieferanten_catalog(
	'wtt_fs',
	array( 'Fallstudie', 'Implementation' ),
	array( 'Fallstudie', 'Implementation', 'Bauteile' )
);

$list = WTT\Composition::list_all_collections();
echo 'collections=' . count( $list ) . PHP_EOL;
foreach ( $list as $c ) {
	echo $c['taxonomy'] . ' [' . $c['kind'] . '] ' . $c['path'] . ' cols=' . $c['columnCount'] . PHP_EOL;
}

$schema = WTT\Composition::get_schema( 'wtt_tree', WTT\Demo_Data::find_term_by_path( 'wtt_tree', array( 'BOM Testprojekt', 'Lieferanten' ) ) );
echo 'Lieferanten kind=' . ( $schema['kind'] ?? '?' ) . ' rows=' . count( $schema['rows'] ?? array() ) . PHP_EOL;
foreach ( array_slice( $schema['rows'] ?? array(), 0, 3 ) as $r ) {
	echo '  ' . ( $r['cells']['0'] ?? '' ) . ' url=' . ( $r['cells']['2148'] ?? '' ) . PHP_EOL;
}
$rewe = null;
foreach ( $schema['rows'] ?? array() as $r ) {
	if ( ( $r['cells']['0'] ?? '' ) === 'Rewe' ) {
		$rewe = $r;
		break;
	}
}
echo 'Rewe=' . ( $rewe ? 'yes url=' . ( $rewe['cells']['2148'] ?? '?' ) : 'no' ) . PHP_EOL;
