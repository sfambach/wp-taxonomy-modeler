<?php
/**
 * One-shot Laragon smoke: RO / Hide → Relation edge fields (own attrs).
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file C:\Devel\Wordpress\source\wp-taxonomy-tree\scripts\_smoke-q123-ro-hide-edge.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Attribute_Q123_Migrate;
use WTT\Relation;
use WTT\Taxonomy;

$tax = Taxonomy::FS;

$hosts = array( 'Kontakt', 'Widerstand', 'Preis', 'Wert' );
$host  = null;
$pick  = null;
foreach ( $hosts as $name ) {
	$term = get_term_by( 'name', $name, $tax );
	if ( ! $term instanceof WP_Term ) {
		continue;
	}
	foreach ( Attribute::list_own( $tax, (int) $term->term_id ) as $row ) {
		if ( ! is_array( $row ) || '' === Attribute::normalize_attr_id( $row['id'] ?? '' ) ) {
			continue;
		}
		$host = $term;
		$pick = $row;
		break;
	}
	if ( null !== $pick ) {
		break;
	}
}
if ( ! $host instanceof WP_Term || null === $pick ) {
	fwrite( STDERR, "No own attributes on smoke hosts (" . implode( ', ', $hosts ) . ")\n" );
	exit( 1 );
}

$host_id = (int) $host->term_id;
$attr_id = Attribute::normalize_attr_id( $pick['id'] ?? '' );

$edge_before = null;
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
		$edge_before = $edge;
		break;
	}
}
$ro_before     = is_array( $edge_before ) && ( ! empty( $edge_before['readOnly'] ) || ! empty( $edge_before['readonly'] ) );
$hidden_before = is_array( $edge_before ) && ! empty( $edge_before['hidden'] );
$mult_before   = is_array( $edge_before )
	? Relation::normalize_multiplicity( (string) ( $edge_before['multiplicity'] ?? '1' ) )
	: '1';

$host_ro_before = Attribute::get_readonly_ids( $host_id );
$host_had_ro    = isset( $host_ro_before[ $attr_id ] );

/* Force host-map RO then fold should clear it onto edge. */
$map = Attribute::get_readonly_ids( $host_id );
$map[ $attr_id ] = true;
/* Use reflection-free path: set via term meta then fold. */
update_term_meta( $host_id, Attribute::META_KEY_READONLY, array_keys( $map ) );

/* Clear edge RO so fold has work to do. */
Relation::update_read_only( $tax, $host_id, $attr_id, false );

$flags = get_option( Attribute_Q123_Migrate::OPTION_EDGE_FLAGS_FOLDED, array() );
if ( ! is_array( $flags ) ) {
	$flags = array();
}
unset( $flags[ $tax ] );
update_option( Attribute_Q123_Migrate::OPTION_EDGE_FLAGS_FOLDED, $flags, false );
Attribute_Q123_Migrate::maybe_migrate( $tax );

$edge_folded = null;
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
		$edge_folded = $edge;
		break;
	}
}
$edge_ro_after_fold = is_array( $edge_folded )
	&& ( ! empty( $edge_folded['readOnly'] ) || ! empty( $edge_folded['readonly'] ) );
$host_ro_after_fold = Attribute::get_readonly_ids( $host_id );
$host_ro_cleared    = ! isset( $host_ro_after_fold[ $attr_id ] );

/* API write: clear then set RO on edge. */
$clear_ro = Attribute::set_readonly( $tax, $host_id, $attr_id, false );
$set_ro   = Attribute::set_readonly( $tax, $host_id, $attr_id, true );
if ( is_wp_error( $clear_ro ) || is_wp_error( $set_ro ) ) {
	fwrite( STDERR, "set_readonly failed\n" );
	exit( 1 );
}

$edge_ro = null;
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
		$edge_ro = $edge;
		break;
	}
}
$api_edge_ro = is_array( $edge_ro ) && ( ! empty( $edge_ro['readOnly'] ) || ! empty( $edge_ro['readonly'] ) );
$host_ro_api = Attribute::get_readonly_ids( $host_id );
$api_host_clear = ! isset( $host_ro_api[ $attr_id ] );

$row = null;
foreach ( Attribute::list_own( $tax, $host_id ) as $r ) {
	if ( Attribute::normalize_attr_id( $r['id'] ?? '' ) === $attr_id ) {
		$row = $r;
		break;
	}
}
$decorate_ro = is_array( $row ) && ! empty( $row['readonly'] );

