<?php
/**
 * Dump / assert Bauformen SMD Abmessung instances (L/B/H as Meter quantities).
 *
 * Usage (from WordPress root):
 *   php wp-cli.phar --user=admin eval-file path/to/test-get-node.php
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
 * @param array<string, mixed> $node Node payload from Tree_Model::get_node.
 * @return array<string, mixed>|null
 */
function wtt_member( array $node, string $member_name ): ?array {
	foreach ( $node['setMembers'] ?? array() as $member ) {
		if ( ( $member['name'] ?? '' ) === $member_name ) {
			return is_array( $member ) ? $member : null;
		}
	}
	return null;
}

$bauformen = wtt_find_named_under( $taxonomy, 0, 'Bauformen' );
if ( $bauformen <= 0 ) {
	// Demo may nest Bauformen under Typen — search by name.
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'name'       => 'Bauformen',
			'hide_empty' => false,
			'number'     => 1,
		)
	);
	$bauformen = ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof WP_Term )
		? (int) $terms[0]->term_id
		: 0;
}

if ( $bauformen <= 0 ) {
	fwrite( STDERR, "Bauformen root not found — seed demo first.\n" );
	exit( 1 );
}

$errors = 0;
$packages = get_terms(
	array(
		'taxonomy'   => $taxonomy,
		'parent'     => $bauformen,
		'hide_empty' => false,
		'number'     => 0,
	)
);

if ( ! is_array( $packages ) ) {
	$packages = array();
}

foreach ( $packages as $pkg ) {
	if ( ! $pkg instanceof WP_Term ) {
		continue;
	}
	$label = $pkg->name;
	$abmessung_id = wtt_find_named_under( $taxonomy, (int) $pkg->term_id, 'Abmessung' );
	if ( $abmessung_id <= 0 ) {
		echo "{$label}: no Abmessung child (skip)\n";
		continue;
	}

	$node = WTT\Tree_Model::get_node( $taxonomy, $abmessung_id );
	if ( is_wp_error( $node ) ) {
		echo "FAIL {$label}: " . $node->get_error_message() . "\n";
		++$errors;
		continue;
	}

	printf(
		"%s Abmessung isSet=%s members=%d\n",
		$label,
		! empty( $node['isSet'] ) ? 'yes' : 'no',
		count( $node['setMembers'] ?? array() )
	);

	foreach ( array( 'L', 'B', 'H' ) as $edge ) {
		$m = wtt_member( $node, $edge );
		if ( null === $m ) {
			echo "FAIL: missing member {$edge}\n";
			++$errors;
			continue;
		}
		$type_name = is_array( $m['type'] ?? null ) ? (string) ( $m['type']['name'] ?? '' ) : '';
		$literal   = (string) ( $m['fixedLiteral'] ?? '' );
		printf( "  %s type=%s fixedLiteral=%s\n", $edge, $type_name !== '' ? $type_name : '(none)', $literal !== '' ? $literal : '(none)' );
		if ( 'Meter' !== $type_name ) {
			echo "FAIL: {$edge} must be typed Meter\n";
			++$errors;
		}
		if ( str_starts_with( $label, 'SMD' ) && '' === $literal ) {
			echo "FAIL: SMD {$edge} must have a fixed magnitude literal\n";
			++$errors;
		}
	}
	echo "\n";
}

if ( $errors > 0 ) {
	fwrite( STDERR, "FAILED with {$errors} error(s).\n" );
	exit( 1 );
}

echo "OK: Abmessung instances use L/B/H as Meter quantities.\n";
