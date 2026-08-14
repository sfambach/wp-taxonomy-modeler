<?php
/**
 * Laragon smoke: Walk-Wizard nested path deltas on attribute Relation (OQ-W16).
 *
 * Asserts Preferred (view) + validators (data) write to settings.nested[path],
 * resolve on decorate settingsWalk, Reset deletes keys, nested type Preferred unchanged.
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file C:\Devel\Wordpress\source\wp-taxonomy-tree\scripts\_smoke-q123-walk-nested-overrides.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Node_Type;
use WTT\Relation;
use WTT\Settings_Walk;
use WTT\Taxonomy;

$tax = Taxonomy::FS;

$hosts = array( 'Passiv', 'Widerstand', 'Kondensator', 'Platine', 'Preis', 'Kontakt' );
$host  = null;
$pick  = null;
foreach ( $hosts as $name ) {
	$term = get_term_by( 'name', $name, $tax );
	if ( ! $term instanceof WP_Term ) {
		continue;
	}
	foreach ( Attribute::list_own( $tax, (int) $term->term_id ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$walk = isset( $row['settingsWalk'] ) && is_array( $row['settingsWalk'] ) ? $row['settingsWalk'] : array();
		if ( count( $walk ) < 2 ) {
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
	fwrite( STDERR, "No nested-walk own attr on smoke hosts\n" );
	exit( 1 );
}

$host_id   = (int) $host->term_id;
$attr_id   = Attribute::normalize_attr_id( $pick['id'] ?? '' );
$attr_name = (string) ( $pick['name'] ?? '' );
$summary   = isset( $pick['settingsWalk'] ) && is_array( $pick['settingsWalk'] )
	? $pick['settingsWalk']
	: array();

$child = null;
foreach ( $summary as $level ) {
	if ( ! is_array( $level ) ) {
		continue;
	}
	$path = Settings_Walk::normalize_walk_path( (string) ( $level['path'] ?? '' ) );
	if ( '' !== $path ) {
		$child = $level;
		break;
	}
}

if ( null === $child ) {
	fwrite( STDERR, "No nested walk path on attr {$attr_name}\n" );
	exit( 1 );
}

$path       = Settings_Walk::normalize_walk_path( (string) ( $child['path'] ?? '' ) );
$child_nid  = (int) ( $child['nodeId'] ?? 0 );
$type_pref0 = $child_nid > 0 ? Node_Type::get_preferred_render( $child_nid ) : '';

$marker_pref = 'CompactRenderer';
$set_pref    = Attribute::set_walk_settings_key(
	$tax,
	$host_id,
	$attr_id,
	$path,
	'view',
	'preferredRenderer',
	$marker_pref
);
$set_val = Attribute::set_walk_settings_key(
	$tax,
	$host_id,
	$attr_id,
	$path,
	'data',
	'validators',
	array(
		array(
			'id'        => 'required',
			'errorText' => 'smoke-walk',
		),
	)
);

$edge_row = null;
foreach ( Attribute::list_own( $tax, $host_id ) as $row ) {
	if ( Attribute::normalize_attr_id( $row['id'] ?? '' ) === $attr_id ) {
		$edge_row = $row;
		break;
	}
}

$settings = is_array( $edge_row ) && isset( $edge_row['settings'] ) && is_array( $edge_row['settings'] )
	? $edge_row['settings']
	: array();
$nested   = isset( $settings['nested'][ $path ] ) && is_array( $settings['nested'][ $path ] )
	? $settings['nested'][ $path ]
	: array();
$view     = isset( $nested['view'] ) && is_array( $nested['view'] )
	? Settings_Walk::normalize_view_bag( $nested['view'] )
	: array();
$data     = isset( $nested['data'] ) && is_array( $nested['data'] ) ? $nested['data'] : array();

$pref_ok = ! is_wp_error( $set_pref )
	&& Settings_Walk::view_string( $view, 'preferredRenderer' ) === Node_Type::normalize_preferred_render( $marker_pref );
$val_ok  = ! is_wp_error( $set_val ) && isset( $data['validators'] ) && is_array( $data['validators'] );

$row2 = null;
foreach ( Attribute::list_own( $tax, $host_id ) as $row ) {
	if ( Attribute::normalize_attr_id( $row['id'] ?? '' ) === $attr_id ) {
		$row2 = $row;
		break;
	}
}
$level2 = null;
if ( is_array( $row2 ) && isset( $row2['settingsWalk'] ) && is_array( $row2['settingsWalk'] ) ) {
	foreach ( $row2['settingsWalk'] as $level ) {
		if ( ! is_array( $level ) ) {
			continue;
		}
		if ( Settings_Walk::normalize_walk_path( (string) ( $level['path'] ?? '' ) ) === $path ) {
			$level2 = $level;
			break;
		}
	}
}
$decorate_pref = is_array( $level2 ) && ! empty( $level2['hasPreferredOverride'] );
$decorate_val  = is_array( $level2 ) && ! empty( $level2['hasValidatorsOverride'] );

$type_pref1     = $child_nid > 0 ? Node_Type::get_preferred_render( $child_nid ) : '';
$type_unchanged = $type_pref0 === $type_pref1;

/* Cleanup */
Attribute::set_walk_settings_key( $tax, $host_id, $attr_id, $path, 'view', 'preferredRenderer', null );
Attribute::set_walk_settings_key( $tax, $host_id, $attr_id, $path, 'data', 'validators', null );

