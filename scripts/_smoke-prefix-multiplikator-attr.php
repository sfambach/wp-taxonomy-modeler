<?php
/**
 * Smoke: Präfix multiplikator attr (RO+Hide, Mult=1) + Q105 single Mult.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	$candidates = array(
		dirname( __DIR__, 3 ) . '/wp-load.php',
		dirname( __DIR__, 2 ) . '/wp-load.php',
		'C:/devel/wordpress/wp-load.php',
	);
	foreach ( $candidates as $load ) {
		if ( is_readable( $load ) ) {
			require_once $load;
			break;
		}
	}
}

if ( ! defined( 'ABSPATH' ) || ! class_exists( '\WTT\Case_Data' ) ) {
	fwrite( STDERR, "FAIL: WordPress / plugin not loaded\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Case_Data;
use WTT\Node_Type;
use WTT\Taxonomy;

$tax = Taxonomy::FS;
Case_Data::ensure_prefix_multiplikator_attribute( $tax );

if ( ! Attribute::multiplicity_allows_background_only( '1' ) ) {
	fwrite( STDERR, "FAIL: Mult 1 should allow Hide\n" );
	exit( 1 );
}
if ( ! Attribute::multiplicity_allows_background_only( '0..1' ) ) {
	fwrite( STDERR, "FAIL: Mult 0..1 should allow Hide\n" );
	exit( 1 );
}
if ( Attribute::multiplicity_allows_background_only( '0..*' ) ) {
	fwrite( STDERR, "FAIL: Mult 0..* must not allow Hide\n" );
	exit( 1 );
}

$prefixes_id = Case_Data::find_catalog_folder( $tax, 'prefixes' );
if ( $prefixes_id <= 0 ) {
	fwrite( STDERR, "FAIL: Präfixe missing\n" );
	exit( 1 );
}

$row = null;
foreach ( Attribute::list_own( $tax, $prefixes_id ) as $r ) {
	if ( is_array( $r ) && 'multiplikator' === strtolower( (string) ( $r['name'] ?? '' ) ) ) {
		$row = $r;
		break;
	}
}
if ( ! is_array( $row ) ) {
	fwrite( STDERR, "FAIL: multiplikator attr missing on Präfixe\n" );
	exit( 1 );
}
if ( '1' !== (string) ( $row['multiplicity'] ?? '' ) ) {
	fwrite( STDERR, 'FAIL: expected Mult 1, got ' . (string) ( $row['multiplicity'] ?? '' ) . "\n" );
	exit( 1 );
}
if ( empty( $row['readonly'] ) ) {
	fwrite( STDERR, "FAIL: multiplikator should be RO\n" );
	exit( 1 );
}
if ( empty( $row['hidden'] ) ) {
	fwrite( STDERR, "FAIL: multiplikator should be Hide/BO\n" );
	exit( 1 );
}

$milli_id = 0;
foreach ( get_terms(
	array(
		'taxonomy'   => $tax,
		'parent'     => $prefixes_id,
		'hide_empty' => false,
		'name'       => 'Milli',
		'number'     => 1,
	)
) as $t ) {
	if ( $t instanceof WP_Term ) {
		$milli_id = (int) $t->term_id;
		break;
	}
}
if ( $milli_id <= 0 ) {
	fwrite( STDERR, "FAIL: Milli leaf missing\n" );
	exit( 1 );
}

$factor = Node_Type::get_multiplikator( $milli_id );
if ( null === $factor || abs( $factor - 1.0e-3 ) > 1.0e-12 ) {
	fwrite( STDERR, 'FAIL: Milli factor expected 1e-3, got ' . var_export( $factor, true ) . "\n" );
	exit( 1 );
}

$leaf_row = null;
foreach ( Attribute::list( $tax, $milli_id ) as $r ) {
	if ( is_array( $r ) && 'multiplikator' === strtolower( (string) ( $r['name'] ?? '' ) ) ) {
		$leaf_row = $r;
		break;
	}
}
if ( ! is_array( $leaf_row ) ) {
	fwrite( STDERR, "FAIL: Milli inherits multiplikator\n" );
	exit( 1 );
}
$vals = isset( $leaf_row['fixedValues'] ) && is_array( $leaf_row['fixedValues'] )
	? $leaf_row['fixedValues']
	: array();
if ( empty( $vals ) || ! is_numeric( $vals[0] ) || abs( (float) $vals[0] - 1.0e-3 ) > 1.0e-12 ) {
	fwrite( STDERR, 'FAIL: Milli Default not 1e-3: ' . wp_json_encode( $vals ) . "\n" );
	exit( 1 );
}

echo "OK Q105 Mult 1 allows Hide\n";
echo "OK Präfixe multiplikator Mult=1 RO+Hide\n";
echo "OK Milli factor={$factor}\n";
exit( 0 );
