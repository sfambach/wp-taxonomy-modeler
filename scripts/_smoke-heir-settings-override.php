<?php
/**
 * Smoke: inherited Preferred / Walk Settings write host override map (father unchanged).
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via wp eval-file.\n" );
	exit( 1 );
}

$tax = 'wtt_fs';
$ok  = true;

$passiv = get_term_by( 'name', 'Passiv', $tax );
$kond   = get_term_by( 'name', 'Kondensator', $tax );
if ( ! $passiv instanceof WP_Term || ! $kond instanceof WP_Term ) {
	echo "SKIP: Passiv/Kondensator not found\n";
	exit( 0 );
}

$rows = \WTT\Attribute::list( $tax, (int) $kond->term_id );
$wert = null;
foreach ( $rows as $r ) {
	if ( is_array( $r ) && 'Wert' === (string) ( $r['name'] ?? '' ) && ! empty( $r['inherited'] ) ) {
		$wert = $r;
		break;
	}
}
if ( null === $wert ) {
	echo "SKIP: inherited Wert not found on Kondensator\n";
	exit( 0 );
}

$attr_id = (string) ( $wert['id'] ?? '' );
$father_edge = \WTT\Relation::list_outgoing( $tax, (int) $passiv->term_id );
$father_before = null;
foreach ( $father_edge as $e ) {
	if ( is_array( $e ) && (string) ( $e['id'] ?? '' ) === $attr_id ) {
		$father_before = isset( $e['settings'] ) ? $e['settings'] : null;
		break;
	}
}

$set = \WTT\Attribute::set_preferred_render( $tax, (int) $kond->term_id, $attr_id, 'FormRenderer' );
if ( is_wp_error( $set ) ) {
	echo 'FAIL set_preferred_render: ' . $set->get_error_message() . "\n";
	$ok = false;
} else {
	echo "OK set_preferred_render on heir\n";
}

$host_ov = \WTT\Attribute::get_settings_override_for_attr( (int) $kond->term_id, $attr_id );
$view_pref = is_array( $host_ov ) && isset( $host_ov['view']['preferredRenderer'] )
	? (string) $host_ov['view']['preferredRenderer']
	: '';
if ( 'FormRenderer' !== $view_pref && false === stripos( $view_pref, 'form' ) ) {
	echo 'FAIL host override preferred: ' . wp_json_encode( $host_ov ) . "\n";
	$ok = false;
} else {
	echo "OK host settings override has Preferred\n";
}

$father_edge2 = \WTT\Relation::list_outgoing( $tax, (int) $passiv->term_id );
$father_after = null;
foreach ( $father_edge2 as $e ) {
	if ( is_array( $e ) && (string) ( $e['id'] ?? '' ) === $attr_id ) {
		$father_after = isset( $e['settings'] ) ? $e['settings'] : null;
		break;
	}
}
if ( wp_json_encode( $father_before ) !== wp_json_encode( $father_after ) ) {
	echo "FAIL father edge settings mutated\n";
	$ok = false;
} else {
	echo "OK father edge unchanged\n";
}

$decorated = \WTT\Attribute::list( $tax, (int) $kond->term_id );
$pref_ok   = false;
foreach ( $decorated as $r ) {
	if ( is_array( $r ) && (string) ( $r['id'] ?? '' ) === $attr_id ) {
		$pref = (string) ( $r['preferredRender'] ?? '' );
		$has  = ! empty( $r['preferredRenderOverride'] );
		if ( $has && ( false !== stripos( $pref, 'form' ) || 'FormRenderer' === $pref ) ) {
			$pref_ok = true;
		}
		echo 'decorate preferred=' . $pref . ' override=' . ( $has ? '1' : '0' ) . "\n";
		break;
	}
}
if ( ! $pref_ok ) {
	echo "FAIL decorate did not surface heir Preferred override\n";
	$ok = false;
} else {
	echo "OK decorate surfaces override\n";
}

/* Cleanup. */
\WTT\Attribute::set_preferred_render( $tax, (int) $kond->term_id, $attr_id, '' );

echo $ok ? "ALL OK\n" : "FAILED\n";
exit( $ok ? 0 : 1 );
