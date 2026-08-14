<?php
/**
 * Smoke: nested structure embed uses type Preferred + typePresentation (0.0.558).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$js   = (string) file_get_contents( $root . '/assets/js/wtt-object-render.js' );
$php  = (string) file_get_contents( $root . '/includes/class-attribute.php' );
$ver  = (string) file_get_contents( $root . '/wp-taxonomy-tree.php' );

$checks = array(
	'structureTypeHostFromField'       => false !== strpos( $js, 'function structureTypeHostFromField' ),
	'never outer hostPresentation'     => false !== strpos( $js, 'Never fall back to outer hostPresentation' )
		|| false !== strpos( $js, 'Never use the outer Form host presentation' ),
	'seedStructureFieldStore'          => false !== strpos( $js, 'function seedStructureFieldStore' ),
	'typePresentation on decorate_row' => false !== strpos( $php, "row['typePresentation']" ),
	'normalizeAttributes keeps label'  => false !== strpos( $js, 'typeDisplayName' )
		&& false !== strpos( $js, 'attr.typeLabel || attr.typeDisplayName || attr.typeName' ),
	'version 0.0.558'                  => false !== strpos( $ver, "define( 'WTT_VERSION', '0.0.558' )" ),
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
