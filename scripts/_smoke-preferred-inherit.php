<?php
/**
 * Smoke: Preferred render inherits along child_of when own meta empty.
 *
 * @package WTT
 */

require 'C:/devel/wordpress/wp-load.php';

use WTT\Node_Type;
use WTT\Tree_Model;

$tax = 'wtt_fs';
$ok  = true;

delete_option( 'wtt_repaired_preferred_inherit_v1' );
$cleared = Node_Type::repair_legacy_preferred_form_seeds( $tax );
echo "repair_cleared={$cleared}\n";

$bau = get_terms(
	array(
		'taxonomy'   => $tax,
		'name'       => 'Bauformen',
		'hide_empty' => false,
		'number'     => 20,
	)
);
if ( is_wp_error( $bau ) || empty( $bau ) ) {
	echo "FAIL: Bauformen not found\n";
	exit( 1 );
}

/* Prefer the Bauformen that has children (live catalog). */
$father = null;
foreach ( $bau as $b ) {
	$kids = get_terms(
		array(
			'taxonomy'   => $tax,
			'parent'     => (int) $b->term_id,
			'hide_empty' => false,
			'number'     => 1,
		)
	);
	if ( ! is_wp_error( $kids ) && ! empty( $kids ) ) {
		$father = $b;
		break;
	}
}
if ( ! $father ) {
	echo "FAIL: no Bauformen with children\n";
	exit( 1 );
}

$fid = (int) $father->term_id;
if ( ! Node_Type::has_own_preferred_render( $fid ) ) {
	Node_Type::set_preferred_render( $tax, $fid, 'CompactRenderer' );
}
$f_own = Node_Type::get_own_preferred_render( $fid );
echo "Bauformen id={$fid} own={$f_own}\n";

$kids = get_terms(
	array(
		'taxonomy'   => $tax,
		'parent'     => $fid,
		'hide_empty' => false,
		'number'     => 50,
	)
);
foreach ( (array) $kids as $k ) {
	$kid_id = (int) $k->term_id;
	$eff    = Node_Type::get_preferred_render( $kid_id );
	$inh    = ! Node_Type::has_own_preferred_render( $kid_id );
	echo "  {$k->name} effective={$eff} inherit_meta=" . ( $inh ? 'yes' : 'no' ) . "\n";
	if ( $inh && $eff !== $f_own ) {
		echo "FAIL: {$k->name} expected {$f_own}\n";
		$ok = false;
	}
}

$axial = get_terms(
	array(
		'taxonomy'   => $tax,
		'name'       => 'Durchloch Axial',
		'parent'     => $fid,
		'hide_empty' => false,
		'number'     => 1,
	)
);
if ( is_wp_error( $axial ) || empty( $axial ) ) {
	echo "FAIL: Durchloch Axial under Bauformen\n";
	exit( 1 );
}
$aid  = (int) $axial[0]->term_id;
$node = Tree_Model::get_node( $tax, $aid );
if ( ! is_array( $node ) ) {
	echo "FAIL: get_node\n";
	exit( 1 );
}
echo 'get_node preferredRender=' . ( $node['preferredRender'] ?? '' )
	. ' own=' . ( $node['preferredRenderOwn'] ?? '' )
	. ' inherited=' . ( ! empty( $node['preferredRenderInherited'] ) ? 'yes' : 'no' ) . "\n";

if ( ( $node['preferredRender'] ?? '' ) !== $f_own ) {
	echo "FAIL: get_node preferredRender\n";
	$ok = false;
}
if ( empty( $node['preferredRenderInherited'] ) || '' !== (string) ( $node['preferredRenderOwn'] ?? '' ) ) {
	echo "FAIL: get_node inherit flags\n";
	$ok = false;
}

Node_Type::set_preferred_render( $tax, $aid, 'FormRenderer' );
if ( 'FormRenderer' !== Node_Type::get_preferred_render( $aid ) ) {
	echo "FAIL: Form override\n";
	$ok = false;
}
Node_Type::set_preferred_render( $tax, $aid, 'inherit' );
if ( Node_Type::get_preferred_render( $aid ) !== $f_own || Node_Type::has_own_preferred_render( $aid ) ) {
	echo "FAIL: inherit restore\n";
	$ok = false;
}

echo $ok ? "OK\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
