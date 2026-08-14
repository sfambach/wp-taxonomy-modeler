<?php
/**
 * One-shot Laragon smoke: orphan `_wtt_attribute_slot` purge (Q123).
 *
 * Plants a true orphan slot, clears the one-shot flag, runs maybe_migrate,
 * asserts orphan deleted + parked Zeile/Kopf/Fuss kept.
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file .../scripts/_smoke-q123-orphan-slots.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Attribute_Q123_Migrate;
use WTT\Taxonomy;

$tax = Taxonomy::FS;

$planted = wp_insert_term(
	'wtt-smoke-orphan-slot-' . wp_generate_password( 6, false, false ),
	$tax,
	array( 'parent' => 0 )
);
if ( is_wp_error( $planted ) || empty( $planted['term_id'] ) ) {
	echo "plant_fail\n";
	exit( 1 );
}
$orphan_id = (int) $planted['term_id'];
update_term_meta( $orphan_id, Attribute::META_KEY_SLOT, '1' );

$band_ids = array();
foreach ( array( 'Zeile', 'Kopf', 'Fuss' ) as $band ) {
	$t = get_term_by( 'name', $band, $tax );
	if ( $t instanceof WP_Term && Attribute::is_slot( (int) $t->term_id ) ) {
		$band_ids[ $band ] = (int) $t->term_id;
	}
}

$flags = get_option( Attribute_Q123_Migrate::OPTION_ORPHAN_SLOTS_PURGED, array() );
if ( ! is_array( $flags ) ) {
	$flags = array();
}
unset( $flags[ $tax ] );
update_option( Attribute_Q123_Migrate::OPTION_ORPHAN_SLOTS_PURGED, $flags, false );

Attribute_Q123_Migrate::maybe_migrate( $tax );

$orphan_gone = ! Attribute::is_slot( $orphan_id ) && ! ( get_term( $orphan_id, $tax ) instanceof WP_Term );
$bands_kept  = true;
foreach ( $band_ids as $bid ) {
	if ( ! Attribute::is_slot( $bid ) || ! ( get_term( $bid, $tax ) instanceof WP_Term ) ) {
		$bands_kept = false;
		break;
	}
}

$flags_after = get_option( Attribute_Q123_Migrate::OPTION_ORPHAN_SLOTS_PURGED, array() );
$purge_flag  = is_array( $flags_after ) && ! empty( $flags_after[ $tax ] );

/* Cleanup if plant somehow survived. */
if ( get_term( $orphan_id, $tax ) instanceof WP_Term ) {
	wp_delete_term( $orphan_id, $tax );
}

$ok = $orphan_gone && $bands_kept && $purge_flag
	&& defined( 'WTT_VERSION' ) && WTT_VERSION === '0.0.429';

echo 'WTT_VERSION=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . "\n";
echo 'orphan_id=' . $orphan_id . "\n";
echo 'orphan_gone=' . ( $orphan_gone ? 'yes' : 'no' ) . "\n";
echo 'bands_kept=' . ( $bands_kept ? 'yes' : 'no' ) . "\n";
echo 'band_count=' . count( $band_ids ) . "\n";
echo 'purge_flag=' . ( $purge_flag ? 'yes' : 'no' ) . "\n";
echo 'orphan_slots_purge=' . ( $ok ? 'yes' : 'no' ) . "\n";
echo 'smoke=' . ( $ok ? 'ok' : 'FAIL' ) . "\n";

exit( $ok ? 0 : 1 );
