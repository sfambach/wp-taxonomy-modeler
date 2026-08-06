<?php
/**
 * Smoke: Object View block registration + render for a known wtt_fs node.
 *
 * Usage: wp eval-file …/scripts/assert-object-view.php
 *
 * @package WP_Taxonomy_Tree
 */

$tax = \WTT\Taxonomy::FS;

$bauteil = get_term_by( 'name', 'Bauteil', $tax );
if ( ! $bauteil instanceof WP_Term ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $tax,
			'hide_empty' => false,
			'number'     => 20,
			'search'     => 'Bauteil',
		)
	);
	if ( is_array( $terms ) ) {
		foreach ( $terms as $t ) {
			if ( $t instanceof WP_Term && false !== stripos( $t->name, 'Bauteil' ) ) {
				$bauteil = $t;
				break;
			}
		}
	}
}

if ( ! $bauteil instanceof WP_Term ) {
	/* Fallback: any non-trashed term in wtt_fs. */
	$nodes = \WTT\Object_Render::list_pickable_nodes( $tax );
	if ( array() === $nodes ) {
		fwrite( STDERR, "No pickable nodes in {$tax}\n" );
		exit( 1 );
	}
	$term_id = (int) $nodes[0]['id'];
	$name    = (string) $nodes[0]['name'];
	echo "Fallback node: {$name} id={$term_id}\n";
} else {
	$term_id = (int) $bauteil->term_id;
	$name    = $bauteil->name;
	echo "Node: {$name} id={$term_id} tax={$tax}\n";
}

$view = \WTT\Object_Render::get_view( $tax, $term_id );
if ( null === $view ) {
	fwrite( STDERR, "get_view failed\n" );
	exit( 1 );
}

echo 'Properties: ' . count( $view['properties'] ?? array() ) . PHP_EOL;

$html = \WTT\Blocks::render_object_view(
	array(
		'termId'   => $term_id,
		'taxonomy' => $tax,
	)
);

if ( false === strpos( $html, 'wtt-object-view' ) || false === strpos( $html, esc_html( $name ) ) ) {
	fwrite( STDERR, "Render failed\n$html\n" );
	exit( 1 );
}

$registered = \WP_Block_Type_Registry::get_instance()->is_registered( 'taxo/object-view' );
echo 'Block registered: ' . ( $registered ? 'yes' : 'no' ) . PHP_EOL;

if ( ! $registered ) {
	fwrite( STDERR, "taxo/object-view not registered — run npm run build\n" );
	exit( 1 );
}

$empty = \WTT\Blocks::render_object_view( array( 'termId' => 0 ) );
if ( false === strpos( $empty, 'wtt-object-view__empty' ) ) {
	fwrite( STDERR, "Empty state missing\n" );
	exit( 1 );
}

echo "OK object-view smoke\n";
exit( 0 );
