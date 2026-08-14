<?php
declare(strict_types=1);
require 'C:/devel/wordpress/wp-load.php';

use WTT\Attribute;
use WTT\Case_Data;
use WTT\Taxonomy;

$tax = Taxonomy::FS;
$w   = Case_Data::find_catalog_folder( $tax, 'waehrung' );
echo "waehrung={$w}\n";
if ( $w <= 0 ) {
	exit( 0 );
}
foreach ( Attribute::list_own( $tax, $w ) as $a ) {
	echo 'attr ' . ( $a['name'] ?? '?' ) . ' type=' . ( $a['typeKey'] ?? '' ) . "\n";
}
$kids = get_terms(
	array(
		'taxonomy'   => $tax,
		'parent'     => $w,
		'hide_empty' => false,
		'number'     => 20,
	)
);
foreach ( (array) $kids as $k ) {
	if ( $k instanceof WP_Term ) {
		echo 'kid ' . $k->name . "\n";
	}
}
