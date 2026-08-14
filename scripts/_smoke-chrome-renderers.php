<?php
/**
 * Smoke: chrome Preferred renderers + time/datetime/color enum ids.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
require_once $root . '/includes/enum-renderer.php';

use WTT\Renderer;

$expect = array(
	'int'                    => Renderer::Int,
	'int_spinner'            => Renderer::IntSpinner,
	'int_range'              => Renderer::IntRange,
	'double_spinner'         => Renderer::DoubleSpinner,
	'double_range'           => Renderer::DoubleRange,
	'bool'                   => Renderer::Bool,
	'bool_checkbox'          => Renderer::BoolCheckbox,
	'bool_radio'             => Renderer::BoolRadio,
	'time'                   => Renderer::Time,
	'datetime'               => Renderer::DateTime,
	'color'                  => Renderer::Color,
	'IntSpinnerRenderer'     => Renderer::IntSpinner,
);

$fail = 0;
foreach ( $expect as $raw => $case ) {
	$got = Renderer::try_from_legacy( (string) $raw );
	if ( $got !== $case ) {
		fwrite( STDERR, 'FAIL legacy ' . $raw . ' => ' . ( $got ? $got->value : 'null' ) . ' expected ' . $case->value . PHP_EOL );
		++$fail;
	}
	$norm = Renderer::normalize( (string) $raw );
	if ( $norm !== $case->value ) {
		fwrite( STDERR, 'FAIL normalize ' . $raw . ' => ' . $norm . ' expected ' . $case->value . PHP_EOL );
		++$fail;
	}
}

if ( Renderer::normalize( 'datetime' ) === Renderer::Date->value ) {
	fwrite( STDERR, "FAIL datetime still maps to DateRenderer\n" );
	++$fail;
}

$js = (string) file_get_contents( $root . '/assets/js/wtt-node-render.js' );
foreach (
	array(
		"id = 'int_spinner'",
		"id = 'int_range'",
		"id = 'double_spinner'",
		"id = 'double_range'",
		"id = 'bool_checkbox'",
		"id = 'bool_radio'",
		"makeScalarRenderer('time'",
		"makeScalarRenderer('datetime'",
		"makeScalarRenderer('color'",
		"control: 'switch'",
		"control: 'range'",
	) as $needle
) {
	if ( false === strpos( $js, $needle ) ) {
		fwrite( STDERR, 'FAIL JS missing ' . $needle . PHP_EOL );
		++$fail;
	}
}

if ( $fail > 0 ) {
	fwrite( STDERR, "chrome-renderers smoke: {$fail} failure(s)\n" );
	exit( 1 );
}

echo "chrome-renderers smoke: ok\n";
exit( 0 );