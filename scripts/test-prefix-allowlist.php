<?php
/**
 * Assert Q51 Basiseinheit prefix allowlists (L1) and Kondensator filter.
 *
 * Usage (from WordPress root):
 *   php wp-cli.phar --user=admin eval-file path/to/test-prefix-allowlist.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file from the WordPress install.\n" );
	exit( 1 );
}

if ( ! class_exists( 'WTT\\Node_Type' ) ) {
	fwrite( STDERR, "PLUGIN_INACTIVE\n" );
	exit( 1 );
}

if ( ! class_exists( 'WTT\\Taxonomy' ) ) {
	fwrite( STDERR, "PLUGIN_INACTIVE\n" );
	exit( 1 );
}
\WTT\Taxonomy::register_taxonomies();
$taxonomy = \WTT\Taxonomy::TREE;

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

/**
 * @return array<int, string>
 */
function wtt_allowed_prefix_names( string $taxonomy, int $unit_id ): array {
	$payload = \WTT\Node_Type::get_prefix_allowlist( $taxonomy, $unit_id );
	if ( null === $payload ) {
		return array();
	}
	$names = array();
	foreach ( $payload['prefixes'] as $p ) {
		if ( ! empty( $p['enabled'] ) ) {
			$names[] = (string) $p['name'];
		}
	}
	sort( $names );
	return $names;
}

$errors = 0;
$root   = wtt_find_named_under( $taxonomy, 0, 'BOM Testprojekt' );
$typen  = $root > 0 ? wtt_find_named_under( $taxonomy, $root, 'Typen' ) : 0;
$base   = $typen > 0 ? wtt_find_named_under( $taxonomy, $typen, 'Basiseinheit' ) : 0;

$expect = array(
	'Meter'  => array( 'c', 'k', 'm', 'u' ),
	'Ohm'    => array( 'Mega', 'k', 'm', 'n', 'p', 'u' ),
	'Farad'  => array( 'm', 'n', 'p', 'u' ),
	'Kelvin' => array(),
);

foreach ( $expect as $unit_name => $want ) {
	$unit_id = $base > 0 ? wtt_find_named_under( $taxonomy, $base, $unit_name ) : 0;
	$got     = $unit_id > 0 ? wtt_allowed_prefix_names( $taxonomy, $unit_id ) : array();
	sort( $want );
	printf( "%s id=%d allow=[%s]\n", $unit_name, $unit_id, implode( ',', $got ) );
	if ( $got !== $want ) {
		echo 'FAIL expected [' . implode( ',', $want ) . "]\n";
		++$errors;
	}
}

$bauteile = $root > 0 ? wtt_find_named_under( $taxonomy, $root, 'Bauteile' ) : 0;
$kond     = $bauteile > 0 ? wtt_find_named_under( $taxonomy, $bauteile, 'Kondensator' ) : 0;
$praefix  = $kond > 0 ? wtt_find_named_under( $taxonomy, $kond, 'Praefix' ) : 0;

echo "Kondensator/Praefix id={$praefix}\n";
if ( $praefix > 0 ) {
	$branch = \WTT\Node_Type::get_type_branch( $taxonomy, $praefix );
	$enabled = array();
	foreach ( $branch['children'] ?? array() as $child ) {
		if ( ! empty( $child['enabled'] ) ) {
			$enabled[] = (string) $child['name'];
		}
	}
	sort( $enabled );
	printf(
		"unitFilter=%s unit=%s enabled=[%s]\n",
		! empty( $branch['unitFilter'] ) ? 'yes' : 'no',
		(string) ( $branch['unitName'] ?? '' ),
		implode( ',', $enabled )
	);
	$want = array( 'm', 'n', 'p', 'u' );
	if ( empty( $branch['unitFilter'] ) || ( $branch['unitName'] ?? '' ) !== 'Farad' || $enabled !== $want ) {
		echo "FAIL Kondensator Praefix must be filtered by Farad allowlist\n";
		++$errors;
	}
	$local_disabled = \WTT\Node_Type::get_disabled_branch_ids( $praefix );
	if ( ! empty( $local_disabled ) ) {
		echo "FAIL Kondensator Praefix still has local disabled_branch ids\n";
		++$errors;
	}
}

if ( $errors > 0 ) {
	fwrite( STDERR, "FAILED with {$errors} error(s).\n" );
	exit( 1 );
}

echo "OK: Q51 prefix allowlists + Kondensator filter.\n";
