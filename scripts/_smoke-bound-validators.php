<?php
/**
 * Smoke: int_min / int_max / double_min / double_max bound validators.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = null ) { return $t; }
}
require_once $root . '/includes/class-validator.php';

use WTT\Validator;

$fail = 0;
foreach ( array( 'int_min', 'int_max', 'double_min', 'double_max' ) as $id ) {
	if ( ! Validator::is_known_id( $id ) || ! Validator::is_bound_id( $id ) ) {
		fwrite( STDERR, "FAIL known/bound $id\n" );
		++$fail;
	}
}

$norm = Validator::normalize_entry( array( 'id' => 'int_min', 'params' => array( 'value' => '3' ) ) );
if ( ! is_array( $norm ) || ( $norm['params']['value'] ?? null ) !== 3 ) {
	fwrite( STDERR, "FAIL normalize int_min params\n" );
	++$fail;
}

$ok_low = Validator::validate_value( 'int_min', '2', array( 'params' => array( 'value' => 3 ) ) );
$ok_hi  = Validator::validate_value( 'int_min', '3', array( 'params' => array( 'value' => 3 ) ) );
if ( ! empty( $ok_low['ok'] ) || empty( $ok_hi['ok'] ) ) {
	fwrite( STDERR, "FAIL int_min compare\n" );
	++$fail;
}

$ok_d = Validator::validate_value( 'double_max', '10.5', array( 'params' => array( 'value' => 10.0 ) ) );
$ok_e = Validator::validate_value( 'double_max', '9.5', array( 'params' => array( 'value' => 10.0 ) ) );
if ( ! empty( $ok_d['ok'] ) || empty( $ok_e['ok'] ) ) {
	fwrite( STDERR, "FAIL double_max compare\n" );
	++$fail;
}

$js = (string) file_get_contents( $root . '/assets/js/wtt-validator.js' );
foreach ( array( "id: 'int_min'", "id: 'int_max'", "id: 'double_min'", "id: 'double_max'", 'params.value' ) as $n ) {
	if ( false === strpos( $js, $n ) ) {
		fwrite( STDERR, "FAIL JS missing $n\n" );
		++$fail;
	}
}

if ( $fail ) {
	fwrite( STDERR, "bound-validators smoke: $fail failure(s)\n" );
	exit( 1 );
}
echo "bound-validators smoke: ok\n";
exit( 0 );