<?php
/**
 * One-shot: Basiseinheit leaves → Compact Preferred + Without-prefix Kuerzel.
 * From WP docroot: wp eval-file …/scripts/_ensure-bu-compact.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file from WordPress.\n" );
	exit( 1 );
}

use WTT\Case_Data;
use WTT\Node_Type;
use WTT\Renderer;
use WTT\Taxonomy;

$taxonomy = Taxonomy::FS;
Case_Data::ensure_without_prefix_composition( $taxonomy );
Case_Data::ensure_basiseinheit_unit_compact_preferred( $taxonomy );

/**
 * @param list<string> $names
 */
function wtt_find_path( string $taxonomy, array $names ): int {
	$parent = 0;
	foreach ( $names as $name ) {
		$hits = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent,
				'name'       => $name,
				'hide_empty' => false,
				'number'     => 1,
			)
		);
		if ( ! is_array( $hits ) || ! isset( $hits[0] ) || ! ( $hits[0] instanceof WP_Term ) ) {
			return 0;
		}
		$parent = (int) $hits[0]->term_id;
	}
	return $parent;
}

$paths = array(
	array( 'Fallstudie', 'Definition', 'Konstanten', 'Basiseinheiten', 'With prefix' ),
	array( 'Fallstudie', 'Definition', 'Konstanten', 'Basiseinheiten', 'Without prefix' ),
);

foreach ( $paths as $path ) {
	$bucket_id = wtt_find_path( $taxonomy, $path );
	$label     = (string) end( $path );
	if ( $bucket_id <= 0 ) {
		echo $label . ": missing\n";
		continue;
	}
	$kids  = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'parent'     => $bucket_id,
			'hide_empty' => false,
			'number'     => 0,
		)
	);
	$count = 0;
	$ok    = 0;
	if ( is_array( $kids ) ) {
		foreach ( $kids as $kid ) {
			if ( ! ( $kid instanceof WP_Term ) ) {
				continue;
			}
			$id = (int) $kid->term_id;
			if ( ! Node_Type::is_basiseinheit_unit_node( $taxonomy, $id ) ) {
				continue;
			}
			++$count;
			$pref = Node_Type::get_preferred_render( $id );
			if ( Renderer::Compact->value === $pref ) {
				++$ok;
			}
			echo $label . '/' . $kid->name . ' pref=' . $pref . "\n";
		}
	}
	echo $label . ": compact $ok / $count\n";
}

echo "done\n";
