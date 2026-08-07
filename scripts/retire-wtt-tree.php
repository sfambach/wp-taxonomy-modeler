<?php
/**
 * One-shot: delete all wtt_tree terms and clear safe option keys for that taxonomy.
 *
 * Usage (from WordPress docroot):
 *   php C:\laragon\bin\wp-cli.phar --path=C:\devel\wordpress eval-file path/to/retire-wtt-tree.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file from a WordPress install.\n" );
	exit( 1 );
}

\WTT\Taxonomy::register_taxonomies();

$taxonomy = \WTT\Taxonomy::TREE;
$deleted  = 0;
$errors   = array();

if ( taxonomy_exists( $taxonomy ) ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 0,
			'fields'     => 'ids',
		)
	);
	if ( is_array( $terms ) ) {
		/* Deepest first so parents delete cleanly. */
		$terms = array_map( 'intval', $terms );
		usort(
			$terms,
			static function ( int $a, int $b ) use ( $taxonomy ): int {
				$ta = get_term( $a, $taxonomy );
				$tb = get_term( $b, $taxonomy );
				$da = ( $ta instanceof \WP_Term ) ? count( get_ancestors( $a, $taxonomy ) ) : 0;
				$db = ( $tb instanceof \WP_Term ) ? count( get_ancestors( $b, $taxonomy ) ) : 0;
				return $db <=> $da;
			}
		);
		foreach ( $terms as $term_id ) {
			$result = wp_delete_term( $term_id, $taxonomy );
			if ( true === $result ) {
				++$deleted;
			} elseif ( is_wp_error( $result ) ) {
				$errors[] = $term_id . ': ' . $result->get_error_message();
			}
		}
	}
}

$cleared_options = array();

/* Catalog bindings keyed by taxonomy slug. */
$bindings = get_option( 'wtt_catalog_bindings', array() );
if ( is_array( $bindings ) && isset( $bindings[ $taxonomy ] ) ) {
	unset( $bindings[ $taxonomy ] );
	update_option( 'wtt_catalog_bindings', $bindings, false );
	$cleared_options[] = 'wtt_catalog_bindings[' . $taxonomy . ']';
}

/*
 * Model instances are keyed by structure term id (not taxonomy).
 * Orphan bags for deleted BOM term ids are harmless; leave store intact.
 */

$remaining = taxonomy_exists( $taxonomy )
	? get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 0,
			'fields'     => 'ids',
		)
	)
	: array();
$remaining_count = is_array( $remaining ) ? count( $remaining ) : 0;

WP_CLI::log( sprintf( 'Deleted %d term(s) from %s.', $deleted, $taxonomy ) );
WP_CLI::log( sprintf( 'Remaining terms: %d', $remaining_count ) );
if ( $cleared_options ) {
	WP_CLI::log( 'Cleared options: ' . implode( ', ', $cleared_options ) );
}
if ( $errors ) {
	foreach ( $errors as $err ) {
		WP_CLI::warning( $err );
	}
	exit( 1 );
}
WP_CLI::success( 'wtt_tree retired locally.' );
