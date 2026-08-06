<?php
/**
 * Assert Q83 Bauteile split: Bauteilarten (kinds) + Bauteile (records).
 *
 * @package WP_Taxonomy_Tree
 */

$arten = WTT\Demo_Data::find_term_by_path( 'wtt_tree', array( 'BOM Testprojekt', 'Bauteilarten' ) );
$bau   = WTT\Demo_Data::find_term_by_path( 'wtt_tree', array( 'BOM Testprojekt', 'Bauteile' ) );
if ( $arten <= 0 || $bau <= 0 ) {
	fwrite( STDERR, "Bauteilarten or Bauteile missing\n" );
	exit( 1 );
}

$kinds = get_terms(
	array(
		'taxonomy'   => 'wtt_tree',
		'parent'     => $arten,
		'hide_empty' => false,
		'number'     => 0,
	)
);
echo 'Bauteilarten kinds=' . count( (array) $kinds ) . PHP_EOL;
foreach ( (array) $kinds as $k ) {
	$edges = WTT\Relation::list_outgoing_by_type_key( 'wtt_tree', (int) $k->term_id, 'composition' );
	echo '  ' . $k->name . ' composition=' . count( $edges ) . PHP_EOL;
}

$recs = get_terms(
	array(
		'taxonomy'   => 'wtt_tree',
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
if ( count( (array) $kinds ) < 12 || $ex < 24 ) {
	fwrite( STDERR, "Expected >=12 kinds and >=24 records\n" );
	exit( 1 );
}
echo "OK bauteile Q83 split\n";
