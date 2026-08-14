<?php
/**
 * One-shot Laragon smoke: Composition attribute columns keep edge UUID keys.
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file C:\Devel\Wordpress\source\wp-taxonomy-tree\scripts\_smoke-q123-composition-cols.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Composition;
use WTT\Taxonomy;

$tax = Taxonomy::FS;
$kontakt = get_term_by( 'name', 'Kontakt', $tax );
if ( ! $kontakt instanceof WP_Term ) {
	fwrite( STDERR, "Kontakt term missing on {$tax}\n" );
	exit( 1 );
}

$host_id = (int) $kontakt->term_id;
$cols    = Composition::get_attribute_columns( $tax, $host_id );
$attrs   = Attribute::list( $tax, $host_id );

$first_attr = is_array( $attrs[0] ?? null ) ? $attrs[0] : null;
$first_col  = is_array( $cols[0] ?? null ) ? $cols[0] : null;

$attr_id = Attribute::normalize_attr_id( $first_attr['id'] ?? '' );
$col_id  = Attribute::normalize_attr_id( $first_col['id'] ?? '' );
$bad_int = (int) ( $first_attr['id'] ?? 0 );

$norm = Composition::normalize_rows(
	array(
		array(
			'id'    => 'smoke-row',
			'cells' => array(
				$col_id => 'ok',
			),
		),
	),
	$cols
);
$cell_ok = isset( $norm[0]['cells'][ $col_id ] ) && 'ok' === $norm[0]['cells'][ $col_id ];

echo 'WTT_VERSION=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . PHP_EOL;
echo 'kontakt_id=' . $host_id . PHP_EOL;
echo 'attr_count=' . count( $attrs ) . PHP_EOL;
echo 'col_count=' . count( $cols ) . PHP_EOL;
echo 'first_attr_id=' . $attr_id . PHP_EOL;
echo 'first_col_id=' . $col_id . PHP_EOL;
echo 'first_attr_int_cast=' . $bad_int . PHP_EOL;
echo 'ids_match=' . ( $attr_id !== '' && $attr_id === $col_id ? 'yes' : 'no' ) . PHP_EOL;
echo 'normalize_rows_cell=' . ( $cell_ok ? 'ok' : 'fail' ) . PHP_EOL;

if ( count( $attrs ) > 0 && count( $cols ) < 1 ) {
	fwrite( STDERR, "FAIL: attributes present but get_attribute_columns empty\n" );
	exit( 1 );
}
if ( $attr_id !== '' && $attr_id !== $col_id ) {
	fwrite( STDERR, "FAIL: column id != attribute edge id\n" );
	exit( 1 );
}
if ( $attr_id !== '' && $bad_int > 0 && (string) $bad_int !== $attr_id && $attr_id === $col_id ) {
	echo "prefix_hazard_avoided=yes\n";
}
if ( ! $cell_ok && '' !== $col_id ) {
	fwrite( STDERR, "FAIL: normalize_rows dropped edge cell key\n" );
	exit( 1 );
}

echo "smoke=ok\n";
