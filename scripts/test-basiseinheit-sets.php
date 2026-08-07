<?php
/**
 * Assert Basiseinheit units are sets with Typ + Kuerzel (+ Praefix when allowed).
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file from the WordPress install.\n" );
	exit( 1 );
}

if ( ! class_exists( 'WTT\\Tree_Model' ) ) {
	fwrite( STDERR, "PLUGIN_INACTIVE\n" );
	exit( 1 );
}

if ( ! class_exists( 'WTT\\Taxonomy' ) ) {
	fwrite( STDERR, "PLUGIN_INACTIVE\n" );
	exit( 1 );
}
\WTT\Taxonomy::register_taxonomies();
$taxonomy = \WTT\Taxonomy::FS;

/**
 * @return int
 */
function wtt_find_named_under( string $taxonomy, int $parent_id, string $name ): int {
	$found = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'name'       => $name,
			'parent'     => $parent_id,
			'hide_empty' => false,
			'number'     => 1,
		)
	);
	if ( is_array( $found ) && isset( $found[0] ) && $found[0] instanceof WP_Term ) {
		return (int) $found[0]->term_id;
	}
	return 0;
}

$root  = wtt_find_named_under( $taxonomy, 0, 'BOM Testprojekt' );
$typen = $root > 0 ? wtt_find_named_under( $taxonomy, $root, 'Typen' ) : 0;
$base  = $typen > 0 ? wtt_find_named_under( $taxonomy, $typen, 'Basiseinheit' ) : 0;

$expect = array(
	'Meter'     => array( 'symbol' => 'm', 'prefix' => true, 'numeric' => 'double' ),
	'Liter'     => array( 'symbol' => 'l', 'prefix' => true, 'numeric' => 'double' ),
	'Kilogramm' => array( 'symbol' => 'g', 'prefix' => true, 'numeric' => 'double', 'prefix_root_to_si' => 1.0e-3 ),
	'Sekunde'   => array( 'symbol' => 's', 'prefix' => true, 'numeric' => 'double' ),
	'Kelvin'    => array( 'symbol' => 'K', 'prefix' => false, 'numeric' => 'double' ),
	'Celsius'   => array( 'symbol' => '°C', 'prefix' => false, 'numeric' => 'double' ),
	'Ampere'    => array( 'symbol' => 'A', 'prefix' => true, 'numeric' => 'double' ),
	'Ohm'       => array( 'symbol' => 'Ω', 'prefix' => true, 'numeric' => 'double' ),
	'Farad'     => array( 'symbol' => 'F', 'prefix' => true, 'numeric' => 'double' ),
	'Watt'      => array( 'symbol' => 'W', 'prefix' => true, 'numeric' => 'double' ),
	'Volt'      => array( 'symbol' => 'V', 'prefix' => true, 'numeric' => 'double' ),
	'Stück'     => array( 'symbol' => 'Stk', 'prefix' => false, 'numeric' => 'int' ),
);

$errors = 0;
foreach ( $expect as $unit_name => $cfg ) {
	$id = $base > 0 ? wtt_find_named_under( $taxonomy, $base, $unit_name ) : 0;
	echo "=== {$unit_name} id={$id} ===\n";
	if ( $id <= 0 ) {
		echo "MISSING\n";
		++$errors;
		continue;
	}
	$node = \WTT\Tree_Model::get_node( $taxonomy, $id );
	if ( is_wp_error( $node ) ) {
		echo $node->get_error_message() . "\n";
		++$errors;
		continue;
	}
	if ( empty( $node['isSet'] ) ) {
		echo "FAIL: expected isSet\n";
		++$errors;
	}
	$names  = array();
	$symbol = '';
	$typ_type = '';
	foreach ( $node['setMembers'] ?? array() as $m ) {
		$names[] = $m['name'];
		if ( 'Kuerzel' === $m['name'] ) {
			$symbol = is_array( $m['fixed'] ?? null ) ? (string) ( $m['fixed']['name'] ?? '' ) : '';
			if ( '' === $symbol && isset( $m['fixedLiteral'] ) ) {
				$symbol = (string) $m['fixedLiteral'];
			}
		}
		if ( 'Typ' === $m['name'] && is_array( $m['type'] ?? null ) ) {
			$typ_type = strtolower( (string) ( $m['type']['name'] ?? '' ) );
		}
	}
	printf( "members=[%s] Kuerzel=%s Typ=%s\n", implode( ',', $names ), $symbol !== '' ? $symbol : '(none)', $typ_type !== '' ? $typ_type : '(none)' );
	if ( ! in_array( 'Typ', $names, true ) || ! in_array( 'Kuerzel', $names, true ) ) {
		echo "FAIL: need Typ + Kuerzel\n";
		++$errors;
	}
	$has_prefix_member = in_array( 'Praefix', $names, true );
	if ( $cfg['prefix'] !== $has_prefix_member ) {
		echo 'FAIL: Praefix member expected ' . ( $cfg['prefix'] ? 'yes' : 'no' ) . "\n";
		++$errors;
	}
	if ( $symbol !== $cfg['symbol'] ) {
		echo "FAIL: Kuerzel expected {$cfg['symbol']}\n";
		++$errors;
	}
	if ( $typ_type !== $cfg['numeric'] ) {
		echo "FAIL: Typ type expected {$cfg['numeric']}\n";
		++$errors;
	}
	if ( isset( $cfg['prefix_root_to_si'] ) ) {
		$root_si = isset( $node['prefixRootToSi'] ) ? (float) $node['prefixRootToSi'] : null;
		if ( null === $root_si || abs( $root_si - (float) $cfg['prefix_root_to_si'] ) > 1.0e-12 ) {
			echo "FAIL: prefix_root_to_si expected {$cfg['prefix_root_to_si']}\n";
			++$errors;
		}
	}
}

if ( $errors > 0 ) {
	fwrite( STDERR, "FAILED with {$errors} error(s).\n" );
	exit( 1 );
}
echo "OK: Basiseinheit unit sets + Celsius/Stück (all catalog units).\n";
