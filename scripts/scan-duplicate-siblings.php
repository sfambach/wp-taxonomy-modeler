<?php
/**
 * Report same-name active siblings under wtt_fs (and optionally hard-dedupe Model/Bauteil).
 *
 * Usage from WordPress root:
 *   php wp-content/plugins/wp-taxonomy-tree/scripts/scan-duplicate-siblings.php
 *   php …/scan-duplicate-siblings.php --fix
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	$wp_load = 'C:/devel/wordpress/wp-load.php';
	require $wp_load;
}

$fix = in_array( '--fix', $argv ?? array(), true );
$tax  = 'wtt_fs';

if ( ! taxonomy_exists( $tax ) ) {
	fwrite( STDERR, "Taxonomy {$tax} missing\n" );
	exit( 1 );
}

/**
 * @return list<array{parent:string,parent_id:int,name:string,ids:list<int>,trashed_ids:list<int>}>
 */
function wtt_scan_duplicate_siblings( string $taxonomy, bool $include_trashed = false ): array {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 0,
		)
	);
	if ( ! is_array( $terms ) ) {
		return array();
	}

	$by_parent = array();
	foreach ( $terms as $term ) {
		if ( ! $term instanceof WP_Term ) {
			continue;
		}
		$tid = (int) $term->term_id;
		if ( ! $include_trashed && WTT\Trash::is_trashed( $tid ) ) {
			continue;
		}
		$key = 'Diode' === $term->name ? 'Dioden' : $term->name;
		$by_parent[ (int) $term->parent ][ $key ][] = $tid;
	}

	$dupes = array();
	foreach ( $by_parent as $parent_id => $names ) {
		foreach ( $names as $name => $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			$parent_label = '0 (root)';
			if ( $parent_id > 0 ) {
				$parts = array();
				$cur   = get_term( $parent_id, $taxonomy );
				while ( $cur instanceof WP_Term ) {
					array_unshift( $parts, $cur->name );
					$cur = $cur->parent ? get_term( (int) $cur->parent, $taxonomy ) : null;
				}
				$parent_label = implode( '/', $parts );
			}
			$dupes[] = array(
				'parent'    => $parent_label,
				'parent_id' => $parent_id,
				'name'      => $name,
				'ids'       => $ids,
			);
		}
	}

	return $dupes;
}

$dupes = wtt_scan_duplicate_siblings( $tax, false );
if ( array() === $dupes ) {
	echo "No active same-name siblings under {$tax}\n";
} else {
	echo 'Found ' . count( $dupes ) . " active duplicate name group(s):\n";
	foreach ( $dupes as $row ) {
		echo sprintf(
			"  parent=%s (#%d) name=%s ids=%s\n",
			$row['parent'],
			$row['parent_id'],
			$row['name'],
			implode( ',', $row['ids'] )
		);
	}
}

$model = WTT\Demo_Data::find_model_bauteil_id( $tax );
if ( $fix && $model > 0 ) {
	$removed = WTT\Demo_Data::dedupe_named_children_subtree( $tax, $model, 6 );
	echo "Hard-deduped Model/Bauteil subtree, terms removed≈{$removed}\n";
	$left = wtt_scan_duplicate_siblings( $tax, false );
	$bau  = array_filter(
		$left,
		static function ( array $row ): bool {
			return str_contains( $row['parent'], 'Model/Bauteil' );
		}
	);
	echo 'Remaining under Model/Bauteil: ' . count( $bau ) . "\n";
	$root = array_filter(
		$left,
		static function ( array $row ): bool {
			return 0 === (int) $row['parent_id'];
		}
	);
	if ( array() !== $root ) {
		echo 'Still ' . count( $root ) . " active duplicate group(s) at taxonomy root (not auto-fixed)\n";
	}
	exit( array() === $bau ? ( array() === $root ? 0 : 2 ) : 3 );
}

if ( array() !== $dupes ) {
	$only_root = true;
	foreach ( $dupes as $row ) {
		if ( 0 !== (int) $row['parent_id'] ) {
			$only_root = false;
			break;
		}
	}
	if ( $only_root ) {
		echo "Only taxonomy-root orphans remain — not auto-fixed (ask before purge)\n";
	} else {
		echo "Re-run with --fix to hard-dedupe Model/Bauteil\n";
	}
	exit( 2 );
}
exit( 0 );
