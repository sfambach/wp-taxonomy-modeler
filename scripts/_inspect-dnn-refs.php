<?php
/**
 * Who points at display_node_name term 4877 / any display_node_name.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

require_once 'C:/devel/wordpress/wp-load.php';

use WTT\Taxonomy;

$taxonomy = Taxonomy::FS;
$legacy   = get_terms(
	array(
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'name'       => 'display_node_name',
		'number'     => 20,
	)
);
if ( ! is_array( $legacy ) || empty( $legacy ) ) {
	echo "No display_node_name terms\n";
	exit( 0 );
}

global $wpdb;
foreach ( $legacy as $term ) {
	if ( ! $term instanceof WP_Term ) {
		continue;
	}
	$id = (int) $term->term_id;
	echo "=== term {$id} ===\n";
	$parent = get_term( (int) $term->parent, $taxonomy );
	echo 'parent=' . ( $parent instanceof WP_Term ? $parent->name . " ({$term->parent})" : '?' ) . "\n";

	$meta_keys = array( '_wtt_type_id', '_wtt_type', 'type_id' );
	foreach ( $meta_keys as $key ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 50",
				$key,
				(string) $id
			)
		);
		echo "meta {$key}: " . count( (array) $rows ) . "\n";
		foreach ( (array) $rows as $row ) {
			$t = get_term( (int) $row->term_id, $taxonomy );
			echo '  host=' . ( $t instanceof WP_Term ? $t->name . "({$row->term_id})" : $row->term_id ) . "\n";
		}
	}

	/* Relation edges may store type as JSON with typeId. */
	$like = '%' . $wpdb->esc_like( (string) $id ) . '%';
	$json = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT term_id, meta_key FROM {$wpdb->termmeta} WHERE meta_value LIKE %s AND meta_key LIKE %s LIMIT 40",
			$like,
			$wpdb->esc_like( '_wtt_' ) . '%'
		)
	);
	echo "loose _wtt_* hits: " . count( (array) $json ) . "\n";
	foreach ( (array) $json as $row ) {
		$t = get_term( (int) $row->term_id, $taxonomy );
		echo '  ' . ( $t instanceof WP_Term ? $t->name : '?' ) . " id={$row->term_id} key={$row->meta_key}\n";
	}
}
