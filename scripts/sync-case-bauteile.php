<?php
/**
 * Sync Fallstudie (wtt_fs) Bauteile + Lieferant catalog.
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$result = WTT\Case_Data::install( WTT\Taxonomy::FS );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, $result->get_error_message() . PHP_EOL );
	exit( 1 );
}

$path = array( 'Fallstudie', 'Implementation', 'Bauteile' );
$b    = WTT\Demo_Data::find_term_by_path( WTT\Taxonomy::FS, $path );
echo 'Bauteile id=' . $b . PHP_EOL;
if ( $b > 0 ) {
	$kinds = get_terms(
		array(
			'taxonomy'   => WTT\Taxonomy::FS,
			'parent'     => $b,
			'hide_empty' => false,
			'number'     => 0,
		)
	);
	foreach ( $kinds as $k ) {
		echo '  ' . $k->name . PHP_EOL;
	}
}
$l = WTT\Demo_Data::find_term_by_path(
	WTT\Taxonomy::FS,
	array( 'Fallstudie', 'Implementation', 'Lieferanten' )
);
echo 'Lieferanten catalog id=' . $l . PHP_EOL;
echo 'created=' . (int) $result['created'] . ' existing=' . (int) $result['existing'] . PHP_EOL;
