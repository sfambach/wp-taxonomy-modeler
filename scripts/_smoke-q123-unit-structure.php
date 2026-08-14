<?php
/**
 * Smoke: Unit structure (OQ-W11) — With prefix / size / Passiv Wert.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	require_once 'C:/devel/wordpress/wp-load.php';
}

if ( ! class_exists( '\\WTT\\Case_Data' ) ) {
	fwrite( STDERR, "WTT not loaded\n" );
	exit( 1 );
}

$tax = 'wtt_fs';

echo 'WTT_VERSION=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . PHP_EOL;

\WTT\Case_Data::ensure_unit_quantity_structure( $tax );

/**
 * @param list<string> $path
 */
function wtt_smoke_path( string $tax, array $path ): int {
	return \WTT\Case_Data::find_term_by_path( $tax, $path );
}

/**
 * @return array<string, string> name → typeName
 */
function wtt_smoke_attr_map( string $tax, int $id ): array {
	$map = array();
	foreach ( \WTT\Attribute::list_own( $tax, $id ) as $row ) {
		$n = (string) ( $row['name'] ?? '' );
		if ( '' === $n ) {
			continue;
		}
		$map[ $n ] = (string) ( $row['typeName'] ?? '' );
	}
	return $map;
}

$with = wtt_smoke_path( $tax, array( 'Fallstudie', 'Definition', 'Data Types', 'Unit', 'With prefix' ) );
$size = wtt_smoke_path( $tax, array( 'Fallstudie', 'Definition', 'Data Types', 'Complex', 'quantity', 'size' ) );
$passiv = wtt_smoke_path( $tax, array( 'Fallstudie', 'Model', 'Bauteil', 'Passiv' ) );

$with_map = $with > 0 ? wtt_smoke_attr_map( $tax, $with ) : array();
$size_map = $size > 0 ? wtt_smoke_attr_map( $tax, $size ) : array();

echo "with_prefix_id={$with}\n";
echo 'with_attrs=' . implode( '|', array_map( static fn( $n, $t ) => $n . '→' . $t, array_keys( $with_map ), $with_map ) ) . PHP_EOL;
echo "size_id={$size}\n";
echo 'size_attrs=' . implode( '|', array_map( static fn( $n, $t ) => $n . '→' . $t, array_keys( $size_map ), $size_map ) ) . PHP_EOL;

$passiv_wert = '';
if ( $passiv > 0 ) {
	foreach ( \WTT\Attribute::list_own( $tax, $passiv ) as $row ) {
		if ( 'Wert' === (string) ( $row['name'] ?? '' ) ) {
			$passiv_wert = (string) ( $row['typeName'] ?? '' );
			echo 'passiv_wert_typeName=' . $passiv_wert . PHP_EOL;
			break;
		}
	}
}

$meter = 0;
if ( $with > 0 ) {
	$kids = get_terms(
		array(
			'taxonomy'   => $tax,
			'parent'     => $with,
			'hide_empty' => false,
			'name'       => 'Meter',
			'number'     => 1,
		)
	);
	if ( is_array( $kids ) && isset( $kids[0] ) && $kids[0] instanceof WP_Term ) {
		$meter = (int) $kids[0]->term_id;
	}
}
$meter_attr_count = $meter > 0 ? count( \WTT\Attribute::list_own( $tax, $meter ) ) : -1;
echo "meter_id={$meter}\n";
echo "meter_own_attr_count={$meter_attr_count}\n";

$ok_with = isset( $with_map['Praefix'], $with_map['Kuerzel'] )
	&& count( $with_map ) >= 2;
$ok_size = isset( $size_map['Value'], $size_map['Unit'] )
	&& 'double' === $size_map['Value']
	&& 'With prefix' === $size_map['Unit'];
$ok_passiv = 'size' === $passiv_wert;
$ok_meter  = 0 === $meter_attr_count;

/* Idempotent second pass. */
\WTT\Case_Data::ensure_unit_quantity_structure( $tax );
$with_map2 = $with > 0 ? wtt_smoke_attr_map( $tax, $with ) : array();
$size_map2 = $size > 0 ? wtt_smoke_attr_map( $tax, $size ) : array();
$ok_idem   = count( $with_map2 ) === count( $with_map ) && count( $size_map2 ) === count( $size_map );

echo 'with_prefix_ok=' . ( $ok_with ? 'yes' : 'no' ) . PHP_EOL;
echo 'size_ok=' . ( $ok_size ? 'yes' : 'no' ) . PHP_EOL;
echo 'passiv_wert_ok=' . ( $ok_passiv ? 'yes' : 'no' ) . PHP_EOL;
echo 'meter_leaf_ok=' . ( $ok_meter ? 'yes' : 'no' ) . PHP_EOL;
echo 'idempotent=' . ( $ok_idem ? 'yes' : 'no' ) . PHP_EOL;
echo 'smoke=' . ( $ok_with && $ok_size && $ok_passiv && $ok_meter && $ok_idem ? 'ok' : 'FAIL' ) . PHP_EOL;

exit( $ok_with && $ok_size && $ok_passiv && $ok_meter && $ok_idem ? 0 : 1 );
