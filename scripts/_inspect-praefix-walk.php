<?php
declare(strict_types=1);
require 'C:/devel/wordpress/wp-load.php';

use WTT\Attribute;
use WTT\Case_Data;
use WTT\Taxonomy;

$tax  = Taxonomy::FS;
$with = Case_Data::find_catalog_folder( $tax, 'with_prefix' );
echo "with={$with}\n";

foreach ( Attribute::list( $tax, $with ) as $row ) {
	$name = (string) ( $row['name'] ?? '' );
	if ( 'Praefix' !== $name && 'Präfix' !== $name ) {
		continue;
	}
	echo 'attr=' . $name . ' type=' . (string) ( $row['typeKey'] ?? $row['typeName'] ?? '' ) . "\n";
	echo 'walkMeta=' . wp_json_encode( $row['settingsWalkMeta'] ?? null ) . "\n";
	$walk = isset( $row['settingsWalk'] ) && is_array( $row['settingsWalk'] ) ? $row['settingsWalk'] : array();
	echo 'levels=' . count( $walk ) . "\n";
	foreach ( $walk as $i => $lv ) {
		if ( ! is_array( $lv ) ) {
			continue;
		}
		$opts = isset( $lv['choiceOptions'] ) && is_array( $lv['choiceOptions'] ) ? count( $lv['choiceOptions'] ) : 0;
		echo "[{$i}] depth=" . ( $lv['depth'] ?? '?' )
			. ' name=' . ( $lv['name'] ?? '' )
			. ' edge=' . ( $lv['edgeName'] ?? '' )
			. ' typeKey=' . ( $lv['typeKey'] ?? '' )
			. ' supportCF=' . ( empty( $lv['supportsChoiceFilter'] ) ? '0' : '1' )
			. " opts={$opts}\n";
	}
}
