<?php
/**
 * Assert Lieferant enum type + Bauteil slot.
 *
 * @package WP_Taxonomy_Tree
 */

$lid = WTT\Node_Type::find_type_by_name(
	'wtt_tree',
	WTT\Demo_Data::find_term_by_path( 'wtt_tree', array( 'BOM Testprojekt' ) ),
	'Lieferant'
);
echo 'Lieferant type_id=' . $lid . PHP_EOL;
if ( $lid > 0 ) {
	$opt = get_terms(
		array(
			'taxonomy'   => 'wtt_tree',
			'parent'     => $lid,
			'hide_empty' => false,
			'number'     => 0,
		)
	);
	/* Option folder then names */
	foreach ( $opt as $o ) {
		echo '  child: ' . $o->name . PHP_EOL;
		$grand = get_terms(
			array(
				'taxonomy'   => 'wtt_tree',
				'parent'     => (int) $o->term_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		foreach ( $grand as $g ) {
			echo '    option: ' . $g->name . PHP_EOL;
		}
	}
}
$slot = WTT\Demo_Data::find_term_by_path(
	'wtt_tree',
	array( 'BOM Testprojekt', 'Bauteile', 'Widerstand', 'Lieferant' )
);
echo 'Widerstand/Lieferant slot=' . $slot;
if ( $slot > 0 ) {
	echo ' type=' . WTT\Node_Type::get_type_id( $slot );
}
echo PHP_EOL;
