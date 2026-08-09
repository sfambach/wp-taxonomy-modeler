<?php
/**
 * Run unit-catalog ensure (remap stale attribute types) and verify Praefix opts.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

require dirname( __DIR__, 3 ) . '/wp-load.php';

use WTT\Attribute;
use WTT\Case_Data;
use WTT\Taxonomy;

$taxonomy = Taxonomy::FS;
Case_Data::ensure_unit_catalog( $taxonomy );

$hosts = array( 'Widerstand', 'Passiv', 'Kondensator' );
foreach ( $hosts as $name ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'name'       => $name,
		)
	);
	if ( ! is_array( $terms ) || ! $terms ) {
		echo "missing {$name}\n";
		continue;
	}
	$t = $terms[0];
	echo "=== {$name} #{$t->term_id} ===\n";
	foreach ( Attribute::list( $taxonomy, (int) $t->term_id ) as $a ) {
		$an = (string) ( $a['name'] ?? '' );
		if ( stripos( $an, 'Praef' ) === false && stripos( $an, 'Einheit' ) === false ) {
			continue;
		}
		echo sprintf(
			"  %s typeId=%d root=%d mode=%s opts=%d\n",
			$an,
			(int) ( $a['typeId'] ?? 0 ),
			(int) ( $a['fixedRootId'] ?? 0 ),
			(string) ( $a['fixedMode'] ?? '' ),
			count( $a['fixedOptions'] ?? array() )
		);
		if ( stripos( $an, 'Praef' ) !== false ) {
			foreach ( array_slice( $a['fixedOptions'] ?? array(), 0, 5 ) as $o ) {
				echo '    - ' . ( $o['name'] ?? '' ) . "\n";
			}
		}
	}
}
