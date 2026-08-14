<?php
/**
 * Inspect set/table/quantity attribute refs.
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$tax = 'wtt_fs';
$want = array( 'set', 'table', 'list', 'enum', 'quantity', 'size', 'node_ref', 'node_embed', 'node_pick', 'Bauart' );
$ids  = array();
foreach ( $want as $name ) {
	$ts = get_terms(
		array(
			'taxonomy'   => $tax,
			'name'       => $name,
			'hide_empty' => false,
			'number'     => 5,
		)
	);
	foreach ( $ts as $t ) {
		$ids[ (int) $t->term_id ] = $t->name;
	}
}

$all = get_terms(
	array(
		'taxonomy'   => $tax,
		'hide_empty' => false,
		'number'     => 0,
	)
);
$hits = array();
foreach ( $all as $term ) {
	foreach ( \WTT\Attribute::list_own( $tax, (int) $term->term_id ) as $a ) {
		$tid = (int) ( $a['typeId'] ?? 0 );
		if ( isset( $ids[ $tid ] ) ) {
			$hits[ $ids[ $tid ] ][] = $term->name . '::' . ( $a['name'] ?? '?' );
		}
	}
}
foreach ( $hits as $type => $uses ) {
	echo $type . ' => ' . count( $uses ) . "\n";
	foreach ( $uses as $u ) {
		echo "  {$u}\n";
	}
}
