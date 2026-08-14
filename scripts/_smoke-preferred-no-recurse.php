<?php
/**
 * Probe: get_preferred_render must not recurse infinitely.
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file.\n" );
	exit( 1 );
}

use WTT\Node_Type;
use WTT\Taxonomy;

$tax   = Taxonomy::FS;
$terms = get_terms(
	array(
		'taxonomy'   => $tax,
		'hide_empty' => false,
		'number'     => 0,
		'fields'     => 'ids',
	)
);

$n = 0;
try {
	foreach ( (array) $terms as $id ) {
		++$n;
		Node_Type::get_preferred_render( (int) $id );
	}
	echo "ok scanned=$n\n";
} catch ( Throwable $e ) {
	echo 'ERR at n=' . $n . ' id=' . ( isset( $id ) ? (int) $id : 0 ) . "\n";
	echo $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
	exit( 1 );
}
