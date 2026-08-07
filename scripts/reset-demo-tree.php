<?php
/**
 * Reset demo tree (delete BOM Testprojekt root + reinstall blueprint).
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file.\n" );
	exit( 1 );
}

\WTT\Taxonomy::register_taxonomies();
/* Legacy Demo_Data helper — product scaffold uses Case_Data / wtt_fs (Reset case tree). */
$taxonomy = \WTT\Taxonomy::TREE;
$result   = \WTT\Demo_Data::reset( $taxonomy );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, $result->get_error_message() . "\n" );
	exit( 1 );
}

\WTT\Demo_Data::ensure_media_type( $taxonomy );
\WTT\Demo_Data::ensure_bom_columns( $taxonomy );

printf(
	"reset taxonomy=%s deleted=%d created=%d existing=%d\n",
	$result['taxonomy'],
	(int) $result['deleted'],
	(int) $result['created'],
	(int) $result['existing']
);
