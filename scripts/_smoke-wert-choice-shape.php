<?php
/**
 * Dump Base unit / Praefix choiceOptions shape on Wert walk.
 *
 * @package WTT
 */

require 'C:/devel/wordpress/wp-load.php';

use WTT\Attribute;

$tax  = 'wtt_fs';
$host = 0;
$terms = get_terms(
	array(
		'taxonomy'   => $tax,
		'name'       => 'Widerstand',
		'hide_empty' => false,
		'number'     => 1,
	)
);
if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
	$host = (int) $terms[0]->term_id;
}

foreach ( Attribute::list( $tax, $host ) as $row ) {
	if ( 0 !== strcasecmp( (string) ( $row['name'] ?? '' ), 'Wert' ) ) {
		continue;
	}
	Attribute::ensure_settings_walk_cache( $tax, $host, (string) $row['id'] );
	foreach ( Attribute::list( $tax, $host ) as $r2 ) {
		if ( (string) ( $r2['id'] ?? '' ) !== (string) ( $row['id'] ?? '' ) ) {
			continue;
		}
		foreach ( (array) ( $r2['settingsWalk'] ?? array() ) as $lv ) {
			$edge = (string) ( $lv['edgeName'] ?? '' );
			if ( ! in_array( $edge, array( 'Base unit', 'Praefix', 'Praefixe' ), true ) && (int) ( $lv['depth'] ?? 0 ) !== 0 ) {
				continue;
			}
			$opts = isset( $lv['choiceOptions'] ) && is_array( $lv['choiceOptions'] ) ? $lv['choiceOptions'] : array();
			echo "edge={$edge} name=" . ( $lv['name'] ?? '' ) . ' supports=' . ( ! empty( $lv['supportsChoiceFilter'] ) ? 'Y' : 'N' ) . ' count=' . count( $opts ) . "\n";
			foreach ( array_slice( $opts, 0, 3 ) as $o ) {
				echo '  sample=' . wp_json_encode( $o, JSON_UNESCAPED_UNICODE ) . "\n";
			}
			if ( count( $opts ) > 3 ) {
				echo '  …+' . ( count( $opts ) - 3 ) . "\n";
			}
		}
	}
}

/* Unit type own attrs for comparison */
$ut = get_terms(
	array(
		'taxonomy'   => $tax,
		'name'       => 'Unit type',
		'hide_empty' => false,
		'number'     => 3,
	)
);
foreach ( (array) $ut as $t ) {
	echo "=== Unit type {$t->term_id} own attrs ===\n";
	foreach ( Attribute::list_own( $tax, (int) $t->term_id ) as $a ) {
		echo $a['name'] . ' fixedMode=' . ( $a['fixedMode'] ?? '' ) . ' opts=' . count( $a['fixedOptions'] ?? array() ) . ' walk=' . count( $a['settingsWalk'] ?? array() ) . "\n";
	}
}
