<?php
/**
 * One-shot Laragon smoke: preferred render → edge settings.view only (no slot meta).
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file C:\Devel\Wordpress\source\wp-taxonomy-tree\scripts\_smoke-q123-preferred-edge.php
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
use WTT\Renderer;
use WTT\Settings_Walk;
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
$marker  = Renderer::Compact->value;

$edge_before = null;
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
		$edge_before = $edge;
		break;
	}
}
$settings_before = is_array( $edge_before ) && isset( $edge_before['settings'] ) && is_array( $edge_before['settings'] )
	? $edge_before['settings']
	: array();

$legacy_slot = (int) ( $pick['legacySlotId'] ?? 0 );
$slot_had    = $legacy_slot > 0
	&& metadata_exists( 'term', $legacy_slot, Node_Type::META_KEY_PREFERRED_RENDER );

$set = Attribute::set_preferred_render( $tax, $host_id, $attr_id, $marker );
if ( is_wp_error( $set ) ) {
	fwrite( STDERR, 'set failed: ' . $set->get_error_message() . "\n" );
	exit( 1 );
}

$edge_after = null;
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
		$edge_after = $edge;
		break;
	}
}
$view_after = array();
if ( is_array( $edge_after ) && isset( $edge_after['settings']['view'] ) && is_array( $edge_after['settings']['view'] ) ) {
	$view_after = Settings_Walk::normalize_view_bag( $edge_after['settings']['view'] );
}
$edge_pref = Settings_Walk::view_string( $view_after, 'preferredRenderer' );

$row = null;
foreach ( Attribute::list_own( $tax, $host_id ) as $r ) {
	if ( Attribute::normalize_attr_id( $r['id'] ?? '' ) === $attr_id ) {
		$row = $r;
		break;
	}
}
$decorated_pref = is_array( $row ) ? (string) ( $row['preferredRender'] ?? '' ) : '';
$has_override   = is_array( $row ) && ! empty( $row['preferredRenderOverride'] );
$walk_src       = is_array( $row ) && isset( $row['settingsWalkMeta']['preferredSource'] )
	? (string) $row['settingsWalkMeta']['preferredSource']
	: '';

$slot_has_after = $legacy_slot > 0
	&& metadata_exists( 'term', $legacy_slot, Node_Type::META_KEY_PREFERRED_RENDER );

/* Clear — must delete delta key. */
$clear = Attribute::set_preferred_render( $tax, $host_id, $attr_id, '' );
if ( is_wp_error( $clear ) ) {
	fwrite( STDERR, 'clear failed: ' . $clear->get_error_message() . "\n" );
	exit( 1 );
}

$edge_cleared = null;
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
		$edge_cleared = $edge;
		break;
	}
}
$view_cleared = array();
if ( is_array( $edge_cleared ) && isset( $edge_cleared['settings']['view'] ) && is_array( $edge_cleared['settings']['view'] ) ) {
	$view_cleared = Settings_Walk::normalize_view_bag( $edge_cleared['settings']['view'] );
}
$key_gone = ! Settings_Walk::bag_has_key( $view_cleared, 'preferredRenderer' );

$row_cleared = null;
foreach ( Attribute::list_own( $tax, $host_id ) as $r ) {
	if ( Attribute::normalize_attr_id( $r['id'] ?? '' ) === $attr_id ) {
		$row_cleared = $r;
		break;
	}
}
$override_after_clear = is_array( $row_cleared ) && ! empty( $row_cleared['preferredRenderOverride'] );

/* Restore prior settings if the edge had unrelated deltas. */
Relation::update_settings(
	$tax,
	$host_id,
	$attr_id,
	empty( $settings_before ) ? null : $settings_before
);

$ok = (
	WTT_VERSION === '0.0.423'
	&& $edge_pref === Node_Type::normalize_preferred_render( $marker )
	&& $decorated_pref === Node_Type::normalize_preferred_render( $marker )
	&& $has_override
	&& 'edge' === $walk_src
	&& ! $slot_has_after
	&& $key_gone
	&& ! $override_after_clear
);

echo "WTT_VERSION=" . WTT_VERSION . "\n";
echo 'host=' . $host->name . " id={$host_id}\n";
echo "attr_id={$attr_id}\n";
echo 'attr_name=' . (string) ( $pick['name'] ?? '' ) . "\n";
echo "marker={$marker}\n";
echo 'edge_preferredRenderer=' . $edge_pref . "\n";
echo 'decorate_preferredRender=' . $decorated_pref . "\n";
echo 'hasOverride=' . ( $has_override ? 'yes' : 'no' ) . "\n";
echo 'settingsWalkMeta.preferredSource=' . $walk_src . "\n";
echo 'legacy_slot_had_meta_before=' . ( $slot_had ? 'yes' : 'no' ) . "\n";
echo 'legacy_slot_has_meta_after=' . ( $slot_has_after ? 'yes' : 'no' ) . "\n";
echo 'clear_deleted_delta_key=' . ( $key_gone ? 'yes' : 'no' ) . "\n";
echo 'override_after_clear=' . ( $override_after_clear ? 'yes' : 'no' ) . "\n";
echo 'preferred_edge_write=' . ( $edge_pref === Node_Type::normalize_preferred_render( $marker ) ? 'yes' : 'no' ) . "\n";
echo 'smoke=' . ( $ok ? 'ok' : 'FAIL' ) . "\n";

exit( $ok ? 0 : 1 );
