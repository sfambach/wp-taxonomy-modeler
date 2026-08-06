<?php
$tax = 'wtt_fs';
foreach ( array( 'node_ref', 'node_embed', 'node_pick' ) as $n ) {
	$terms = get_terms( array( 'taxonomy' => $tax, 'name' => $n, 'hide_empty' => false, 'number' => 0 ) );
	foreach ( $terms as $t ) {
		echo $n . ' id=' . $t->term_id . ' parent=' . $t->parent . ' dt=' . ( WTT\Node_Type::is_datatype( $tax, (int) $t->term_id ) ? '1' : '0' ) . ' abs=' . ( WTT\Node_Type::is_abstract( $tax, (int) $t->term_id ) ? '1' : '0' ) . PHP_EOL;
	}
}
$ctx = WTT\Demo_Data::find_term_by_path( $tax, array( 'Fallstudie', 'Implementation', 'Bauteile', 'Widerstand' ) );
$found = WTT\Node_Type::find_type_by_name( $tax, $ctx, 'node_ref' );
echo "find_type_by_name node_ref from Widerstand ctx={$ctx} => {$found}\n";
WTT\Demo_Data::ensure_lieferant_slot_ref_scopes(
	$tax,
	array( 'Fallstudie', 'Implementation', 'Bauteile' ),
	array( 'Fallstudie', 'Implementation', 'Lieferanten' )
);
$slot = WTT\Demo_Data::find_term_by_path( $tax, array( 'Fallstudie', 'Implementation', 'Bauteile', 'Widerstand', 'Lieferant' ) );
echo 'slot type=' . WTT\Node_Type::get_type_id( $slot ) . PHP_EOL;
