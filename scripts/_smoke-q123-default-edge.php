<?php
/**
 * One-shot Laragon smoke: Default seed → Relation edge.default (own attrs).
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file C:\Devel\Wordpress\source\wp-taxonomy-tree\scripts\_smoke-q123-default-edge.php
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
$attr_name = (string) ( $pick['name'] ?? '' );
$marker    = 'wtt-smoke-default-' . gmdate( 'His' );

$edge_before = null;
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
		$edge_before = $edge;
		break;
	}
}
$default_before = is_array( $edge_before ) && array_key_exists( 'default', $edge_before )
	? $edge_before['default']
	: null;

/* Seed host name-map then fold onto edge. */
$map                = Attribute::get_fixed_values_host_map( $host_id );
$map[ $attr_name ]  = $marker . '-fold';
update_term_meta( $host_id, Attribute::META_KEY_FIXED_VALUES, $map );

/* Clear edge default so fold has work. */
Relation::update_default( $tax, $host_id, $attr_id, null );

$flags = get_option( Attribute_Q123_Migrate::OPTION_DEFAULTS_FOLDED, array() );
if ( ! is_array( $flags ) ) {
	$flags = array();
}
unset( $flags[ $tax ] );
update_option( Attribute_Q123_Migrate::OPTION_DEFAULTS_FOLDED, $flags, false );

Attribute_Q123_Migrate::maybe_migrate( $tax );

$edge_after_fold = null;
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
		$edge_after_fold = $edge;
		break;
	}
}
$host_map_after_fold = Attribute::get_fixed_values_host_map( $host_id );
$fold_edge           = is_array( $edge_after_fold )
	&& isset( $edge_after_fold['default'] )
	&& is_array( $edge_after_fold['default'] )
	&& in_array( $marker . '-fold', $edge_after_fold['default'], true );
$fold_host_cleared   = ! isset( $host_map_after_fold[ $attr_name ] );

/* API write → edge only. */
$api_marker = $marker . '-api';
$written    = Attribute::set_fixed_values( $tax, $host_id, $attr_id, $api_marker );
$api_ok     = ! is_wp_error( $written );

$edge_after_api = null;
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
		$edge_after_api = $edge;
		break;
	}
}
$host_map_after_api = Attribute::get_fixed_values_host_map( $host_id );
$api_edge           = is_array( $edge_after_api )
	&& isset( $edge_after_api['default'] )
	&& is_array( $edge_after_api['default'] )
	&& in_array( $api_marker, $edge_after_api['default'], true );
$api_host_cleared   = ! isset( $host_map_after_api[ $attr_name ] );

$row = null;
foreach ( Attribute::list_own( $tax, $host_id ) as $candidate ) {
	if ( Attribute::normalize_attr_id( $candidate['id'] ?? '' ) === $attr_id ) {
		$row = $candidate;
		break;
	}
}
$decorate_ok = is_array( $row )
	&& isset( $row['fixedValues'] )
	&& is_array( $row['fixedValues'] )
	&& in_array( $api_marker, $row['fixedValues'], true );

/* Restore prior edge default. */
Relation::update_default( $tax, $host_id, $attr_id, $default_before );

$fold_flags = get_option( Attribute_Q123_Migrate::OPTION_DEFAULTS_FOLDED, array() );
$fold_flag  = is_array( $fold_flags ) && ! empty( $fold_flags[ $tax ] );

$ok = $fold_edge && $fold_host_cleared && $api_ok && $api_edge && $api_host_cleared && $decorate_ok && $fold_flag;

echo 'WTT_VERSION=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . PHP_EOL;
echo 'host=' . $host->name . ' id=' . $host_id . PHP_EOL;
echo 'attr_id=' . $attr_id . PHP_EOL;
echo 'attr_name=' . $attr_name . PHP_EOL;
echo 'marker=' . $marker . PHP_EOL;
echo 'fold_edge_default=' . ( $fold_edge ? 'yes' : 'no' ) . PHP_EOL;
echo 'fold_host_cleared=' . ( $fold_host_cleared ? 'yes' : 'no' ) . PHP_EOL;
echo 'api_edge_default=' . ( $api_edge ? 'yes' : 'no' ) . PHP_EOL;
echo 'api_host_cleared=' . ( $api_host_cleared ? 'yes' : 'no' ) . PHP_EOL;
echo 'decorate_fixedValues=' . ( $decorate_ok ? 'yes' : 'no' ) . PHP_EOL;
echo 'fold_flag=' . ( $fold_flag ? 'yes' : 'no' ) . PHP_EOL;
echo 'default_edge_write=' . ( $ok ? 'yes' : 'no' ) . PHP_EOL;
echo 'smoke=' . ( $ok ? 'ok' : 'FAIL' ) . PHP_EOL;

if ( ! $ok ) {
	exit( 1 );
}
