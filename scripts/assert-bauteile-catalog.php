<?php
/**
 * Assert Q83: grouped kinds under Model/Bauteil (Implementation/Bauteile optional / may be absent).
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	require 'C:/devel/wordpress/wp-load.php';
}

$tax   = 'wtt_fs';
$model = WTT\Demo_Data::find_model_bauteil_id( $tax );
if ( $model <= 0 ) {
	fwrite( STDERR, "Model/Bauteil missing on wtt_fs\n" );
	exit( 1 );
}

foreach ( array( 'Passiv', 'Halbleiter', 'Elektromechanik', 'Sonstige' ) as $group ) {
	$id = WTT\Demo_Data::find_direct_child_named( $tax, $model, $group );
	if ( $id <= 0 ) {
		fwrite( STDERR, "Missing group Model/Bauteil/{$group}\n" );
		exit( 1 );
	}
}

$checks = array(
	'Widerstand' => 'Passiv',
	'Dioden'     => 'Halbleiter',
	'Relais'     => 'Elektromechanik',
	'Quarz'      => 'Sonstige',
);
foreach ( $checks as $kind => $group ) {
	$id = WTT\Demo_Data::find_bauteil_kind_under( $tax, $model, $kind );
	if ( $id <= 0 ) {
		fwrite( STDERR, "Missing kind {$kind}\n" );
		exit( 1 );
	}
	$term = get_term( $id, $tax );
	$parent = $term instanceof WP_Term ? get_term( (int) $term->parent, $tax ) : null;
	if ( ! $parent instanceof WP_Term || $parent->name !== $group ) {
		fwrite( STDERR, "Kind {$kind} not under {$group}\n" );
		exit( 1 );
	}
	$hersteller = WTT\Demo_Data::find_direct_child_named( $tax, $id, 'Hersteller' );
	if ( $hersteller > 0 ) {
		fwrite( STDERR, "Unexpected Hersteller on {$kind}\n" );
		exit( 1 );
	}
}

foreach ( array( 'Passiv', 'Halbleiter', 'Elektromechanik', 'Sonstige' ) as $group ) {
	$gid = WTT\Demo_Data::find_direct_child_named( $tax, $model, $group );
	if ( $gid <= 0 ) {
		fwrite( STDERR, "Missing {$group}\n" );
		exit( 1 );
	}
	$kids = get_terms(
		array(
			'taxonomy'   => $tax,
			'parent'     => $gid,
			'hide_empty' => false,
			'number'     => 0,
		)
	);
	$seen = array();
	foreach ( (array) $kids as $kid ) {
		if ( ! $kid instanceof WP_Term ) {
			continue;
		}
		if ( WTT\Trash::is_trashed( (int) $kid->term_id ) ) {
			continue;
		}
		$key = 'Diode' === $kid->name ? 'Dioden' : $kid->name;
		if ( isset( $seen[ $key ] ) ) {
			fwrite( STDERR, "Duplicate under {$group}: {$key}\n" );
			exit( 1 );
		}
		$seen[ $key ] = true;
	}
	echo $group . ' kids=' . implode( ',', array_keys( $seen ) ) . PHP_EOL;
}
echo "OK bauteile groups + no kind duplicates\n";
