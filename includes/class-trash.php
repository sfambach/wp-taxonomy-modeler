<?php
/**
 * Soft-delete / Trash bin for taxonomy nodes (Q89).
 *
 * Deleted nodes keep parent/child links; they are hidden from the normal tree
 * and listed under a special Trash node. Empty trash hard-deletes them.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trash bin + soft-delete markers.
 */
final class Trash {

	public const NODE_NAME = 'Trash';

	/** Soft-delete flag on any node. */
	public const META_KEY_TRASHED = '_wtt_trashed';

	/** Marks the special Trash bin term. */
	public const META_KEY_IS_TRASH = '_wtt_is_trash';

	/**
	 * Attribute on the Trash node: list of soft-deleted root term ids
	 * (entries moved to trash; descendants are also marked trashed).
	 */
	public const META_KEY_TRASH_ITEMS = '_wtt_trash_item_ids';

	/**
	 * Ensure a Trash bin exists under the taxonomy root (idempotent).
	 */
	public static function ensure_trash_node( string $taxonomy, int $parent_id = 0 ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$existing = self::find_trash_node_id( $taxonomy );
		if ( $existing > 0 ) {
			self::configure_trash_node( $taxonomy, $existing );
			return $existing;
		}

		if ( $parent_id <= 0 ) {
			$parent_id = self::resolve_default_parent( $taxonomy );
		}

		$inserted = wp_insert_term(
			self::NODE_NAME,
			$taxonomy,
			array(
				'parent'      => max( 0, $parent_id ),
				'description' => 'Soft-deleted nodes. Empty to permanently remove.',
				'slug'        => 'wtt-trash',
			)
		);
		if ( is_wp_error( $inserted ) ) {
			/* Slug clash — try by name under parent. */
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => self::NODE_NAME,
					'parent'     => max( 0, $parent_id ),
					'hide_empty' => false,
					'number'     => 1,
				)
			);
			if ( is_array( $found ) && isset( $found[0] ) && $found[0] instanceof \WP_Term ) {
				self::configure_trash_node( $taxonomy, (int) $found[0]->term_id );
				return (int) $found[0]->term_id;
			}
			return 0;
		}

		$trash_id = (int) ( $inserted['term_id'] ?? 0 );
		if ( $trash_id > 0 ) {
			self::configure_trash_node( $taxonomy, $trash_id );
		}
		return $trash_id;
	}

	/**
	 * @return int Trash term id or 0.
	 */
	public static function find_trash_node_id( string $taxonomy ): int {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'meta_key'   => self::META_KEY_IS_TRASH,
				'meta_value' => '1',
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( $term instanceof \WP_Term ) {
					return (int) $term->term_id;
				}
			}
		}
		/* Fallback by name. */
		$by_name = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => self::NODE_NAME,
				'hide_empty' => false,
				'number'     => 5,
			)
		);
		if ( is_array( $by_name ) ) {
			foreach ( $by_name as $term ) {
				if ( $term instanceof \WP_Term && self::is_trash_node( (int) $term->term_id ) ) {
					return (int) $term->term_id;
				}
			}
		}
		return 0;
	}

	public static function is_trash_node( int $term_id ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		return '1' === (string) get_term_meta( $term_id, self::META_KEY_IS_TRASH, true );
	}

	public static function is_trashed( int $term_id ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		return '1' === (string) get_term_meta( $term_id, self::META_KEY_TRASHED, true );
	}

	/**
	 * Clear soft-delete on $term_id and descendants; drop matching Trash list roots.
	 * Used by catalog ensure_* so re-seed restores visibility without recreating terms.
	 *
	 * @return int Number of terms untrashed.
	 */
	public static function restore_subtree( string $taxonomy, int $term_id ): int {
		if ( $term_id <= 0 || self::is_trash_node( $term_id ) ) {
			return 0;
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$ids   = self::collect_descendant_ids( $taxonomy, $term_id );
		$ids[] = $term_id;
		$ids   = array_values( array_unique( array_map( 'intval', $ids ) ) );

		$restored = 0;
		foreach ( $ids as $id ) {
			if ( self::is_trash_node( $id ) ) {
				continue;
			}
			if ( ! self::is_trashed( $id ) ) {
				continue;
			}
			delete_term_meta( $id, self::META_KEY_TRASHED );
			++$restored;
			Tree_Model::touch_modified( $id );
		}

		$trash_id = self::find_trash_node_id( $taxonomy );
		if ( $trash_id > 0 ) {
			/* get_trash_item_ids drops roots that are no longer soft-deleted. */
			self::get_trash_item_ids( $trash_id );
			if ( $restored > 0 ) {
				Tree_Model::touch_modified( $trash_id );
			}
		}

		return $restored;
	}

	/**
	 * Soft-delete $term_id; optionally include descendants (cascade).
	 *
	 * When $include_descendants is false (promote / node-only), only this term
	 * is marked trashed — callers must reparent children first.
	 * When true (cascade / branch), the node and all descendants are marked;
	 * WP term_parent links among them are kept.
	 *
	 * @return true|\WP_Error
	 */
	public static function move_to_trash( string $taxonomy, int $term_id, bool $include_descendants = true ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		if ( self::is_trash_node( $term_id ) ) {
			return new \WP_Error(
				'wtt_not_deletable',
				__( 'The Trash node cannot be deleted.', 'wp-taxonomy-tree' )
			);
		}
		if ( class_exists( Hidden_Nodes::class ) && Hidden_Nodes::is_bin( $term_id ) ) {
			return new \WP_Error(
				'wtt_not_deletable',
				__( 'The Hidden nodes bin cannot be deleted.', 'wp-taxonomy-tree' )
			);
		}
		if ( ! Node_Type::is_deletable( $term_id ) ) {
			return new \WP_Error(
				'wtt_not_deletable',
				__( 'This node is a system or catalog type and cannot be deleted.', 'wp-taxonomy-tree' )
			);
		}

		$trash_id = self::ensure_trash_node( $taxonomy );
		if ( $trash_id <= 0 ) {
			return new \WP_Error( 'wtt_no_trash', __( 'Could not create Trash node.', 'wp-taxonomy-tree' ) );
		}
		if ( $term_id === $trash_id ) {
			return new \WP_Error(
				'wtt_not_deletable',
				__( 'The Trash node cannot be deleted.', 'wp-taxonomy-tree' )
			);
		}

		$ids = array( $term_id );
		if ( $include_descendants ) {
			$ids = array_merge( self::collect_descendant_ids( $taxonomy, $term_id ), $ids );
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		foreach ( $ids as $id ) {
			if ( self::is_trash_node( $id ) ) {
				return new \WP_Error(
					'wtt_not_deletable',
					__( 'Cannot trash a subtree that contains the Trash node.', 'wp-taxonomy-tree' )
				);
			}
			if ( class_exists( Hidden_Nodes::class ) && Hidden_Nodes::is_bin( $id ) ) {
				return new \WP_Error(
					'wtt_not_deletable',
					__( 'Cannot trash a subtree that contains the Hidden nodes bin.', 'wp-taxonomy-tree' )
				);
			}
			if ( ! Node_Type::is_deletable( $id ) ) {
				$blocked = get_term( $id, $taxonomy );
				$name    = $blocked instanceof \WP_Term ? $blocked->name : (string) $id;
				return new \WP_Error(
					'wtt_not_deletable',
					sprintf(
						/* translators: %s: node name */
						__( 'Cannot trash: protected node “%s” is in the subtree.', 'wp-taxonomy-tree' ),
						$name
					)
				);
			}
		}

		foreach ( $ids as $id ) {
			update_term_meta( $id, self::META_KEY_TRASHED, '1' );
		}

		self::add_trash_item( $trash_id, $term_id );
		Tree_Model::touch_modified( $term_id );
		Tree_Model::touch_modified( $trash_id );

		return true;
	}

	/**
	 * Permanently delete all soft-deleted nodes and clear the Trash list.
	 *
	 * @return array{deleted:int}|\WP_Error
	 */
	public static function empty_trash( string $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}

		$trash_id = self::ensure_trash_node( $taxonomy );
		$ids      = self::list_all_trashed_ids( $taxonomy );
		/* Deepest first so parents remain until children are gone. */
		usort(
			$ids,
			static function ( int $a, int $b ) use ( $taxonomy ): int {
				return self::term_depth( $taxonomy, $b ) <=> self::term_depth( $taxonomy, $a );
			}
		);

		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( self::is_trash_node( $id ) ) {
				continue;
			}
			$result = wp_delete_term( $id, $taxonomy );
			if ( ! is_wp_error( $result ) && false !== $result && 0 !== $result ) {
				++$deleted;
			}
		}

		self::store_trash_item_ids( $trash_id, array() );
		if ( $trash_id > 0 ) {
			Tree_Model::touch_modified( $trash_id );
		}

		return array( 'deleted' => $deleted );
	}

	/**
	 * Ids registered on the Trash node attribute (soft-delete roots).
	 *
	 * Stored as JSON string (`[1,2,3]`) — same pattern as other list meta.
	 * Legacy PHP-serialized arrays are accepted and migrated on write.
	 *
	 * @return list<int>
	 */
	public static function get_trash_item_ids( int $trash_id ): array {
		if ( $trash_id <= 0 ) {
			return array();
		}
		$raw = get_term_meta( $trash_id, self::META_KEY_TRASH_ITEMS, true );
		$ids = self::normalize_trash_item_ids( $raw );
		/* Drop stale entries (term gone or no longer soft-deleted). */
		$clean = array();
		foreach ( $ids as $id ) {
			if ( self::is_trashed( $id ) && ! self::is_trash_node( $id ) ) {
				$clean[] = $id;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		if ( $clean !== $ids ) {
			self::store_trash_item_ids( $trash_id, $clean );
		}
		return $clean;
	}

	/**
	 * Forest of soft-deleted nodes for display under the Trash bin
	 * (preserves parent/child among trashed terms).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function build_trashed_forest( string $taxonomy ): array {
		$trashed = self::list_all_trashed_ids( $taxonomy );
		if ( empty( $trashed ) ) {
			return array();
		}
		$trashed_map = array_fill_keys( $trashed, true );
		$by_parent   = array();

		foreach ( $trashed as $id ) {
			$term = get_term( $id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$parent = (int) $term->parent;
			if ( $parent <= 0 || empty( $trashed_map[ $parent ] ) ) {
				$parent = 0;
			}
			if ( ! isset( $by_parent[ $parent ] ) ) {
				$by_parent[ $parent ] = array();
			}
			$by_parent[ $parent ][] = $term;
		}

		foreach ( $by_parent as $pid => $siblings ) {
			usort(
				$siblings,
				static function ( \WP_Term $a, \WP_Term $b ): int {
					return strcasecmp( $a->name, $b->name );
				}
			);
			$by_parent[ $pid ] = $siblings;
		}

		return self::nest_trashed( $taxonomy, $by_parent, 0 );
	}

	/**
	 * Payload extras for the Trash node detail panel.
	 *
	 * @return array<string, mixed>
	 */
	public static function trash_node_payload( string $taxonomy, int $trash_id ): array {
		$item_ids = self::get_trash_item_ids( $trash_id );
		$items    = array();
		foreach ( $item_ids as $id ) {
			$term = get_term( $id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$items[] = array(
				'id'   => $id,
				'name' => $term->name,
				'path' => self::term_path_label( $taxonomy, $id ),
			);
		}
		return array(
			'isTrash'       => true,
			'trashItemIds'  => $item_ids,
			'trashItems'    => $items,
			'trashCount'    => count( self::list_all_trashed_ids( $taxonomy ) ),
			'trashRoots'    => count( $item_ids ),
		);
	}

	private static function configure_trash_node( string $taxonomy, int $trash_id ): void {
		update_term_meta( $trash_id, self::META_KEY_IS_TRASH, '1' );
		Node_Type::set_deletable( $trash_id, false );
		delete_term_meta( $trash_id, self::META_KEY_TRASHED );

		$raw = get_term_meta( $trash_id, self::META_KEY_TRASH_ITEMS, true );
		if ( false === $raw || null === $raw || '' === $raw ) {
			self::store_trash_item_ids( $trash_id, array() );
		} elseif ( is_array( $raw ) ) {
			/* Migrate legacy PHP-serialized array → JSON string. */
			self::store_trash_item_ids( $trash_id, $raw );
		}
		unset( $taxonomy );
	}

	private static function resolve_default_parent( string $taxonomy ): int {
		if ( Taxonomy::is_case_study( $taxonomy ) ) {
			$path = Case_Data::find_term_by_path( $taxonomy, array( Case_Data::ROOT_NAME ) );
			if ( $path > 0 ) {
				return $path;
			}
		}
		$roots = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => 0,
				'hide_empty' => false,
				'number'     => 1,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);
		if ( is_array( $roots ) && isset( $roots[0] ) && $roots[0] instanceof \WP_Term ) {
			return (int) $roots[0]->term_id;
		}
		return 0;
	}

	/**
	 * @return list<int>
	 */
	private static function collect_descendant_ids( string $taxonomy, int $term_id ): array {
		$out      = array();
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $children ) ) {
			return $out;
		}
		foreach ( $children as $child ) {
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			$cid   = (int) $child->term_id;
			$out[] = $cid;
			$out   = array_merge( $out, self::collect_descendant_ids( $taxonomy, $cid ) );
		}
		return $out;
	}

	/**
	 * @return list<int>
	 */
	public static function list_all_trashed_ids( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'meta_key'   => self::META_KEY_TRASHED,
				'meta_value' => '1',
			)
		);
		$out = array();
		if ( ! is_array( $terms ) ) {
			return $out;
		}
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$out[] = (int) $term->term_id;
			}
		}
		return $out;
	}

	private static function add_trash_item( int $trash_id, int $root_id ): void {
		$ids = self::get_trash_item_ids( $trash_id );
		if ( ! in_array( $root_id, $ids, true ) ) {
			$ids[] = $root_id;
		}
		self::store_trash_item_ids( $trash_id, $ids );
	}

	/**
	 * Normalize raw term meta (JSON string, legacy array, or empty) to int ids.
	 *
	 * @param mixed $raw Meta value from get_term_meta( ..., true ).
	 * @return list<int>
	 */
	private static function normalize_trash_item_ids( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			if ( '' === $raw ) {
				return array();
			}
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return array();
			}
			$raw = $decoded;
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Persist trash root ids as a JSON string (never a bare PHP array).
	 *
	 * @param list<int> $ids
	 */
	private static function store_trash_item_ids( int $trash_id, array $ids ): void {
		if ( $trash_id <= 0 ) {
			return;
		}
		$clean = self::normalize_trash_item_ids( $ids );
		update_term_meta( $trash_id, self::META_KEY_TRASH_ITEMS, wp_json_encode( $clean ) );
	}

	private static function term_depth( string $taxonomy, int $term_id ): int {
		$depth = 0;
		$cur   = $term_id;
		$guard = 0;
		while ( $cur > 0 && $guard < 64 ) {
			$term = get_term( $cur, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				break;
			}
			$cur = (int) $term->parent;
			++$depth;
			++$guard;
		}
		return $depth;
	}

	private static function term_path_label( string $taxonomy, int $term_id ): string {
		$parts = array();
		$cur   = $term_id;
		$guard = 0;
		while ( $cur > 0 && $guard < 64 ) {
			$term = get_term( $cur, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				break;
			}
			array_unshift( $parts, $term->name );
			$cur = (int) $term->parent;
			++$guard;
		}
		return implode( ' / ', $parts );
	}

	/**
	 * @param array<int, array<int, \WP_Term>> $by_parent
	 * @return array<int, array<string, mixed>>
	 */
	private static function nest_trashed( string $taxonomy, array $by_parent, int $parent_id ): array {
		if ( ! isset( $by_parent[ $parent_id ] ) ) {
			return array();
		}
		$nodes = array();
		foreach ( $by_parent[ $parent_id ] as $index => $term ) {
			$term_id  = (int) $term->term_id;
			$children = self::nest_trashed( $taxonomy, $by_parent, $term_id );
			$type     = Node_Type::get_assignment( $taxonomy, $term_id );
			$nodes[]  = array(
				'id'           => $term_id,
				'name'         => $term->name,
				'slug'         => $term->slug,
				'description'  => Tree_Model::decode_term_description( (string) $term->description ),
				'shortDescription' => Tree_Model::get_short_description( $term_id ),
				'parent'       => (int) $term->parent,
				'count'        => (int) $term->count,
				'position'     => Tree_Model::get_position( $term_id ),
				'index'        => $index,
				'type'         => $type,
				'typeId'       => Node_Type::get_effective_type_id( $taxonomy, $term_id ),
				'ownTypeId'    => Node_Type::get_type_id( $term_id ),
				'typeLabel'    => is_array( $type ) ? (string) $type['name'] : '',
				'isDatatype'   => Node_Type::is_datatype( $taxonomy, $term_id ),
				'isAbstract'   => Node_Type::is_abstract( $taxonomy, $term_id ),
				'isTemplate'   => Node_Type::is_template( $term_id ),
				'deletable'    => false,
				'trashed'      => true,
				'children'     => $children,
				'hasChildren'  => count( $children ) > 0,
			);
		}
		return $nodes;
	}
}
