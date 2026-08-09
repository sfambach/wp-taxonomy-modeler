<?php
/**
 * One-shot: migrate Konstanten → Data Types/Unit (Q120).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	require_once 'C:/devel/wordpress/wp-load.php';
}

if ( ! class_exists( '\WTT\Case_Data' ) ) {
	fwrite( STDERR, "WTT Case_Data not loaded — is the plugin active?\n" );
	exit( 1 );
}

\WTT\Case_Data::ensure_unit_catalog( 'wtt_fs' );

$checks = array(
	'Data Types/Präfixe'              => array( 'Fallstudie', 'Definition', 'Data Types', 'Präfixe' ),
	'Unit/With prefix'                => array( 'Fallstudie', 'Definition', 'Data Types', 'Unit', 'With prefix' ),
	'Unit/Without prefix'             => array( 'Fallstudie', 'Definition', 'Data Types', 'Unit', 'Without prefix' ),
	'Without prefix/Währung'          => array( 'Fallstudie', 'Definition', 'Data Types', 'Unit', 'Without prefix', 'Währung' ),
	'Data Types/Bauformen'            => array( 'Fallstudie', 'Definition', 'Data Types', 'Bauformen' ),
	'legacy Konstanten (expect 0)'    => array( 'Fallstudie', 'Definition', 'Konstanten' ),
);

foreach ( $checks as $label => $path ) {
	$id = \WTT\Case_Data::find_term_by_path( 'wtt_fs', $path );
	echo $label . ' => ' . $id . PHP_EOL;
}

echo "done\n";
