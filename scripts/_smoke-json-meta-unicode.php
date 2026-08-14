<?php
/**
 * Smoke: UTF-8 roundtrip through JSON meta encode + stripped-escape repair.
 *
 * Run: wp eval-file …/scripts/_smoke-json-meta-unicode.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via wp eval-file.\n" );
	exit( 1 );
}

use WTT\Json_Meta;

$sample = 'Währung';
$encoded = Json_Meta::encode( array( 'name' => $sample ) );
if ( false === $encoded || ! is_string( $encoded ) ) {
	echo "encode=fail\nsmoke=fail\n";
	exit( 1 );
}

/* Simulate WP update_*_meta stripslashes on the stored value. */
$stored = wp_unslash( $encoded );
$decoded = json_decode( $stored, true );
$name    = is_array( $decoded ) ? (string) ( $decoded['name'] ?? '' ) : '';
if ( $name !== $sample ) {
	echo "roundtrip_name={$name}\nexpected={$sample}\nsmoke=fail\n";
	exit( 1 );
}

$corrupted = 'Wu00e4hrung';
$repaired  = Json_Meta::repair_stripped_unicode_escapes( $corrupted );
if ( $repaired !== $sample ) {
	echo "repair={$repaired}\nexpected={$sample}\nsmoke=fail\n";
	exit( 1 );
}

/* Default json_encode + stripslashes reproduces the bug; our encode must not. */
$bad_json = wp_json_encode( array( 'name' => $sample ) );
$bad_name = '';
if ( is_string( $bad_json ) ) {
	$bad_decoded = json_decode( wp_unslash( $bad_json ), true );
	$bad_name    = is_array( $bad_decoded ) ? (string) ( $bad_decoded['name'] ?? '' ) : '';
}
if ( $bad_name === $sample ) {
	echo "note=default_json_survived_unslash (PHP/WP flags may differ)\n";
} elseif ( 'Wu00e4hrung' !== $bad_name ) {
	echo "note=unexpected_corruption={$bad_name}\n";
}

echo "roundtrip=ok\nrepair=ok\nsmoke=ok\n";
