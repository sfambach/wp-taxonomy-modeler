<?php
/**
 * Smoke: Data Types / Unit type + C1 + Menge/Base unit/Praefix attrs.
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Case_Data;
use WTT\Taxonomy;

$tax = Taxonomy::FS;
$ut  = Case_Data::ensure_unit_type( $tax );

$ut_path = Case_Data::find_term_by_path(
	$tax,
	array( 'Fallstudie', 'Definition', 'Data Types', 'Unit type' )
);
$c1_path = Case_Data::find_term_by_path(
	$tax,
	array( 'Fallstudie', 'Definition', 'Data Types', 'Unit type', 'C1' )
);

$names = array();
foreach ( Attribute::list_own( $tax, $ut ) as $row ) {
	$names[] = (string) ( $row['name'] ?? '' );
}
sort( $names );

$need = array( 'Base unit', 'Menge', 'Praefix' );
$have = true;
foreach ( $need as $n ) {
	if ( ! in_array( $n, $names, true ) ) {
		$have = false;
		break;
	}
}

$ok = ( $ut > 0 && $ut === $ut_path && $c1_path > 0 && $have ) ? 'ok' : 'fail';
echo 'smoke=' . $ok . PHP_EOL;
echo 'unit_type_id=' . (int) $ut . PHP_EOL;
echo 'c1_id=' . (int) $c1_path . PHP_EOL;
echo 'attrs=' . implode( ',', $names ) . PHP_EOL;
echo 'plugin=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . PHP_EOL;
