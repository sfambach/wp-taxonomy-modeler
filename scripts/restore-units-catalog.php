<?php
/**
 * Restore Praefixe / Basiseinheit (wtt_tree) and Konstanten (wtt_fs) after Datentypen-only clear.
 *
 * Non-destructive: does not wipe Fallstudie Implementation / Bauteile.
 *
 * Usage:
 *   php wp-cli.phar --path=C:\devel\wordpress --user=admin eval-file scripts/restore-units-catalog.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file from the WordPress install.\n" );
	exit( 1 );
}

if ( ! class_exists( 'WTT\\Demo_Data' ) || ! class_exists( 'WTT\\Case_Data' ) || ! class_exists( 'WTT\\Taxonomy' ) ) {
	fwrite( STDERR, "PLUGIN_INACTIVE\n" );
	exit( 1 );
}

\WTT\Taxonomy::register_taxonomies();

$demo = \WTT\Demo_Data::ensure_praefixe_and_basiseinheit( \WTT\Taxonomy::TREE );
printf(
	"wtt_tree Praefixe/Basiseinheit created=%d existing=%d\n",
	(int) $demo['created'],
	(int) $demo['existing']
);

\WTT\Case_Data::ensure_konstanten( \WTT\Taxonomy::FS );
$fs_paths = array(
	array( 'Fallstudie', 'Definition', 'Konstanten' ),
	array( 'Fallstudie', 'Definition', 'Konstanten', 'Präfixe' ),
	array( 'Fallstudie', 'Definition', 'Konstanten', 'Basiseinheiten' ),
);
foreach ( $fs_paths as $path ) {
	$id = \WTT\Case_Data::find_term_by_path( \WTT\Taxonomy::FS, $path );
	$n  = 0;
	if ( $id > 0 ) {
		$kids = get_terms(
			array(
				'taxonomy'   => \WTT\Taxonomy::FS,
				'parent'     => $id,
				'hide_empty' => false,
				'fields'     => 'names',
			)
		);
		$n = is_array( $kids ) ? count( $kids ) : 0;
	}
	printf(
		"wtt_fs %s id=%d children=%d\n",
		implode( '/', $path ),
		$id,
		$n
	);
}

$tree_paths = array(
	array( 'BOM Testprojekt', 'Typen', 'Praefixe' ),
	array( 'BOM Testprojekt', 'Typen', 'Basiseinheit' ),
);
foreach ( $tree_paths as $path ) {
	$id = \WTT\Demo_Data::find_term_by_path( \WTT\Taxonomy::TREE, $path );
	$n  = 0;
	$names = array();
	if ( $id > 0 ) {
		$kids = get_terms(
			array(
				'taxonomy'   => \WTT\Taxonomy::TREE,
				'parent'     => $id,
				'hide_empty' => false,
				'fields'     => 'names',
			)
		);
		if ( is_array( $kids ) ) {
			$n     = count( $kids );
			$names = $kids;
		}
	}
	printf(
		"wtt_tree %s id=%d children=%d [%s]\n",
		implode( '/', $path ),
		$id,
		$n,
		implode( ', ', $names )
	);
}

echo "OK\n";
