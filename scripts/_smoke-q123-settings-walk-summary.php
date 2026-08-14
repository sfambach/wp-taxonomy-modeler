<?php
/**
 * One-shot Laragon smoke: decorate_row settingsWalk summary (nested composition).
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file C:\Devel\Wordpress\source\wp-taxonomy-tree\scripts\_smoke-q123-settings-walk-summary.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Settings_Walk;
use WTT\Taxonomy;

$tax = Taxonomy::FS;

$hosts = array( 'Passiv', 'Platine', 'Kondensator', 'Bauteilliste', 'Widerstand', 'Preis', 'Kontakt' );
$host  = null;
$pick  = null;
foreach ( $hosts as $name ) {
	$term = get_term_by( 'name', $name, $tax );
	if ( ! $term instanceof WP_Term ) {
		continue;
	}
	foreach ( Attribute::list_own( $tax, (int) $term->term_id ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$meta = isset( $row['settingsWalkMeta'] ) && is_array( $row['settingsWalkMeta'] )
			? $row['settingsWalkMeta']
			: array();
		if ( ! Settings_Walk::walk_is_nested( $meta ) ) {
			continue;
		}
		$host = $term;
		$pick = $row;
		break;
	}
	if ( null !== $pick ) {
		break;
	}
}

if ( ! $host instanceof WP_Term || null === $pick ) {
	fwrite( STDERR, "No nested-walk own attr on smoke hosts (" . implode( ', ', $hosts ) . ")\n" );
	exit( 1 );
}

$host_id   = (int) $host->term_id;
$attr_id   = Attribute::normalize_attr_id( $pick['id'] ?? '' );
$attr_name = (string) ( $pick['name'] ?? '' );
$meta      = isset( $pick['settingsWalkMeta'] ) && is_array( $pick['settingsWalkMeta'] )
	? $pick['settingsWalkMeta']
	: array();
$summary   = isset( $pick['settingsWalk'] ) && is_array( $pick['settingsWalk'] )
	? $pick['settingsWalk']
	: array();

$node_count = (int) ( $meta['nodeCount'] ?? 0 );
$depth      = (int) ( $meta['depth'] ?? 0 );
$levels     = count( $summary );
$has_names  = false;
$has_pref   = false;
$has_ids    = true;
$id_count   = 0;
foreach ( $summary as $level ) {
	if ( ! is_array( $level ) ) {
		continue;
	}
	if ( '' !== (string) ( $level['name'] ?? '' ) || '' !== (string) ( $level['edgeName'] ?? '' ) ) {
		$has_names = true;
	}
	if ( '' !== (string) ( $level['preferred'] ?? '' ) ) {
		$has_pref = true;
	}
	$nid = (int) ( $level['nodeId'] ?? 0 );
	if ( $nid > 0 ) {
		++$id_count;
	} else {
		$has_ids = false;
	}
}

$ok = $node_count > 1 && $levels > 0 && $has_names && $has_ids && $id_count === $levels;

echo 'WTT_VERSION=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . "\n";
echo 'host=' . $host->name . ' id=' . $host_id . "\n";
echo 'attr_id=' . $attr_id . "\n";
echo 'attr_name=' . $attr_name . "\n";
echo 'nodeCount=' . $node_count . "\n";
echo 'depth=' . $depth . "\n";
echo 'settingsWalk_levels=' . $levels . "\n";
echo 'has_names=' . ( $has_names ? 'yes' : 'no' ) . "\n";
echo 'has_preferred=' . ( $has_pref ? 'yes' : 'no' ) . "\n";
echo 'has_nodeIds=' . ( $has_ids && $id_count === $levels ? 'yes' : 'no' ) . "\n";
if ( isset( $summary[0] ) && is_array( $summary[0] ) ) {
	echo 'root_name=' . (string) ( $summary[0]['name'] ?? '' ) . "\n";
	echo 'root_nodeId=' . (int) ( $summary[0]['nodeId'] ?? 0 ) . "\n";
	echo 'root_preferred=' . (string) ( $summary[0]['preferred'] ?? '' ) . "\n";
}
if ( isset( $summary[1] ) && is_array( $summary[1] ) ) {
	echo 'child_edgeName=' . (string) ( $summary[1]['edgeName'] ?? '' ) . "\n";
	echo 'child_name=' . (string) ( $summary[1]['name'] ?? '' ) . "\n";
	echo 'child_nodeId=' . (int) ( $summary[1]['nodeId'] ?? 0 ) . "\n";
}
echo 'walk_summary=' . ( $ok ? 'yes' : 'no' ) . "\n";
echo 'smoke=' . ( $ok ? 'ok' : 'fail' ) . "\n";

exit( $ok ? 0 : 1 );
