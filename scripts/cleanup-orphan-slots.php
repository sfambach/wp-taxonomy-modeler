<?php
/**
 * Soft-delete unreferenced parent=0 attribute-slot orphans on wtt_fs.
 *
 * 1) Collect live referenced ids (Attribute::list + prop bindings + hierarchy-child edges).
 * 2) Prune stale besteht_aus/aggregation edges to parent=0 terms not in that set.
 * 3) Soft-delete remaining unreferenced parent=0 terms (Trash).
 *
 * Usage:
 *   php …/php.exe scripts/cleanup-orphan-slots.php --dry-run
 *   php …/php.exe scripts/cleanup-orphan-slots.php
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	$wp_load = 'C:/devel/wordpress/wp-load.php';
	if ( is_readable( $wp_load ) ) {
		require $wp_load;
	} else {
		fwrite( STDERR, "Run via WP-CLI eval-file or with wp-load.php available.\n" );
		exit( 1 );
	}
}

if ( ! class_exists( 'WTT\\Attribute' ) || ! class_exists( 'WTT\\Trash' ) ) {
	fwrite( STDERR, "PLUGIN_INACTIVE\n" );
	exit( 1 );
}

\WTT\Taxonomy::register_taxonomies();

$dry_run   = false;
$argv_list = isset( $argv ) && is_array( $argv ) ? $argv : array();
foreach ( $argv_list as $arg ) {
	if ( '--dry-run' === $arg || '-n' === $arg ) {
		$dry_run = true;
	}
}
if ( isset( $args ) && is_array( $args ) ) {
	foreach ( $args as $arg ) {
		if ( '--dry-run' === $arg || '-n' === $arg ) {
			$dry_run = true;
		}
	}
}

$taxonomy = \WTT\Taxonomy::FS;
if ( ! taxonomy_exists( $taxonomy ) ) {
	fwrite( STDERR, "Taxonomy not found: {$taxonomy}\n" );
	exit( 1 );
}

$fallstudie_id = 0;
$roots         = get_terms(
	array(
		'taxonomy'   => $taxonomy,
		'parent'     => 0,
		'hide_empty' => false,
		'number'     => 0,
		'name'       => \WTT\Case_Data::ROOT_NAME,
	)
);
if ( is_array( $roots ) ) {
	foreach ( $roots as $t ) {
		if ( $t instanceof \WP_Term && 0 === (int) $t->parent ) {
			$fallstudie_id = (int) $t->term_id;
			break;
		}
	}
}

$trash_id   = \WTT\Trash::ensure_trash_node( $taxonomy );
$referenced = array_fill_keys( \WTT\Attribute::collect_referenced_term_ids( $taxonomy ), true );

/* --- Prune stale attribute-binding edges to parent=0 orphans --- */
$all_terms = get_terms(
	array(
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'number'     => 0,
	)
);
if ( ! is_array( $all_terms ) ) {
	$all_terms = array();
}

$pruned_edges = 0;
$edge_errors  = array();
foreach ( $all_terms as $host ) {
	if ( ! $host instanceof \WP_Term ) {
		continue;
	}
	$host_id = (int) $host->term_id;
	foreach ( \WTT\Relation::list_outgoing( $taxonomy, $host_id ) as $edge ) {
		$key = (string) ( $edge['typeKey'] ?? $edge['typeName'] ?? '' );
		if ( ! \WTT\Attribute::is_attribute_binding( $key ) ) {
			continue;
		}
		$to = (int) ( $edge['toId'] ?? 0 );
		if ( $to <= 0 || isset( $referenced[ $to ] ) ) {
			continue;
		}
		$target = get_term( $to, $taxonomy );
		if ( ! $target instanceof \WP_Term || (int) $target->parent > 0 ) {
			continue;
		}
		/* Stale edge → parent=0 orphan not in live keep set. */
		if ( $dry_run ) {
			++$pruned_edges;
			continue;
		}
		$edge_id = (string) ( $edge['id'] ?? '' );
		$type_id = (int) ( $edge['typeId'] ?? 0 );
		$result  = \WTT\Relation::remove( $taxonomy, $host_id, $type_id, $to, $edge_id );
		if ( is_wp_error( $result ) ) {
			$edge_errors[] = "{$host->name}→{$target->name}: " . $result->get_error_message();
		} else {
			++$pruned_edges;
		}
	}
}

