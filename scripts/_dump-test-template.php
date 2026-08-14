<?php
/**
 * Dump Fallstudie (wtt_fs) as an intermediate test template fixture.
 *
 * Captures hierarchy + node config + attribute Relations (not Model_Data instances).
 *
 * Usage:
 *   php scripts/_dump-test-template.php
 *
 * Writes:
 *   scripts/fixtures/test-template-wtt_fs.json          (stable pointer)
 *   scripts/fixtures/test-template-wtt_fs-YYYY-MM-DD.json (dated copy)
 *
 * @package WTT
 */

require 'C:/devel/wordpress/wp-load.php';

use WTT\Attribute;
use WTT\Node_Type;
use WTT\Tree_Model;

if ( ! class_exists( Tree_Model::class ) ) {
	fwrite( STDERR, "PLUGIN_INACTIVE\n" );
	exit( 1 );
}

$tax = 'wtt_fs';
$tree = Tree_Model::get_tree( $tax );

/**
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function wtt_template_node_config( array $node ): array {
	$id = isset( $node['id'] ) ? (int) $node['id'] : 0;
	$validators = array();
	if ( ! empty( $node['validators'] ) && is_array( $node['validators'] ) ) {
		foreach ( $node['validators'] as $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}
			$validators[] = array(
				'id'        => (string) ( $v['id'] ?? '' ),
				'isDefault' => ! empty( $v['isDefault'] ),
			);
		}
	}

	$attrs = array();
	if ( $id > 0 ) {
		foreach ( Attribute::effective_list( $tax = 'wtt_fs', $id ) as $row ) {
			$attrs[] = array(
				'id'           => Attribute::normalize_attr_id( $row['id'] ?? '' ),
				'name'         => (string) ( $row['name'] ?? '' ),
				'typeId'       => (int) ( $row['typeId'] ?? 0 ),
				'typeName'     => (string) ( $row['typeName'] ?? '' ),
				'multiplicity' => (string) ( $row['multiplicity'] ?? '' ),
				'binding'      => (string) ( $row['binding'] ?? '' ),
				'inherited'    => ! empty( $row['inherited'] ),
				'definedOnId'  => (int) ( $row['definedOnId'] ?? 0 ),
				'readonly'     => ! empty( $row['readonly'] ),
				'hidden'       => ! empty( $row['hidden'] ),
				'default'      => array_key_exists( 'default', $row ) ? $row['default'] : null,
			);
		}
	}

	$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
	$out_children = array();
	foreach ( $children as $child ) {
		if ( ! is_array( $child ) ) {
			continue;
		}
		if ( ! empty( $child['isTrash'] ) || ! empty( $child['isHiddenBin'] ) ) {
			continue;
		}
		/* Soft-trashed terms may still appear as children in some payloads — skip. */
		$cid = isset( $child['id'] ) ? (int) $child['id'] : 0;
		if ( $cid > 0 && metadata_exists( 'term', $cid, '_wtt_trashed' ) && '1' === (string) get_term_meta( $cid, '_wtt_trashed', true ) ) {
			continue;
		}
		$out_children[] = wtt_template_node_config( $child );
	}

	return array(
		'id'                 => $id,
		'name'               => (string) ( $node['name'] ?? '' ),
		'slug'               => (string) ( $node['slug'] ?? '' ),
		'parent'             => (int) ( $node['parent'] ?? 0 ),
		'typeId'             => (int) ( $node['typeId'] ?? 0 ),
		'typeName'           => (string) ( $node['typeName'] ?? '' ),
		'isTemplate'         => ! empty( $node['isTemplate'] ) || ( $id > 0 && Node_Type::is_template( $id ) ),
		'preferredRender'    => $id > 0 ? Node_Type::get_preferred_render( $id ) : '',
		'preferredRenderOwn' => $id > 0 && Node_Type::has_own_preferred_render( $id )
			? Node_Type::get_own_preferred_render( $id )
			: '',
		'preferredConverter' => $id > 0
			? (string) ( Node_Type::get_preferred_converter( $id ) ?? ( $node['preferredConverter'] ?? '' ) )
			: '',
		'validators'         => $validators,
		'attributes'         => $attrs,
		'children'           => $out_children,
	);
}

/**
 * @param list<array<string, mixed>> $nodes
 * @return list<array<string, mixed>>
 */
function wtt_template_serialize_forest( array $nodes ): array {
	$out = array();
	foreach ( $nodes as $node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}
		if ( ! empty( $node['isTrash'] ) || ! empty( $node['isHiddenBin'] ) ) {
			continue;
		}
		$out[] = wtt_template_node_config( $node );
	}
	return $out;
}

$notes = array(
	'Passiv.Toleranz typed as Unit type (not Percent/Toleranz leaf).',
	'size under quantity is soft-trashed in live DB — omitted from template tree.',
	'Typo live: "Bauteil Monatge Typen" (Montage).',
	'Percent/Toleranz live under Eigene Datentypen with Value/Unit (+ Sign on Toleranz).',
);

$payload = array(
	'kind'        => 'wtt-test-template',
	'exportedAt'  => gmdate( 'c' ),
	'taxonomy'    => $tax,
	'plugin'      => defined( 'WTT_VERSION' ) ? WTT_VERSION : '',
	'notes'       => $notes,
	'tree'        => wtt_template_serialize_forest( $tree ),
);

$plugin_dir = defined( 'WTT_PLUGIN_DIR' ) ? WTT_PLUGIN_DIR : dirname( __DIR__ ) . '/';
$fixtures   = $plugin_dir . 'scripts/fixtures';
if ( ! is_dir( $fixtures ) && ! wp_mkdir_p( $fixtures ) ) {
	fwrite( STDERR, "Could not create fixtures dir: {$fixtures}\n" );
	exit( 1 );
}

$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
if ( false === $json ) {
	fwrite( STDERR, "JSON encode failed.\n" );
	exit( 1 );
}

$stable = $fixtures . '/test-template-wtt_fs.json';
$dated  = $fixtures . '/test-template-wtt_fs-' . gmdate( 'Y-m-d' ) . '.json';

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
if ( false === file_put_contents( $stable, $json . "\n" ) ) {
	fwrite( STDERR, "Write failed: {$stable}\n" );
	exit( 1 );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $dated, $json . "\n" );

$count = 0;
$walker = static function ( array $nodes ) use ( &$count, &$walker ): void {
	foreach ( $nodes as $n ) {
		++$count;
		if ( ! empty( $n['children'] ) && is_array( $n['children'] ) ) {
			$walker( $n['children'] );
		}
	}
};
$walker( $payload['tree'] );

printf( "wrote %s\n", $stable );
printf( "wrote %s\n", $dated );
printf( "nodes=%d plugin=%s\n", $count, $payload['plugin'] );