$row3 = null;
foreach ( Attribute::list_own( $tax, $host_id ) as $row ) {
	if ( Attribute::normalize_attr_id( $row['id'] ?? '' ) === $attr_id ) {
		$row3 = $row;
		break;
	}
}
$settings3 = is_array( $row3 ) && isset( $row3['settings'] ) && is_array( $row3['settings'] )
	? $row3['settings']
	: array();
$nested_gone = ! isset( $settings3['nested'][ $path ] )
	|| (
		! Settings_Walk::bag_has_key(
			isset( $settings3['nested'][ $path ]['view'] ) && is_array( $settings3['nested'][ $path ]['view'] )
				? Settings_Walk::normalize_view_bag( $settings3['nested'][ $path ]['view'] )
				: array(),
			'preferredRenderer'
		)
		&& ! array_key_exists(
			'validators',
			isset( $settings3['nested'][ $path ]['data'] ) && is_array( $settings3['nested'][ $path ]['data'] )
				? $settings3['nested'][ $path ]['data']
				: array()
		)
	);

$ok = $pref_ok && $val_ok && $decorate_pref && $decorate_val && $type_unchanged && $nested_gone;

echo 'WTT_VERSION=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . "\n";
echo 'host=' . $host->name . ' id=' . $host_id . "\n";
echo 'attr_id=' . $attr_id . "\n";
echo 'attr_name=' . $attr_name . "\n";
echo 'path=' . $path . "\n";
echo 'child_nodeId=' . $child_nid . "\n";
echo 'nested_pref_write=' . ( $pref_ok ? 'yes' : 'no' ) . "\n";
echo 'nested_validators_write=' . ( $val_ok ? 'yes' : 'no' ) . "\n";
echo 'decorate_hasPreferredOverride=' . ( $decorate_pref ? 'yes' : 'no' ) . "\n";
echo 'decorate_hasValidatorsOverride=' . ( $decorate_val ? 'yes' : 'no' ) . "\n";
echo 'type_node_unchanged=' . ( $type_unchanged ? 'yes' : 'no' ) . "\n";
echo 'reset_deleted_keys=' . ( $nested_gone ? 'yes' : 'no' ) . "\n";
echo 'smoke=' . ( $ok ? 'ok' : 'fail' ) . "\n";

exit( $ok ? 0 : 1 );
