<?php
/**
 * Wipe all wtt_fs (Fallstudie) terms and reinstall the case-study blueprint.
 *
 * Deletes Fallstudie root, Q87 attribute slots, Trash/Hidden bins, band orphans,
 * catalog bindings for wtt_fs, and Model_Data bags for that taxonomy — then seeds
 * a clean example tree. Does not touch posts, users, or other taxonomies.
 *
 * Usage (from WordPress docroot):
 *   php C:\laragon\bin\wp-cli.phar --path=C:\devel\wordpress --user=admin eval-file path/to/reset-case-tree.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file from a WordPress install.\n" );
	exit( 1 );
}

if ( ! defined( 'WTT_ALLOW_DEMO_MUTATIONS' ) ) {
	define( 'WTT_ALLOW_DEMO_MUTATIONS', true );
}

\WTT\Taxonomy::register_taxonomies();

$taxonomy = \WTT\Taxonomy::FS;
if ( ! taxonomy_exists( $taxonomy ) ) {
	fwrite( STDERR, "Taxonomy {$taxonomy} is not registered.\n" );
	exit( 1 );
}

$before = get_terms(
	array(
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'number'     => 0,
		'fields'     => 'ids',
	)
);
$before_count = is_array( $before ) ? count( $before ) : 0;

$result = \WTT\Case_Data::reset( $taxonomy );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, $result->get_error_message() . "\n" );
	exit( 1 );
}

$after = get_terms(
	array(
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'number'     => 0,
		'fields'     => 'ids',
	)
);
$after_count = is_array( $after ) ? count( $after ) : 0;

$slot_roots = 0;
$top        = get_terms(
	array(
		'taxonomy'   => $taxonomy,
		'parent'     => 0,
		'hide_empty' => false,
		'number'     => 0,
	)
);
foreach ( (array) $top as $term ) {
	if ( $term instanceof WP_Term && \WTT\Attribute::is_slot( (int) $term->term_id ) ) {
		++$slot_roots;
	}
}

printf(
	"reset taxonomy=%s before=%d deleted=%d created=%d existing=%d after=%d orphan_slot_roots=%d\n",
	$result['taxonomy'],
	$before_count,
	(int) $result['deleted'],
	(int) $result['created'],
	(int) $result['existing'],
	$after_count,
	$slot_roots
);

$paths = array(
	array( 'Fallstudie' ),
	array( 'Fallstudie', 'Definition' ),
	array( 'Fallstudie', 'Definition', 'Data Types' ),
	array( 'Fallstudie', 'Model' ),
	array( 'Fallstudie', 'Model', 'Bauteil' ),
	array( 'Fallstudie', 'Model', 'Kontakt' ),
	array( 'Fallstudie', 'Model', 'Platine' ),
);
foreach ( $paths as $path ) {
	$id = \WTT\Case_Data::find_term_by_path( $taxonomy, $path );
	printf( "  %s → %s\n", implode( '/', $path ), $id > 0 ? (string) $id : 'MISSING' );
}

echo "OK\n";
