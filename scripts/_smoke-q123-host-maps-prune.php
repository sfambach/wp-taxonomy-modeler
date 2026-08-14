<?php
/**
 * One-shot Laragon smoke: safe host-map prune after Q123 edge folds.
 *
 * Plants redundant own-attr host keys that already live on the edge, clears the
 * one-shot flag, runs maybe_migrate, asserts host keys cleared + inherited kept.
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file C:\Devel\Wordpress\source\wp-taxonomy-tree\scripts\_smoke-q123-host-maps-prune.php
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

$host_id   = (int) $host->term_id;
$attr_id   = Attribute::normalize_attr_id( $pick['id'] ?? '' );
$attr_name = Relation::normalize_edge_name( (string) ( $pick['name'] ?? '' ) );

$edge_before = null;
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
		$edge_before = $edge;
		break;
	}
}
if ( ! is_array( $edge_before ) ) {
	fwrite( STDERR, "Edge not found for {$attr_id}\n" );
	exit( 1 );
}

$ro_before      = ! empty( $edge_before['readOnly'] ) || ! empty( $edge_before['readonly'] );
$default_before = array_key_exists( 'default', $edge_before ) || array_key_exists( 'defaultSeed', $edge_before )
	? Attribute::normalize_default_seed( $edge_before['default'] ?? $edge_before['defaultSeed'] ?? null )
	: null;
$extras_before  = get_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, true );
$extras_before  = is_array( $extras_before ) ? $extras_before : array();

/* Ensure edge has RO + a default so prune can clear host mirrors. */
if ( ! $ro_before ) {
	Relation::update_read_only( $tax, $host_id, $attr_id, true );
}
if ( null === $default_before || array() === $default_before ) {
	Relation::update_default( $tax, $host_id, $attr_id, array( 'wtt-smoke-prune-default' ) );
}

/* Plant redundant own host keys (already on edge). */
$ro_map            = Attribute::get_readonly_ids( $host_id );
$ro_map[ $attr_id ] = true;
update_term_meta( $host_id, Attribute::META_KEY_READONLY, array_keys( $ro_map ) );

$fv = Attribute::get_fixed_values_host_map( $host_id );
if ( '' !== $attr_name ) {
	$fv[ $attr_name ] = 'wtt-smoke-prune-host-mirror';
	update_term_meta( $host_id, Attribute::META_KEY_FIXED_VALUES, $fv );
}

/* Covered typeExtras own key + empty-map path after prune. */
$extras_plant               = $extras_before;
$extras_plant[ $attr_id ]   = array( 'preferredConverter' => 'roman' );
/* Ensure edge settings cover that bag so prune drops the key. */
$settings = isset( $edge_before['settings'] ) && is_array( $edge_before['settings'] )
	? $edge_before['settings']
	: array();
if ( ! isset( $settings['view'] ) || ! is_array( $settings['view'] ) ) {
	$settings['view'] = array();
}
$settings['view']['preferredConverter'] = 'roman';
Relation::update_settings( $tax, $host_id, $attr_id, $settings );
update_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, $extras_plant );

/* Fake inherited RO key that must survive (no local edge). */
$fake_inherited = 'wttsmokeinheritedro00000000000001';
$ro_map         = Attribute::get_readonly_ids( $host_id );
$ro_map[ $fake_inherited ] = true;
update_term_meta( $host_id, Attribute::META_KEY_READONLY, array_keys( $ro_map ) );

$host_ro_planted = isset( Attribute::get_readonly_ids( $host_id )[ $attr_id ] );
$host_fv_planted = '' !== $attr_name && isset( Attribute::get_fixed_values_host_map( $host_id )[ $attr_name ] );
$tx_plant_check  = get_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, true );
$host_tx_planted = is_array( $tx_plant_check ) && isset( $tx_plant_check[ $attr_id ] );

/* Reset prune flag and run. */
$flags = get_option( Attribute_Q123_Migrate::OPTION_HOST_MAPS_PRUNED, array() );
if ( ! is_array( $flags ) ) {
	$flags = array();
}
unset( $flags[ $tax ] );
update_option( Attribute_Q123_Migrate::OPTION_HOST_MAPS_PRUNED, $flags, false );

Attribute_Q123_Migrate::maybe_migrate( $tax );

$ro_after   = Attribute::get_readonly_ids( $host_id );
$fv_after   = Attribute::get_fixed_values_host_map( $host_id );
$tx_after   = get_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, true );
$tx_after   = is_array( $tx_after ) ? $tx_after : array();
$flag_after = get_option( Attribute_Q123_Migrate::OPTION_HOST_MAPS_PRUNED, array() );

$own_ro_cleared        = $host_ro_planted && ! isset( $ro_after[ $attr_id ] );
$own_fv_cleared        = ! $host_fv_planted || ! isset( $fv_after[ $attr_name ] );
$own_tx_cleared        = $host_tx_planted && ! isset( $tx_after[ $attr_id ] );
$inherited_ro_kept     = isset( $ro_after[ $fake_inherited ] );
$prune_flag            = ! empty( $flag_after[ $tax ] );

/* Cleanup planted inherited + restore edge / maps. */
unset( $ro_after[ $fake_inherited ] );
if ( empty( $ro_after ) ) {
	delete_term_meta( $host_id, Attribute::META_KEY_READONLY );
} else {
	update_term_meta( $host_id, Attribute::META_KEY_READONLY, array_keys( $ro_after ) );
}

if ( ! $ro_before ) {
	Relation::update_read_only( $tax, $host_id, $attr_id, false );
}
if ( null === $default_before || array() === $default_before ) {
	Relation::update_default( $tax, $host_id, $attr_id, null );
} else {
	Relation::update_default( $tax, $host_id, $attr_id, $default_before );
}

/* Restore prior typeExtras map + edge settings view converter if we changed it. */
$settings_restore = isset( $edge_before['settings'] ) && is_array( $edge_before['settings'] )
	? $edge_before['settings']
	: null;
Relation::update_settings( $tax, $host_id, $attr_id, $settings_restore );
if ( empty( $extras_before ) ) {
	delete_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS );
} else {
	update_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, $extras_before );
}

$ok = $own_ro_cleared && $own_fv_cleared && $own_tx_cleared && $inherited_ro_kept && $prune_flag
	&& defined( 'WTT_VERSION' ) && WTT_VERSION === '0.0.428';

echo 'WTT_VERSION=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . "\n";
echo 'host=' . $host->name . ' id=' . $host_id . "\n";
echo 'attr_id=' . $attr_id . "\n";
echo 'attr_name=' . $attr_name . "\n";
echo 'host_ro_planted=' . ( $host_ro_planted ? 'yes' : 'no' ) . "\n";
echo 'own_ro_cleared=' . ( $own_ro_cleared ? 'yes' : 'no' ) . "\n";
echo 'own_fv_cleared=' . ( $own_fv_cleared ? 'yes' : 'no' ) . "\n";
echo 'own_tx_cleared=' . ( $own_tx_cleared ? 'yes' : 'no' ) . "\n";
echo 'inherited_ro_kept=' . ( $inherited_ro_kept ? 'yes' : 'no' ) . "\n";
echo 'prune_flag=' . ( $prune_flag ? 'yes' : 'no' ) . "\n";
echo 'host_maps_prune=' . ( $ok ? 'yes' : 'no' ) . "\n";
echo 'smoke=' . ( $ok ? 'ok' : 'FAIL' ) . "\n";

exit( $ok ? 0 : 1 );
