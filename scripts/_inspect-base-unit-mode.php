<?php
/**
 * Inspect Unit type → Base unit paint mode (CatalogChoice vs Structure).
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Case_Data;
use WTT\Node_Type;
use WTT\Taxonomy;

$tax = Taxonomy::FS;
$ut  = Case_Data::find_term_by_path(
	$tax,
	array( 'Fallstudie', 'Definition', 'Data Types', 'Complex Datatypes', 'quantity', 'Unit type' )
);
if ( $ut <= 0 ) {
	$ut = Case_Data::find_term_by_path(
		$tax,
		array( 'Fallstudie', 'Definition', 'Data Types', 'Unit type' )
	);
}

$rows = $ut > 0 ? Attribute::list( $tax, (int) $ut ) : array();
$base = null;
foreach ( $rows as $r ) {
	if ( is_array( $r ) && isset( $r['name'] ) && 'Base unit' === $r['name'] ) {
		$base = $r;
		break;
	}
}

$type_id = $base ? (int) ( $base['typeId'] ?? 0 ) : 0;
$type    = $type_id > 0 ? get_term( $type_id, $tax ) : null;
$parent  = ( $type instanceof WP_Term && (int) $type->parent > 0 )
	? get_term( (int) $type->parent, $tax )
	: null;

echo wp_json_encode(
	array(
		'unitTypeId' => (int) $ut,
		'base'       => $base
			? array(
				'typeId'               => $type_id,
				'typeName'             => $type instanceof WP_Term ? $type->name : '',
				'typeParent'           => $parent instanceof WP_Term ? $parent->name : '',
				'fixedMode'            => $base['fixedMode'] ?? '',
				'fixedOptionsCount'    => is_array( $base['fixedOptions'] ?? null ) ? count( $base['fixedOptions'] ) : 0,
				'typePropertiesCount'  => is_array( $base['typeProperties'] ?? null ) ? count( $base['typeProperties'] ) : 0,
				'typePropertiesNames'  => array_values(
					array_map(
						static function ( $p ) {
							return is_array( $p ) ? (string) ( $p['name'] ?? '' ) : '';
						},
						is_array( $base['typeProperties'] ?? null ) ? $base['typeProperties'] : array()
					)
				),
				'preferredRender'      => $base['preferredRender'] ?? '',
				'typePreferredRender'  => $base['typePreferredRender'] ?? '',
				'isUnitPrefixBucket'   => $type_id > 0 ? Node_Type::is_unit_prefix_bucket( $tax, $type_id ) : false,
				'prefersStructure'     => $type_id > 0 ? Attribute::prefers_structure_over_catalog( $tax, $type_id ) : false,
				'typeHasAttrs'         => $type_id > 0 ? Attribute::type_has_attributes( $tax, $type_id ) : false,
			)
			: null,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
) . "\n";
