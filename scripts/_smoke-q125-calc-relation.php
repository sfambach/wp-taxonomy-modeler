<?php
/**
 * Laragon smoke: RelationType calc (Q125) + default_from seed (Q124 behaviour).
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file C:\Devel\Wordpress\source\wp-taxonomy-tree\scripts\_smoke-q125-calc-relation.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Case_Data;
use WTT\Relation;
use WTT\Taxonomy;

$tax = Taxonomy::FS;

Case_Data::ensure_relation_types( $tax );
$bom     = Case_Data::ensure_bauteilliste_model( $tax );
$list_id = (int) ( $bom['bauteillisteId'] ?? 0 );
$pos_id  = (int) ( $bom['positionId'] ?? 0 );

$calc_id = Relation::find_type_id_by_name( $tax, Relation::TYPE_CALC );
$ok_calc = $calc_id > 0 ? 'yes' : 'no';

$alias_id = Relation::find_type_id_by_name( $tax, Relation::TYPE_DEFAULTVALUE_FROM );
$ok_alias = ( $alias_id > 0 && ( $alias_id === $calc_id || Relation::type_keys_match( 'calc', 'defaultvalue_from' ) ) )
	? 'yes'
	: 'no';

$link_ok = 'no';
$op_ok   = 'no';
foreach ( Relation::list_outgoing_by_type_key( $tax, $pos_id, Relation::TYPE_CALC ) as $edge ) {
	$name = strtolower( trim( (string) ( $edge['name'] ?? '' ) ) );
	$to   = (int) ( $edge['toId'] ?? 0 );
	if ( 'bauart' !== $name || $to !== $list_id ) {
		continue;
	}
	$link_ok = 'yes';
	if ( Relation::is_default_from_calc_edge( $edge ) ) {
		$op_ok = 'yes';
	}
	break;
}

$assignable_hides_legacy = 'yes';
foreach ( Relation::get_assignable_type_options( $tax ) as $opt ) {
	if ( 'defaultvalue_from' === strtolower( (string) ( $opt['name'] ?? '' ) ) ) {
		$assignable_hides_legacy = 'no';
		break;
	}
}

$ok = ( 'yes' === $ok_calc && 'yes' === $ok_alias && 'yes' === $link_ok && 'yes' === $op_ok && 'yes' === $assignable_hides_legacy )
	? 'ok'
	: 'fail';

echo 'smoke=' . $ok . PHP_EOL;
echo 'calc_type=' . $ok_calc . PHP_EOL;
echo 'alias_match=' . $ok_alias . PHP_EOL;
echo 'bauart_link=' . $link_ok . PHP_EOL;
echo 'op_default_from=' . $op_ok . PHP_EOL;
echo 'legacy_hidden=' . $assignable_hides_legacy . PHP_EOL;
echo 'plugin=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . PHP_EOL;
