<?php
/**
 * Inventory leftover `_wtt_attribute_slot` terms on wtt_fs (Laragon).
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file .../scripts/_smoke-q123-slot-inventory.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Node_Type;
use WTT\Relation;

$tax   = 'wtt_fs';
$terms = get_terms(
	array(
		'taxonomy'   => $tax,
		'hide_empty' => false,
		'number'     => 0,
		'fields'     => 'ids',
	)
);
if ( ! is_array( $terms ) ) {
	echo "no_terms\n";
	exit( 1 );
}

$band_names = array( 'Zeile', 'Kopf', 'Fuss' );
$slots      = array();

foreach ( $terms as $tid ) {
	$tid = (int) $tid;
	if ( ! Attribute::is_slot( $tid ) ) {
		continue;
	}
	$t = get_term( $tid, $tax );
	if ( ! $t instanceof WP_Term ) {
		continue;
	}
	$type_id   = Node_Type::get_type_id( $tid );
	$type_name = '';
	if ( $type_id > 0 ) {
		$tt = get_term( $type_id, $tax );
		if ( $tt instanceof WP_Term ) {
			$type_name = $tt->name;
		}
	}
	$attr_in = array();
	$any_to  = array();
	foreach ( $terms as $hid ) {
		$hid = (int) $hid;
		foreach ( Relation::list_outgoing( $tax, $hid ) as $e ) {
			$to = (int) ( $e['toId'] ?? 0 );
			if ( $to !== $tid ) {
				continue;
			}
			$row = array(
				'from'    => $hid,
				'edgeId'  => (string) ( $e['id'] ?? '' ),
				'typeKey' => (string) ( $e['typeKey'] ?? '' ),
				'name'    => (string) ( $e['name'] ?? '' ),
			);
			$any_to[] = $row;
			if ( Attribute::is_attribute_binding( $row['typeKey'] ) ) {
				$attr_in[] = $row;
			}
		}
	}
	$slots[] = array(
		'id'       => $tid,
		'name'     => $t->name,
		'parent'   => (int) $t->parent,
		'typeId'   => $type_id,
		'typeName' => $type_name,
		'attrIn'   => $attr_in,
		'anyTo'    => $any_to,
	);
}

echo 'WTT_VERSION=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . PHP_EOL;
echo 'slot_count=' . count( $slots ) . PHP_EOL;

$parked = 0;
$orphan = 0;
$bound  = 0;

foreach ( $slots as $s ) {
	$is_band = in_array( $s['name'], $band_names, true );
	$n_attr  = count( $s['attrIn'] );
	$n_any   = count( $s['anyTo'] );
	if ( $n_attr > 0 || $n_any > 0 ) {
		$kind = $is_band ? 'parked-band' : 'referenced';
		if ( $is_band ) {
			++$parked;
		} else {
			++$bound;
		}
	} elseif ( $is_band ) {
		$kind = 'parked-band-orphan';
		++$parked;
	} else {
		$kind = 'true-orphan';
		++$orphan;
	}
	echo sprintf(
		"%s id=%d name=%s parent=%d type=%s(%d) attrIn=%d anyTo=%d kind=%s\n",
		$is_band ? 'BAND' : 'SLOT',
		$s['id'],
		$s['name'],
		$s['parent'],
		'' !== $s['typeName'] ? $s['typeName'] : '-',
		$s['typeId'],
		$n_attr,
		$n_any,
		$kind
	);
	foreach ( $s['anyTo'] as $e ) {
		$hf = get_term( (int) $e['from'], $tax );
		echo sprintf(
			"  from=%d(%s) edge=%s typeKey=%s name=%s\n",
			$e['from'],
			$hf instanceof WP_Term ? $hf->name : '?',
			$e['edgeId'],
			$e['typeKey'],
			$e['name']
		);
	}
}

echo 'summary_parked=' . $parked . PHP_EOL;
echo 'summary_referenced_nonband=' . $bound . PHP_EOL;
echo 'summary_true_orphan=' . $orphan . PHP_EOL;
echo "inventory=ok\n";
