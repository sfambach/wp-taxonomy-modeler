<?php
/**
 * Find CatalogChoice-ish attrs with little/no settings walk nesting.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

require 'C:/devel/wordpress/wp-load.php';

use WTT\Attribute;
use WTT\Node_Type;
use WTT\Taxonomy;

$tax = Taxonomy::FS;

$hosts = get_terms(
	array(
		'taxonomy'   => $tax,
		'hide_empty' => false,
		'number'     => 0,
	)
);
if ( ! is_array( $hosts ) ) {
	fwrite( STDERR, "no terms\n" );
	exit( 1 );
}

$hits = array();
foreach ( $hosts as $host ) {
	if ( ! $host instanceof WP_Term ) {
		continue;
	}
	$host_id = (int) $host->term_id;
	$rows    = Attribute::list_own( $tax, $host_id );
	if ( ! is_array( $rows ) || array() === $rows ) {
		continue;
	}
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$opts = isset( $row['fixedOptions'] ) && is_array( $row['fixedOptions'] ) ? $row['fixedOptions'] : array();
		$mode = (string) ( $row['fixedMode'] ?? '' );
		$type = strtolower( (string) ( $row['typeKey'] ?? $row['typeName'] ?? '' ) );
		$has_choice =
			( 'catalog' === $mode && count( $opts ) > 0 )
			|| count( $opts ) > 0
			|| false !== strpos( $type, 'präfixe' )
			|| false !== strpos( $type, 'praefixe' )
			|| false !== strpos( $type, 'bauform' )
			|| false !== strpos( $type, 'währung' )
			|| false !== strpos( $type, 'waehrung' );

		if ( ! $has_choice && empty( $row['choiceDepth'] ) ) {
			continue;
		}

		$meta  = isset( $row['settingsWalkMeta'] ) && is_array( $row['settingsWalkMeta'] ) ? $row['settingsWalkMeta'] : array();
		$walk  = isset( $row['settingsWalk'] ) && is_array( $row['settingsWalk'] ) ? $row['settingsWalk'] : array();
		$nodes = (int) ( $meta['nodeCount'] ?? 0 );
		$depth = (int) ( $meta['depth'] ?? 0 );
		$levels = count( $walk );

		/* Prefer shallow / no-walk candidates. */
		$score = $nodes + $depth * 10 + $levels * 5;
		$hits[] = array(
			'score'    => $score,
			'host'     => $host->name,
			'hostId'   => $host_id,
			'attr'     => (string) ( $row['name'] ?? '' ),
			'type'     => $type,
			'opts'     => count( $opts ),
			'nodes'    => $nodes,
			'depth'    => $depth,
			'levels'   => $levels,
			'walk'     => $walk,
			'meta'     => $meta,
		);
	}
}

usort(
	$hits,
	static function ( array $a, array $b ): int {
		return $a['score'] <=> $b['score'];
	}
);

echo "CatalogChoice-ish attrs (shallowest first), count=" . count( $hits ) . "\n\n";
foreach ( array_slice( $hits, 0, 25 ) as $h ) {
	echo "{$h['host']} / {$h['attr']} → type={$h['type']} opts={$h['opts']} walkNodes={$h['nodes']} walkDepth={$h['depth']} levels={$h['levels']}\n";
	foreach ( $h['walk'] as $i => $lv ) {
		if ( ! is_array( $lv ) ) {
			continue;
		}
		$cf = empty( $lv['supportsChoiceFilter'] ) ? '0' : '1';
		$co = isset( $lv['choiceOptions'] ) && is_array( $lv['choiceOptions'] ) ? count( $lv['choiceOptions'] ) : 0;
		echo "  [{$i}] d=" . ( $lv['depth'] ?? '?' ) . ' ' . ( $lv['edgeName'] ?? '' ) . '→' . ( $lv['name'] ?? '' ) . " CF={$cf} opts={$co}\n";
	}
	echo "\n";
}
