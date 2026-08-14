<?php
/**
 * Smoke: char converters — glyph/ascii/unicode + int formats via codepoint.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT {
	if ( ! function_exists( '__' ) ) {
		/**
		 * @param string $text Text.
		 * @param string $domain Domain.
		 */
		function __( $text, $domain = null ) { // phpcs:ignore
			return $text;
		}
	}
	if ( ! class_exists( __NAMESPACE__ . '\\Node_Type', false ) ) {
		/**
		 * Minimal stub for Converter smoke (normalize type key only).
		 */
		class Node_Type {
			public static function normalize_type_name( string $name ): string {
				return strtolower( trim( $name ) );
			}
		}
	}
}

namespace {
	$root = dirname( __DIR__ );
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $root . '/' );
	}
	require_once $root . '/includes/class-converter.php';

	use WTT\Converter;

	$fail = 0;

	foreach ( array( 'glyph', 'ascii', 'unicode', 'arabic', 'hex', 'binary', 'octal', 'roman' ) as $id ) {
		if ( ! Converter::applies_to_type( $id, 'char' ) ) {
			fwrite( STDERR, "FAIL char applies $id\n" );
			++$fail;
		}
	}
	foreach ( array( 'arabic', 'hex', 'binary', 'octal', 'roman' ) as $id ) {
		if ( ! Converter::applies_to_type( $id, 'int' ) ) {
			fwrite( STDERR, "FAIL int applies $id\n" );
			++$fail;
		}
	}
	if ( Converter::applies_to_type( 'glyph', 'int' ) || Converter::applies_to_type( 'ascii', 'int' ) ) {
		fwrite( STDERR, "FAIL glyph/ascii must not apply to int\n" );
		++$fail;
	}
	if ( Converter::default_for_type( 'char' ) !== 'glyph' ) {
		fwrite( STDERR, "FAIL char default\n" );
		++$fail;
	}
	if ( Converter::default_for_type( 'int' ) !== 'arabic' ) {
		fwrite( STDERR, "FAIL int default\n" );
		++$fail;
	}

	$char_ids = array_column( Converter::list_for_type( 'char' ), 'id' );
	foreach ( array( 'glyph', 'hex', 'ascii', 'unicode' ) as $need ) {
		if ( ! in_array( $need, $char_ids, true ) ) {
			fwrite( STDERR, "FAIL list_for_type char missing $need\n" );
			++$fail;
		}
	}

	$js = (string) file_get_contents( $root . '/assets/js/wtt-converter.js' );
	foreach (
		array(
			"id: 'glyph'",
			"id: 'ascii'",
			"id: 'unicode'",
			"appliesTo: ['int', 'char']",
			'makeGlyphConverter',
			'makeAsciiConverter',
			'makeUnicodeConverter',
			"typeKey === 'char' ? 'glyph'",
		) as $needle
	) {
		if ( false === strpos( $js, $needle ) ) {
			fwrite( STDERR, "FAIL JS missing $needle\n" );
			++$fail;
		}
	}

	$nr = (string) file_get_contents( $root . '/assets/js/wtt-node-render.js' );
	if ( false === strpos( $nr, 'formatPreferred(raw, node)' ) ) {
		fwrite( STDERR, "FAIL CharRenderer must format via preferred converter\n" );
		++$fail;
	}

	$ver = (string) file_get_contents( $root . '/wp-taxonomy-tree.php' );
	if ( false === strpos( $ver, "define( 'WTT_VERSION', '0.0.520' )" ) ) {
		fwrite( STDERR, "FAIL version not 0.0.520\n" );
		++$fail;
	}

	if ( $fail > 0 ) {
		fwrite( STDERR, "char-converters smoke: {$fail} failure(s)\n" );
		exit( 1 );
	}

	echo "char-converters smoke: ok\n";
	exit( 0 );
}
