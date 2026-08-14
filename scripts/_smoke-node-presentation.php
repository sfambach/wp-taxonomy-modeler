<?php
/**
 * Smoke: node_presentation type + With-prefix Kuerzel → symbol.
 *
 * Run: wp eval-file scripts/_smoke-node-presentation.php
 * Or load via WP docroot with plugin active.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	$candidates = array(
		dirname( __DIR__, 3 ) . '/wp-load.php',
		dirname( __DIR__, 2 ) . '/wp-load.php',
		'C:/devel/wordpress/wp-load.php',
	);
	foreach ( $candidates as $load ) {
		if ( is_readable( $load ) ) {
			require_once $load;
			break;
		}
	}
}

if ( ! defined( 'ABSPATH' ) || ! class_exists( '\WTT\Case_Data' ) ) {
	fwrite( STDERR, "FAIL: WordPress / plugin not loaded\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Case_Data;
use WTT\Node_Presentation;
use WTT\Node_Type;
use WTT\Sample_Data;
use WTT\Taxonomy;
use WTT\Tree_Model;

/**
 * @return int
 */
function wtt_smoke_find_datatype( string $taxonomy, string $name ): int {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'name'       => $name,
			'number'     => 5,
		)
	);
	if ( ! is_array( $terms ) ) {
		return 0;
	}
	foreach ( $terms as $term ) {
		if ( $term instanceof WP_Term ) {
			return (int) $term->term_id;
		}
	}
	return 0;
}

$taxonomy = Taxonomy::FS;
Case_Data::ensure_simple_datatypes( $taxonomy );
Case_Data::ensure_with_prefix_composition( $taxonomy );

$pres_id = wtt_smoke_find_datatype( $taxonomy, 'node_presentation' );
if ( $pres_id <= 0 ) {
	$pres_id = wtt_smoke_find_datatype( $taxonomy, 'display_node_name' );
}
if ( $pres_id <= 0 ) {
	fwrite( STDERR, "FAIL: node_presentation datatype missing\n" );
	exit( 1 );
}

$pres_term = get_term( $pres_id, $taxonomy );
$name      = $pres_term instanceof WP_Term ? (string) $pres_term->name : '';
if ( 'node_presentation' !== $name && 'display_node_name' !== $name ) {
	fwrite( STDERR, "FAIL: unexpected type name {$name}\n" );
	exit( 1 );
}

$with_id = Case_Data::find_catalog_folder( $taxonomy, 'with_prefix' );
if ( $with_id <= 0 ) {
	fwrite( STDERR, "FAIL: With prefix folder missing\n" );
	exit( 1 );
}

$kuerzel = null;
foreach ( Attribute::list_own( $taxonomy, $with_id ) as $row ) {
	if ( is_array( $row ) && 'Kuerzel' === (string) ( $row['name'] ?? '' ) ) {
		$kuerzel = $row;
		break;
	}
}
if ( ! is_array( $kuerzel ) ) {
	fwrite( STDERR, "FAIL: Kuerzel attr missing on With prefix\n" );
	exit( 1 );
}

$type_key = Node_Type::normalize_type_name( (string) ( $kuerzel['typeKey'] ?? $kuerzel['typeName'] ?? '' ) );
if ( 'node_presentation' !== $type_key && 'display_node_name' !== $type_key ) {
	fwrite( STDERR, "FAIL: Kuerzel typeKey={$type_key}, expected node_presentation\n" );
	exit( 1 );
}

$cfg = isset( $kuerzel['presentationConfig'] ) && is_array( $kuerzel['presentationConfig'] )
	? $kuerzel['presentationConfig']
	: null;
$ctx = is_array( $cfg ) ? (string) ( $cfg['context'] ?? '' ) : '';
if ( '' === $ctx && isset( $kuerzel['typeExtras']['presentationContext'] ) ) {
	$ctx = (string) $kuerzel['typeExtras']['presentationContext'];
}
$ctx = Node_Type::normalize_presentation_context( $ctx );
if ( 'symbol' !== $ctx ) {
	fwrite( STDERR, "FAIL: Kuerzel presentation context={$ctx}, expected symbol\n" );
	exit( 1 );
}

$meter_id = 0;
$terms    = get_terms(
	array(
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'name'       => 'Meter',
		'number'     => 5,
	)
);
if ( is_array( $terms ) ) {
	foreach ( $terms as $term ) {
		if ( $term instanceof WP_Term ) {
			$meter_id = (int) $term->term_id;
			break;
		}
	}
}
if ( $meter_id <= 0 ) {
	fwrite( STDERR, "FAIL: Meter node not found\n" );
	exit( 1 );
}

Node_Presentation::fill_term_from_legacy( $meter_id );
$symbol = Node_Presentation::get( $meter_id, 'symbol' );
$short  = trim( (string) Tree_Model::get_short_description( $meter_id ) );
if ( '' === $symbol && '' !== $short && Node_Presentation::looks_like_symbol( $short ) ) {
	$symbol = $short;
}
if ( '' === $symbol ) {
	fwrite( STDERR, "FAIL: Meter has no presentation.symbol / shortDescription\n" );
	exit( 1 );
}

$sample_map = Sample_Data::name_map();
if ( isset( $sample_map['kuerzel'] ) || isset( $sample_map['symbol'] ) ) {
	fwrite( STDERR, "FAIL: hardcoded kuerzel/symbol still in Sample_Data::name_map\n" );
	exit( 1 );
}

echo "OK node_presentation id={$pres_id} name={$name}\n";
echo "OK Kuerzel type={$type_key} context={$ctx}\n";
echo "OK Meter id={$meter_id} symbol={$symbol}\n";
exit( 0 );
