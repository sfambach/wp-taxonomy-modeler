<?php
/**
 * Smoke: heir host can reorder own + inherited attributes locally (father unchanged).
 *
 * @package WTT
 */

require 'C:/devel/wordpress/wp-load.php';

use WTT\Attribute;

$tax = 'wtt_fs';
$ok  = true;

$find = static function ( string $name ) use ( $tax ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $tax,
			'name'       => $name,
			'hide_empty' => false,
			'number'     => 20,
		)
	);
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return null;
	}
	return $terms[0];
};

$percent = $find( 'Percent' );
$tol     = $find( 'Toleranz' );
if ( ! $percent || ! $tol ) {
	echo "FAIL: Percent or Toleranz not found\n";
	exit( 1 );
}

$pid = (int) $percent->term_id;
$tid = (int) $tol->term_id;
echo "Percent={$pid} Toleranz={$tid} parent={$tol->parent}\n";

$names = static function ( array $rows ): array {
	$out = array();
	foreach ( $rows as $r ) {
		$out[] = (string) ( $r['name'] ?? '' ) . ( ! empty( $r['inherited'] ) ? '(inh)' : '(own)' );
	}
	return $out;
};

$ids = static function ( array $rows ): array {
	$out = array();
	foreach ( $rows as $r ) {
		$out[] = Attribute::normalize_attr_id( $r['id'] ?? '' );
	}
	return $out;
};

$find_row = static function ( array $rows, string $name ) {
	foreach ( $rows as $i => $r ) {
		if ( $name === (string) ( $r['name'] ?? '' ) ) {
			return array( $i, $r );
		}
	}
	return array( -1, null );
};

/* Snapshot father order. */
$father_before = Attribute::effective_list( $tax, $pid );
$father_ids    = $ids( $father_before );
echo 'Percent order: ' . implode( ', ', $names( $father_before ) ) . "\n";

$child = Attribute::effective_list( $tax, $tid );
echo 'Toleranz start: ' . implode( ', ', $names( $child ) ) . "\n";

if ( count( $child ) < 2 ) {
	echo "FAIL: need at least 2 attributes on Toleranz\n";
	exit( 1 );
}

list( $sign_index, $sign_row ) = $find_row( $child, 'Sign' );
if ( ! $sign_row || ! empty( $sign_row['inherited'] ) ) {
	echo "FAIL: own Sign not found on Toleranz\n";
	exit( 1 );
}
$sign_id = Attribute::normalize_attr_id( $sign_row['id'] ?? '' );

/*
 * Normalize to Value, Unit, Sign (screenshot layout) then move Sign up past Unit.
 * If Sign is already last, skip the push-down loop.
 */
$target_last = count( $child ) - 1;
$guard       = 0;
while ( $sign_index < $target_last && $guard < 10 ) {
	$r = Attribute::reorder( $tax, $tid, $sign_id, 1 );
	if ( is_wp_error( $r ) ) {
		echo 'FAIL: push Sign down: ' . $r->get_error_message() . "\n";
		exit( 1 );
	}
	$child = Attribute::effective_list( $tax, $tid );
	list( $sign_index, $sign_row ) = $find_row( $child, 'Sign' );
	++$guard;
}
echo 'Toleranz normalized: ' . implode( ', ', $names( $child ) ) . "\n";

if ( $sign_index <= 0 ) {
	echo "FAIL: Sign still at top after normalize\n";
	exit( 1 );
}

$before_names = $names( $child );
$moved        = Attribute::reorder( $tax, $tid, $sign_id, -1 );
if ( is_wp_error( $moved ) ) {
	echo 'FAIL: reorder up: ' . $moved->get_error_message() . "\n";
	exit( 1 );
}

$child_after = Attribute::effective_list( $tax, $tid );
echo 'Toleranz after ↑: ' . implode( ', ', $names( $child_after ) ) . "\n";

list( $sign_after, ) = $find_row( $child_after, 'Sign' );
if ( $sign_after !== $sign_index - 1 ) {
	echo "FAIL: Sign index expected " . ( $sign_index - 1 ) . ", got {$sign_after}\n";
	echo '  before: ' . implode( ', ', $before_names ) . "\n";
	$ok = false;
}

/* Also move an inherited attr (Unit) to verify heir can reorder inherited peers. */
list( $unit_index, $unit_row ) = $find_row( $child_after, 'Unit' );
if ( $unit_row && ! empty( $unit_row['inherited'] ) && $unit_index > 0 ) {
	$unit_id = Attribute::normalize_attr_id( $unit_row['id'] ?? '' );
	$ru      = Attribute::reorder( $tax, $tid, $unit_id, -1 );
	if ( is_wp_error( $ru ) ) {
		echo 'FAIL: reorder inherited Unit: ' . $ru->get_error_message() . "\n";
		$ok = false;
	} else {
		$after_unit = Attribute::effective_list( $tax, $tid );
		list( $ui, ) = $find_row( $after_unit, 'Unit' );
		echo 'Toleranz after Unit↑: ' . implode( ', ', $names( $after_unit ) ) . "\n";
		if ( $ui !== $unit_index - 1 ) {
			echo "FAIL: Unit index expected " . ( $unit_index - 1 ) . ", got {$ui}\n";
			$ok = false;
		}
	}
}

$father_after = Attribute::effective_list( $tax, $pid );
if ( $ids( $father_after ) !== $father_ids ) {
	echo "FAIL: Percent order changed after child reorder\n";
	echo '  before: ' . implode( ',', $father_ids ) . "\n";
	echo '  after:  ' . implode( ',', $ids( $father_after ) ) . "\n";
	$ok = false;
} else {
	echo "Percent order unchanged OK\n";
}

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
