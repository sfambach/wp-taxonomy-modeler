<?php
/**
 * Assert Q88 parent-as-type on Definition + children (wtt_fs).
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file.\n" );
	exit( 1 );
}

$tax = \WTT\Taxonomy::FS;
$def = \WTT\Case_Data::find_term_by_path( $tax, array( 'Fallstudie', 'Definition' ) );
$root = \WTT\Case_Data::find_term_by_path( $tax, array( 'Fallstudie' ) );
if ( $def <= 0 || $root <= 0 ) {
	fwrite( STDERR, "FAIL: Fallstudie/Definition missing\n" );
	exit( 1 );
}

$node = \WTT\Tree_Model::get_node( $tax, $def );
$type_name = is_array( $node['type'] ?? null ) ? (string) ( $node['type']['name'] ?? '' ) : '';
printf(
	"Definition id=%d typeId=%d typeName=%s typeIsParent=%s ownTypeId=%d\n",
	$def,
	(int) ( $node['typeId'] ?? 0 ),
	$type_name,
	! empty( $node['typeIsParent'] ) ? '1' : '0',
	(int) ( $node['ownTypeId'] ?? 0 )
);

if ( (int) ( $node['typeId'] ?? 0 ) !== $root || 'Fallstudie' !== $type_name || empty( $node['typeIsParent'] ) ) {
	fwrite( STDERR, "FAIL: Definition must derive type Fallstudie (parent)\n" );
	exit( 1 );
}

$kids = get_terms(
	array(
		'taxonomy'   => $tax,
		'parent'     => $def,
		'hide_empty' => false,
		'number'     => 0,
	)
);
echo "chain (name → type):\n";
printf( "  Fallstudie → %s\n", ( get_term( \WTT\Node_Type::get_effective_type_id( $tax, $root ), $tax )->name ?? '?' ) );
printf( "  Definition → Fallstudie\n" );
foreach ( (array) $kids as $k ) {
	if ( ! $k instanceof WP_Term ) {
		continue;
	}
	if ( \WTT\Trash::is_trashed( (int) $k->term_id ) || \WTT\Trash::is_trash_node( (int) $k->term_id ) ) {
		continue;
	}
	$tid = \WTT\Node_Type::get_effective_type_id( $tax, (int) $k->term_id );
	$t   = $tid > 0 ? get_term( $tid, $tax ) : null;
	$sub = \WTT\Node_Type::is_hierarchy_datatype_subject( $tax, (int) $k->term_id );
	printf(
		"  %s → %s%s\n",
		$k->name,
		$t instanceof WP_Term ? $t->name : '(none)',
		$sub ? '' : ' [attr/catalog field — own type]'
	);
}

fwrite( STDOUT, "OK\n" );
