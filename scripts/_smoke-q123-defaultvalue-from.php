<?php
/**
 * Laragon smoke: RelationType defaultvalue_from (Q124) + create seed.
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file C:\Devel\Wordpress\source\wp-taxonomy-tree\scripts\_smoke-q123-defaultvalue-from.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Case_Data;
use WTT\Model_Data;
use WTT\Relation;
use WTT\Taxonomy;

$tax = Taxonomy::FS;

Case_Data::ensure_relation_types( $tax );
$bom = Case_Data::ensure_bauteilliste_model( $tax );
$list_id = (int) ( $bom['bauteillisteId'] ?? 0 );
$pos_id  = (int) ( $bom['positionId'] ?? 0 );

$type_id = Relation::find_type_id_by_name( $tax, Relation::TYPE_DEFAULTVALUE_FROM );
$ok_type = $type_id > 0 ? 'yes' : 'no';

$link_ok = 'no';
foreach ( Relation::list_outgoing_by_type_key( $tax, $pos_id, Relation::TYPE_DEFAULTVALUE_FROM ) as $edge ) {
	$name = strtolower( trim( (string) ( $edge['name'] ?? '' ) ) );
	$to   = (int) ( $edge['toId'] ?? 0 );
	if ( 'bauart' === $name && $to === $list_id ) {
		$link_ok = 'yes';
		break;
	}
}

$list_bauart_attr = '';
$pos_bauart_attr  = '';
foreach ( Attribute::list( $tax, $list_id ) as $row ) {
	if ( 'bauart' === strtolower( trim( (string) ( $row['name'] ?? '' ) ) ) ) {
		$list_bauart_attr = Attribute::normalize_attr_id( $row['id'] ?? '' );
		break;
	}
}
foreach ( Attribute::list( $tax, $pos_id ) as $row ) {
	if ( 'bauart' === strtolower( trim( (string) ( $row['name'] ?? '' ) ) ) ) {
		$pos_bauart_attr = Attribute::normalize_attr_id( $row['id'] ?? '' );
		break;
	}
}

$seed_ok = 'skip';
if ( '' !== $list_bauart_attr && '' !== $pos_bauart_attr ) {
	$marker = 'wtt-dvf-' . gmdate( 'His' );
	$parent = Model_Data::save(
		$tax,
		$list_id,
		array(
			'values' => array(
				$list_bauart_attr => $marker,
			),
		)
	);
	if ( is_wp_error( $parent ) ) {
		$seed_ok = 'parent_fail';
	} else {
		$parent_id = sanitize_key( (string) ( $parent['id'] ?? '' ) );
		$linked    = Model_Data::create_linked(
			$tax,
			$list_id,
			$parent_id,
			$pos_id,
			Model_Data::LINK_COMPOSITION,
			array()
		);
		if ( is_wp_error( $linked ) ) {
			$seed_ok = 'link_fail';
		} else {
			$child_vals = isset( $linked['child']['values'] ) && is_array( $linked['child']['values'] )
				? $linked['child']['values']
				: array();
			$got        = isset( $child_vals[ $pos_bauart_attr ] )
				? trim( (string) $child_vals[ $pos_bauart_attr ] )
				: '';
			$seed_ok    = ( $got === $marker ) ? 'yes' : 'no:' . $got;
		}
	}
}

echo 'defaultvalue_from_type=' . $ok_type . PHP_EOL;
echo 'bom_bauart_link=' . $link_ok . PHP_EOL;
echo 'create_seed=' . $seed_ok . PHP_EOL;
echo 'list_id=' . $list_id . ' position_id=' . $pos_id . PHP_EOL;
echo 'smoke=' . ( ( 'yes' === $ok_type && 'yes' === $link_ok && 'yes' === $seed_ok ) ? 'ok' : 'fail' ) . PHP_EOL;
