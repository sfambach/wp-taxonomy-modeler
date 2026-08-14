<?php
/**
 * Smoke: Konstanten holds Präfixe, Basiseinheiten, Bauformen, Währung.
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Case_Data;
use WTT\Taxonomy;
use WTT\Trash;

$tax = Taxonomy::FS;
Case_Data::ensure_konstanten( $tax );

$checks = array(
	'konstanten'     => Case_Data::find_term_by_path( $tax, array( 'Fallstudie', 'Definition', 'Konstanten' ) ),
	'prefixes'       => Case_Data::find_catalog_folder( $tax, 'prefixes' ),
	'basiseinheiten' => Case_Data::find_catalog_folder( $tax, 'basiseinheiten' ),
	'with_prefix'    => Case_Data::find_catalog_folder( $tax, 'with_prefix' ),
	'without_prefix' => Case_Data::find_catalog_folder( $tax, 'without_prefix' ),
	'bauformen'      => Case_Data::find_catalog_folder( $tax, 'bauformen' ),
	'waehrung'       => Case_Data::find_catalog_folder( $tax, 'waehrung' ),
	'unit_type'      => Case_Data::find_term_by_path( $tax, array( 'Fallstudie', 'Definition', 'Data Types', 'Unit type' ) ),
);

$parent_ok = true;
foreach ( array( 'prefixes', 'basiseinheiten', 'bauformen', 'waehrung' ) as $key ) {
	$id = (int) $checks[ $key ];
	if ( $id <= 0 ) {
		$parent_ok = false;
		break;
	}
	$term = get_term( $id, $tax );
	if ( ! $term instanceof \WP_Term || (int) $term->parent !== (int) $checks['konstanten'] ) {
		$parent_ok = false;
		break;
	}
}

$milli = Case_Data::find_term_by_path(
	$tax,
	array( 'Fallstudie', 'Definition', 'Konstanten', 'Präfixe', 'Milli' )
);
$meter = 0;
if ( $checks['with_prefix'] > 0 ) {
	$kids = get_terms(
		array(
			'taxonomy'   => $tax,
			'parent'     => $checks['with_prefix'],
			'name'       => 'Meter',
			'hide_empty' => false,
			'number'     => 1,
		)
	);
	if ( is_array( $kids ) && isset( $kids[0] ) && $kids[0] instanceof \WP_Term ) {
		$meter = (int) $kids[0]->term_id;
	}
}

$dt_prefixes      = Case_Data::find_term_by_path( $tax, array( 'Fallstudie', 'Definition', 'Data Types', 'Präfixe' ) );
$dt_unit          = Case_Data::find_term_by_path( $tax, array( 'Fallstudie', 'Definition', 'Data Types', 'Unit' ) );
$dt_prefixes_gone = ( $dt_prefixes <= 0 || Trash::is_trashed( $dt_prefixes ) );
$dt_unit_gone     = ( $dt_unit <= 0 || Trash::is_trashed( $dt_unit ) );

$waehrung_parent_ok = false;
if ( $checks['waehrung'] > 0 ) {
	$wt = get_term( (int) $checks['waehrung'], $tax );
	$waehrung_parent_ok = $wt instanceof \WP_Term && (int) $wt->parent === (int) $checks['konstanten'];
}

$ok = (
	$parent_ok
	&& $checks['with_prefix'] > 0
	&& $checks['unit_type'] > 0
	&& $milli > 0
	&& $meter > 0
	&& $dt_prefixes_gone
	&& $dt_unit_gone
	&& $waehrung_parent_ok
) ? 'ok' : 'fail';

echo 'smoke=' . $ok . PHP_EOL;
foreach ( $checks as $k => $v ) {
	echo $k . '=' . (int) $v . PHP_EOL;
}
echo 'milli=' . (int) $milli . PHP_EOL;
echo 'meter=' . (int) $meter . PHP_EOL;
echo 'dt_prefixes_gone=' . ( $dt_prefixes_gone ? 'yes' : 'no' ) . PHP_EOL;
echo 'dt_unit_gone=' . ( $dt_unit_gone ? 'yes' : 'no' ) . PHP_EOL;
echo 'waehrung_under_konstanten=' . ( $waehrung_parent_ok ? 'yes' : 'no' ) . PHP_EOL;
echo 'plugin=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . PHP_EOL;
