<?php
/**
 * Taxonomy tree model over WordPress terms.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads hierarchical taxonomies as nested arrays for the admin UI.
 */
final class Tree_Model {

	public const META_KEY_POSITION = '_wtt_position';

	/** Compact expansion of an abbreviation (e.g. L → Länge, m → Millimeter). */
	public const META_KEY_SHORT_DESCRIPTION = '_wtt_short_description';

	/**
	 * List hierarchical public/admin taxonomies.
	 *
	 * @return array<int, array{slug:string,label:string}>
	 */
	public static function hierarchical_taxonomies(): array {
		$objects = get_taxonomies(
			array(
				'hierarchical' => true,
				'show_ui'      => true,
			),
			'objects'
		);

		$list = array();
		foreach ( $objects as $tax ) {
			if ( ! $tax instanceof \WP_Taxonomy ) {
				continue;
			}
			$list[] = array(
				'slug'  => $tax->name,
				'label' => (string) $tax->labels->name,
			);
		}

		usort(
			$list,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return $list;
	}

	public static function is_hierarchical_taxonomy( string $taxonomy ): bool {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		$tax = get_taxonomy( $taxonomy );
		return $tax instanceof \WP_Taxonomy && (bool) $tax->hierarchical;
	}

	/**
	 * Build nested tree for a taxonomy.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_tree( string $taxonomy ): array {
		if ( ! self::is_hierarchical_taxonomy( $taxonomy ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$by_parent = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$parent = (int) $term->parent;
			if ( ! isset( $by_parent[ $parent ] ) ) {
				$by_parent[ $parent ] = array();
			}
			$by_parent[ $parent ][] = $term;
		}

		foreach ( $by_parent as $parent_id => $siblings ) {
			$by_parent[ $parent_id ] = self::sort_sibling_terms( $siblings );
		}

		return self::nest( $taxonomy, $by_parent, 0 );
	}

	public static function get_position( int $term_id ): int {
		$value = get_term_meta( $term_id, self::META_KEY_POSITION, true );
		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		return max( 0, (int) $value );
	}

	public static function set_position( int $term_id, int $position ): void {
		update_term_meta( $term_id, self::META_KEY_POSITION, max( 0, $position ) );
	}

	public static function get_short_description( int $term_id ): string {
		$value = get_term_meta( $term_id, self::META_KEY_SHORT_DESCRIPTION, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_short_description( string $taxonomy, int $term_id, string $short_description ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		$short_description = sanitize_text_field( $short_description );
		if ( '' === $short_description ) {
			delete_term_meta( $term_id, self::META_KEY_SHORT_DESCRIPTION );
		} else {
			update_term_meta( $term_id, self::META_KEY_SHORT_DESCRIPTION, $short_description );
		}

		return true;
	}

	/**
	 * Next free position among siblings (append).
	 */
	public static function next_sibling_position( string $taxonomy, int $parent_id ): int {
		$siblings = self::get_sibling_terms( $taxonomy, $parent_id );
		if ( empty( $siblings ) ) {
			return 0;
		}

		$max = 0;
		foreach ( $siblings as $sibling ) {
			$max = max( $max, self::get_position( (int) $sibling->term_id ) );
		}

		return $max + 1;
	}

	/**
	 * Move a term among its siblings. Direction: up|down.
	 *
	 * @return true|\WP_Error
	 */
	public static function move_term( string $taxonomy, int $term_id, string $direction ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		$direction = sanitize_key( $direction );
		if ( ! in_array( $direction, array( 'up', 'down' ), true ) ) {
			return new \WP_Error( 'wtt_bad_direction', __( 'Invalid move direction.', 'wp-taxonomy-tree' ) );
		}

		$parent_id = (int) $term->parent;
		$siblings  = self::get_sibling_terms( $taxonomy, $parent_id );
		self::normalize_sibling_positions( $siblings );

		$siblings = self::get_sibling_terms( $taxonomy, $parent_id );
		$index    = -1;
		foreach ( $siblings as $i => $sibling ) {
			if ( (int) $sibling->term_id === $term_id ) {
				$index = $i;
				break;
			}
		}

		if ( $index < 0 ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found among siblings.', 'wp-taxonomy-tree' ) );
		}

		$swap_with = 'up' === $direction ? $index - 1 : $index + 1;
		if ( $swap_with < 0 || $swap_with >= count( $siblings ) ) {
			return true;
		}

		$a_id = (int) $siblings[ $index ]->term_id;
		$b_id = (int) $siblings[ $swap_with ]->term_id;
		$a_pos = self::get_position( $a_id );
		$b_pos = self::get_position( $b_id );

		self::set_position( $a_id, $b_pos );
		self::set_position( $b_id, $a_pos );

		return true;
	}

	/**
	 * Move a term under a new parent (or to root when $new_parent_id is 0).
	 *
	 * @return true|\WP_Error
	 */
	public static function reparent_term( string $taxonomy, int $term_id, int $new_parent_id ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		if ( $new_parent_id === $term_id ) {
			return new \WP_Error( 'wtt_bad_parent', __( 'A term cannot be its own parent.', 'wp-taxonomy-tree' ) );
		}

		if ( $new_parent_id > 0 ) {
			$parent = get_term( $new_parent_id, $taxonomy );
			if ( ! $parent instanceof \WP_Term ) {
				return new \WP_Error( 'wtt_bad_parent', __( 'Parent term not found.', 'wp-taxonomy-tree' ) );
			}

			$ancestors = array_map( 'intval', get_ancestors( $new_parent_id, $taxonomy, 'taxonomy' ) );
			if ( in_array( $term_id, $ancestors, true ) ) {
				return new \WP_Error(
					'wtt_cycle',
					__( 'Cannot move a term under its own descendant.', 'wp-taxonomy-tree' )
				);
			}
		}

		$old_parent = (int) $term->parent;
		if ( $old_parent === $new_parent_id ) {
			return true;
		}

		$result = wp_update_term(
			$term_id,
			$taxonomy,
			array(
				'parent' => $new_parent_id,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::normalize_sibling_positions( self::get_sibling_terms( $taxonomy, $old_parent ) );
		self::normalize_sibling_positions( self::get_sibling_terms( $taxonomy, $new_parent_id ) );

		return true;
	}

	/**
	 * @param array<int, \WP_Term> $siblings
	 * @return array<int, \WP_Term>
	 */
	private static function sort_sibling_terms( array $siblings ): array {
		usort(
			$siblings,
			static function ( \WP_Term $a, \WP_Term $b ): int {
				$pa = self::get_position( (int) $a->term_id );
				$pb = self::get_position( (int) $b->term_id );
				if ( $pa !== $pb ) {
					return $pa <=> $pb;
				}
				return strcasecmp( $a->name, $b->name );
			}
		);

		return $siblings;
	}

	/**
	 * Ensure every sibling has a sequential position 0..n-1 in current sort order.
	 *
	 * @param array<int, \WP_Term> $siblings
	 */
	private static function normalize_sibling_positions( array $siblings ): void {
		$sorted = self::sort_sibling_terms( $siblings );
		foreach ( $sorted as $i => $sibling ) {
			self::set_position( (int) $sibling->term_id, $i );
		}
	}

	/**
	 * @return array<int, \WP_Term>
	 */
	private static function get_sibling_terms( string $taxonomy, int $parent_id ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$siblings = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$siblings[] = $term;
			}
		}

		return self::sort_sibling_terms( $siblings );
	}

	/**
	 * @param array<int, array<int, \WP_Term>> $by_parent Terms grouped by parent.
	 * @return array<int, array<string, mixed>>
	 */
	private static function nest( string $taxonomy, array $by_parent, int $parent_id ): array {
		if ( ! isset( $by_parent[ $parent_id ] ) ) {
			return array();
		}

		$nodes = array();
		$siblings = $by_parent[ $parent_id ];
		$count    = count( $siblings );
		foreach ( $siblings as $index => $term ) {
			$children = self::nest( $taxonomy, $by_parent, (int) $term->term_id );
			$type     = Node_Type::get_assignment( $taxonomy, (int) $term->term_id );
			$nodes[]  = array(
				'id'          => (int) $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'shortDescription' => self::get_short_description( (int) $term->term_id ),
				'parent'      => (int) $term->parent,
				'count'       => (int) $term->count,
				'position'    => self::get_position( (int) $term->term_id ),
				'index'       => $index,
				'canMoveUp'   => $index > 0,
				'canMoveDown' => $index < $count - 1,
				'children'    => $children,
				'hasChildren' => count( $children ) > 0,
				'typeLabel'   => is_array( $type ) ? (string) $type['name'] : '',
			);
		}

		return $nodes;
	}

	/**
	 * Serialize a single term for the side panel.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_node( string $taxonomy, int $term_id ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		$parent_name = '';
		if ( $term->parent ) {
			$parent = get_term( (int) $term->parent, $taxonomy );
			if ( $parent instanceof \WP_Term ) {
				$parent_name = $parent->name;
			}
		}

		return array(
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'shortDescription' => self::get_short_description( (int) $term->term_id ),
			'parent'      => (int) $term->parent,
			'parentName'  => $parent_name,
			'count'       => (int) $term->count,
			'hasChildren' => self::term_has_children( $taxonomy, (int) $term->term_id ),
			'typeId'      => Node_Type::get_type_id( (int) $term->term_id ),
			'type'        => Node_Type::get_assignment( $taxonomy, (int) $term->term_id ),
			'typeOptions' => Node_Type::get_picker_options( $taxonomy, (int) $term->term_id ),
			'required'    => Node_Type::is_required( (int) $term->term_id ),
			'hasFooter'   => Node_Type::has_footer( (int) $term->term_id ),
			'setSeparator'=> Node_Type::get_set_separator( (int) $term->term_id ),
			'setJoinUnits'=> Node_Type::get_set_join_units( (int) $term->term_id ),
			'setLabelChildren' => Node_Type::get_set_label_children( (int) $term->term_id ),
			'isTable'     => Node_Type::has_type_named( $taxonomy, (int) $term->term_id, 'table' ),
			'isSet'       => Node_Type::is_set_typed( $taxonomy, (int) $term->term_id ),
			'fixedEnabled'=> Node_Type::is_fixed_enabled( (int) $term->term_id ),
			'fixedLiteral'=> Node_Type::get_fixed_literal( (int) $term->term_id ),
			'fixedNodeId' => Node_Type::get_fixed_node_id( (int) $term->term_id ),
			'fixed'       => Node_Type::get_fixed_assignment( $taxonomy, (int) $term->term_id ),
			'fixedOptions'=> Node_Type::get_fixed_picker_options( $taxonomy, (int) $term->term_id ),
			'typeBranch'  => Node_Type::is_basiseinheit_unit_node(
				$taxonomy,
				Node_Type::get_type_id( (int) $term->term_id )
			)
				? null
				: Node_Type::get_type_branch( $taxonomy, (int) $term->term_id ),
			'setMembers'  => Node_Type::get_set_members( $taxonomy, (int) $term->term_id ),
			'setParent'   => Node_Type::get_set_parent( $taxonomy, (int) $term->term_id ),
			'helpChildren'=> Node_Type::get_help_children( $taxonomy, (int) $term->term_id ),
			'isBasiseinheitUnit' => Node_Type::is_basiseinheit_unit_node( $taxonomy, (int) $term->term_id ),
			'prefixAllowlist'    => Node_Type::get_prefix_allowlist( $taxonomy, (int) $term->term_id ),
			'prefixRootToSi'     => Node_Type::is_basiseinheit_unit_node( $taxonomy, (int) $term->term_id )
				? Node_Type::get_prefix_root_to_si( (int) $term->term_id )
				: null,
			'multiplikator'      => Node_Type::get_multiplikator( (int) $term->term_id ),
			'quantitySchema'     => Node_Type::get_quantity_schema_for_type(
				$taxonomy,
				Node_Type::get_type_id( (int) $term->term_id )
			),
		);
	}

	public static function term_has_children( string $taxonomy, int $term_id ): bool {
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 1,
			)
		);

		return is_array( $children ) && count( $children ) > 0;
	}

	/**
	 * Update display name and/or description (identity stays on term_id).
	 *
	 * @return true|\WP_Error
	 */
	public static function update_term_fields( string $taxonomy, int $term_id, ?string $name = null, ?string $description = null ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		$args = array();

		if ( null !== $name ) {
			$name = trim( $name );
			if ( '' === $name ) {
				return new \WP_Error( 'wtt_empty_name', __( 'Name is required.', 'wp-taxonomy-tree' ) );
			}
			if ( $term->name !== $name ) {
				$args['name'] = $name;
			}
		}

		if ( null !== $description && $term->description !== $description ) {
			$args['description'] = $description;
		}

		if ( empty( $args ) ) {
			return true;
		}

		$result = wp_update_term( $term_id, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function rename_term( string $taxonomy, int $term_id, string $name ) {
		return self::update_term_fields( $taxonomy, $term_id, $name, null );
	}

	/**
	 * Shallow-copy a term as the next sibling (same parent). Copies description + WTT settings, not children.
	 * Identity of the copy is a new term_id; links stay ID-based.
	 *
	 * @return array<string, mixed>|\WP_Error Serialized node of the copy.
	 */
	public static function copy_term_as_sibling( string $taxonomy, int $term_id ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		$parent_id = (int) $term->parent;
		$name      = self::unique_sibling_name( $taxonomy, $parent_id, $term->name );

		$result = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'parent'      => $parent_id,
				'description' => $term->description,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$new_id     = (int) $result['term_id'];
		$source_pos = self::get_position( $term_id );
		$siblings   = self::get_sibling_terms( $taxonomy, $parent_id );

		foreach ( $siblings as $sibling ) {
			$sib_id = (int) $sibling->term_id;
			if ( $sib_id === $new_id ) {
				continue;
			}
			$pos = self::get_position( $sib_id );
			if ( $pos > $source_pos ) {
				self::set_position( $sib_id, $pos + 1 );
			}
		}
		self::set_position( $new_id, $source_pos + 1 );

		Node_Type::copy_settings( $taxonomy, $term_id, $new_id );
		self::set_short_description( $taxonomy, $new_id, self::get_short_description( $term_id ) );

		return self::get_node( $taxonomy, $new_id );
	}

	/**
	 * @return string
	 */
	private static function unique_sibling_name( string $taxonomy, int $parent_id, string $base_name ): string {
		$base_name = trim( $base_name );
		if ( '' === $base_name ) {
			$base_name = __( 'Copy', 'wp-taxonomy-tree' );
		}

		$suffix = __( ' (copy)', 'wp-taxonomy-tree' );
		$candidate = $base_name . $suffix;
		$n = 2;

		while ( self::sibling_name_exists( $taxonomy, $parent_id, $candidate ) ) {
			$candidate = $base_name . $suffix . ' ' . $n;
			++$n;
			if ( $n > 200 ) {
				$candidate = $base_name . $suffix . ' ' . wp_generate_password( 4, false );
				break;
			}
		}

		return $candidate;
	}

	private static function sibling_name_exists( string $taxonomy, int $parent_id, string $name ): bool {
		$matches = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'name'       => $name,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
			)
		);

		return is_array( $matches ) && count( $matches ) > 0;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_term( string $taxonomy, string $name, int $parent = 0 ) {
		$name = trim( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'wtt_empty_name', __( 'Name is required.', 'wp-taxonomy-tree' ) );
		}

		if ( $parent > 0 ) {
			$parent_term = get_term( $parent, $taxonomy );
			if ( ! $parent_term instanceof \WP_Term ) {
				return new \WP_Error( 'wtt_bad_parent', __( 'Parent term not found.', 'wp-taxonomy-tree' ) );
			}
		}

		$position = self::next_sibling_position( $taxonomy, max( 0, $parent ) );

		$result = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'parent' => max( 0, $parent ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = (int) $result['term_id'];
		self::set_position( $term_id, $position );

		return self::get_node( $taxonomy, $term_id );
	}

	/**
	 * Delete a term. Mode: leaf | promote | cascade.
	 *
	 * @return true|\WP_Error
	 */
	public static function delete_term( string $taxonomy, int $term_id, string $mode = 'leaf' ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		$has_children = self::term_has_children( $taxonomy, $term_id );

		if ( $has_children && 'leaf' === $mode ) {
			return new \WP_Error(
				'wtt_has_children',
				__( 'Term has children. Choose promote or cascade.', 'wp-taxonomy-tree' )
			);
		}

		if ( $has_children && 'promote' === $mode ) {
			$children = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $term_id,
					'hide_empty' => false,
				)
			);
			if ( is_array( $children ) ) {
				foreach ( $children as $child ) {
					if ( ! $child instanceof \WP_Term ) {
						continue;
					}
					$updated = wp_update_term(
						(int) $child->term_id,
						$taxonomy,
						array( 'parent' => (int) $term->parent )
					);
					if ( is_wp_error( $updated ) ) {
						return $updated;
					}
				}
			}
		}

		if ( $has_children && 'cascade' === $mode ) {
			$deleted = self::delete_descendants( $taxonomy, $term_id );
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
		}

		$result = wp_delete_term( $term_id, $taxonomy );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result || 0 === $result ) {
			return new \WP_Error( 'wtt_delete_failed', __( 'Could not delete term.', 'wp-taxonomy-tree' ) );
		}

		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function delete_descendants( string $taxonomy, int $term_id ) {
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $children ) ) {
			return true;
		}

		foreach ( $children as $child ) {
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			$nested = self::delete_descendants( $taxonomy, (int) $child->term_id );
			if ( is_wp_error( $nested ) ) {
				return $nested;
			}
			$result = wp_delete_term( (int) $child->term_id, $taxonomy );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}
}
