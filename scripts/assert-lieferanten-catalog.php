<?php
/**
 * Assert Lieferanten catalog on Fallstudie (wtt_fs).
 *
 * @package WP_Taxonomy_Tree
 */

$tax   = WTT\Taxonomy::FS;
$paths = array( 'Fallstudie', 'Implementation', 'Lieferanten' );
$id    = WTT\Demo_Data::find_term_by_path( $tax, $paths );
echo $tax . ' Lieferanten=' . $id;
if ( $id > 0 ) {
	$kids = get_terms(
		array(
			'taxonomy'   => $tax,
			'parent'     => $id,
			'hide_empty' => false,
			'number'     => 0,
		)
	);
	$slots = 0;
	$recs  = 0;
	foreach ( $kids as $k ) {
		if ( WTT\Demo_Data::is_catalog_example( (int) $k->term_id ) ) {
			++$recs;
		} else {
			++$slots;
			echo ' [slot:' . $k->name . ']';
		}
	}
	echo " slots={$slots} records={$recs}";
	$w = WTT\Demo_Data::find_term_by_path(
		$tax,
		array( 'Fallstudie', 'Definition', 'Bauteilarten', 'Widerstand', 'Lieferant' )
	);
	if ( $w > 0 ) {
		$tid = WTT\Node_Type::get_type_id( $w );
		$t   = $tid ? get_term( $tid ) : null;
		$sc  = WTT\Node_Type::get_ref_scope_id( $w );
		echo ' Widerstand.Lieferant type=' . ( $t instanceof WP_Term ? $t->name : $tid ) . ' scope=' . $sc;
	}
}
echo PHP_EOL;
