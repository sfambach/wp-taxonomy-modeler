<?php
/**
 * Smoke / dump: Wert settingsWalk for Passiv + Widerstand.
 *
 * @package WTT
 */

require 'C:/devel/wordpress/wp-load.php';

use WTT\Attribute;
use WTT\Node_Type;

$tax = 'wtt_fs';

foreach ( array( 'Widerstand', 'Passiv' ) as $name ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $tax,
			'name'       => $name,
			'hide_empty' => false,
			'number'     => 3,
		)
	);
	foreach ( (array) $terms as $t ) {
		$host = (int) $t->term_id;
		echo "=== {$name} id={$host} ===\n";
		foreach ( Attribute::list( $tax, $host ) as $row ) {
			if ( 0 !== strcasecmp( (string) ( $row['name'] ?? '' ), 'Wert' ) ) {
				continue;
			}
			$attr_id = (string) ( $row['id'] ?? '' );
			echo 'type=' . ( $row['typeName'] ?? $row['typeLabel'] ?? '' ) . ' typeId=' . (int) ( $row['typeId'] ?? 0 ) . " attr={$attr_id}\n";
			Attribute::ensure_settings_walk_cache( $tax, $host, $attr_id );
			$fresh = null;
			foreach ( Attribute::list( $tax, $host ) as $r2 ) {
				if ( (string) ( $r2['id'] ?? '' ) === $attr_id ) {
					$fresh = $r2;
					break;
				}
			}
			$walk = is_array( $fresh ) ? ( $fresh['settingsWalk'] ?? array() ) : array();
			foreach ( (array) $walk as $i => $lv ) {
				if ( ! is_array( $lv ) ) {
					continue;
				}
				$nid = (int) ( $lv['nodeId'] ?? 0 );
				$bucket = $nid > 0 && Node_Type::is_unit_prefix_bucket( $tax, $nid ) ? 'Y' : 'N';
				echo sprintf(
					"#%d d=%s edge=%s name=%s node=%d bucket=%s choice=%s opts=%d cycle=%s\n",
					$i,
					(string) ( $lv['depth'] ?? '' ),
					(string) ( $lv['edgeName'] ?? '' ),
					(string) ( $lv['name'] ?? '' ),
					$nid,
					$bucket,
					! empty( $lv['supportsChoiceFilter'] ) ? 'Y' : 'N',
					is_array( $lv['choiceOptions'] ?? null ) ? count( $lv['choiceOptions'] ) : 0,
					! empty( $lv['cycleStopped'] ) ? 'Y' : 'N'
				);
			}
		}
	}
}

$wp = get_terms(
	array(
		'taxonomy'   => $tax,
		'name'       => 'With prefix',
		'hide_empty' => false,
		'number'     => 10,
	)
);
foreach ( (array) $wp as $w ) {
	$parent = get_term( (int) $w->parent, $tax );
	$pname  = $parent instanceof WP_Term ? $parent->name : '?';
	echo 'With prefix id=' . (int) $w->term_id . " parent={$pname} bucket=" .
		( Node_Type::is_unit_prefix_bucket( $tax, (int) $w->term_id ) ? 'Y' : 'N' ) . "\n";
	$edges = array();
	foreach ( array( 'besteht_aus', 'aggregation' ) as $bk ) {
		foreach ( WTT\Relation::list_outgoing_by_type_key( $tax, (int) $w->term_id, $bk ) as $e ) {
			$edges[] = ( $e['name'] ?? '' ) . '→' . (int) ( $e['toId'] ?? 0 );
		}
	}
	echo '  edges: ' . implode( ', ', $edges ) . "\n";
}
