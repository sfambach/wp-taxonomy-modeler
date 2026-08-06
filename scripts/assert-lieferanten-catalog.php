<?php
/**
 * Assert Lieferanten catalog (record set, not enum).
 *
 * @package WP_Taxonomy_Tree
 */

foreach ( array( WTT\Taxonomy::FS, WTT\Taxonomy::TREE ) as $tax ) {
	$paths = WTT\Taxonomy::FS === $tax
		? array( 'Fallstudie', 'Implementation', 'Lieferanten' )
		: array( 'BOM Testprojekt', 'Lieferanten' );
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
			WTT\Taxonomy::FS === $tax
				? array( 'Fallstudie', 'Definition', 'Bauteilarten', 'Widerstand', 'Lieferant' )
				: array( 'BOM Testprojekt', 'Bauteilarten', 'Widerstand', 'Lieferant' )
		);
		if ( $w > 0 ) {
			$tid = WTT\Node_Type::get_type_id( $w );
			$t   = $tid ? get_term( $tid ) : null;
			$sc  = WTT\Node_Type::get_ref_scope_id( $w );
			echo ' Widerstand.Lieferant type=' . ( $t instanceof WP_Term ? $t->name : $tid ) . ' scope=' . $sc;
		}
	}
	echo PHP_EOL;
}