/* Hide / BO — only when Mult is 0..1; otherwise expect wtt_bo_mult. */
$bo_ok           = false;
$bo_blocked_ok   = false;
$hide_cleared    = true;
$mult_for_bo     = $mult_before;
if ( Attribute::BACKGROUND_ONLY_MULTIPLICITY === $mult_for_bo ) {
	$set_h = Attribute::set_hidden( $tax, $host_id, $attr_id, true );
	if ( is_wp_error( $set_h ) ) {
		fwrite( STDERR, 'set_hidden failed: ' . $set_h->get_error_message() . "\n" );
		exit( 1 );
	}
	$edge_h = null;
	foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
		if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
			$edge_h = $edge;
			break;
		}
	}
	$bo_ok = is_array( $edge_h ) && ! empty( $edge_h['hidden'] );
	Attribute::set_hidden( $tax, $host_id, $attr_id, false );
	$edge_h2 = null;
	foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
		if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
			$edge_h2 = $edge;
			break;
		}
	}
	$hide_cleared = is_array( $edge_h2 ) && empty( $edge_h2['hidden'] );
	$bo_blocked_ok = true; /* N/A path skipped */
} else {
	$bad = Attribute::set_hidden( $tax, $host_id, $attr_id, true );
	$bo_blocked_ok = is_wp_error( $bad ) && 'wtt_bo_mult' === $bad->get_error_code();
	$bo_ok         = true; /* N/A — Mult not 0..1 */
}

/* Restore prior edge flags. */
Relation::update_read_only( $tax, $host_id, $attr_id, $ro_before );
Relation::update_hidden( $tax, $host_id, $attr_id, $hidden_before );
if ( $host_had_ro ) {
	$ids = Attribute::get_readonly_ids( $host_id );
	$ids[ $attr_id ] = true;
	update_term_meta( $host_id, Attribute::META_KEY_READONLY, array_keys( $ids ) );
}

$fold_flag = get_option( Attribute_Q123_Migrate::OPTION_EDGE_FLAGS_FOLDED, array() );
$fold_yes  = is_array( $fold_flag ) && ! empty( $fold_flag[ $tax ] );

$ok = (
	WTT_VERSION === '0.0.424'
	&& $edge_ro_after_fold
	&& $host_ro_cleared
	&& $api_edge_ro
	&& $api_host_clear
	&& $decorate_ro
	&& $bo_ok
	&& $bo_blocked_ok
	&& $hide_cleared
	&& $fold_yes
);

echo 'WTT_VERSION=' . WTT_VERSION . "\n";
echo 'host=' . $host->name . " id={$host_id}\n";
echo "attr_id={$attr_id}\n";
echo 'attr_name=' . (string) ( $pick['name'] ?? '' ) . "\n";
echo 'mult=' . $mult_for_bo . "\n";
echo 'fold_edge_ro=' . ( $edge_ro_after_fold ? 'yes' : 'no' ) . "\n";
echo 'fold_host_ro_cleared=' . ( $host_ro_cleared ? 'yes' : 'no' ) . "\n";
echo 'api_edge_readOnly=' . ( $api_edge_ro ? 'yes' : 'no' ) . "\n";
echo 'api_host_ro_cleared=' . ( $api_host_clear ? 'yes' : 'no' ) . "\n";
echo 'decorate_readonly=' . ( $decorate_ro ? 'yes' : 'no' ) . "\n";
echo 'bo_path=' . ( Attribute::BACKGROUND_ONLY_MULTIPLICITY === $mult_for_bo ? 'set_clear' : 'blocked' ) . "\n";
echo 'bo_ok=' . ( $bo_ok ? 'yes' : 'no' ) . "\n";
echo 'bo_blocked_ok=' . ( $bo_blocked_ok ? 'yes' : 'no' ) . "\n";
echo 'hide_cleared=' . ( $hide_cleared ? 'yes' : 'no' ) . "\n";
echo 'fold_flag=' . ( $fold_yes ? 'yes' : 'no' ) . "\n";
echo 'ro_hide_edge_write=' . ( $api_edge_ro && $api_host_clear ? 'yes' : 'no' ) . "\n";
echo 'smoke=' . ( $ok ? 'ok' : 'FAIL' ) . "\n";

exit( $ok ? 0 : 1 );
