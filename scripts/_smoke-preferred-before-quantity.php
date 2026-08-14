<?php
/**
 * Smoke: Preferred Compact/Form wins over Value+Unit heuristic; no display_node_name sibling.
 *
 * Run: wp eval-file scripts/_smoke-preferred-before-quantity.php
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
use WTT\Taxonomy;

/**
 * @return int
 */
function wtt_smoke_find_named( string $taxonomy, string $name, int $parent = -1 ): int {
	$args = array(
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'name'       => $name,
		'number'     => 20,
	);
	if ( $parent >= 0 ) {
		$args['parent'] = $parent;
	}
	$terms = get_terms( $args );
	if ( ! is_array( $terms ) ) {
		return 0;
	}
	foreach ( $terms as $term ) {
		if ( $term instanceof WP_Term ) {
			return (int) $term->term_id;
		}
	}
	return 0;
}

$taxonomy = Taxonomy::FS;
$ensured  = Case_Data::ensure_simple_datatypes( $taxonomy );
echo 'ensure_simple: ' . wp_json_encode( $ensured ) . "\n";

$pres_id = wtt_smoke_find_named( $taxonomy, 'node_presentation' );
if ( $pres_id <= 0 ) {
	fwrite( STDERR, "FAIL: node_presentation missing after ensure\n" );
	exit( 1 );
}
$pres = get_term( $pres_id, $taxonomy );
if ( ! $pres instanceof WP_Term || 'node_presentation' !== $pres->name ) {
	fwrite( STDERR, 'FAIL: expected name node_presentation, got ' . ( $pres instanceof WP_Term ? $pres->name : '?' ) . "\n" );
	exit( 1 );
}
echo "OK node_presentation id={$pres_id}\n";

$parent = (int) $pres->parent;
$legacy_any = get_terms(
	array(
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'name'       => 'display_node_name',
		'number'     => 20,
	)
);
if ( is_array( $legacy_any ) ) {
	foreach ( $legacy_any as $term ) {
		if ( ! $term instanceof WP_Term ) {
			continue;
		}
		/* Soft-trashed leftovers still count as failure — purge should hard-delete. */
		fwrite( STDERR, 'FAIL: leftover display_node_name id=' . (int) $term->term_id . ' parent=' . (int) $term->parent . ' trashed=' . ( \WTT\Trash::is_trashed( (int) $term->term_id ) ? '1' : '0' ) . "\n" );
		exit( 1 );
	}
}
echo "OK no live display_node_name terms (Simple parent={$parent})\n";

$js = file_get_contents( dirname( __DIR__ ) . '/assets/js/wtt-object-render.js' );
if ( ! is_string( $js ) || ! str_contains( $js, 'Preferred object layout' ) ) {
	fwrite( STDERR, "FAIL: paintFieldContent Preferred-first marker missing in wtt-object-render.js\n" );
	exit( 1 );
}
if ( ! str_contains( $js, 'objectLayoutPaint' ) ) {
	fwrite( STDERR, "FAIL: objectLayoutPaint missing\n" );
	exit( 1 );
}
echo "OK Preferred-before-Quantity marker in object-render\n";
echo "PASS\n";
exit( 0 );
