<?php
/**
 * Smoke: charset_range / charset_allowlist / charset_regex for char+text.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text Text.
	 * @param string $domain Domain.
	 */
	function __( $text, $domain = null ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
		return $text;
	}
}
require_once $root . '/includes/class-validator.php';

use WTT\Validator;

$fail = 0;

foreach ( array( 'charset_range', 'charset_allowlist', 'charset_regex' ) as $id ) {
	if ( ! Validator::is_charset_id( $id ) || ! Validator::is_param_value_id( $id ) || ! Validator::is_known_id( $id ) ) {
		fwrite( STDERR, "FAIL id flags $id\n" );
		++$fail;
	}
}

$n = Validator::normalize_entry(
	array(
		'id'     => 'charset_range',
		'params' => array( 'value' => 'a-z,0-9' ),
	)
);
if ( ( $n['params']['value'] ?? null ) !== 'a-z,0-9' ) {
	fwrite( STDERR, "FAIL normalize charset_range\n" );
	++$fail;
}

$r1 = Validator::validate_value( 'charset_range', 'm', array( 'params' => array( 'value' => 'a-z' ) ) );
$r2 = Validator::validate_value( 'charset_range', 'M', array( 'params' => array( 'value' => 'a-z' ) ) );
$r3 = Validator::validate_value( 'charset_range', 'Ab9', array( 'params' => array( 'value' => 'a-z,A-Z,0-9' ) ) );
$r4 = Validator::validate_value( 'charset_range', '!', array( 'params' => array( 'value' => 'a-z,A-Z,0-9' ) ) );
if ( empty( $r1['ok'] ) || ! empty( $r2['ok'] ) || empty( $r3['ok'] ) || ! empty( $r4['ok'] ) ) {
	fwrite( STDERR, "FAIL charset_range compare\n" );
	++$fail;
}

$a1 = Validator::validate_value( 'charset_allowlist', 'b', array( 'params' => array( 'value' => 'a,b,c' ) ) );
$a2 = Validator::validate_value( 'charset_allowlist', 'd', array( 'params' => array( 'value' => 'a,b,c' ) ) );
$a3 = Validator::validate_value( 'charset_allowlist', 'a,c', array( 'params' => array( 'value' => 'a,\\,,c' ) ) );
if ( empty( $a1['ok'] ) || ! empty( $a2['ok'] ) || empty( $a3['ok'] ) ) {
	fwrite( STDERR, "FAIL charset_allowlist compare\n" );
	++$fail;
}

$x1 = Validator::validate_value( 'charset_regex', '5', array( 'params' => array( 'value' => '[0-9]|[a-z]' ) ) );
$x2 = Validator::validate_value( 'charset_regex', 'A', array( 'params' => array( 'value' => '[0-9]|[a-z]' ) ) );
$x3 = Validator::validate_value( 'charset_regex', 'abc', array( 'params' => array( 'value' => '[a-z]+' ) ) );
if ( empty( $x1['ok'] ) || ! empty( $x2['ok'] ) || empty( $x3['ok'] ) ) {
	fwrite( STDERR, "FAIL charset_regex compare\n" );
	++$fail;
}

$u1 = Validator::validate_value(
	'charset_range',
	'B',
	array( 'params' => array( 'value' => 'U+0041-U+005A' ) )
);
if ( empty( $u1['ok'] ) ) {
	fwrite( STDERR, "FAIL unicode range\n" );
	++$fail;
}

$js = (string) file_get_contents( $root . '/assets/js/wtt-validator.js' );
foreach (
	array(
		"id: 'charset_range'",
		"id: 'charset_allowlist'",
		"id: 'charset_regex'",
		'makeCharsetValidate',
		'isCharsetValidatorId',
	) as $needle
) {
	if ( false === strpos( $js, $needle ) ) {
		fwrite( STDERR, "FAIL JS missing $needle\n" );
		++$fail;
	}
}

$adm = (string) file_get_contents( $root . '/assets/js/tree-admin.js' );
if ( false === strpos( $adm, 'wtt-validators-table__bound--text' ) ) {
	fwrite( STDERR, "FAIL Bound text input missing\n" );
	++$fail;
}

$ver = (string) file_get_contents( $root . '/wp-taxonomy-tree.php' );
if ( false === strpos( $ver, "define( 'WTT_VERSION', '0.0.519' )" ) ) {
	fwrite( STDERR, "FAIL version not 0.0.519\n" );
	++$fail;
}

if ( $fail > 0 ) {
	fwrite( STDERR, "charset-validators smoke: {$fail} failure(s)\n" );
	exit( 1 );
}

echo "charset-validators smoke: ok\n";
exit( 0 );
