<?php
/**
 * Inspect leftover display_node_name terms.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	require_once 'C:/devel/wordpress/wp-load.php';
}

$taxonomies = array( 'wtt_fs' );
if ( taxonomy_exists( 'wtt_bom' ) ) {
	$taxonomies[] = 'wtt_bom';
}

foreach ( $taxonomies as $taxonomy ) {
	echo "=== {$taxonomy} ===\n";
	foreach ( array( 'display_node_name', 'node_presentation' ) as $name ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'name'       => $name,
				'number'     => 50,
			)
		);
		if ( ! is_array( $terms ) ) {
			echo "{$name}: error\n";
			continue;
		}
		echo "{$name}: " . count( $terms ) . "\n";
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$parent = get_term( (int) $term->parent, $taxonomy );
			$pname  = $parent instanceof WP_Term ? $parent->name : '?';
			echo "  id={$term->term_id} parent={$term->parent}({$pname}) slug={$term->slug}\n";
		}
	}
}
