<?php
/**
 * Smoke: numeric/text bounds wire into chrome (range/spinner/length).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$js   = (string) file_get_contents( $root . '/assets/js/wtt-node-render.js' );
$obj  = (string) file_get_contents( $root . '/assets/js/wtt-object-render.js' );
$adm  = (string) file_get_contents( $root . '/assets/js/tree-admin.js' );

$fail = 0;
foreach (
	array(
		'function resolveValidatorsList',
		'function numericBoundsFromNode',
		'function textLengthBoundsFromNode',
		'hasMin',
		'hasMax',
		"id === 'int_min'",
		"id === 'text_max_length'",
		'attrs.min = String(numBounds.min)',
		'attrs.max = String(numBounds.max)',
		'attrs.minlength = String(lenBounds.min)',
		'areaAttrs.maxlength',
		'resolveValidatorsList(node)',
	) as $needle
) {
	if ( false === strpos( $js, $needle ) ) {
		fwrite( STDERR, 'FAIL wtt-node-render missing ' . $needle . PHP_EOL );
		++$fail;
	}
}

if ( false === strpos( $obj, 'validators: Array.isArray(attr.validators)' ) ) {
	fwrite( STDERR, "FAIL attributesToFields must copy validators\n" );
	++$fail;
}

if ( false === strpos( $adm, 'validators: Array.isArray(source.validators)' ) ) {
	fwrite( STDERR, "FAIL asPreviewField must copy validators\n" );
	++$fail;
}

$ver = (string) file_get_contents( $root . '/wp-taxonomy-tree.php' );
if ( ! preg_match( "/define\\( 'WTT_VERSION', '0\\.0\\.\\d+' \\)/", $ver ) ) {
	fwrite( STDERR, "FAIL WTT_VERSION missing\n" );
	++$fail;
}

if ( $fail > 0 ) {
	fwrite( STDERR, "bounds-chrome smoke: {$fail} failure(s)\n" );
	exit( 1 );
}

echo "bounds-chrome smoke: ok\n";
exit( 0 );
