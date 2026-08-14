<?php
/**
 * Run Complex ensure + report remaining kids.
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$tax = 'wtt_fs';
$out = \WTT\Case_Data::ensure_complex_datatypes( $tax );
echo 'ensure: ' . wp_json_encode( $out ) . "\n";

$complex = \WTT\Case_Data::find_term_by_path(
	$tax,
	array( 'Fallstudie', 'Definition', 'Data Types', 'Complex' )
);
echo "Complex id={$complex}\n";
$kids = get_terms(
	array(
		'taxonomy'   => $tax,
		'parent'     => $complex,
		'hide_empty' => false,
		'number'     => 0,
	)
);
foreach ( $kids as $k ) {
	$trashed = \WTT\Trash::is_trashed( (int) $k->term_id ) ? ' TRASHED' : '';
	echo $k->term_id . "\t" . $k->name . $trashed . "\n";
}

$bauart = \WTT\Demo_Data::ensure_bauart_enum( $tax );
echo "Bauart id={$bauart}\n";
if ( $bauart > 0 ) {
	$t = get_term( $bauart, $tax );
	$p = $t instanceof WP_Term ? get_term( (int) $t->parent, $tax ) : null;
	echo 'Bauart parent=' . ( $p instanceof WP_Term ? $p->name : '?' ) . "\n";
}
