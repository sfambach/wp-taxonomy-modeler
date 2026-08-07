<?php
/**
 * Assert Q83 Bauteile split on Fallstudie (wtt_fs): Bauteilarten + Bauteile.
 *
 * @package WP_Taxonomy_Tree
 */

$tax   = WTT\Taxonomy::FS;
$arten = WTT\Demo_Data::find_term_by_path( $tax, array( 'Fallstudie', 'Definition', 'Bauteilarten' ) );
$bau   = WTT\Demo_Data::find_term_by_path( $tax, array( 'Fallstudie', 'Implementation', 'Bauteile' ) );
if ( $arten <= 0 || $bau <= 0 ) {
	fwrite( STDERR, "Bauteilarten or Bauteile missing on wtt_fs\n" );
	exit( 1 );
}

$kinds = get_terms(
	array(
		'taxonomy'   => $tax,
		'parent'     => $arten,
		'hide_empty' => false,
		'number'     => 0,
	)
);
echo 'Bauteilarten kinds=' . count( (array) $kinds ) . PHP_EOL;
foreach ( (array) $kinds as $k ) {
	$edges = WTT\Relation::list_outgoing_by_type_key( $tax, (int) $k->term_id, 'composition' );
	echo '  ' . $k->name . ' composition=' . count( $edges ) . PHP_EOL;
}

$recs = get_terms(
	array(
		'taxonomy'   => $tax,
		'parent'     => $bau,
		'hide_empty' => false,
		'number'     => 0,
	)
);
$ex = 0;
foreach ( (array) $recs as $r ) {
	if ( WTT\Demo_Data::is_catalog_example( (int) $r->term_id ) ) {
		++$ex;
	}
}
echo "Bauteile records=$ex\n";
echo "OK bauteile Q83 split (wtt_fs)\n";
