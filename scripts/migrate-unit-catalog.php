<?php
/**
 * One-shot: migrate Konstanten → Data Types/Unit + dedupe duplicate prefixes (Q120).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

require_once 'C:/devel/wordpress/wp-load.php';

if ( ! class_exists( '\WTT\Case_Data' ) ) {
	fwrite( STDERR, "WTT Case_Data not loaded — is the plugin active?\n" );
	exit( 1 );
}

\WTT\Case_Data::ensure_unit_catalog( 'wtt_fs' );

$prefixes = \WTT\Case_Data::find_term_by_path(
	'wtt_fs',
	array( 'Fallstudie', 'Definition', 'Data Types', 'Präfixe' )
);
echo 'prefixes_root=' . $prefixes . PHP_EOL;
if ( $prefixes > 0 ) {
	$kids = get_terms(
		array(
			'taxonomy'   => 'wtt_fs',
			'parent'     => $prefixes,
			'hide_empty' => false,
			'number'     => 0,
		)
	);
	$names = array();
	if ( is_array( $kids ) ) {
		foreach ( $kids as $t ) {
			if ( $t instanceof WP_Term ) {
				echo $t->term_id . ' ' . $t->name . PHP_EOL;
				$names[ $t->name ] = ( $names[ $t->name ] ?? 0 ) + 1;
			}
		}
	}
	foreach ( $names as $n => $c ) {
		if ( $c > 1 ) {
			echo "STILL DUP $n x$c\n";
		}
	}
}
echo "done\n";
