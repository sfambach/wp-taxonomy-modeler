<?php
/**
 * Smoke: node_presentation / display_node_name exempt from RO-needs-default.
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

if ( ! defined( 'ABSPATH' ) || ! class_exists( '\WTT\Attribute_Validator' ) ) {
	fwrite( STDERR, "FAIL: WordPress / plugin not loaded\n" );
	exit( 1 );
}

$ok = true;
foreach ( array( 'node_presentation', 'display_node_name' ) as $type_key ) {
	$row = array(
		'name'        => 'Name',
		'typeKey'     => $type_key,
		'readonly'    => true,
		'fixedValues' => array(),
	);
	if ( \WTT\Attribute_Validator::row_readonly_without_default( $row ) ) {
		echo "FAIL: {$type_key} should be exempt\n";
		$ok = false;
	} else {
		echo "OK: {$type_key} exempt\n";
	}
}

$row_montage = array(
	'name'        => 'Montage Art',
	'typeKey'     => 'bauteil monatge typen',
	'readonly'    => true,
	'fixedValues' => array(),
	'fixedMode'   => 'catalog',
);

if ( ! \WTT\Attribute_Validator::row_readonly_without_default( $row_montage ) ) {
	echo "FAIL: Montage Art RO without default should flag\n";
	$ok = false;
} else {
	echo "OK: Montage Art still flags\n";
}

$row_montage['fixedValues'] = array( '5178' );
if ( \WTT\Attribute_Validator::row_readonly_without_default( $row_montage ) ) {
	echo "FAIL: Montage Art with default should pass\n";
	$ok = false;
} else {
	echo "OK: Montage Art with default passes\n";
}

echo $ok ? "ALL OK\n" : "FAILED\n";
exit( $ok ? 0 : 1 );
