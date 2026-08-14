<?php
/**
 * One-shot Laragon smoke: Q105 BO/Hide ⇒ Mult 0..1 validator + fixes.
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file C:\Devel\Wordpress\source\wp-taxonomy-tree\scripts\_smoke-q123-bo-mult-rule.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Attribute_Validator;
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
		/* Prefer Mult ≠ 0..1 so edge.hidden + bad Mult triggers the rule. */
		$mult = Relation::normalize_multiplicity( (string) ( $row['multiplicity'] ?? '' ) );
		if ( Attribute::BACKGROUND_ONLY_MULTIPLICITY === $mult ) {
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
	fwrite( STDERR, "No own attr with Mult≠0..1 on smoke hosts (" . implode( ', ', $hosts ) . ")\n" );
	exit( 1 );
}

$host_id     = (int) $host->term_id;
$attr_id     = Attribute::normalize_attr_id( $pick['id'] ?? '' );
$attr_name   = (string) ( $pick['name'] ?? '' );
$mult_before = Relation::normalize_multiplicity( (string) ( $pick['multiplicity'] ?? '' ) );

$edge_before = null;
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
		$edge_before = $edge;
		break;
	}
}
$hidden_edge_before = is_array( $edge_before ) && ! empty( $edge_before['hidden'] );
$host_hidden_before = Attribute::get_hidden_ids( $host_id );

/*
 * Plant debt on edge.hidden while Mult ≠ 0..1 (own reads are edge-only ≈ 0.0.431;
 * host-map Hide no longer surfaces for own attrs).
 */
Relation::update_hidden( $tax, $host_id, $attr_id, true );
Attribute::clear_hidden_host_key( $host_id, $attr_id );

$v1 = Attribute_Validator::validate( $tax, $host_id );
$has_rule = false;
$has_set  = false;
$has_clr  = false;
foreach ( $v1['fixes'] as $fix ) {
	if ( ! is_array( $fix ) ) {
		continue;
	}
	if ( ( $fix['attrId'] ?? '' ) !== $attr_id ) {
		continue;
	}
	if ( Attribute_Validator::RULE_BACKGROUND_ONLY_NEEDS_MULT === ( $fix['rule'] ?? '' ) ) {
		$has_rule = true;
	}
	if ( Attribute_Validator::FIX_SET_MULT_01 === ( $fix['action'] ?? '' ) ) {
		$has_set = true;
	}
	if ( Attribute_Validator::FIX_CLEAR_HIDE === ( $fix['action'] ?? '' ) ) {
		$has_clr = true;
	}
}
$report_ok = ! $v1['ok'] && $has_rule && $has_set && $has_clr;

/* Fix (b): clear Hide — Mult stays. */
$fix_clear = Attribute_Validator::apply_fix(
	$tax,
	$host_id,
	$attr_id,
	Attribute_Validator::FIX_CLEAR_HIDE
);
$clear_ok = ! is_wp_error( $fix_clear )
	&& is_array( $fix_clear['validation'] ?? null )
	&& ! empty( $fix_clear['validation']['ok'] );
$edge_after_clear = null;
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
		$edge_after_clear = $edge;
		break;
	}
}
$clear_edge_ok = is_array( $edge_after_clear ) && empty( $edge_after_clear['hidden'] );

/* Re-plant debt for fix (a). */
Relation::update_hidden( $tax, $host_id, $attr_id, true );

$fix_mult = Attribute_Validator::apply_fix(
	$tax,
	$host_id,
	$attr_id,
	Attribute_Validator::FIX_SET_MULT_01
);
$mult_ok = ! is_wp_error( $fix_mult )
	&& is_array( $fix_mult['validation'] ?? null )
	&& ! empty( $fix_mult['validation']['ok'] );

$edge_after = null;
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
		$edge_after = $edge;
		break;
	}
}
$mult_after = is_array( $edge_after )
	? Relation::normalize_multiplicity( (string) ( $edge_after['multiplicity'] ?? '' ) )
	: '';
$set_mult_ok = Attribute::BACKGROUND_ONLY_MULTIPLICITY === $mult_after;

/* Restore prior Mult / Hide state. */
Attribute::set_multiplicity( $tax, $host_id, $attr_id, $mult_before );
Relation::update_hidden( $tax, $host_id, $attr_id, $hidden_edge_before );
$restore_ids = array();
foreach ( array_keys( $host_hidden_before ) as $hid ) {
	$hid = Attribute::normalize_attr_id( $hid );
	if ( '' !== $hid ) {
		$restore_ids[] = $hid;
	}
}
if ( array() === $restore_ids ) {
	delete_term_meta( $host_id, Attribute::META_KEY_HIDDEN );
} else {
	update_term_meta( $host_id, Attribute::META_KEY_HIDDEN, $restore_ids );
}

$ok = $report_ok && $clear_ok && $clear_edge_ok && $mult_ok && $set_mult_ok;

echo 'WTT_VERSION=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . "\n";
echo 'host=' . $host->name . ' id=' . $host_id . "\n";
echo 'attr_id=' . $attr_id . "\n";
echo 'attr_name=' . $attr_name . "\n";
echo 'mult_before=' . $mult_before . "\n";
echo 'report_ok=' . ( $report_ok ? 'yes' : 'no' ) . "\n";
echo 'fix_clear_ok=' . ( $clear_ok && $clear_edge_ok ? 'yes' : 'no' ) . "\n";
echo 'fix_set_mult_ok=' . ( $mult_ok && $set_mult_ok ? 'yes' : 'no' ) . "\n";
echo 'bo_mult_rule=' . ( $ok ? 'yes' : 'no' ) . "\n";
echo 'smoke=' . ( $ok ? 'ok' : 'FAIL' ) . "\n";

if ( ! $ok ) {
	exit( 1 );
}
