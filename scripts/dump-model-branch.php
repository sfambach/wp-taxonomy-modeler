<?php
/**
 * Dump Fallstudie/Model subtree + own attributes (diagnostic).
 *
 * Usage from WordPress root:
 *   php path/to/this-script.php
 * or load via wp-load include.
 *
 * @package WP_Taxonomy_Tree
 */

$wp_load = dirname( __DIR__, 3 ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	$wp_load = 'C:/devel/wordpress/wp-load.php';
}
require $wp_load;

if ( ! class_exists( 'WTT\\Case_Data' ) ) {
	fwrite( STDERR, "PLUGIN_INACTIVE\n" );
	exit( 1 );
}

$tax   = \WTT\Taxonomy::FS;
$model = \WTT\Demo_Data::find_term_by_path( $tax, array( 'Fallstudie', 'Model' ) );
echo "Model id={$model}\n";
if ( $model <= 0 ) {
	exit( 0 );
}

/**
 * @param list<string> $path Path segments under Fallstudie.
 */
function wtt_dump_attrs( string $tax, array $path ): void {
	$id = \WTT\Demo_Data::find_term_by_path( $tax, $path );
	if ( $id <= 0 ) {
		echo implode( '/', $path ) . " MISSING\n";
		return;
	}
	$attrs = \WTT\Attribute::list_own( $tax, $id );
	$names = array();
	foreach ( $attrs as $row ) {
		$names[] = (string) ( $row['name'] ?? '?' );
	}
	echo implode( '/', $path ) . ' #' . $id . ' attrs=[' . implode( ', ', $names ) . "]\n";
}

/**
 * Recursive hierarchy dump.
 */
function wtt_dump_tree( string $tax, int $parent, int $depth = 0 ): void {
	$kids = get_terms(
		array(
			'taxonomy'   => $tax,
			'parent'     => $parent,
			'hide_empty' => false,
			'number'     => 0,
		)
	);
	if ( ! is_array( $kids ) ) {
		return;
	}
	foreach ( $kids as $k ) {
		if ( ! $k instanceof WP_Term ) {
			continue;
		}
		$slot = \WTT\Attribute::is_slot( (int) $k->term_id ) ? ' [SLOT]' : '';
		$abs  = \WTT\Node_Type::is_abstract( $tax, (int) $k->term_id ) ? ' [abs]' : '';
		echo str_repeat( '  ', $depth ) . $k->name . ' #' . $k->term_id . $slot . $abs . "\n";
		if ( $depth < 6 ) {
			wtt_dump_tree( $tax, (int) $k->term_id, $depth + 1 );
		}
	}
}

wtt_dump_tree( $tax, $model, 0 );
echo "--- attrs ---\n";

$checks = array(
	array( 'Fallstudie', 'Model', 'Kontakt' ),
	array( 'Fallstudie', 'Model', 'Platine' ),
	array( 'Fallstudie', 'Model', 'Bauteil' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Passiv' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Passiv', 'Widerstand' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Passiv', 'Kondensator' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Passiv', 'Spule' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Halbleiter' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Halbleiter', 'Dioden' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Halbleiter', 'Transistor' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Halbleiter', 'LED' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Halbleiter', 'IC' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Elektromechanik' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Elektromechanik', 'Relais' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Elektromechanik', 'Steckverbinder' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Elektromechanik', 'Schalter' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Sonstige' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Sonstige', 'Quarz' ),
	array( 'Fallstudie', 'Model', 'Bauteil', 'Sonstige', 'Sicherung' ),
);
foreach ( $checks as $p ) {
	wtt_dump_attrs( $tax, $p );
}

echo "--- dioden arten ---\n";
$dioden = \WTT\Demo_Data::find_term_by_path(
	$tax,
	array( 'Fallstudie', 'Model', 'Bauteil', 'Halbleiter', 'Dioden' )
);
if ( $dioden > 0 ) {
	$arten = get_terms(
		array(
			'taxonomy'   => $tax,
			'parent'     => $dioden,
			'hide_empty' => false,
			'number'     => 0,
		)
	);
	foreach ( (array) $arten as $a ) {
		if ( $a instanceof WP_Term ) {
			echo '  ' . $a->name . ' #' . $a->term_id . "\n";
		}
	}
} else {
	echo "Dioden MISSING\n";
}
