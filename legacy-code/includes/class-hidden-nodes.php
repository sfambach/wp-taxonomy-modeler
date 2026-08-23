<?php
/**
 * Tree node visibility — hide from the normal tree without soft-delete.
 *
 * Hidden ≠ Trash. Hidden nodes stay normal terms with `_wtt_hidden=1` and
 * keep their parent/child links. They appear only under the Hidden nodes bin.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hide / unhide taxonomy nodes for the admin tree.
 */
final class Hidden_Nodes {

	public const NODE_NAME = 'Hidden nodes';

	/** Per-node visibility flag (1 = hidden from normal tree). */
	public const META_KEY_HIDDEN = '_wtt_hidden';

	/** Marks the special Hidden nodes bin term. */
	public const META_KEY_IS_BIN = '_wtt_is_hidden_bin';

	/**
	 * Ensure a Hidden nodes bin exists under the taxonomy root (idempotent).
	 */
	public static function ensure_bin( string $taxonomy, int $parent_id = 0 ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$existing = self::find_bin_id( $taxonomy );
		if ( $existing > 0 ) {
			self::configure_bin( $taxonomy, $existing );
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
				'description' => 'Nodes hidden from the tree. Unhide to restore them under their parent.',
				'slug'        => 'wtt-hidden-nodes',
			)
		);
		if ( is_wp_error( $inserted ) ) {
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
				self::configure_bin( $taxonomy, (int) $found[0]->term_id );
				return (int) $found[0]->term_id;
			}
			return 0;
		}

		$bin_id = (int) ( $inserted['term_id'] ?? 0 );
		if ( $bin_id > 0 ) {
			self::configure_bin( $taxonomy, $bin_id );
		}
		return $bin_id;
	}

	/**
	 * @return int Hidden bin term id or 0.
	 */
	public static function find_bin_id( string $taxonomy ): int {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'meta_key'   => self::META_KEY_IS_BIN,
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
				if ( $term instanceof \WP_Term && self::is_bin( (int) $term->term_id ) ) {
					return (int) $term->term_id;
				}
			}
		}
		return 0;
	}

	public static function is_bin( int $term_id ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		return '1' === (string) get_term_meta( $term_id, self::META_KEY_IS_BIN, true );
	}

	public static function is_hidden( int $term_id ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		return '1' === (string) get_term_meta( $term_id, self::META_KEY_HIDDEN, true );
	}

	/**
	 * Mark a node hidden (out of the normal tree). Does not mark descendants;
	 * they disappear from the tree because the parent is filtered out.
	 *
	 * @return true|\WP_Error
	 */
	public static function hide( string $taxonomy, int $term_id ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		if ( self::is_bin( $term_id ) ) {
			return new \WP_Error(
				'wtt_cannot_hide',
				__( 'The Hidden nodes bin cannot be hidden.', 'wp-taxonomy-tree' )
			);
		}
		if ( class_exists( Trash::class ) && Trash::is_trash_node( $term_id ) ) {
			return new \WP_Error(
				'wtt_cannot_hide',
				__( 'The Trash node cannot be hidden.', 'wp-taxonomy-tree' )
			);
		}
		if ( class_exists( Trash::class ) && Trash::is_trashed( $term_id ) ) {
			return new \WP_Error(
				'wtt_cannot_hide',
				__( 'Trashed nodes cannot be hidden. Restore or empty Trash instead.', 'wp-taxonomy-tree' )
			);
		}
		if ( class_exists( Attribute::class ) && Attribute::is_slot( $term_id ) ) {
			return new \WP_Error(
				'wtt_cannot_hide',
				__( 'Attribute slots are already hidden from the tree.', 'wp-taxonomy-tree' )
			);
		}

		$bin_id = self::ensure_bin( $taxonomy );
		if ( $bin_id <= 0 ) {
			return new \WP_Error( 'wtt_no_hidden_bin', __( 'Could not create Hidden nodes bin.', 'wp-taxonomy-tree' ) );
		}
		if ( $term_id === $bin_id ) {
			return new \WP_Error(
				'wtt_cannot_hide',
				__( 'The Hidden nodes bin cannot be hidden.', 'wp-taxonomy-tree' )
			);
		}

		update_term_meta( $term_id, self::META_KEY_HIDDEN, '1' );
		Tree_Model::touch_modified( $term_id );
		Tree_Model::touch_modified( $bin_id );

		return true;
	}

	/**
	 * Clear the hidden flag so the node reappears under its WP parent
	 * (if that parent is visible).
	 *
	 * @return true|\WP_Error
	 */
	public static function unhide( string $taxonomy, int $term_id ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		if ( self::is_bin( $term_id ) ) {
			return new \WP_Error(
				'wtt_cannot_unhide',
				__( 'The Hidden nodes bin is not a hidden node.', 'wp-taxonomy-tree' )
			);
		}
		if ( ! self::is_hidden( $term_id ) ) {
			return true;
		}

		delete_term_meta( $term_id, self::META_KEY_HIDDEN );
		Tree_Model::touch_modified( $term_id );
		$bin_id = self::find_bin_id( $taxonomy );
		if ( $bin_id > 0 ) {
			Tree_Model::touch_modified( $bin_id );
		}

		return true;
	}

	/**
	 * Explicitly hidden term ids (not the bin itself).
	 *
	 * @return list<int>
	 */
	public static function list_hidden_ids( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'meta_key'   => self::META_KEY_HIDDEN,
				'meta_value' => '1',
			)
		);
		$out = array();
		if ( ! is_array( $terms ) ) {
			return $out;
		}
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$id = (int) $term->term_id;
			if ( self::is_bin( $id ) ) {
				continue;
			}
			if ( class_exists( Trash::class ) && ( Trash::is_trash_node( $id ) || Trash::is_trashed( $id ) ) ) {
				continue;
			}
			$out[] = $id;
		}
		return $out;
	}

	/**
	 * Forest under the Hidden bin: each explicitly hidden node whose parent is
	 * not hidden becomes a root; its WP descendants are shown for browsing
	 * (they leave the normal tree with the hidden ancestor). Nested terms that
	 * are themselves hidden keep `hidden: true` for Unhide.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function build_hidden_forest( string $taxonomy ): array {
		$hidden = self::list_hidden_ids( $taxonomy );
		if ( empty( $hidden ) ) {
			return array();
		}
		$hidden_map = array_fill_keys( $hidden, true );
		$roots      = array();

		foreach ( $hidden as $id ) {
			$term = get_term( $id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$parent = (int) $term->parent;
			if ( $parent > 0 && ! empty( $hidden_map[ $parent ] ) ) {
				continue;
			}
			$roots[] = $term;
		}

		usort(
			$roots,
			static function ( \WP_Term $a, \WP_Term $b ): int {
				return strcasecmp( $a->name, $b->name );
			}
		);

		$nodes = array();
		foreach ( $roots as $index => $term ) {
			$nodes[] = self::serialize_hidden_branch( $taxonomy, $term, $index, $hidden_map );
		}
		return $nodes;
	}

	/**
	 * @param array<int, true> $hidden_map
	 * @return array<string, mixed>
	 */
	private static function serialize_hidden_branch( string $taxonomy, \WP_Term $term, int $index, array $hidden_map ): array {
		$term_id  = (int) $term->term_id;
		$children = self::load_visible_wp_children( $taxonomy, $term_id );
		$child_nodes = array();
		foreach ( $children as $cindex => $child ) {
			$child_nodes[] = self::serialize_hidden_branch( $taxonomy, $child, $cindex, $hidden_map );
		}
		$type = Node_Type::get_assignment( $taxonomy, $term_id );
		return array(
			'id'               => $term_id,
			'name'             => $term->name,
			'slug'             => $term->slug,
			'description'      => Tree_Model::decode_term_description( (string) $term->description ),
			'shortDescription' => Tree_Model::get_short_description( $term_id ),
			'parent'           => (int) $term->parent,
			'count'            => (int) $term->count,
			'position'         => Tree_Model::get_position( $term_id ),
			'index'            => $index,
			'type'             => $type,
			'typeId'           => Node_Type::get_effective_type_id( $taxonomy, $term_id ),
			'ownTypeId'        => Node_Type::get_type_id( $term_id ),
			'typeLabel'        => is_array( $type ) ? (string) $type['name'] : '',
			'isAbstract'       => Node_Type::is_abstract( $taxonomy, $term_id ),
			'isTemplate'       => Node_Type::is_template( $term_id ),
			'deletable'        => Node_Type::is_deletable( $term_id ),
			'hidden'           => ! empty( $hidden_map[ $term_id ] ),
			'trashed'          => false,
			'isTrash'          => false,
			'isHiddenBin'      => false,
			'children'         => $child_nodes,
			'hasChildren'      => count( $child_nodes ) > 0,
		);
	}

	/**
	 * Direct WP children for Hidden-bin browsing (skip trash / slots / bin).
	 *
	 * @return list<\WP_Term>
	 */
	private static function load_visible_wp_children( string $taxonomy, int $parent_id ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		$out = array();
		if ( ! is_array( $terms ) ) {
			return $out;
		}
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$id = (int) $term->term_id;
			if ( self::is_bin( $id ) ) {
				continue;
			}
			if ( class_exists( Trash::class ) && ( Trash::is_trash_node( $id ) || Trash::is_trashed( $id ) ) ) {
				continue;
			}
			if ( class_exists( Attribute::class ) && Attribute::is_slot( $id ) ) {
				continue;
			}
			$out[] = $term;
		}
		usort(
			$out,
			static function ( \WP_Term $a, \WP_Term $b ): int {
				return strcasecmp( $a->name, $b->name );
			}
		);
		return $out;
	}

	/**
	 * Payload extras for the Hidden nodes bin detail panel.
	 *
	 * @return array<string, mixed>
	 */
	public static function bin_payload( string $taxonomy, int $bin_id ): array {
		unset( $bin_id );
		$ids   = self::list_hidden_ids( $taxonomy );
		$items = array();
		foreach ( $ids as $id ) {
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
		usort(
			$items,
			static function ( array $a, array $b ): int {
				return strcasecmp( (string) $a['path'], (string) $b['path'] );
			}
		);
		return array(
			'isHiddenBin' => true,
			'hiddenItems' => $items,
			'hiddenCount' => count( $items ),
		);
	}

	private static function configure_bin( string $taxonomy, int $bin_id ): void {
		update_term_meta( $bin_id, self::META_KEY_IS_BIN, '1' );
		Node_Type::set_deletable( $bin_id, false );
		delete_term_meta( $bin_id, self::META_KEY_HIDDEN );
		if ( class_exists( Trash::class ) ) {
			delete_term_meta( $bin_id, Trash::META_KEY_TRASHED );
		}
		self::pin_bin_last( $taxonomy, $bin_id );
	}

	/**
	 * Keep Hidden nodes as the last sibling under its parent.
	 */
	private static function pin_bin_last( string $taxonomy, int $bin_id ): void {
		$term = get_term( $bin_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}
		$parent_id = (int) $term->parent;
		$siblings  = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		$max = 0;
		foreach ( (array) $siblings as $sibling ) {
			if ( ! $sibling instanceof \WP_Term ) {
				continue;
			}
			$sid = (int) $sibling->term_id;
			if ( $sid === $bin_id ) {
				continue;
			}
			$max = max( $max, Tree_Model::get_position( $sid ) );
		}
		/* Position is secondary: sibling_sort_rank always pins Hidden last in the UI. */
		Tree_Model::set_position( $bin_id, $max + 1 );
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
}
