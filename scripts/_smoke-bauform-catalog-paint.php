<?php
/**
 * Smoke: Passiv Bauform paints as CatalogChoice (not structure embed).
 *
 * @package WTT
 */

require 'C:/devel/wordpress/wp-load.php';

use WTT\Attribute;
use WTT\Object_Render;

$tax = 'wtt_fs';
$ok  = true;

$passiv = get_terms(
	array(
		'taxonomy'   => $tax,
		'name'       => 'Passiv',
		'hide_empty' => false,
		'number'     => 5,
	)
);
if ( is_wp_error( $passiv ) || empty( $passiv ) ) {
	echo "FAIL: Passiv not found\n";
	exit( 1 );
}

$found = null;
foreach ( $passiv as $term ) {
	$rows = Attribute::list( $tax, (int) $term->term_id );
	foreach ( $rows as $row ) {
		if ( 0 === strcasecmp( (string) ( $row['name'] ?? '' ), 'Bauform' ) ) {
			$found = $row;
			$found['_host'] = (int) $term->term_id;
			break 2;
		}
	}
}

if ( ! is_array( $found ) ) {
	echo "FAIL: Bauform attr on Passiv\n";
	exit( 1 );
}

$mode = (string) ( $found['fixedMode'] ?? '' );
$opts = isset( $found['fixedOptions'] ) && is_array( $found['fixedOptions'] ) ? $found['fixedOptions'] : array();
$tp   = isset( $found['typeProperties'] ) && is_array( $found['typeProperties'] ) ? $found['typeProperties'] : array();
$pref = (string) ( $found['typePreferredRender'] ?? '' );

echo "host={$found['_host']} fixedMode={$mode} opts=" . count( $opts ) . ' typeProps=' . count( $tp ) . " typePreferred={$pref}\n";

if ( 'catalog' !== $mode ) {
	echo "FAIL: expected fixedMode=catalog\n";
	$ok = false;
}
if ( array() === $opts ) {
	echo "FAIL: expected fixedOptions (Bauformen children)\n";
	$ok = false;
}

/* Mirror Object_Render::is_structure_prop via reflection-free public paint path. */
$view = Object_Render::get_view( $tax, (int) $found['_host'] );
$props = isset( $view['properties'] ) && is_array( $view['properties'] ) ? $view['properties'] : array();
$bau = null;
foreach ( $props as $p ) {
	if ( is_array( $p ) && 0 === strcasecmp( (string) ( $p['name'] ?? '' ), 'Bauform' ) ) {
		$bau = $p;
		break;
	}
}
if ( ! is_array( $bau ) ) {
	/* get_view may use attributes key */
	$attrs = isset( $view['attributes'] ) && is_array( $view['attributes'] ) ? $view['attributes'] : array();
	foreach ( $attrs as $p ) {
		if ( is_array( $p ) && 0 === strcasecmp( (string) ( $p['name'] ?? '' ), 'Bauform' ) ) {
			$bau = $p;
			break;
		}
	}
}

if ( is_array( $bau ) ) {
	echo 'view Bauform fixedMode=' . ( $bau['fixedMode'] ?? '' ) . ' typeProps=' . count( $bau['typeProperties'] ?? array() ) . "\n";
	if ( 'catalog' !== (string) ( $bau['fixedMode'] ?? '' ) ) {
		echo "FAIL: view fixedMode\n";
		$ok = false;
	}
} else {
	echo "WARN: Bauform not in Object_Render view keys=" . implode( ',', array_keys( $view ) ) . "\n";
}

/* size / Unit type must stay structure when they have attrs. */
$size = get_terms(
	array(
		'taxonomy'   => $tax,
		'name'       => 'size',
		'hide_empty' => false,
		'number'     => 1,
	)
);
if ( ! is_wp_error( $size ) && ! empty( $size ) ) {
	$sid = (int) $size[0]->term_id;
	$prefers = Attribute::prefers_structure_over_catalog( $tax, $sid );
	echo 'size prefers_structure=' . ( $prefers ? 'yes' : 'no' ) . "\n";
	if ( ! $prefers && Attribute::type_has_attributes( $tax, $sid ) ) {
		echo "FAIL: size should prefer structure\n";
		$ok = false;
	}
}

$bauformen = get_terms(
	array(
		'taxonomy'   => $tax,
		'name'       => 'Bauformen',
		'hide_empty' => false,
		'number'     => 5,
	)
);
foreach ( (array) $bauformen as $b ) {
	$kids = get_terms(
		array(
			'taxonomy'   => $tax,
			'parent'     => (int) $b->term_id,
			'hide_empty' => false,
			'number'     => 1,
		)
	);
	if ( is_wp_error( $kids ) || empty( $kids ) ) {
		continue;
	}
	$bid = (int) $b->term_id;
	$prefers = Attribute::prefers_structure_over_catalog( $tax, $bid );
	echo "Bauformen id={$bid} prefers_structure=" . ( $prefers ? 'yes' : 'no' ) . "\n";
	if ( $prefers ) {
		echo "FAIL: Bauformen must not prefer structure\n";
		$ok = false;
	}
	break;
}

echo $ok ? "OK\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
