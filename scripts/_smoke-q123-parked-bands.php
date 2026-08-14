<?php
/**
 * Q90 parked table bands (Zeile/Kopf/Fuss) — Attributes hide + Relations legacy flag.
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file .../scripts/_smoke-q123-parked-bands.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Attribute_Q123_Migrate;
use WTT\Relation;
use WTT\Tree_Model;

$tax = 'wtt_fs';

echo 'WTT_VERSION=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . PHP_EOL;

$tables = get_terms(
	array(
		'taxonomy'   => $tax,
		'name'       => 'table',
		'hide_empty' => false,
		'number'     => 1,
	)
);
if ( ! is_array( $tables ) || empty( $tables ) || ! $tables[0] instanceof WP_Term ) {
	echo "table_host=missing\n";
	echo "smoke=fail\n";
	exit( 1 );
}

$table_id = (int) $tables[0]->term_id;
echo 'table_id=' . $table_id . PHP_EOL;

/* Ensure names (idempotent; may set one-shot flag). */
$named = Attribute_Q123_Migrate::maybe_name_parked_band_edges( $tax );
echo 'name_fold_named=' . (int) ( $named['named'] ?? 0 ) . PHP_EOL;
$flags = get_option( Attribute_Q123_Migrate::OPTION_PARKED_BAND_NAMES, array() );
echo 'name_fold_flag=' . ( ! empty( $flags[ $tax ] ) ? 'yes' : 'no' ) . PHP_EOL;

$attrs = Attribute::list_own( $tax, $table_id );
$attr_names = array();
foreach ( $attrs as $row ) {
	$attr_names[] = (string) ( $row['name'] ?? '' );
}
$leaked = array_intersect( $attr_names, array( 'Zeile', 'Kopf', 'Fuss', 'Head', 'Line', 'Foot' ) );
echo 'attributes_own_count=' . count( $attrs ) . PHP_EOL;
echo 'attributes_hide_bands=' . ( empty( $leaked ) ? 'yes' : 'no' ) . PHP_EOL;
if ( ! empty( $leaked ) ) {
	echo 'leaked=' . implode( ',', $leaked ) . PHP_EOL;
}

$payload = Tree_Model::get_stored_relations_payload( $tax, $table_id );
$von     = $payload['von'] ?? array();
$parked  = 0;
$named_ok = 0;
foreach ( $von as $row ) {
	if ( empty( $row['parkedTableBand'] ) ) {
		continue;
	}
	++$parked;
	$name = (string) ( $row['name'] ?? '' );
	$to   = (string) ( $row['otherName'] ?? '' );
	if ( '' !== $name && $name === $to ) {
		++$named_ok;
	}
	if ( empty( $row['protected'] ) || empty( $row['typeLocked'] ) ) {
		echo "payload_lock_incomplete name={$name}\n";
	}
	echo sprintf(
		"band name=%s to=%s parked=%s locked=%s\n",
		$name,
		$to,
		! empty( $row['parkedTableBand'] ) ? 'yes' : 'no',
		( ! empty( $row['protected'] ) && ! empty( $row['typeLocked'] ) ) ? 'yes' : 'no'
	);
}

echo 'parked_payload=' . $parked . PHP_EOL;
echo 'parked_names_ok=' . ( $parked >= 3 && $named_ok >= 3 ? 'yes' : 'no' ) . PHP_EOL;

/* Spot-check edge detector on live outgoing. */
$edge_hits = 0;
foreach ( Relation::list_outgoing( $tax, $table_id ) as $edge ) {
	if ( Attribute::is_parked_table_band_edge( $tax, $edge ) ) {
		++$edge_hits;
	}
}
echo 'edge_detector=' . $edge_hits . PHP_EOL;

$ok = empty( $leaked )
	&& $parked >= 3
	&& $named_ok >= 3
	&& $edge_hits >= 3
	&& ! empty( $flags[ $tax ] );

echo 'parked_bands=' . ( $ok ? 'yes' : 'no' ) . PHP_EOL;
echo 'smoke=' . ( $ok ? 'ok' : 'fail' ) . PHP_EOL;
exit( $ok ? 0 : 1 );
