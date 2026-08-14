<?php
declare(strict_types=1);
$root = dirname( __DIR__ );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', $root . '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = null ) { return $t; } }
require_once $root . '/includes/class-validator.php';
use WTT\Validator;
$fail = 0;
foreach ( array( 'text_min_length', 'text_max_length' ) as $id ) {
	if ( ! Validator::is_length_id( $id ) || ! Validator::is_param_threshold_id( $id ) ) {
		fwrite( STDERR, "FAIL id $id\n" ); ++$fail;
	}
}
$n = Validator::normalize_entry( array( 'id' => 'text_max_length', 'params' => array( 'value' => 100 ) ) );
if ( ( $n['params']['value'] ?? null ) !== 100 ) { fwrite( STDERR, "FAIL normalize\n" ); ++$fail; }
$a = Validator::validate_value( 'text_min_length', 'ab', array( 'params' => array( 'value' => 3 ) ) );
$b = Validator::validate_value( 'text_min_length', 'abc', array( 'params' => array( 'value' => 3 ) ) );
$c = Validator::validate_value( 'text_max_length', 'abcd', array( 'params' => array( 'value' => 3 ) ) );
$d = Validator::validate_value( 'text_max_length', 'abc', array( 'params' => array( 'value' => 3 ) ) );
if ( ! empty( $a['ok'] ) || empty( $b['ok'] ) || ! empty( $c['ok'] ) || empty( $d['ok'] ) ) {
	fwrite( STDERR, "FAIL length compare\n" ); ++$fail;
}
$js = file_get_contents( $root . '/assets/js/wtt-validator.js' );
foreach ( array( "id: 'text_min_length'", "id: 'text_max_length'", 'makeLengthValidate' ) as $needle ) {
	if ( false === strpos( $js, $needle ) ) { fwrite( STDERR, "FAIL JS $needle\n" ); ++$fail; }
}
echo $fail ? "FAIL $fail\n" : "text-length-validators smoke: ok\n";
exit( $fail ? 1 : 0 );