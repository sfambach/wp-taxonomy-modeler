<?php
/**
 * Inspect Complex catalog and attribute type refs.
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via wp eval-file.\n" );
	exit( 1 );
}

$tax     = 'wtt_fs';
$complex = 0;
$paths   = array(
	array( 'Fallstudie', 'Definition', 'Data Types', 'Complex' ),
	array( 'Fallstudie', 'Definition', 'Datentypen', 'Complex' ),
);
foreach ( $paths as $p ) {
	$parent = 0;
	$ok     = true;
	foreach ( $p as $name ) {
		$t = get_terms(
			array(
				'taxonomy'   => $tax,
				'parent'     => $parent,
				'name'       => $name,
				'hide_empty' => false,
				'number'     => 1,
			)
		);
		if ( empty( $t ) || is_wp_error( $t ) ) {
			$ok = false;
			break;
		}
		$parent = (int) $t[0]->term_id;
	}
	if ( $ok ) {
		$complex = $parent;
		break;
	}
}
echo "Complex id: {$complex}\n";
if ( $complex <= 0 ) {
	exit( 0 );
}

$kids  = get_terms(
	array(
		'taxonomy'   => $tax,
		'parent'     => $complex,
		'hide_empty' => false,
	)
);
$names = array();
foreach ( $kids as $k ) {
	echo sprintf( "%d\t%s\n", $k->term_id, $k->name );
	$names[ strtolower( $k->name ) ] = (int) $k->term_id;
	$gk = get_terms(
		array(
			'taxonomy'   => $tax,
			'parent'     => (int) $k->term_id,
			'hide_empty' => false,
		)
	);
	foreach ( $gk as $g ) {
		echo sprintf( "  %d\t%s\n", $g->term_id, $g->name );
		$names[ strtolower( $g->name ) ] = (int) $g->term_id;
	}
}

echo "\n--- attribute type refs ---\n";
$all   = get_terms(
	array(
		'taxonomy'   => $tax,
		'hide_empty' => false,
		'number'     => 0,
	)
);
$count = array();
foreach ( $all as $term ) {
	$attrs = \WTT\Attribute::list_own( $tax, (int) $term->term_id );
	foreach ( $attrs as $a ) {
		$tid = (int) ( $a['typeId'] ?? 0 );
		foreach ( $names as $n => $id ) {
			if ( $tid === $id ) {
				if ( ! isset( $count[ $n ] ) ) {
					$count[ $n ] = array();
				}
				$count[ $n ][] = $term->name . '::' . ( $a['name'] ?? '?' );
			}
		}
	}
}
foreach ( $count as $n => $uses ) {
	echo $n . ' => ' . count( $uses ) . " uses\n";
	foreach ( array_slice( $uses, 0, 12 ) as $u ) {
		echo "  {$u}\n";
	}
}

echo "\n--- size / unit type ---\n";
foreach ( array( 'size', 'Unit type', 'C1', 'quantity' ) as $want ) {
	$hit = get_terms(
		array(
			'taxonomy'   => $tax,
			'name'       => $want,
			'hide_empty' => false,
			'number'     => 5,
		)
	);
	foreach ( $hit as $h ) {
		$path = array();
		$cur  = $h;
		while ( $cur instanceof WP_Term ) {
			array_unshift( $path, $cur->name );
			$cur = $cur->parent ? get_term( (int) $cur->parent, $tax ) : null;
			if ( ! $cur instanceof WP_Term ) {
				break;
			}
		}
		echo $want . ' id=' . $h->term_id . ' path=' . implode( '/', $path ) . "\n";
	}
}
