<?php
/**
 * Smoke: Q116 sole-required ListChooser lock wiring.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/assets/js/tree-admin.js' );
$node  = file_get_contents( $root . '/assets/js/wtt-node-render.js' );
$obj_c = file_get_contents( $root . '/assets/css/wtt-object-render.css' );
$adm_c = file_get_contents( $root . '/assets/css/tree-admin.css' );
$ver   = file_get_contents( $root . '/wp-taxonomy-tree.php' );

$checks = array(
	'memberListSelectAllowsEmpty'     => false !== strpos( $admin, 'function memberListSelectAllowsEmpty' ),
	'preferred lock counts all opts'  => false !== strpos( $admin, 'sole Preferred choice' )
		&& false !== strpos( $admin, 'countRealSelectOptions(preferredSelect)' ),
	'praefix Mult drives allowEmpty'  => false !== strpos( $admin, 'allowEmpty: memberListSelectAllowsEmpty(praefix)' ),
	'prefix sole lock in node-render' => false !== strpos( $node, 'required Praefix with one allowed prefix' ),
	'object-render sole CSS'          => false !== strpos( $obj_c, 'select.is-sole-locked' ),
	'tree-admin sole CSS'             => false !== strpos( $adm_c, 'select.is-sole-locked' ),
	'version 0.0.509'                 => false !== strpos( $ver, "define( 'WTT_VERSION', '0.0.509' )" ),
);

$ok = true;
foreach ( $checks as $label => $pass ) {
	echo ( $pass ? 'OK  ' : 'FAIL' ) . ' ' . $label . PHP_EOL;
	if ( ! $pass ) {
		$ok = false;
	}
}
echo $ok ? 'smoke=ok' . PHP_EOL : 'smoke=fail' . PHP_EOL;
exit( $ok ? 0 : 1 );
