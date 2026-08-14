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

	/** Last editor user id (audit). */
	public const META_KEY_MODIFIED_BY = '_wtt_modified_by';

	/** Last modification unix timestamp (audit). */
	public const META_KEY_MODIFIED_AT = '_wtt_modified_at';

	/**
	 * Record last modification (user + time) on a node.
	 */
	public static function touch_modified( int $term_id, ?int $user_id = null ): void {
		if ( $term_id <= 0 ) {
			return;
		}
		$uid = null !== $user_id ? max( 0, $user_id ) : (int) get_current_user_id();
		if ( $uid > 0 ) {
			update_term_meta( $term_id, self::META_KEY_MODIFIED_BY, $uid );
		}
		update_term_meta( $term_id, self::META_KEY_MODIFIED_AT, time() );
		/* Request memos must not survive host mutations in the same request. */
		if ( class_exists( Attribute::class ) ) {
			Attribute::bust_request_caches();
		}
	}

	/**
	 * @return array{userId:int,userName:string,at:int,atLabel:string}|null
	 */
	public static function get_modified_info( int $term_id ): ?array {
		if ( $term_id <= 0 ) {
			return null;
		}
		$at = (int) get_term_meta( $term_id, self::META_KEY_MODIFIED_AT, true );
		$by = (int) get_term_meta( $term_id, self::META_KEY_MODIFIED_BY, true );
		if ( $at <= 0 && $by <= 0 ) {
			return null;
		}
		$user_name = '';
		if ( $by > 0 ) {
			$user = get_userdata( $by );
			$user_name = $user instanceof \WP_User ? $user->display_name : ( '#' . $by );
		}
		$at_label = '';
		if ( $at > 0 ) {
			$at_label = wp_date(
				trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
				$at
			);
		}
		return array(
			'userId'   => $by,
			'userName' => $user_name,
			'at'       => $at,
			'atLabel'  => $at_label,
		);
	}

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

		Node_Type::repair_legacy_preferred_form_seeds( $taxonomy );

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

		Trash::ensure_trash_node( $taxonomy );
		Hidden_Nodes::ensure_bin( $taxonomy );
		/*
		 * One-shot: detach legacy attribute slots from host hierarchy.
		 * Must not re-run on every tree fetch (AJAX save/reload) — that fights
		 * live edits and can look like settings/attrs “reverting”.
		 */
		$detach_flag = 'wtt_migrated_detach_attr_hierarchy_' . sanitize_key( $taxonomy );
		if ( '1' !== (string) get_option( $detach_flag, '' ) ) {
			Attribute::migrate_detach_hierarchy( $taxonomy );
			update_option( $detach_flag, '1', false );
		}

		$tree = self::nest( $taxonomy, $by_parent, 0 );
		self::attach_model_data_counts( $taxonomy, $tree );
		return $tree;
	}

	/**
	 * Attach modelDataCount for structure hosts (attribute schema) — batch, no N+1 bag reads.
	 *
	 * @param list<array<string, mixed>> $nodes Tree roots (mutated in place).
	 */
	private static function attach_model_data_counts( string $taxonomy, array &$nodes ): void {
		if ( ! class_exists( Model_Data::class ) || empty( $nodes ) ) {
			return;
		}

		$counts = Model_Data::counts_by_structure( $taxonomy, false );
		$hosts  = Model_Data::structure_host_id_set( $taxonomy );
		if ( empty( $hosts ) ) {
			return;
		}

		self::walk_attach_model_data_counts( $nodes, $counts, $hosts );
	}

	/**
	 * @param list<array<string, mixed>> $nodes
	 * @param array<int, int>            $counts
	 * @param array<int, true>           $hosts
	 */
	private static function walk_attach_model_data_counts( array &$nodes, array $counts, array $hosts ): void {
		foreach ( $nodes as &$node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$id = (int) ( $node['id'] ?? 0 );
			if ( $id > 0 && isset( $hosts[ $id ] ) ) {
				$node['modelDataCount'] = (int) ( $counts[ $id ] ?? 0 );
				$node['isModelDataHost'] = true;
			}
			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				self::walk_attach_model_data_counts( $node['children'], $counts, $hosts );
			}
		}
		unset( $node );
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
	 * WP term descriptions store <>& as HTML entities (e.g. -> becomes -&gt;).
	 * Decode for admin UI / JSON so editors see plain text.
	 */
	public static function decode_term_description( string $description ): string {
		if ( '' === $description ) {
			return '';
		}
		return html_entity_decode( $description, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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
	 * @param bool $normalize_positions When false, caller places siblings (batch reparent).
	 * @return true|\WP_Error
	 */
	public static function reparent_term( string $taxonomy, int $term_id, int $new_parent_id, bool $normalize_positions = true ) {
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

		if ( $normalize_positions ) {
			self::normalize_sibling_positions( self::get_sibling_terms( $taxonomy, $old_parent ) );
			/* New siblings: append moved term at end, then renumber. */
			self::place_among_siblings( $taxonomy, $new_parent_id, array( $term_id ), 0 );
		}

		/* Q88: after reparent, specialization type follows the new parent when applicable. */
		Node_Type::apply_parent_as_type( $taxonomy, $term_id );
		self::touch_modified( $term_id );

		return true;
	}

	/**
	 * Reparent several terms under the same new parent (tree preorder).
	 * Optional $before_id: place the moved block immediately before that sibling (0 = append at end).
	 *
	 * @param list<int> $term_ids
	 * @return array{moved:list<int>,tree:array<int,array<string,mixed>>}|\WP_Error
	 */
	public static function reparent_terms( string $taxonomy, array $term_ids, int $new_parent_id, int $before_id = 0 ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}

		$wanted = array();
		foreach ( $term_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$wanted[ $id ] = true;
			}
		}
		if ( empty( $wanted ) ) {
			return new \WP_Error( 'wtt_empty_selection', __( 'Nothing selected to move.', 'wp-taxonomy-tree' ) );
		}

		if ( $new_parent_id > 0 ) {
			$parent = get_term( $new_parent_id, $taxonomy );
			if ( ! $parent instanceof \WP_Term ) {
				return new \WP_Error( 'wtt_bad_parent', __( 'Parent term not found.', 'wp-taxonomy-tree' ) );
			}
		}

		$before_id = (int) $before_id;
		if ( $before_id > 0 ) {
			$before_term = get_term( $before_id, $taxonomy );
			if ( ! $before_term instanceof \WP_Term ) {
				return new \WP_Error( 'wtt_bad_before', __( 'Insert-before target not found.', 'wp-taxonomy-tree' ) );
			}
			if ( (int) $before_term->parent !== $new_parent_id ) {
				return new \WP_Error(
					'wtt_bad_before',
					__( 'Insert-before target must be a sibling under the new parent.', 'wp-taxonomy-tree' )
				);
			}
			if ( isset( $wanted[ $before_id ] ) ) {
				$before_id = 0;
			}
		}

		$ordered = array();
		self::collect_preorder_ids( self::get_tree( $taxonomy ), $wanted, $ordered );
		if ( empty( $ordered ) ) {
			return new \WP_Error( 'wtt_not_found', __( 'Selected terms were not found in the tree.', 'wp-taxonomy-tree' ) );
		}

		$old_parents = array();
		foreach ( $ordered as $term_id ) {
			$term = get_term( $term_id, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$old_parents[ (int) $term->parent ] = true;
			}
			$result = self::reparent_term( $taxonomy, $term_id, $new_parent_id, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		foreach ( array_keys( $old_parents ) as $old_parent ) {
			if ( (int) $old_parent !== $new_parent_id ) {
				self::normalize_sibling_positions( self::get_sibling_terms( $taxonomy, (int) $old_parent ) );
			}
		}
		self::place_among_siblings( $taxonomy, $new_parent_id, $ordered, $before_id );

		return array(
			'moved' => $ordered,
			'tree'  => self::get_tree( $taxonomy ),
		);
	}

	/**
	 * Place $moved_ids as a contiguous block among siblings of $parent_id.
	 * $before_id 0 = append at end; otherwise insert immediately before that sibling.
	 *
	 * @param list<int> $moved_ids
	 */
	public static function place_among_siblings( string $taxonomy, int $parent_id, array $moved_ids, int $before_id = 0 ): void {
		$siblings = self::get_sibling_terms( $taxonomy, $parent_id );
		if ( empty( $siblings ) ) {
			return;
		}

		$moved_set = array();
		foreach ( $moved_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$moved_set[ $id ] = true;
			}
		}

		$moved_ordered = array();
		foreach ( $moved_ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 || ! isset( $moved_set[ $id ] ) ) {
				continue;
			}
			$term = get_term( $id, $taxonomy );
			if ( $term instanceof \WP_Term && (int) $term->parent === $parent_id ) {
				$moved_ordered[] = $id;
			}
		}

		$others = array();
		foreach ( self::sort_sibling_terms( $siblings ) as $sib ) {
			$id = (int) $sib->term_id;
			if ( ! isset( $moved_set[ $id ] ) ) {
				$others[] = $id;
			}
		}

		$before_id = (int) $before_id;
		$out       = array();
		$inserted  = false;
		if ( $before_id <= 0 ) {
			$out = array_merge( $others, $moved_ordered );
		} else {
			foreach ( $others as $oid ) {
				if ( ! $inserted && $oid === $before_id ) {
					foreach ( $moved_ordered as $mid ) {
						$out[] = $mid;
					}
					$inserted = true;
				}
				$out[] = $oid;
			}
			if ( ! $inserted ) {
				$out = array_merge( $out, $moved_ordered );
			}
		}

		foreach ( $out as $i => $id ) {
			self::set_position( (int) $id, (int) $i );
		}
	}

	/**
	 * @param array<int, \WP_Term> $siblings
	 * @return array<int, \WP_Term>
	 */
	private static function sort_sibling_terms( array $siblings ): array {
		usort(
			$siblings,
			static function ( \WP_Term $a, \WP_Term $b ): int {
				$ra = self::sibling_sort_rank( (int) $a->term_id );
				$rb = self::sibling_sort_rank( (int) $b->term_id );
				if ( $ra !== $rb ) {
					return $ra <=> $rb;
				}
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
	 * Force system bins after normal siblings: Trash near end, Hidden nodes last.
	 */
	private static function sibling_sort_rank( int $term_id ): int {
		if ( Hidden_Nodes::is_bin( $term_id ) ) {
			return 2;
		}
		if ( Trash::is_trash_node( $term_id ) ) {
			return 1;
		}
		return 0;
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
		/* Drop soft-deleted nodes from the normal tree. */
		$visible = array();
		foreach ( $siblings as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$tid = (int) $term->term_id;
			if ( Trash::is_trashed( $tid ) ) {
				continue;
			}
			if ( Hidden_Nodes::is_hidden( $tid ) ) {
				continue;
			}
			/* Attribute slots are Bindung targets only — never hierarchy children in the tree. */
			if ( Attribute::is_slot( $tid ) ) {
				continue;
			}
			if ( $parent_id > 0 && Attribute::is_own_member( $taxonomy, $parent_id, $tid ) ) {
				continue;
			}
			$visible[] = $term;
		}
		$count = count( $visible );
		foreach ( $visible as $index => $term ) {
			$term_id       = (int) $term->term_id;
			$is_trash      = Trash::is_trash_node( $term_id );
			$is_hidden_bin = Hidden_Nodes::is_bin( $term_id );
			/*
			 * Trash / Hidden bins: do not show WP children — show soft-deleted /
			 * explicitly hidden forests instead.
			 */
			if ( $is_trash ) {
				$children = Trash::build_trashed_forest( $taxonomy );
			} elseif ( $is_hidden_bin ) {
				$children = Hidden_Nodes::build_hidden_forest( $taxonomy );
			} else {
				$children = self::nest( $taxonomy, $by_parent, $term_id );
			}
			$type     = Node_Type::get_assignment( $taxonomy, $term_id );
			$is_table = Node_Type::has_type_named( $taxonomy, $term_id, 'table' )
				|| Node_Type::is_table_type_catalog( $taxonomy, $term_id );
			$table_invalid   = false;
			$table_error_hint = '';
			if ( $is_table ) {
				$validation       = Table_Validator::validate( $taxonomy, $term_id );
				$table_invalid    = empty( $validation['ok'] );
				$table_error_hint = $table_invalid && ! empty( $validation['errors'] )
					? implode( ' ', array_map( 'strval', array_slice( $validation['errors'], 0, 2 ) ) )
					: '';
			}
			$nodes[]  = array(
				'id'          => $term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => self::decode_term_description( (string) $term->description ),
				'shortDescription' => self::get_short_description( $term_id ),
				'icon'        => Tree_Icons::get( $term_id ),
				'parent'      => (int) $term->parent,
				'count'       => (int) $term->count,
				'position'    => self::get_position( $term_id ),
				'index'       => $index,
				'canMoveUp'   => $index > 0,
				'canMoveDown' => $index < $count - 1,
				'type'        => $type,
				'typeId'      => Node_Type::get_effective_type_id( $taxonomy, $term_id ),
				'ownTypeId'   => Node_Type::get_type_id( $term_id ),
				'typeLabel'   => is_array( $type ) ? ( 'subtree' === strtolower( (string) $type['name'] ) ? 'node_embed' : (string) $type['name'] ) : '',
				'typeInheriting' => Node_Type::is_type_inheriting( $term_id ),
				'typeOverride'   => Node_Type::is_type_override( $term_id ),
				'isTemplate'  => Node_Type::is_template( $term_id ),
				'deletable'   => ( $is_trash || $is_hidden_bin ) ? false : Node_Type::is_deletable( $term_id ),
				'isTrash'     => $is_trash,
				'isHiddenBin' => $is_hidden_bin,
				'hidden'      => false,
				'trashed'     => false,
				'required'    => Node_Type::is_required( $term_id ),
				'refScopeId'  => Node_Type::get_ref_scope_id( $term_id ),
				'allowedRefIds' => Node_Type::get_allowed_ref_ids( $term_id ),
				'fixedNodeId' => Node_Type::get_fixed_node_id( $term_id ),
				'fixed'       => Node_Type::get_fixed_assignment( $taxonomy, $term_id ),
				'isTable'     => $is_table,
				'tableInvalid'=> $table_invalid,
				'tableErrorHint' => $table_error_hint,
				'children'    => $children,
				'hasChildren' => count( $children ) > 0,
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

		$node = array(
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => self::decode_term_description( (string) $term->description ),
			'shortDescription' => self::get_short_description( (int) $term->term_id ),
			'icon'        => Tree_Icons::get( (int) $term->term_id ),
			'parent'      => (int) $term->parent,
			'parentName'  => $parent_name,
			'count'       => (int) $term->count,
			'hasChildren' => self::term_has_children( $taxonomy, (int) $term->term_id ),
			'modified'    => self::get_modified_info( (int) $term->term_id ),
			'trashed'     => Trash::is_trashed( (int) $term->term_id ),
			'isTrash'     => Trash::is_trash_node( (int) $term->term_id ),
			'hidden'      => Hidden_Nodes::is_hidden( (int) $term->term_id ),
			'isHiddenBin' => Hidden_Nodes::is_bin( (int) $term->term_id ),
			'typeId'      => Node_Type::get_effective_type_id( $taxonomy, (int) $term->term_id ),
			'ownTypeId'   => Node_Type::get_type_id( (int) $term->term_id ),
			'type'        => Node_Type::get_assignment( $taxonomy, (int) $term->term_id ),
			'typeInheriting' => Node_Type::is_type_inheriting( (int) $term->term_id ),
			'typeOverride'   => Node_Type::is_type_override( (int) $term->term_id ),
			'canInheritType' => Node_Type::can_inherit_type( $taxonomy, (int) $term->term_id ),
			'inheritedTypeId'=> Node_Type::find_inherited_type_id( $taxonomy, (int) $term->term_id ),
			'typeIsParent'   => Node_Type::is_typed_as_parent( $taxonomy, (int) $term->term_id ),
			'freeTypeLocked' => Node_Type::is_free_type_assignment_locked( $taxonomy, (int) $term->term_id ),
			'typeOptions' => Node_Type::get_picker_options( $taxonomy, (int) $term->term_id ),
			'datatypeTree'=> Node_Type::get_datatype_tree( $taxonomy ),
			'isTemplate'  => Node_Type::is_template( (int) $term->term_id ),
			'deletable'   => (
				Trash::is_trash_node( (int) $term->term_id ) || Hidden_Nodes::is_bin( (int) $term->term_id )
			) ? false : Node_Type::is_deletable( (int) $term->term_id ),
			'required'    => Node_Type::is_required( (int) $term->term_id ),
			'hasFooter'   => Node_Type::has_footer( (int) $term->term_id ),
			'footerOp'    => Node_Type::get_footer_op( (int) $term->term_id ),
			'fussFieldContext' => Node_Type::get_fuss_field_context( $taxonomy, (int) $term->term_id ),
			'setSeparator'=> Node_Type::get_set_separator( (int) $term->term_id ),
			'setJoinUnits'=> Node_Type::get_set_join_units( (int) $term->term_id ),
			'setLabelChildren' => Node_Type::get_set_label_children( (int) $term->term_id ),
			'isTable'     => Node_Type::has_type_named( $taxonomy, (int) $term->term_id, 'table' ),
			'isTableTypeCatalog' => Node_Type::is_table_type_catalog( $taxonomy, (int) $term->term_id ),
			'tableValidation' => (
				Node_Type::has_type_named( $taxonomy, (int) $term->term_id, 'table' )
				|| Node_Type::is_table_type_catalog( $taxonomy, (int) $term->term_id )
			)
				? Table_Validator::validate( $taxonomy, (int) $term->term_id )
				: null,
			'typeProps'   => Node_Type::get_type_props( (int) $term->term_id ),
			'effectiveTypeProps' => Node_Type::get_effective_type_props( $taxonomy, (int) $term->term_id ),
			/* Cast so empty bindings encode as {} not [] (JS Array + stringify drops keys). */
			'propBindings'=> (object) Node_Type::get_prop_bindings( (int) $term->term_id ),
			'directChildren' => Node_Type::get_direct_child_options( $taxonomy, (int) $term->term_id ),
			'enumOptions' => (
				Node_Type::is_enum_typed_field( $taxonomy, (int) $term->term_id )
				|| Node_Type::is_concrete_enum_type( $taxonomy, (int) $term->term_id )
			)
				? Node_Type::get_enum_options( $taxonomy, (int) $term->term_id )
				: array(),
			'isConcreteEnum' => Node_Type::is_concrete_enum_type( $taxonomy, (int) $term->term_id ),
			'isSet'       => Node_Type::is_set_typed( $taxonomy, (int) $term->term_id ),
			'isAttributeSlot' => Attribute::is_slot( (int) $term->term_id ),
			'readonly'    => Node_Type::is_readonly( (int) $term->term_id ),
			'fixedEnabled'=> Node_Type::is_fixed_enabled( (int) $term->term_id ),
			'fixedLiteral'=> Node_Type::get_fixed_literal( (int) $term->term_id ),
			'fixedNodeId' => Node_Type::get_fixed_node_id( (int) $term->term_id ),
			'fixed'       => Node_Type::get_fixed_assignment( $taxonomy, (int) $term->term_id ),
			'fixedOptions'=> Node_Type::get_fixed_picker_options( $taxonomy, (int) $term->term_id ),
			'refScopeId'  => Node_Type::get_ref_scope_id( (int) $term->term_id ),
			'refScope'    => Node_Type::get_ref_scope_assignment( $taxonomy, (int) $term->term_id ),
			'fieldMultiplicity' => Node_Type::get_field_multiplicity( (int) $term->term_id ),
			'allowedRefIds' => Node_Type::get_allowed_ref_ids( (int) $term->term_id ),
			'subtreeOptions' => Node_Type::get_subtree_options(
				$taxonomy,
				Node_Type::get_ref_scope_id( (int) $term->term_id )
			),
			'nodeRefOptions' => Node_Type::get_node_ref_options_for_slot(
				$taxonomy,
				(int) $term->term_id
			),
			'nodeRefCreateFields' => Composition::get_node_ref_create_fields(
				$taxonomy,
				Node_Type::get_ref_scope_id( (int) $term->term_id )
			),
			'typeBranch'  => Node_Type::is_basiseinheit_unit_node(
				$taxonomy,
				Node_Type::get_effective_type_id( $taxonomy, (int) $term->term_id )
			)
				? null
				: Node_Type::get_type_branch( $taxonomy, (int) $term->term_id ),
			'setMembers'  => Node_Type::get_set_members( $taxonomy, (int) $term->term_id ),
			'setParent'   => Node_Type::get_set_parent( $taxonomy, (int) $term->term_id ),
			'helpChildren'=> Node_Type::get_help_children( $taxonomy, (int) $term->term_id ),
			'isBasiseinheitUnit' => Node_Type::is_basiseinheit_unit_node( $taxonomy, (int) $term->term_id ),
			'isKonstantenHost'   => Node_Type::is_konstanten_child_list_host( $taxonomy, (int) $term->term_id ),
			'prefixAllowlist'    => Node_Type::get_prefix_allowlist( $taxonomy, (int) $term->term_id ),
			'prefixRootToSi'     => Node_Type::is_basiseinheit_unit_node( $taxonomy, (int) $term->term_id )
				? Node_Type::get_prefix_root_to_si( (int) $term->term_id )
				: null,
			'multiplikator'      => Node_Type::get_multiplikator( (int) $term->term_id ),
			'quantitySchema'     => Node_Type::get_quantity_schema_for_type(
				$taxonomy,
				Node_Type::is_basiseinheit_unit_node( $taxonomy, (int) $term->term_id )
					? (int) $term->term_id
					: Node_Type::get_effective_type_id( $taxonomy, (int) $term->term_id )
			),
			'mediaConfig'        => Node_Type::get_media_config_for_node( $taxonomy, (int) $term->term_id ),
			'dateConfig'         => Node_Type::get_date_config_for_node( $taxonomy, (int) $term->term_id ),
			'textareaConfig'     => Node_Type::get_textarea_config_for_node( $taxonomy, (int) $term->term_id ),
			'presentationConfig' => Node_Type::get_presentation_config_for_node( $taxonomy, (int) $term->term_id ),
			'presentation'       => class_exists( Node_Presentation::class )
				? Node_Presentation::map_for_term_resolved( $taxonomy, (int) $term->term_id )
				: array(),
			'intConfig'          => Node_Type::get_int_config_for_node( $taxonomy, (int) $term->term_id ),
			'preferredConverter' => Node_Type::get_preferred_converter_for_node( $taxonomy, (int) $term->term_id ),
			'validators'         => Node_Type::get_validators_for_node( $taxonomy, (int) $term->term_id ),
			'preferredRender'    => Node_Type::get_preferred_render( (int) $term->term_id ),
			'preferredRenderOwn' => Node_Type::get_own_preferred_render( (int) $term->term_id ),
			'preferredRenderInherited' => ! Node_Type::has_own_preferred_render( (int) $term->term_id )
				&& '' !== Node_Type::preferred_render_from_ancestors( (int) $term->term_id ),
			'embedChoiceOptions' => class_exists( Attribute::class )
				? Attribute::embed_choice_options_for_type( $taxonomy, (int) $term->term_id )
				: array(),
			'relationsStored'    => self::get_stored_relations_payload( $taxonomy, (int) $term->term_id ),
			/*
			 * RelationType catalog is taxonomy-wide and heavy — not loaded on every
			 * get_node. Tree admin fetches via wtt_get_relation_types when the
			 * Relations panel is expanded (collapsed by default).
			 */
			'relationTypeTree'   => array(),
			'relationTypeOptions'=> array(),
			'relationMultiplicityOptions' => Relation::multiplicity_options(),
			'attributes'  => array(), /* filled below — single Attribute::list */
			'attributeValidation' => null,
			'attributeMoveChildren' => Attribute::list_eligible_move_children(
				$taxonomy,
				(int) $term->term_id
			),
			/* UR-S1: gate structural-change confirm when host has live instances. */
			'hasModelInstances' => false,
			'modelVersion'      => class_exists( Model_Version::class )
				? Model_Version::get( $taxonomy, (int) $term->term_id )
				: 1,
			/* UR-S1: red-badge → Model versions deep-link when stamps diverge. */
			'conflictCount'     => class_exists( Model_Version::class )
				? Model_Version::conflict_count( $taxonomy, (int) $term->term_id )
				: 0,
			'quantityPreviewExample' => Node_Type::is_quantity_type_catalog_node(
				$taxonomy,
				(int) $term->term_id
			)
				? Node_Type::get_quantity_preview_example( $taxonomy, (int) $term->term_id )
				: null,
		);

		$t0 = microtime( true );
		/* One Attribute::list — validator + model-host checks reuse the rows (no N+1 re-decorate). */
		$attrs = Attribute::list( $taxonomy, (int) $term->term_id );
		$node['attributes']           = $attrs;
		$node['attributeValidation']  = Attribute_Validator::validate_rows( $attrs );

		/* Model_Data host label counts (attribute schema → Fill Model Data). */
		$model_data_count   = null;
		$is_model_data_host = false;
		$model_instances    = array();
		if ( class_exists( Model_Data::class ) ) {
			$visible_attrs = 0;
			foreach ( $attrs as $attr_row ) {
				if ( is_array( $attr_row ) && empty( $attr_row['hidden'] ) ) {
					++$visible_attrs;
				}
			}
			if ( $visible_attrs > 0 ) {
				$is_model_data_host = true;
				$model_instances    = Model_Data::list( $taxonomy, (int) $term->term_id, false );
				$model_data_count   = count( $model_instances );
			}
		}
		$node['hasModelInstances'] = $model_data_count > 0;
		$node['modelDataCount']    = $model_data_count;
		$node['isModelDataHost']   = $is_model_data_host;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$node['_timing'] = array(
				'getNodeAttrsMs' => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
				'attrCount'      => count( $attrs ),
			);
		}

		if ( Trash::is_trash_node( (int) $term->term_id ) ) {
			$node = array_merge( $node, Trash::trash_node_payload( $taxonomy, (int) $term->term_id ) );
		}
		if ( Hidden_Nodes::is_bin( (int) $term->term_id ) ) {
			$node = array_merge( $node, Hidden_Nodes::bin_payload( $taxonomy, (int) $term->term_id ) );
		}

		return $node;
	}

	/**
	 * Stored additive Relations for Node UI (Q74). Synthetic edges stay client-side.
	 *
	 * @return array{von:list<array<string,mixed>>,an:list<array<string,mixed>>}
	 */
	public static function get_stored_relations_payload( string $taxonomy, int $term_id ): array {
		$von       = array();
		$von_rows  = Relation::list_outgoing( $taxonomy, $term_id );
		$von_count = count( $von_rows );
		foreach ( $von_rows as $edge ) {
			$index = (int) ( $edge['index'] ?? 0 );
			$row   = array(
				'id'           => (string) ( $edge['id'] ?? '' ),
				'type'         => ! empty( $edge['typeLabel'] )
					? (string) $edge['typeLabel']
					: (string) ( $edge['typeName'] ?? '' ),
				'typeKey'      => (string) ( $edge['typeKey'] ?? '' ),
				'typeId'       => $edge['typeId'],
				'otherId'      => $edge['toId'],
				'otherName'    => $edge['toName'],
				'multiplicity' => (string) ( $edge['multiplicity'] ?? Relation::MULTIPLICITY_DEFAULT ),
				'notes'        => '',
				'protected'    => false,
				'stored'       => true,
				'index'        => $index,
				'canMoveUp'    => $index > 0,
				'canMoveDown'  => $index < ( $von_count - 1 ),
			);
			if ( ! empty( $edge['typeLabel'] ) ) {
				$row['typeLabel'] = (string) $edge['typeLabel'];
			}
			if ( ! empty( $edge['calcOp'] ) ) {
				$row['calcOp'] = (string) $edge['calcOp'];
			}
			if ( ! empty( $edge['settings'] ) && is_array( $edge['settings'] ) ) {
				$row['settings'] = $edge['settings'];
			}
			$name = Relation::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
			if ( Attribute::is_parked_table_band_edge( $taxonomy, $edge ) ) {
				$row['parkedTableBand'] = true;
				$row['protected']       = true;
				$row['typeLocked']      = true;
				$row['notes']           = __( 'Q90 parked table band (legacy) — not a product attribute', 'wp-taxonomy-tree' );
				if ( '' === $name && ! empty( $edge['toName'] ) ) {
					$name = Relation::normalize_edge_name( (string) $edge['toName'] );
				}
			}
			if ( '' !== $name ) {
				$row['name'] = $name;
			}
			$von[] = $row;
		}
		$an = array();
		foreach ( Relation::list_incoming( $taxonomy, $term_id ) as $edge ) {
			$row = array(
				'id'           => (string) ( $edge['id'] ?? '' ),
				'type'         => Relation::type_key_label( (string) ( $edge['typeKey'] ?? $edge['typeName'] ?? '' ) ),
				'typeKey'      => (string) ( $edge['typeKey'] ?? '' ),
				'typeId'       => $edge['typeId'],
				'otherId'      => $edge['fromId'],
				'otherName'    => $edge['fromName'],
				'multiplicity' => (string) ( $edge['multiplicity'] ?? Relation::MULTIPLICITY_DEFAULT ),
				'notes'        => '',
				'protected'    => false,
				'stored'       => true,
			);
			if ( Relation::is_calc_type_key( (string) ( $edge['typeKey'] ?? '' ) ) ) {
				$row['calcOp']    = Relation::calc_op_from_edge( $edge );
				$row['typeLabel'] = Relation::type_key_label( (string) ( $edge['typeKey'] ?? '' ) );
			}
			$name = Relation::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
			if ( '' !== $name ) {
				$row['name'] = $name;
			}
			/*
			 * Incoming to a band slot: mark when this node is the parked band
			 * (viewing Zeile/Kopf/Fuss itself). Outgoing flag uses toId.
			 */
			$from_edge = array(
				'toId'    => $term_id,
				'typeKey' => (string) ( $edge['typeKey'] ?? '' ),
			);
			if ( Attribute::is_parked_table_band_edge( $taxonomy, $from_edge ) ) {
				$row['parkedTableBand'] = true;
				$row['protected']       = true;
				$row['typeLocked']      = true;
				$row['notes']           = __( 'Q90 parked table band (legacy) — not a product attribute', 'wp-taxonomy-tree' );
			}
			$an[] = $row;
		}
		return array(
			'von' => $von,
			'an'  => $an,
		);
	}

	/**
	 * Whether the node has visible hierarchy children (same filters as nest()).
	 * Soft-trashed / hidden / attribute-slot WP children do not count.
	 */
	public static function term_has_children( string $taxonomy, int $term_id ): bool {
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 0,
			)
		);
		if ( ! is_array( $children ) || empty( $children ) ) {
			return false;
		}

		foreach ( $children as $cid ) {
			$cid = (int) $cid;
			if ( $cid <= 0 ) {
				continue;
			}
			if ( Trash::is_trashed( $cid ) ) {
				continue;
			}
			if ( Hidden_Nodes::is_hidden( $cid ) ) {
				continue;
			}
			if ( Attribute::is_slot( $cid ) ) {
				continue;
			}
			if ( $term_id > 0 && Attribute::is_own_member( $taxonomy, $term_id, $cid ) ) {
				continue;
			}
			return true;
		}

		return false;
	}

	/**
	 * Update display name and/or description (identity stays on term_id).
	 * When the name changes, regenerate the slug from the new name (scaffold:
	 * no public permalinks; slug stays readable and in sync).
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
				if ( Node_Type::is_under_type_catalog( $taxonomy, $term_id ) ) {
					$unique = Node_Type::assert_unique_datatype_name( $taxonomy, $name, $term_id );
					if ( is_wp_error( $unique ) ) {
						return $unique;
					}
				}
				$args['name'] = $name;
				/*
				 * Q79: display names may repeat; WP slugs must stay unique in the taxonomy.
				 * Always derive a unique slug — never force sanitize_title($name) alone.
				 */
				$args['slug'] = self::unique_term_slug( $taxonomy, $term_id, (int) $term->parent, $name );
			}
		}

		if ( null !== $description && self::decode_term_description( (string) $term->description ) !== $description ) {
			$args['description'] = $description;
		}

		if ( empty( $args ) ) {
			return true;
		}

		$result = wp_update_term( $term_id, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::touch_modified( $term_id );
		return true;
	}

	/**
	 * Unique term slug for this taxonomy (WP requirement), excluding $term_id.
	 * Display name may still collide across parents (Q79).
	 */
	public static function unique_term_slug( string $taxonomy, int $term_id, int $parent_id, string $name ): string {
		$base = sanitize_title( $name );
		if ( '' === $base ) {
			$base = 'term';
		}
		$probe = (object) array(
			'slug'     => $base,
			'taxonomy' => $taxonomy,
			'parent'   => max( 0, $parent_id ),
			'term_id'  => max( 0, $term_id ),
		);
		return wp_unique_term_slug( $base, $probe );
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
		$result = self::copy_terms_subset( $taxonomy, array( $term_id ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$nodes = $result['nodes'] ?? array();
		if ( empty( $nodes ) || ! is_array( $nodes[0] ) ) {
			return new \WP_Error( 'wtt_copy_failed', __( 'Copy failed.', 'wp-taxonomy-tree' ) );
		}
		return $nodes[0];
	}

	/**
	 * Copy a selected subset of terms.
	 * Parent/child is preserved only when the original parent is also in the selection
	 * (then the copy hangs under the mapped parent copy); otherwise the copy is a sibling
	 * of the source under the original parent. Settings are copied; unselected descendants are not.
	 *
	 * @param list<int> $term_ids
	 * @return array{nodes:list<array<string,mixed>>,idMap:array<int,int>,tree:array<int,array<string,mixed>>}|\WP_Error
	 */
	public static function copy_terms_subset( string $taxonomy, array $term_ids ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}

		$wanted = array();
		foreach ( $term_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$wanted[ $id ] = true;
			}
		}
		if ( empty( $wanted ) ) {
			return new \WP_Error( 'wtt_empty_selection', __( 'Nothing selected to copy.', 'wp-taxonomy-tree' ) );
		}

		$ordered = array();
		self::collect_preorder_ids( self::get_tree( $taxonomy ), $wanted, $ordered );
		if ( empty( $ordered ) ) {
			return new \WP_Error( 'wtt_not_found', __( 'Selected terms were not found in the tree.', 'wp-taxonomy-tree' ) );
		}

		$id_map = array();
		$nodes  = array();

		foreach ( $ordered as $source_id ) {
			$term = get_term( $source_id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
			}

			$orig_parent = (int) $term->parent;
			if ( $orig_parent > 0 && isset( $wanted[ $orig_parent ] ) && isset( $id_map[ $orig_parent ] ) ) {
				$new_parent = (int) $id_map[ $orig_parent ];
			} else {
				$new_parent = $orig_parent;
			}

			$name   = self::unique_sibling_name( $taxonomy, $new_parent, $term->name );
			$result = wp_insert_term(
				$name,
				$taxonomy,
				array(
					'parent'      => max( 0, $new_parent ),
					'description' => self::decode_term_description( (string) $term->description ),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$new_id = (int) $result['term_id'];
			$id_map[ $source_id ] = $new_id;

			if ( $new_parent === $orig_parent ) {
				self::place_copy_after_source( $taxonomy, $source_id, $new_id, $new_parent );
			} else {
				self::set_position( $new_id, self::next_sibling_position( $taxonomy, $new_parent ) );
			}

			Node_Type::copy_settings( $taxonomy, $source_id, $new_id );
			self::set_short_description( $taxonomy, $new_id, self::get_short_description( $source_id ) );

			$node = self::get_node( $taxonomy, $new_id );
			if ( is_wp_error( $node ) ) {
				return $node;
			}
			$nodes[] = $node;
		}

		return array(
			'nodes' => $nodes,
			'idMap' => $id_map,
			'tree'  => self::get_tree( $taxonomy ),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $nodes
	 * @param array<int, true>                 $wanted
	 * @param list<int>                        $out
	 */
	private static function collect_preorder_ids( array $nodes, array $wanted, array &$out ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! isset( $node['id'] ) ) {
				continue;
			}
			$id = (int) $node['id'];
			if ( isset( $wanted[ $id ] ) ) {
				$out[] = $id;
			}
			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				self::collect_preorder_ids( $node['children'], $wanted, $out );
			}
		}
	}

	private static function place_copy_after_source( string $taxonomy, int $source_id, int $new_id, int $parent_id ): void {
		$source_pos = self::get_position( $source_id );
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

		/*
		 * Q79: identity = term_id; instance names may repeat under different parents.
		 * Nodes under the type catalog branch need unique sibling names.
		 */
		if ( $parent > 0 && Node_Type::is_under_type_catalog( $taxonomy, $parent ) ) {
			$unique = Node_Type::assert_unique_datatype_name( $taxonomy, $name, 0, $parent );
			if ( is_wp_error( $unique ) ) {
				return $unique;
			}
		}

		$result = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'parent' => max( 0, $parent ),
				'slug'   => self::unique_term_slug( $taxonomy, 0, max( 0, $parent ), $name ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = (int) $result['term_id'];
		self::set_position( $term_id, $position );

		/* Q88: child's datatype is the parent (everyone inherits). */
		Node_Type::apply_parent_as_type( $taxonomy, $term_id );
		Node_Type::ensure_preferred_render( $taxonomy, $term_id );
		Node_Type::ensure_preferred_converter( $taxonomy, $term_id );
		Node_Type::ensure_validators( $taxonomy, $term_id );
		/* Q95: standard-by-name first, else parent copy; later parent edits do not cascade. */
		Tree_Icons::apply_on_create( $taxonomy, $term_id, max( 0, $parent ) );
		self::touch_modified( $term_id );

		return self::get_node( $taxonomy, $term_id );
	}

	/**
	 * Soft-delete (move to Trash).
	 *
	 * Modes (Q89 + promote/cascade):
	 * - leaf: trash this node only; fails if it still has children.
	 * - promote: reparent direct children to this node’s parent (term_parent /
	 *   child_of SoT), then trash this node only.
	 * - cascade: trash this node and all descendants (parent links among them kept).
	 *
	 * @return true|\WP_Error
	 */
	public static function delete_term( string $taxonomy, int $term_id, string $mode = 'leaf' ) {
		if ( ! in_array( $mode, array( 'leaf', 'promote', 'cascade' ), true ) ) {
			$mode = 'leaf';
		}

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
			$promoted = self::promote_direct_children( $taxonomy, $term );
			if ( is_wp_error( $promoted ) ) {
				return $promoted;
			}
		}

		return Trash::move_to_trash( $taxonomy, $term_id, 'cascade' === $mode );
	}

	/**
	 * Reparent direct children of $term to its parent (or root), preserving order
	 * at the deleted node’s former sibling position.
	 *
	 * @return true|\WP_Error
	 */
	private static function promote_direct_children( string $taxonomy, \WP_Term $term ) {
		$term_id    = (int) $term->term_id;
		$new_parent = (int) $term->parent;

		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $children ) || empty( $children ) ) {
			return true;
		}

		$child_ids = array();
		foreach ( $children as $child ) {
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			$cid = (int) $child->term_id;
			if ( Trash::is_trash_node( $cid ) ) {
				return new \WP_Error(
					'wtt_not_deletable',
					__( 'Cannot promote: the Trash node is a direct child.', 'wp-taxonomy-tree' )
				);
			}
			$child_ids[] = $cid;
		}
		if ( empty( $child_ids ) ) {
			return true;
		}

		$before_id = self::next_sibling_term_id( $taxonomy, $term );
		$result    = self::reparent_terms( $taxonomy, $child_ids, $new_parent, $before_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Next sibling of $term under the same parent (by position), or 0 if last.
	 */
	private static function next_sibling_term_id( string $taxonomy, \WP_Term $term ): int {
		$parent_id = (int) $term->parent;
		$term_id   = (int) $term->term_id;
		$siblings  = self::sort_sibling_terms( self::get_sibling_terms( $taxonomy, $parent_id ) );
		$found     = false;
		foreach ( $siblings as $sib ) {
			if ( ! $sib instanceof \WP_Term ) {
				continue;
			}
			$id = (int) $sib->term_id;
			if ( $found ) {
				return $id;
			}
			if ( $id === $term_id ) {
				$found = true;
			}
		}
		return 0;
	}

	/**
	 * Permanently remove all soft-deleted nodes.
	 *
	 * @return array{deleted:int}|\WP_Error
	 */
	public static function empty_trash( string $taxonomy ) {
		return Trash::empty_trash( $taxonomy );
	}

	/**
	 * Hide a node from the normal tree (keeps term + parent links).
	 *
	 * @return true|\WP_Error
	 */
	public static function hide_term( string $taxonomy, int $term_id ) {
		return Hidden_Nodes::hide( $taxonomy, $term_id );
	}

	/**
	 * Unhide a node so it reappears under its WP parent when visible.
	 *
	 * @return true|\WP_Error
	 */
	public static function unhide_term( string $taxonomy, int $term_id ) {
		return Hidden_Nodes::unhide( $taxonomy, $term_id );
	}

	/**
	 * First non-deletable descendant term id, or 0.
	 */
	private static function find_non_deletable_descendant( string $taxonomy, int $term_id ): int {
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $children ) ) {
			return 0;
		}
		foreach ( $children as $child ) {
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			$cid = (int) $child->term_id;
			if ( ! Node_Type::is_deletable( $cid ) ) {
				return $cid;
			}
			$nested = self::find_non_deletable_descendant( $taxonomy, $cid );
			if ( $nested > 0 ) {
				return $nested;
			}
		}
		return 0;
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
			$cid = (int) $child->term_id;
			if ( ! Node_Type::is_deletable( $cid ) ) {
				return new \WP_Error(
					'wtt_not_deletable',
					sprintf(
						/* translators: %s: node name */
						__( 'Cannot delete protected node “%s”.', 'wp-taxonomy-tree' ),
						$child->name
					)
				);
			}
			$nested = self::delete_descendants( $taxonomy, $cid );
			if ( is_wp_error( $nested ) ) {
				return $nested;
			}
			$result = wp_delete_term( $cid, $taxonomy );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( false === $result || 0 === $result ) {
				return new \WP_Error( 'wtt_delete_failed', __( 'Could not delete term.', 'wp-taxonomy-tree' ) );
			}
		}

		return true;
	}
}