/* --- Soft-delete unreferenced parent=0 terms --- */
$parent_zero = get_terms(
	array(
		'taxonomy'   => $taxonomy,
		'parent'     => 0,
		'hide_empty' => false,
		'number'     => 0,
	)
);
if ( ! is_array( $parent_zero ) ) {
	fwrite( STDERR, "Failed to list parent=0 terms.\n" );
	exit( 1 );
}

$kept    = array();
$trashed = array();
$skipped = array();
$errors  = array();

foreach ( $parent_zero as $term ) {
	if ( ! $term instanceof \WP_Term ) {
		continue;
	}
	$id = (int) $term->term_id;
	if ( $id <= 0 ) {
		continue;
	}
	if ( $id === $fallstudie_id || $id === (int) $trash_id ) {
		$kept[] = $term->name . '#' . $id . ' (root/trash)';
		continue;
	}
	if ( \WTT\Trash::is_trashed( $id ) ) {
		$skipped[] = $term->name . '#' . $id . ' (already trashed)';
		continue;
	}
	if ( isset( $referenced[ $id ] ) ) {
		$kept[] = $term->name . '#' . $id . ' (referenced)';
		continue;
	}
	if ( ! \WTT\Node_Type::is_deletable( $id ) ) {
		$skipped[] = $term->name . '#' . $id . ' (protected)';
		continue;
	}

	if ( $dry_run ) {
		$trashed[] = $term->name . '#' . $id;
		continue;
	}

	$result = \WTT\Trash::move_to_trash( $taxonomy, $id, false );
	if ( true === $result ) {
		$trashed[] = $term->name . '#' . $id;
	} elseif ( is_wp_error( $result ) ) {
		$errors[] = $term->name . '#' . $id . ': ' . $result->get_error_message();
	} else {
		$errors[] = $term->name . '#' . $id . ': unknown failure';
	}
}

$mode = $dry_run ? 'DRY-RUN' : 'EXECUTE';
echo "cleanup-orphan-slots [{$mode}] taxonomy={$taxonomy}\n";
echo 'referenced_ids=' . count( $referenced ) . "\n";
echo 'pruned_stale_edges=' . $pruned_edges . "\n";
echo 'kept=' . count( $kept ) . ' trashed=' . count( $trashed ) . ' skipped=' . count( $skipped ) . ' errors=' . count( $errors ) . "\n";
echo 'trashed sample: ' . implode( ', ', array_slice( $trashed, 0, 25 ) ) . "\n";
if ( array() !== $edge_errors ) {
	echo "edge prune errors:\n";
	foreach ( array_slice( $edge_errors, 0, 10 ) as $err ) {
		echo "  {$err}\n";
	}
}
if ( array() !== $errors ) {
	echo "trash errors:\n";
	foreach ( array_slice( $errors, 0, 15 ) as $err ) {
		echo "  {$err}\n";
	}
}

if ( ! $dry_run ) {
	$tree = \WTT\Tree_Model::get_tree( $taxonomy );
	echo 'tree_roots=' . count( $tree ) . "\n";
	foreach ( $tree as $n ) {
		echo '  - ' . (string) ( $n['name'] ?? '' ) . ' id=' . (int) ( $n['id'] ?? 0 ) . "\n";
	}
	/* Spot-check Attribute hosts. */
	foreach ( array( 'Platine', 'Preis', 'Kontakt' ) as $name ) {
		$found = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => $name,
				'hide_empty' => false,
				'number'     => 5,
			)
		);
		if ( ! is_array( $found ) ) {
			continue;
		}
		foreach ( $found as $t ) {
			if ( ! $t instanceof \WP_Term || \WTT\Attribute::is_slot( (int) $t->term_id ) ) {
				continue;
			}
			$n = count( \WTT\Attribute::list( $taxonomy, (int) $t->term_id ) );
			echo "check {$name}#{$t->term_id} attrs={$n}\n";
		}
	}
}

echo "OK\n";
exit( ( array() === $errors && array() === $edge_errors ) ? 0 : 1 );
