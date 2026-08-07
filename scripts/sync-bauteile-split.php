<?php
/**
 * Sync Q83 Bauteile split on Fallstudie (wtt_fs) only.
 *
 * @package WTT
 */

$tax = WTT\Taxonomy::FS;
WTT\Case_Data::ensure_bauteile_catalog( $tax );

$arten_path = array( 'Fallstudie', 'Definition', 'Bauteilarten' );
$bau_path   = array( 'Fallstudie', 'Implementation', 'Bauteile' );

$arten = WTT\Demo_Data::find_term_by_path( $tax, $arten_path );
$bau   = WTT\Demo_Data::find_term_by_path( $tax, $bau_path );
echo "$tax Bauteilarten=$arten Bauteile=$bau\n";

$kinds = get_terms(
	array(
		'taxonomy'   => $tax,
		'parent'     => $arten,
		'hide_empty' => false,
		'number'     => 0,
	)
);
echo '  kinds=' . count( (array) $kinds ) . "\n";

$recs = get_terms(
	array(
		'taxonomy'   => $tax,
		'parent'     => $bau,
		'hide_empty' => false,
		'number'     => 0,
	)
);
$ex = 0;
$legacy_kinds = 0;
foreach ( (array) $recs as $r ) {
	if ( WTT\Demo_Data::is_catalog_example( (int) $r->term_id ) ) {
		++$ex;
		$tid = (int) get_term_meta( $r->term_id, '_wtt_type_id', true );
		$ty  = $tid > 0 ? get_term( $tid, $tax ) : null;
		if ( 'RC0603FR-071K0L' === $r->name ) {
			echo '  sample ' . $r->name . ' type=' . ( $ty ? $ty->name : '?' ) . "\n";
		}
	} else {
		++$legacy_kinds;
	}
}
echo "  records=$ex legacy_non_example_children=$legacy_kinds\n";

$w_lief = WTT\Demo_Data::find_term_by_path(
	$tax,
	array_merge( $arten_path, array( 'Widerstand', 'Lieferant' ) )
);
echo '  Widerstand.Lieferant=' . $w_lief . ' scope=' . get_term_meta( $w_lief, '_wtt_ref_scope_id', true ) . "\n";
