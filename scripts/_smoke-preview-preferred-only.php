<?php
/**
 * Smoke: admin Preview Preferred-only law is wired in tree-admin.js.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$js   = file_get_contents( $root . '/assets/js/tree-admin.js' );
$php  = file_get_contents( $root . '/includes/class-tree-admin.php' );
$ver  = file_get_contents( $root . '/wp-taxonomy-tree.php' );

$checks = array(
	'effectiveHostPreferredRender'           => false !== strpos( $js, 'function effectiveHostPreferredRender' ),
	'unified uses effectiveHostPreferred'    => false !== strpos( $js, 'var preferred = effectiveHostPreferredRender(n);' )
		&& false !== strpos( $js, 'function renderUnifiedPreviewContent(n)' ),
	'no name-hardcode kontakt in structure'  => false === strpos( $js, "name === 'kontakt'" ),
	'no Tree+Form+Table registry hint'       => false === strpos(
		$js,
		'Rendered via NodeRendererRegistry (tree / form / table)'
	),
	'attr hosts skip automatic choice'       => false !== strpos(
		$js,
		'Attr hosts paint Preferred attribute chrome'
	),
	'choice one Preferred surface'           => false !== strpos(
		$js,
		'one Preferred surface only'
	),
	'draft Preferred never clobbered'        => false !== strpos(
		$js,
		'Never clobber a stored/draft Preferred'
	),
	'catalogChoiceNone em dash'              => false !== strpos(
		$php,
		"'catalogChoiceNone' => __( '—'"
	) || false !== strpos(
		$php,
		"'catalogChoiceNone' => __( '\xe2\x80\x94'"
	),
	'version 0.0.508'                        => false !== strpos( $ver, "define( 'WTT_VERSION', '0.0.508' )" ),
);

$ok = true;
foreach ( $checks as $label => $pass ) {
	echo ( $pass ? 'OK  ' : 'FAIL' ) . ' ' . $label . PHP_EOL;
	if ( ! $pass ) {
		$ok = false;
	}
}

/* Live host Preferred when WP is bootstrapped. */
$wp_load = 'C:/Devel/Wordpress/wp-load.php';
if ( is_readable( $wp_load ) ) {
	require_once $wp_load;
	$tax = 'wtt_fs';
	$terms = get_terms(
		array(
			'taxonomy'   => $tax,
			'hide_empty' => false,
			'name'       => 'Bauteillisten Position',
		)
	);
	if ( ! is_wp_error( $terms ) && ! empty( $terms[0] ) ) {
		$id   = (int) $terms[0]->term_id;
		$pref = (string) get_term_meta( $id, '_wtt_preferred_render', true );
		echo 'LIVE Bauteillisten Position id=' . $id . ' preferred=' . ( $pref !== '' ? $pref : '(empty)' ) . PHP_EOL;
		if ( $pref !== '' && 'TableRenderer' !== $pref && 'FormRenderer' !== $pref ) {
			echo 'NOTE unusual Preferred value (still Preferred-only paint).' . PHP_EOL;
		}
	} else {
		echo 'LIVE host not found (ok for CI).' . PHP_EOL;
	}
}

echo $ok ? 'smoke=ok' . PHP_EOL : 'smoke=fail' . PHP_EOL;
exit( $ok ? 0 : 1 );
