<?php
/**
 * Smoke: Konstanten hosts with children default to ChildListRenderer.
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Case_Data;
use WTT\Node_Type;
use WTT\Renderer;
use WTT\Taxonomy;

$tax = Taxonomy::FS;
Case_Data::ensure_konstanten( $tax );
Case_Data::ensure_unit_catalog( $tax );

$konstanten = Case_Data::find_term_by_path( $tax, array( 'Fallstudie', 'Definition', 'Konstanten' ) );
$updated    = Node_Type::ensure_konstanten_child_list_preferred( $tax, (int) $konstanten );

$hosts = array(
	'prefixes'       => Case_Data::find_catalog_folder( $tax, 'prefixes' ),
	'bauformen'      => Case_Data::find_catalog_folder( $tax, 'bauformen' ),
	'waehrung'       => Case_Data::find_catalog_folder( $tax, 'waehrung' ),
	'basiseinheiten' => Case_Data::find_catalog_folder( $tax, 'basiseinheiten' ),
	'with_prefix'    => Case_Data::find_catalog_folder( $tax, 'with_prefix' ),
);

$rows = array();
$ok   = true;
foreach ( $hosts as $key => $id ) {
	$id = (int) $id;
	if ( $id <= 0 ) {
		$rows[ $key ] = 'missing';
		$ok            = false;
		continue;
	}
	$is_host = Node_Type::is_konstanten_child_list_host( $tax, $id );
	$pref    = Node_Type::get_preferred_render( $id );
	$own     = Node_Type::get_own_preferred_render( $id );
	/*
	 * Default is ChildList; intentional own overrides (e.g. Bauformen Compact) stay OK.
	 * Fail only when host still has empty / Form (ensure should have repaired).
	 */
	$pass = true;
	if ( $is_host ) {
		$pass = (
			Renderer::ChildList->value === $pref
			|| ( '' !== $own && Renderer::Form->value !== $own )
		);
	}
	if ( ! $pass ) {
		$ok = false;
	}
	$rows[ $key ] = array(
		'id'   => $id,
		'host' => $is_host,
		'own'  => $own,
		'pref' => $pref,
		'pass' => $pass,
	);
}

/* Unit leaf must NOT be ChildList host. */
$meter = Case_Data::find_term_by_path(
	$tax,
	array( 'Fallstudie', 'Definition', 'Konstanten', 'Basiseinheiten', 'With prefix', 'Meter' )
);
if ( $meter <= 0 ) {
	$meter = Case_Data::find_term_by_path(
		$tax,
		array( 'Fallstudie', 'Definition', 'Konstanten', 'Basiseinheiten', 'Mit Präfix', 'Meter' )
	);
}
$meter_host = $meter > 0 && Node_Type::is_konstanten_child_list_host( $tax, (int) $meter );
if ( $meter_host ) {
	$ok = false;
}

echo wp_json_encode(
	array(
		'ok'      => $ok,
		'updated' => $updated,
		'rows'    => $rows,
		'meter'   => array(
			'id'   => (int) $meter,
			'host' => $meter_host,
			'pref' => $meter > 0 ? Node_Type::get_preferred_render( (int) $meter ) : '',
		),
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
) . "\n";
