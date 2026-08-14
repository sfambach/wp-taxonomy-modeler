<?php
/**
 * Dump live Konstanten/Präfixe branch for template sync.
 */
declare(strict_types=1);

require 'C:/devel/wordpress/wp-load.php';

use WTT\Attribute;
use WTT\Case_Data;
use WTT\Node_Presentation;
use WTT\Node_Type;
use WTT\Taxonomy;

$tax = Taxonomy::FS;
$id  = Case_Data::find_catalog_folder( $tax, 'prefixes' );
if ( $id <= 0 ) {
	fwrite( STDERR, "Präfixe folder missing\n" );
	exit( 1 );
}

$term = get_term( $id, $tax );
$parent = $term instanceof WP_Term ? (int) $term->parent : 0;
$parent_t = $parent > 0 ? get_term( $parent, $tax ) : null;

$out = array(
	'host' => array(
		'id'               => $id,
		'name'             => $term instanceof WP_Term ? $term->name : '',
		'description'      => $term instanceof WP_Term ? $term->description : '',
		'parent'           => $parent_t instanceof WP_Term ? $parent_t->name : '',
		'preferredRender'  => Node_Type::get_preferred_render( $id ),
		'ownPreferred'     => Node_Type::has_own_preferred_render( $id ),
		'choiceFilter'     => Node_Type::get_choice_filter( $id ),
		'fixedAssignment'  => Node_Type::get_fixed_assignment( $tax, $id ),
		'shortDescription' => get_term_meta( $id, '_wtt_short_description', true ),
		'presentation'     => class_exists( Node_Presentation::class )
			? Node_Presentation::map_for_term_resolved( $tax, $id )
			: null,
	),
	'attributes' => array(),
	'children'   => array(),
);

foreach ( Attribute::list_own( $tax, $id ) as $row ) {
	if ( ! is_array( $row ) ) {
		continue;
	}
	$attr_id = Attribute::normalize_attr_id( $row['id'] ?? '' );
	$out['attributes'][] = array(
		'name'         => $row['name'] ?? '',
		'id'           => $attr_id,
		'typeKey'      => $row['typeKey'] ?? '',
		'typeName'     => $row['typeName'] ?? '',
		'typeId'       => $row['typeId'] ?? 0,
		'multiplicity' => $row['multiplicity'] ?? '',
		'binding'      => $row['binding'] ?? '',
		'readonly'     => ! empty( $row['readonly'] ),
		'hidden'       => ! empty( $row['hidden'] ),
		'fixedValues'  => $row['fixedValues'] ?? array(),
		'fixedMode'    => $row['fixedMode'] ?? '',
		'typeExtras'   => $row['typeExtras'] ?? null,
		'settings'     => $row['settings'] ?? null,
		'preferredRender' => $row['preferredRender'] ?? null,
		'presentationConfig' => $row['presentationConfig'] ?? null,
	);
}

$children = get_terms(
	array(
		'taxonomy'   => $tax,
		'parent'     => $id,
		'hide_empty' => false,
		'number'     => 0,
		'orderby'    => 'name',
		'order'      => 'ASC',
	)
);

if ( is_array( $children ) ) {
	foreach ( $children as $child ) {
		if ( ! $child instanceof WP_Term ) {
			continue;
		}
		$cid = (int) $child->term_id;
		$eff = Attribute::effective_list( $tax, $cid );
		$leaf_attrs = array();
		foreach ( $eff as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$leaf_attrs[] = array(
				'name'        => $r['name'] ?? '',
				'inherited'   => ! empty( $r['inherited'] ),
				'readonly'    => ! empty( $r['readonly'] ),
				'hidden'      => ! empty( $r['hidden'] ),
				'fixedValues' => $r['fixedValues'] ?? array(),
				'inheritedHostOverride' => $r['inheritedHostOverride'] ?? null,
			);
		}
		$out['children'][] = array(
			'id'               => $cid,
			'name'             => $child->name,
			'slug'             => $child->slug,
			'description'      => $child->description,
			'shortDescription' => (string) get_term_meta( $cid, '_wtt_short_description', true ),
			'multiplikator'    => Node_Type::get_multiplikator( $cid ),
			'preferredRender'  => Node_Type::get_preferred_render( $cid ),
			'ownPreferred'     => Node_Type::has_own_preferred_render( $cid ),
			'fixedAssignment'  => Node_Type::get_fixed_assignment( $tax, $cid ),
			'presentation'     => class_exists( Node_Presentation::class )
				? Node_Presentation::map_for_term_resolved( $tax, $cid )
				: null,
			'attributes'       => $leaf_attrs,
		);
	}
}

echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
