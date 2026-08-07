<?php
/**
 * Repair Fallstudie Model branch only (hierarchy + attributes).
 *
 * Does not wipe Implementation / Definition / other roots.
 *
 * Usage (from anywhere, loads WP):
 *   php scripts/repair-model-branch.php
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

$wp_load = 'C:/devel/wordpress/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found\n" );
	exit( 1 );
}
require $wp_load;

if ( ! class_exists( 'WTT\\Case_Data' ) ) {
	fwrite( STDERR, "PLUGIN_INACTIVE\n" );
	exit( 1 );
}

WTT\Taxonomy::register_taxonomies();
$tax = WTT\Taxonomy::FS;

echo "=== ensure_model_branch ===\n";
$result = WTT\Case_Data::ensure_model_branch( $tax );
echo wp_json_encode( $result, JSON_PRETTY_PRINT ) . "\n";

echo "\n=== Model tree (get_tree) ===\n";
$tree = WTT\Tree_Model::get_tree( $tax );
foreach ( $tree as $root ) {
	if ( ( $root['name'] ?? '' ) !== 'Fallstudie' ) {
		continue;
	}
	foreach ( $root['children'] ?? array() as $c ) {
		if ( ( $c['name'] ?? '' ) !== 'Model' ) {
			continue;
		}
		wtt_print_model( $c, 0 );
	}
}

echo "\n=== Verify expected paths ===\n";
$expected = array(
	array( 'Fallstudie', 'Model', 'Kontakt' ),
	array( 'Fallstudie', 'Model', 'Platine' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Passiv', 'Widerstand' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Passiv', 'Kondensator' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Passiv', 'Spule' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Halbleiter', 'Dioden', 'Schalt' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Halbleiter', 'Transistor' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Halbleiter', 'LED' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Halbleiter', 'IC' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Elektromechanik', 'Relais' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Sonstige', 'Quarz' ),
);
foreach ( $expected as $path ) {
	$id = WTT\Case_Data::find_term_by_path( $tax, $path );
	$attrs = $id > 0 ? count( WTT\Attribute::list( $tax, $id ) ) : 0;
	$has   = $id > 0 && WTT\Tree_Model::term_has_children( $tax, $id ) ? 'Y' : 'N';
	echo ( $id > 0 ? 'OK' : 'MISSING' ) . ' ' . implode( '/', $path )
		. " #{$id} attrs={$attrs} hasChildren={$has}\n";
}

/**
 * @param array<string,mixed> $n Node.
 */
function wtt_print_model( array $n, int $d ): void {
	$attrs = isset( $n['attributes'] ) && is_array( $n['attributes'] ) ? $n['attributes'] : array();
	/* get_tree nodes may not embed attributes — count via Attribute::list when id known. */
	$id = (int) ( $n['id'] ?? 0 );
	$an = $id > 0 ? count( WTT\Attribute::list( WTT\Taxonomy::FS, $id ) ) : count( $attrs );
	$kids = isset( $n['children'] ) && is_array( $n['children'] ) ? $n['children'] : array();
	echo str_repeat( '  ', $d ) . ( $n['name'] ?? '?' ) . " #{$id} attrs={$an} kids=" . count( $kids ) . "\n";
	foreach ( $kids as $c ) {
		if ( is_array( $c ) ) {
			wtt_print_model( $c, $d + 1 );
		}
	}
}

echo "OK\n";
