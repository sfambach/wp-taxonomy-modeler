<?php
/**
 * Smoke: node_presentation Symbol resolves (Toleranz Unit → %), not form name.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	$candidates = array(
		dirname( __DIR__, 3 ) . '/wp-load.php',
		dirname( __DIR__, 2 ) . '/wp-load.php',
		'C:/devel/wordpress/wp-load.php',
	);
	foreach ( $candidates as $load ) {
		if ( is_readable( $load ) ) {
			require_once $load;
			break;
		}
	}
}

if ( ! defined( 'ABSPATH' ) || ! class_exists( '\WTT\Case_Data' ) ) {
	fwrite( STDERR, "FAIL: WordPress / plugin not loaded\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Case_Data;
use WTT\Node_Presentation;
use WTT\Taxonomy;

$taxonomy = Taxonomy::FS;
Case_Data::ensure_with_prefix_composition( $taxonomy );

$tol = get_terms(
	array(
		'taxonomy'   => $taxonomy,
		'name'       => 'Toleranz',
		'hide_empty' => false,
		'number'     => 1,
	)
);
if ( ! is_array( $tol ) || ! isset( $tol[0] ) || ! ( $tol[0] instanceof WP_Term ) ) {
	fwrite( STDERR, "FAIL: Toleranz missing\n" );
	exit( 1 );
}
$tol_id = (int) $tol[0]->term_id;

$map = Node_Presentation::map_for_term_resolved( $taxonomy, $tol_id );
if ( '%' !== (string) ( $map['symbol'] ?? '' ) ) {
	fwrite( STDERR, 'FAIL: Toleranz resolved symbol expected %, got ' . wp_json_encode( $map ) . "\n" );
	exit( 1 );
}
echo "OK Toleranz symbol=%\n";

$unit = null;
foreach ( Attribute::list( $taxonomy, $tol_id ) as $row ) {
	if ( is_array( $row ) && 'Unit' === (string) ( $row['name'] ?? '' ) ) {
		$unit = $row;
		break;
	}
}
if ( ! is_array( $unit ) ) {
	fwrite( STDERR, "FAIL: Unit attr missing on Toleranz\n" );
	exit( 1 );
}
$ctx = (string) ( $unit['presentationConfig']['context'] ?? '' );
if ( 'symbol' !== $ctx ) {
	fwrite( STDERR, "FAIL: Unit presentation context={$ctx}, expected symbol\n" );
	exit( 1 );
}
echo "OK Unit context=symbol\n";

$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/wtt-node-render.js' );
if ( ! str_contains( $js, 'Always resolves by Q117 context' ) ) {
	fwrite( STDERR, "FAIL: NodePresentation resolve fix missing\n" );
	exit( 1 );
}
echo "OK renderer ignores live name short-circuit\n";
echo "PASS\n";
exit( 0 );
