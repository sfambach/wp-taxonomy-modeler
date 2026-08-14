<?php
/**
 * Smoke: Attribute duplicate / reorder / move keep edge UUID ids (no slots) ≈ 0.0.439.
 *
 * - Duplicate Kontakt attr → new edge id, toId = type (not slot)
 * - Copy RO onto duplicate; move to temp child; assert RO preserved; move back; cleanup
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file .../scripts/_smoke-q123-dup-move.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via wp eval-file.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Relation;

$tax  = 'wtt_fs';
$host = get_term_by( 'name', 'Kontakt', $tax );
if ( ! $host instanceof WP_Term ) {
	echo "host=missing\nsmoke=fail\n";
	exit( 1 );
}
$host_id = (int) $host->term_id;

$source_id   = '';
$source_name = '';
foreach ( Attribute::list_own( $tax, $host_id ) as $row ) {
	$id = Attribute::normalize_attr_id( $row['id'] ?? '' );
	if ( '' === $id || ctype_digit( $id ) ) {
		continue;
	}
	$source_id   = $id;
	$source_name = (string) ( $row['name'] ?? '' );
	break;
}
if ( '' === $source_id ) {
	echo "source_attr=missing\nsmoke=fail\n";
	exit( 1 );
}

echo 'WTT_VERSION=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . "\n";
echo 'host=Kontakt id=' . $host_id . "\n";
echo 'source_id=' . $source_id . "\n";
echo 'source_name=' . $source_name . "\n";

$dup = Attribute::duplicate( $tax, $host_id, $source_id );
if ( is_wp_error( $dup ) ) {
	echo 'dup_err=' . $dup->get_error_code() . "\nsmoke=fail\n";
	exit( 1 );
}
$dup_id = Attribute::normalize_attr_id( $dup['id'] ?? '' );
if ( '' === $dup_id || ctype_digit( $dup_id ) || $dup_id === $source_id ) {
	echo "dup_id_bad=$dup_id\nsmoke=fail\n";
	exit( 1 );
}

$dup_edge = null;
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $dup_id ) {
		$dup_edge = $edge;
		break;
	}
}
if ( null === $dup_edge ) {
	echo "dup_edge=missing\nsmoke=fail\n";
	Attribute::remove( $tax, $host_id, $dup_id );
	exit( 1 );
}

$to_id   = (int) ( $dup_edge['toId'] ?? 0 );
$no_slot = $to_id > 0 && ! Attribute::is_slot( $to_id );
echo 'dup_id=' . $dup_id . "\n";
echo 'dup_toId=' . $to_id . "\n";
echo 'dup_no_slot=' . ( $no_slot ? 'yes' : 'no' ) . "\n";
echo 'dup_name=' . (string) ( $dup['name'] ?? '' ) . "\n";

/* Plant RO on duplicate so move can assert edge-field transfer. */
$ro_set = Attribute::set_readonly( $tax, $host_id, $dup_id, true );
if ( is_wp_error( $ro_set ) ) {
	echo 'ro_err=' . $ro_set->get_error_code() . "\n";
	Attribute::remove( $tax, $host_id, $dup_id );
	echo "smoke=fail\n";
	exit( 1 );
}

/* Reorder probe: move down then up (no-op at ends is OK). */
$re_down = Attribute::reorder( $tax, $host_id, $dup_id, 1 );
$re_up   = Attribute::reorder( $tax, $host_id, $dup_id, -1 );
$reorder_ok = ! is_wp_error( $re_down ) && ! is_wp_error( $re_up );
echo 'reorder_ok=' . ( $reorder_ok ? 'yes' : 'no' ) . "\n";

/* Temp hierarchy child for move (not an attribute slot). */
$child = wp_insert_term(
	'wtt-smoke-dup-move-child-' . wp_generate_password( 4, false, false ),
	$tax,
	array( 'parent' => $host_id )
);
if ( is_wp_error( $child ) || empty( $child['term_id'] ) ) {
	echo 'child_err=' . ( is_wp_error( $child ) ? $child->get_error_code() : 'missing' ) . "\n";
	Attribute::remove( $tax, $host_id, $dup_id );
	echo "smoke=fail\n";
	exit( 1 );
}
$child_id = (int) $child['term_id'];
echo 'child_id=' . $child_id . "\n";

$moved = Attribute::move_to_child( $tax, $host_id, $dup_id, $child_id );
if ( is_wp_error( $moved ) ) {
	echo 'move_child_err=' . $moved->get_error_code() . "\n";
	Attribute::remove( $tax, $host_id, $dup_id );
	wp_delete_term( $child_id, $tax );
	echo "smoke=fail\n";
	exit( 1 );
}
$moved_id = Attribute::normalize_attr_id( $moved['id'] ?? '' );
$on_child = false;
$ro_kept  = false;
$slot_on_child = false;
foreach ( Relation::list_outgoing( $tax, $child_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) !== $moved_id ) {
		continue;
	}
	$on_child = true;
	$ro_kept  = ! empty( $edge['readOnly'] ) || ! empty( $edge['readonly'] );
	$slot_on_child = Attribute::is_slot( (int) ( $edge['toId'] ?? 0 ) );
	break;
}
$gone_from_host = null === ( function () use ( $tax, $host_id, $dup_id ) {
	foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
		if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $dup_id ) {
			return $edge;
		}
	}
	return null;
} )();

echo 'moved_id=' . $moved_id . "\n";
echo 'move_on_child=' . ( $on_child ? 'yes' : 'no' ) . "\n";
echo 'move_ro_kept=' . ( $ro_kept ? 'yes' : 'no' ) . "\n";
echo 'move_no_slot=' . ( $on_child && ! $slot_on_child ? 'yes' : 'no' ) . "\n";
echo 'gone_from_host=' . ( $gone_from_host ? 'yes' : 'no' ) . "\n";

/* Move back to Kontakt via move_to_parent from child. */
$back = Attribute::move_to_parent( $tax, $child_id, $moved_id );
if ( is_wp_error( $back ) ) {
	echo 'move_back_err=' . $back->get_error_code() . "\n";
	Attribute::remove( $tax, $child_id, $moved_id );
	wp_delete_term( $child_id, $tax );
	echo "smoke=fail\n";
	exit( 1 );
}
$back_id = Attribute::normalize_attr_id( $back['id'] ?? '' );
$back_ro = false;
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $back_id ) {
		$back_ro = ! empty( $edge['readOnly'] ) || ! empty( $edge['readonly'] );
		break;
	}
}
echo 'back_id=' . $back_id . "\n";
echo 'back_ro_kept=' . ( $back_ro ? 'yes' : 'no' ) . "\n";

/* Cleanup. */
$rm = Attribute::remove( $tax, $host_id, $back_id );
if ( is_wp_error( $rm ) ) {
	echo 'cleanup_rm_err=' . $rm->get_error_code() . "\n";
}
wp_delete_term( $child_id, $tax );

$ok = $no_slot
	&& $reorder_ok
	&& $on_child
	&& $ro_kept
	&& ! $slot_on_child
	&& $gone_from_host
	&& $back_ro
	&& '' !== $moved_id
	&& '' !== $back_id;

echo 'dup_move=' . ( $ok ? 'yes' : 'no' ) . "\n";
echo 'smoke=' . ( $ok ? 'ok' : 'fail' ) . "\n";
exit( $ok ? 0 : 1 );
