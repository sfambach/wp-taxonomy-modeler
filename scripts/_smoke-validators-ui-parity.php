<?php
/**
 * Smoke: shared validators table chrome (type / attr / walk).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$js   = (string) file_get_contents( $root . '/assets/js/tree-admin.js' );
$php  = (string) file_get_contents( $root . '/wp-taxonomy-tree.php' );
$i18n = (string) file_get_contents( $root . '/includes/class-tree-admin.php' );

$fail = 0;
foreach (
	array(
		'function buildValidatorsEditor',
		'function buildNodeValidatorsEditor',
		'wtt-validators-table__empty',
		'validatorsEmptyHint',
		'wtt-validators-editor--attr-detail',
		'wtt-validators-editor--walk',
		'wtt-validators-editor--under-preferred',
	) as $needle
) {
	if ( false === strpos( $js, $needle ) ) {
		fwrite( STDERR, 'FAIL tree-admin missing ' . $needle . PHP_EOL );
		++$fail;
	}
}

/* Legacy parallel UIs must not remain as primary paint paths. */
foreach (
	array(
		"className: 'wtt-attributes__validators-list'",
		"className: 'wtt-attributes__walk-validators'",
	) as $legacy
) {
	if ( false !== strpos( $js, $legacy ) ) {
		fwrite( STDERR, 'FAIL legacy validators UI still present: ' . $legacy . PHP_EOL );
		++$fail;
	}
}

if ( false === strpos( $i18n, 'validatorsEmptyHint' ) ) {
	fwrite( STDERR, "FAIL i18n validatorsEmptyHint missing\n" );
	++$fail;
}

if ( false === strpos( $php, "define( 'WTT_VERSION', '0.0.519' )" ) && false === strpos( $php, "define( 'WTT_VERSION', '0.0.518' )" ) ) {
	/* Accept current patch while charset/UI smokes share the tree. */
	if ( ! preg_match( "/define\\( 'WTT_VERSION', '0\\.0\\.\\d+' \\)/", $php ) ) {
		fwrite( STDERR, "FAIL WTT_VERSION missing\n" );
		++$fail;
	}
}

if ( $fail > 0 ) {
	fwrite( STDERR, "validators-ui-parity smoke: {$fail} failure(s)\n" );
	exit( 1 );
}

echo "validators-ui-parity smoke: ok\n";
exit( 0 );
