<?php
/**
 * Dump Base unit attr keys used by admin paint.
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use WTT\Attribute;
use WTT\Case_Data;
use WTT\Taxonomy;

$tax = Taxonomy::FS;
$ut  = 5194;
$rows = Attribute::list( $tax, $ut );
foreach ( $rows as $r ) {
	if ( ! is_array( $r ) || ( $r['name'] ?? '' ) !== 'Base unit' ) {
		continue;
	}
	echo wp_json_encode(
		array(
			'name'                => $r['name'] ?? '',
			'typeKey'             => $r['typeKey'] ?? '',
			'typeName'            => $r['typeName'] ?? '',
			'fixedMode'           => $r['fixedMode'] ?? '',
			'preferredRender'     => $r['preferredRender'] ?? '',
			'typePreferredRender' => $r['typePreferredRender'] ?? '',
			'fixedOptionsSample'  => array_slice(
				array_map(
					static function ( $o ) {
						return array(
							'id'   => $o['id'] ?? null,
							'name' => $o['name'] ?? '',
							'ap'   => isset( $o['allowedPrefixes'] ) ? count( (array) $o['allowedPrefixes'] ) : 0,
						);
					},
					is_array( $r['fixedOptions'] ?? null ) ? $r['fixedOptions'] : array()
				),
				0,
				3
			),
			'typeProperties'      => $r['typeProperties'] ?? array(),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	) . "\n";
}
