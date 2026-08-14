<?php
/**
 * Smoke: inherited Mult override + delete override keep father display order.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	$candidates = array(
		dirname( __DIR__, 3 ) . '/wp-load.php',
		dirname( __DIR__, 2 ) . '/wp-load.php',
		'C:/devel/wordpress/wp-load.php',
	);
	foreach ( $candidates as $load ) {
		if ( is_readable( $load ) ) {
			require_once $load;
			break;
		}
	}
}

if ( ! defined( 'ABSPATH' ) || ! class_exists( '\WTT\Attribute' ) ) {
	fwrite( STDERR, "FAIL: WordPress / plugin not loaded\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Taxonomy;

$taxonomy = Taxonomy::FS;

$find = static function ( string $name ) use ( $taxonomy ) {
	$hits = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'name'       => $name,
			'hide_empty' => false,
			'number'     => 1,
		)
	);
	return ( is_array( $hits ) && isset( $hits[0] ) && $hits[0] instanceof WP_Term )
		? $hits[0]
		: null;
};

$child  = $find( 'Toleranz' );
$father = $find( 'Percent' );
if ( ! $child || ! $father ) {
	fwrite( STDERR, "FAIL: Toleranz/Percent not found\n" );
	exit( 1 );
}

$host_id   = (int) $child->term_id;
$father_id = (int) $father->term_id;

/* Cleanup leftover own Value. */
foreach ( Attribute::list_own( $taxonomy, $host_id ) as $row ) {
	if ( 'Value' === (string) ( $row['name'] ?? '' ) ) {
		Attribute::remove( $taxonomy, $host_id, $row['id'] ?? '' );
	}
}

$father_order = get_term_meta( $father_id, Attribute::META_KEY_ORDER, true );
if ( is_array( $father_order ) && array() !== $father_order ) {
	update_term_meta( $host_id, Attribute::META_KEY_ORDER, $father_order );
}
Attribute::bust_request_caches();

$names = static function ( array $list ): array {
	$out = array();
	foreach ( $list as $row ) {
		$out[] = (string) ( $row['name'] ?? '' );
	}
	return $out;
};

$baseline = $names( Attribute::list( $taxonomy, $host_id ) );
$target   = null;
foreach ( Attribute::list( $taxonomy, $host_id ) as $row ) {
	if ( ! empty( $row['inherited'] ) ) {
		$target = $row;
		break;
	}
}
if ( null === $target ) {
	fwrite( STDERR, "FAIL: no inherited attribute on Toleranz\n" );
	exit( 1 );
}

$old  = (string) ( $target['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY );
$next = ( '1' === $old ) ? '0..1' : '1';
$aid  = Attribute::normalize_attr_id( $target['id'] ?? '' );

$over = Attribute::set_multiplicity( $taxonomy, $host_id, $aid, $next );
if ( is_wp_error( $over ) ) {
	fwrite( STDERR, 'FAIL override: ' . $over->get_error_message() . "\n" );
	exit( 1 );
}
Attribute::bust_request_caches();

$during = $names( Attribute::list( $taxonomy, $host_id ) );
if ( $baseline !== $during ) {
	fwrite(
		STDERR,
		'FAIL: order changed on override: '
		. implode( ', ', $baseline ) . ' → ' . implode( ', ', $during ) . "\n"
	);
	exit( 1 );
}

$own_id = '';
foreach ( Attribute::list_own( $taxonomy, $host_id ) as $row ) {
	if ( (string) ( $row['name'] ?? '' ) === (string) ( $target['name'] ?? '' ) ) {
		$own_id = Attribute::normalize_attr_id( $row['id'] ?? '' );
		break;
	}
}
if ( '' === $own_id ) {
	fwrite( STDERR, "FAIL: own override edge missing after Mult change\n" );
	exit( 1 );
}

$rm = Attribute::remove( $taxonomy, $host_id, $own_id );
if ( is_wp_error( $rm ) ) {
	fwrite( STDERR, 'FAIL remove: ' . $rm->get_error_message() . "\n" );
	exit( 1 );
}
Attribute::bust_request_caches();

$after = $names( Attribute::list( $taxonomy, $host_id ) );
if ( $baseline !== $after ) {
	fwrite(
		STDERR,
		'FAIL: order changed after deleting override: '
		. implode( ', ', $baseline ) . ' → ' . implode( ', ', $after ) . "\n"
	);
	exit( 1 );
}

echo 'PASS: override + delete keep order (' . implode( ', ', $baseline ) . ")\n";
exit( 0 );
