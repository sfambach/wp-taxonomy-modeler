<?php
/**
 * Assert Lieferant type + Widerstand slot on Fallstudie (wtt_fs).
 *
 * @package WP_Taxonomy_Tree
 */

$tax = WTT\Taxonomy::FS;
$lid = WTT\Node_Type::find_type_by_name(
	$tax,
	WTT\Demo_Data::find_term_by_path( $tax, array( 'Fallstudie' ) ),
	'Lieferant'
);
echo 'Lieferant type_id=' . $lid . PHP_EOL;
if ( $lid > 0 ) {
	$opt = get_terms(
		array(
			'taxonomy'   => $tax,
			'parent'     => $lid,
			'hide_empty' => false,
			'number'     => 0,
		)
	);
	foreach ( $opt as $o ) {
		echo '  child: ' . $o->name . PHP_EOL;
		$grand = get_terms(
			array(
				'taxonomy'   => $tax,
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
	$tax,
	array( 'Fallstudie', 'Definition', 'Bauteilarten', 'Widerstand', 'Lieferant' )
);
echo 'Widerstand/Lieferant slot=' . $slot;
if ( $slot > 0 ) {
	echo ' type=' . WTT\Node_Type::get_type_id( $slot );
}
echo PHP_EOL;
