<?php
/**
 * Smoke: mass unit shortDescription stays aligned with Presentation.symbol (not "kg").
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

use WTT\Case_Data;
use WTT\Node_Presentation;
use WTT\Taxonomy;
use WTT\Tree_Model;

$taxonomy = Taxonomy::FS;
Case_Data::ensure_with_prefix_composition( $taxonomy );

$hits = get_terms(
	array(
		'taxonomy'   => $taxonomy,
		'name'       => 'Gramm',
		'hide_empty' => false,
		'number'     => 3,
	)
);
if ( ! is_array( $hits ) || array() === $hits ) {
	$hits = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'name'       => 'Kilogramm',
			'hide_empty' => false,
			'number'     => 3,
		)
	);
}
if ( ! is_array( $hits ) || ! isset( $hits[0] ) || ! ( $hits[0] instanceof WP_Term ) ) {
	fwrite( STDERR, "FAIL: Gramm/Kilogramm not found\n" );
	exit( 1 );
}

$term = $hits[0];
$id   = (int) $term->term_id;

Tree_Model::set_short_description( $taxonomy, $id, 'kg' );
Case_Data::ensure_with_prefix_composition( $taxonomy );

$short  = Tree_Model::get_short_description( $id );
$symbol = Node_Presentation::map_for_term_ui( $id )['symbol'] ?? '';

if ( '' === $symbol ) {
	fwrite( STDERR, "FAIL: Presentation.symbol empty on {$term->name} ($id)\n" );
	exit( 1 );
}
if ( $short !== $symbol ) {
	fwrite( STDERR, "FAIL: shortDescription '$short' !== symbol '$symbol'\n" );
	exit( 1 );
}
if ( 'kg' === $short && 'g' === $symbol ) {
	fwrite( STDERR, "FAIL: still diverged kg vs g\n" );
	exit( 1 );
}

echo "PASS: {$term->name} shortDescription='$short' mirrors Presentation.symbol\n";
exit( 0 );
