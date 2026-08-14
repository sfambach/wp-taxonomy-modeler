<?php
/**
 * Smoke: size Quantity paint path — Unit→With prefix exposes unit-leaf fixedOptions.
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

$size = \WTT\Case_Data::find_term_by_path(
	$tax,
	array( 'Fallstudie', 'Definition', 'Data Types', 'Complex', 'quantity', 'size' )
);
$qty = \WTT\Case_Data::find_term_by_path(
	$tax,
	array( 'Fallstudie', 'Definition', 'Data Types', 'Complex', 'quantity' )
);

echo "size_id={$size}\n";
echo 'size_preferred=' . \WTT\Node_Type::get_preferred_render( $size ) . PHP_EOL;
echo 'qty_preferred=' . \WTT\Node_Type::get_preferred_render( $qty ) . PHP_EOL;
echo 'size_family=' . ( \WTT\Node_Type::is_quantity_family_type( $tax, $size ) ? 'yes' : 'no' ) . PHP_EOL;

$unit_opts          = 0;
$unit_with_prefixes = 0;
foreach ( \WTT\Attribute::list( $tax, $size ) as $a ) {
	$name   = (string) ( $a['name'] ?? '?' );
	$mode   = (string) ( $a['fixedMode'] ?? '' );
	$opts   = is_array( $a['fixedOptions'] ?? null ) ? count( $a['fixedOptions'] ) : 0;
	$pref   = (string) ( $a['preferredRender'] ?? '' );
	$has_ap = 0;
	foreach ( ( $a['fixedOptions'] ?? array() ) as $o ) {
		if ( ! empty( $o['allowedPrefixes'] ) ) {
			++$has_ap;
		}
	}
	if ( 'Unit' === $name ) {
		$unit_opts          = $opts;
		$unit_with_prefixes = $has_ap;
	}
	echo "attr={$name} mode={$mode} opts={$opts} withPrefixes={$has_ap} pref={$pref}\n";
}

$passiv        = \WTT\Case_Data::find_term_by_path(
	$tax,
	array( 'Fallstudie', 'Model', 'Bauteil', 'Passiv' )
);
$wert_props_ok = false;
foreach ( \WTT\Attribute::list( $tax, $passiv ) as $a ) {
	if ( 'Wert' !== (string) ( $a['name'] ?? '' ) ) {
		continue;
	}
	$tp = isset( $a['typeProperties'] ) && is_array( $a['typeProperties'] ) ? $a['typeProperties'] : array();
	echo 'passiv_wert_type=' . (string) ( $a['typeKey'] ?? '?' )
		. ' typePref=' . (string) ( $a['typePreferredRender'] ?? '' )
		. ' props=' . count( $tp ) . PHP_EOL;
	foreach ( $tp as $p ) {
		$n    = (string) ( $p['name'] ?? '?' );
		$opts = is_array( $p['fixedOptions'] ?? null ) ? count( $p['fixedOptions'] ) : 0;
		$mode = (string) ( $p['fixedMode'] ?? '' );
		echo "  prop={$n} mode={$mode} opts={$opts}\n";
		if ( 'Unit' === $n && $opts > 0 && 'catalog' === $mode ) {
			$wert_props_ok = true;
		}
	}
}

$ok = $size > 0
	&& \WTT\Node_Type::is_quantity_family_type( $tax, $size )
	&& false !== stripos( \WTT\Node_Type::get_preferred_render( $size ), 'Quantity' )
	&& $unit_opts > 0
	&& $unit_with_prefixes > 0
	&& $wert_props_ok;

echo 'smoke=' . ( $ok ? 'ok' : 'FAIL' ) . PHP_EOL;
exit( $ok ? 0 : 1 );
