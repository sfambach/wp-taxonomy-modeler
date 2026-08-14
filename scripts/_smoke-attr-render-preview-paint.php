<?php
/**
 * Smoke: attribute walk Render must win nested field paint (0.0.510).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$js   = file_get_contents( $root . '/assets/js/wtt-object-render.js' );
$ver  = file_get_contents( $root . '/wp-taxonomy-tree.php' );

$fn_start = strpos( $js, 'function resolveFieldPreferredPaint' );
$fn_end   = strpos( $js, 'function normalizeFieldPreferredPaintId', $fn_start );
$body     = $fn_start !== false && $fn_end !== false
	? substr( $js, $fn_start, $fn_end - $fn_start )
	: '';

$checks = array(
	'resolveFieldPreferredPaint exists' => $body !== '',
	'slot wins over type'               => false !== strpos( $body, 'if (slotPref)' )
		&& false !== strpos( $body, 'return slotPref' ),
	'no objectLayouts discard'          => false === strpos( $body, 'objectLayouts' ),
	'structure embed prefers slot'      => false !== strpos(
		$js,
		'(field && (field.preferredRender || field.typePreferredRender))'
	),
	'version 0.0.510'                   => false !== strpos( $ver, "define( 'WTT_VERSION', '0.0.510' )" ),
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
