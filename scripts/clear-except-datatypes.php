<?php
/**
 * Q85 restart: wipe tree except Datentypen (+ Relationstypen).
 *
 * Usage (WordPress root):
 *   php wp-cli.phar --user=admin eval-file …/clear-except-datatypes.php
 *   php wp-cli.phar --user=admin eval-file …/clear-except-datatypes.php -- --reset
 *
 * With --reset: full Demo_Data / Case_Data reinstall first (recovers wiped types), then clear.
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file.\n" );
	exit( 1 );
}

if ( ! defined( 'WTT_ALLOW_DEMO_MUTATIONS' ) ) {
	define( 'WTT_ALLOW_DEMO_MUTATIONS', true );
}

\WTT\Taxonomy::register_taxonomies();

$do_reset = false;
if ( isset( $args ) && is_array( $args ) ) {
	$do_reset = in_array( '--reset', $args, true ) || in_array( 'reset', $args, true );
}

/*
 * Optional taxonomy filter: tree | fs | all (default).
 * Full Fallstudie wipe + reinstall: prefer scripts/reset-case-tree.php
 * (Case_Data::wipe_all_terms). --reset on fs still runs Case_Data::reset.
 */
$scope = 'all';
if ( isset( $args ) && is_array( $args ) ) {
	foreach ( $args as $a ) {
		if ( in_array( $a, array( 'tree', 'fs', 'all' ), true ) ) {
			$scope = $a;
		}
	}
}

$taxonomies = array();
if ( 'fs' !== $scope ) {
	$taxonomies[ \WTT\Taxonomy::TREE ] = 'Demo_Data';
}
if ( 'tree' !== $scope ) {
	$taxonomies[ \WTT\Taxonomy::FS ] = 'Case_Data';
}

foreach ( $taxonomies as $taxonomy => $label ) {
	if ( $do_reset && \WTT\Taxonomy::TREE === $taxonomy ) {
		$r = \WTT\Demo_Data::reset( $taxonomy );
		if ( is_wp_error( $r ) ) {
			fwrite( STDERR, "{$label} reset: " . $r->get_error_message() . "\n" );
			exit( 1 );
		}
		\WTT\Demo_Data::ensure_media_type( $taxonomy );
		\WTT\Demo_Data::ensure_bom_columns( $taxonomy );
		printf(
			"reset %s deleted=%d created=%d existing=%d\n",
			$taxonomy,
			(int) $r['deleted'],
			(int) $r['created'],
			(int) $r['existing']
		);
		continue;
	}

	if ( $do_reset && \WTT\Taxonomy::FS === $taxonomy ) {
		$r = \WTT\Case_Data::reset( $taxonomy );
		if ( is_wp_error( $r ) ) {
			fwrite( STDERR, "{$label} reset: " . $r->get_error_message() . "\n" );
			exit( 1 );
		}
		printf(
			"reset %s deleted=%d created=%d existing=%d\n",
			$taxonomy,
			(int) $r['deleted'],
			(int) $r['created'],
			(int) $r['existing']
		);
		continue;
	}

	$result = \WTT\Demo_Data::clear_except_datatypes( $taxonomy );
	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, "{$taxonomy}: " . $result->get_error_message() . "\n" );
		exit( 1 );
	}

	printf(
		"clear %s kept=%d deleted=%d\n",
		$result['taxonomy'],
		count( $result['kept'] ),
		(int) $result['deleted']
	);
}

echo "OK\n";
