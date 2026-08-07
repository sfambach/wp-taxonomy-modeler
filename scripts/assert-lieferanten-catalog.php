<?php
/**
 * Assert Lieferanten catalog on Fallstudie (wtt_fs).
 * Bauteil kinds must not carry Lieferant / Bestellnummer slots (Q83 merge).
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
}

$w = WTT\Demo_Data::find_term_by_path(
	$tax,
	array( 'Fallstudie', 'Implementation', 'Bauteile', 'Widerstand', 'Lieferant' )
);
if ( $w > 0 ) {
	fwrite( STDERR, "Unexpected Widerstand/Lieferant slot under Bauteile\n" );
	exit( 1 );
}
echo PHP_EOL;
echo "OK lieferanten catalog (no Bauteil Lieferant slots)\n";
