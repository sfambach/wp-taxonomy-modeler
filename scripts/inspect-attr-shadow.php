<?php
/**
 * List own vs inherited attrs on Bauteil hosts.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

require dirname( __DIR__, 3 ) . '/wp-load.php';

use WTT\Attribute;
use WTT\Taxonomy;

$taxonomy = Taxonomy::FS;
foreach ( array( 'Kondensator', 'Spule', 'Widerstand', 'Passiv' ) as $name ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'name'       => $name,
			'hide_empty' => false,
		)
	);
	if ( ! is_array( $terms ) || ! $terms ) {
		continue;
	}
	$id = (int) $terms[0]->term_id;
	echo "=== {$name} #{$id} ===\n";
	foreach ( Attribute::list( $taxonomy, $id ) as $a ) {
		echo sprintf(
			"  %s | own=%s inh=%s def=%s shadow=%s\n",
			(string) ( $a['name'] ?? '?' ),
			empty( $a['inherited'] ) ? 'Y' : 'N',
			! empty( $a['inherited'] ) ? 'Y' : 'N',
			(string) ( $a['definedOnName'] ?? '' ),
			! empty( $a['shadowsInherited'] ) ? 'Y' : 'N'
		);
	}
	echo "own_raw:\n";
	foreach ( Attribute::list_own( $taxonomy, $id ) as $a ) {
		echo '  ' . ( $a['name'] ?? '?' ) . "\n";
	}
}
