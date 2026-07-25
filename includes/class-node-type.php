<?php
/**
 * Node data-type assignment (has_type scaffold over term meta).
 *
 * Types are chosen from the Typen branch (Datentypen, Praefixe, Basiseinheit — Q26).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists and validates data-type bindings on taxonomy terms.
 */
final class Node_Type {

	public const META_KEY = '_wtt_type_id';

	/** Slot / set-member fill rule (proto Node.config.required). */
	public const META_KEY_REQUIRED = '_wtt_required';

	/** Table setting: show Fußzeile (proto Node.config.footer.enabled). */
	public const META_KEY_HAS_FOOTER = '_wtt_has_footer';

	/** Set member separator in labels/display (default `/`). */
	public const META_KEY_SET_SEPARATOR = '_wtt_set_separator';

	/** When all set members share a type, join unit once in display (default on). */
	public const META_KEY_SET_JOIN_UNITS = '_wtt_set_join_units';

	/** Include child member names in set field labels, e.g. Abmessung (L/B/H) (default on). */
	public const META_KEY_SET_LABEL_CHILDREN = '_wtt_set_label_children';

	/** Fixed constant value: points at a Typen-branch Node (e.g. Einheit → Ohm). */
	public const META_KEY_FIXED_NODE = '_wtt_fixed_node_id';

	/** Whether a fixed value is active (radio: off / on). */
	public const META_KEY_FIXED_ENABLED = '_wtt_fixed_enabled';

	/** Literal fixed value for simple types (int/double/text/…). */
	public const META_KEY_FIXED_LITERAL = '_wtt_fixed_literal';

	/** Disabled children of a branch-type (e.g. deactivate k under Praefixe). */
	public const META_KEY_DISABLED_BRANCH = '_wtt_disabled_branch_ids';

	/**
	 * Q51 scaffold interim: allowed Präfix term IDs on a Basiseinheit unit node.
	 * Empty list = L1 = no prefixes allowed (base unit only).
	 */
	public const META_KEY_ALLOWED_PREFIX_IDS = '_wtt_allowed_prefix_ids';

	/**
	 * Q51: SI prefix scale relative to the unit’s prefix root (e.g. milli → 1e-3).
	 * Stored on Praefix catalog nodes.
	 */
	public const META_KEY_MULTIPLIKATOR = '_wtt_multiplikator';

	/**
	 * Factor from prefix-root symbol to SI base of this Basiseinheit unit.
	 * Usually 1 (Meter: root = m = SI). Kilogramm: prefixes attach to gram → 1e-3 (1 g = 0.001 kg).
	 * to_si = magnitude * prefix_multiplikator * prefix_root_to_si.
	 */
	public const META_KEY_PREFIX_ROOT_TO_SI = '_wtt_prefix_root_to_si';

	/**
	 * @return array{id:int,name:string,path:string}|null
	 */
	public static function get_assignment( string $taxonomy, int $term_id ): ?array {
		$type_id = self::get_type_id( $term_id );
		if ( $type_id <= 0 ) {
			return null;
		}

		$type = get_term( $type_id, $taxonomy );
		if ( ! $type instanceof \WP_Term ) {
			return null;
		}

		return array(
			'id'   => (int) $type->term_id,
			'name' => $type->name,
			'path' => self::term_path_from_typen( $taxonomy, (int) $type->term_id ),
			'shortDescription' => Tree_Model::get_short_description( (int) $type->term_id ),
		);
	}

	public static function get_type_id( int $term_id ): int {
		$value = get_term_meta( $term_id, self::META_KEY, true );
		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		return max( 0, (int) $value );
	}

	/**
	 * Copy scaffold settings meta from one term to another (type, required, fixed, footer, branch filters).
	 */
	public static function copy_settings( string $taxonomy, int $source_id, int $target_id ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$keys = array(
			self::META_KEY,
			self::META_KEY_REQUIRED,
			self::META_KEY_HAS_FOOTER,
			self::META_KEY_SET_SEPARATOR,
			self::META_KEY_SET_JOIN_UNITS,
			self::META_KEY_SET_LABEL_CHILDREN,
			self::META_KEY_FIXED_NODE,
			self::META_KEY_FIXED_ENABLED,
			self::META_KEY_FIXED_LITERAL,
			self::META_KEY_DISABLED_BRANCH,
			self::META_KEY_ALLOWED_PREFIX_IDS,
			self::META_KEY_MULTIPLIKATOR,
			self::META_KEY_PREFIX_ROOT_TO_SI,
		);

		foreach ( $keys as $key ) {
			delete_term_meta( $target_id, $key );
			$value = get_term_meta( $source_id, $key, true );
			if ( '' === $value || null === $value || false === $value ) {
				continue;
			}
			update_term_meta( $target_id, $key, $value );
		}
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_type_id( string $taxonomy, int $term_id, int $type_id ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		if ( $type_id <= 0 ) {
			delete_term_meta( $term_id, self::META_KEY );
			delete_term_meta( $term_id, self::META_KEY_DISABLED_BRANCH );
			self::clear_fixed_value( $term_id );
			return true;
		}

		$type = get_term( $type_id, $taxonomy );
		if ( ! $type instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_bad_type', __( 'Data type not found.', 'wp-taxonomy-tree' ) );
		}

		if ( ! self::is_assignable_type( $taxonomy, $term_id, $type_id ) ) {
			return new \WP_Error(
				'wtt_bad_type',
				__( 'Data type must be under the Typen branch (Datentypen, Praefixe, or Basiseinheit).', 'wp-taxonomy-tree' )
			);
		}

		$previous = self::get_type_id( $term_id );
		update_term_meta( $term_id, self::META_KEY, $type_id );
		if ( $previous !== $type_id ) {
			delete_term_meta( $term_id, self::META_KEY_DISABLED_BRANCH );
			self::clear_fixed_value( $term_id );
		}
		return true;
	}

	/**
	 * @return array<int, int>
	 */
	public static function get_disabled_branch_ids( int $term_id ): array {
		$raw = get_term_meta( $term_id, self::META_KEY_DISABLED_BRANCH, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw as $id ) {
			if ( is_numeric( $id ) ) {
				$ids[] = (int) $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param array<int, int> $ids Disabled child term IDs.
	 * @return true|\WP_Error
	 */
	public static function set_disabled_branch_ids( string $taxonomy, int $term_id, array $ids ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		$clean = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}
		$clean = array_values( array_unique( $clean ) );

		if ( empty( $clean ) ) {
			delete_term_meta( $term_id, self::META_KEY_DISABLED_BRANCH );
		} else {
			update_term_meta( $term_id, self::META_KEY_DISABLED_BRANCH, wp_json_encode( $clean ) );
		}

		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_branch_child_enabled( string $taxonomy, int $term_id, int $child_id, bool $enabled ) {
		$type_id = self::get_type_id( $term_id );
		if ( $type_id <= 0 ) {
			return new \WP_Error( 'wtt_no_type', __( 'Assign a branch type first.', 'wp-taxonomy-tree' ) );
		}

		if ( ! self::is_direct_child_of( $taxonomy, $child_id, $type_id ) ) {
			return new \WP_Error( 'wtt_bad_branch_child', __( 'Node is not a direct child of the selected type branch.', 'wp-taxonomy-tree' ) );
		}

		$disabled = self::get_disabled_branch_ids( $term_id );
		if ( $enabled ) {
			$disabled = array_values(
				array_filter(
					$disabled,
					static function ( int $id ) use ( $child_id ): bool {
						return $id !== $child_id;
					}
				)
			);
		} elseif ( ! in_array( $child_id, $disabled, true ) ) {
			$disabled[] = $child_id;
		}

		return self::set_disabled_branch_ids( $taxonomy, $term_id, $disabled );
	}

	/**
	 * Children of the assigned type when it is a branch (has children).
	 *
	 * @return array{typeId:int,typeName:string,children:array<int, array{id:int,name:string,enabled:bool}>}|null
	 */
	public static function get_type_branch( string $taxonomy, int $term_id ): ?array {
		$type_id = self::get_type_id( $term_id );
		if ( $type_id <= 0 ) {
			return null;
		}

		$branch = self::build_type_branch( $taxonomy, $type_id, self::get_disabled_branch_ids( $term_id ) );
		if ( null === $branch ) {
			return null;
		}

		return self::apply_unit_prefix_filter_to_branch( $taxonomy, $term_id, $branch );
	}

	/**
	 * @return array<int, int>
	 */
	public static function get_allowed_prefix_ids( int $unit_term_id ): array {
		$raw = get_term_meta( $unit_term_id, self::META_KEY_ALLOWED_PREFIX_IDS, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw as $id ) {
			if ( is_numeric( $id ) ) {
				$ids[] = (int) $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Persist Q51 allowlist on a Basiseinheit unit (L1: empty = no prefixes).
	 *
	 * @param array<int, int> $ids Allowed Präfix term IDs.
	 * @return true|\WP_Error
	 */
	public static function set_allowed_prefix_ids( string $taxonomy, int $unit_term_id, array $ids ) {
		if ( ! self::is_basiseinheit_unit_node( $taxonomy, $unit_term_id ) ) {
			return new \WP_Error(
				'wtt_bad_unit',
				__( 'Allowed prefixes can only be set on a Basiseinheit unit node.', 'wp-taxonomy-tree' )
			);
		}

		$prefixes_root = self::find_prefixes_root( $taxonomy, $unit_term_id );
		$clean         = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 || $prefixes_root <= 0 ) {
				continue;
			}
			if ( self::is_direct_child_of( $taxonomy, $id, $prefixes_root ) ) {
				$clean[] = $id;
			}
		}
		$clean = array_values( array_unique( $clean ) );

		if ( empty( $clean ) ) {
			// Persist empty JSON so L1 is explicit (missing meta also means empty).
			update_term_meta( $unit_term_id, self::META_KEY_ALLOWED_PREFIX_IDS, '[]' );
		} else {
			update_term_meta( $unit_term_id, self::META_KEY_ALLOWED_PREFIX_IDS, wp_json_encode( $clean ) );
		}

		return true;
	}

	public static function get_multiplikator( int $term_id ): ?float {
		$raw = get_term_meta( $term_id, self::META_KEY_MULTIPLIKATOR, true );
		if ( ! is_numeric( $raw ) ) {
			return null;
		}

		return (float) $raw;
	}

	public static function set_multiplikator( int $term_id, float $factor ): void {
		if ( $factor <= 0.0 ) {
			delete_term_meta( $term_id, self::META_KEY_MULTIPLIKATOR );
			return;
		}
		update_term_meta( $term_id, self::META_KEY_MULTIPLIKATOR, (string) $factor );
	}

	public static function get_prefix_root_to_si( int $unit_term_id ): float {
		$raw = get_term_meta( $unit_term_id, self::META_KEY_PREFIX_ROOT_TO_SI, true );
		if ( ! is_numeric( $raw ) ) {
			return 1.0;
		}
		$factor = (float) $raw;
		return $factor > 0.0 ? $factor : 1.0;
	}

	public static function set_prefix_root_to_si( int $unit_term_id, float $factor ): void {
		if ( $factor <= 0.0 || 1.0 === $factor ) {
			delete_term_meta( $unit_term_id, self::META_KEY_PREFIX_ROOT_TO_SI );
			return;
		}
		update_term_meta( $unit_term_id, self::META_KEY_PREFIX_ROOT_TO_SI, (string) $factor );
	}

	/**
	 * Convert a reading to the unit’s SI base: magnitude × prefix_multiplikator × prefix_root_to_si.
	 */
	public static function to_si_base( float $magnitude, ?float $prefix_multiplikator, int $unit_term_id ): float {
		$prefix = null !== $prefix_multiplikator && $prefix_multiplikator > 0.0 ? $prefix_multiplikator : 1.0;
		return $magnitude * $prefix * self::get_prefix_root_to_si( $unit_term_id );
	}

	/**
	 * Direct child of Typen/Basiseinheit (e.g. Meter, Ohm, Farad).
	 */
	public static function is_basiseinheit_unit_node( string $taxonomy, int $term_id ): bool {
		$base_root = self::find_base_units_root( $taxonomy, $term_id );
		if ( $base_root <= 0 ) {
			return false;
		}

		$term = get_term( $term_id, $taxonomy );
		return $term instanceof \WP_Term && (int) $term->parent === $base_root;
	}

	/**
	 * Prefix catalog with enabled flags from this unit's allowlist (L1 empty = none enabled).
	 *
	 * @return array{unitId:int,allowedPrefixIds:array<int,int>,prefixes:array<int, array{id:int,name:string,enabled:bool}>}|null
	 */
	public static function get_prefix_allowlist( string $taxonomy, int $unit_term_id ): ?array {
		if ( ! self::is_basiseinheit_unit_node( $taxonomy, $unit_term_id ) ) {
			return null;
		}

		$allowed = self::get_allowed_prefix_ids( $unit_term_id );
		$allowed_map = array_fill_keys( $allowed, true );
		$prefixes_root = self::find_prefixes_root( $taxonomy, $unit_term_id );
		$children      = $prefixes_root > 0 ? self::get_direct_child_terms( $taxonomy, $prefixes_root ) : array();

		$prefixes = array();
		foreach ( $children as $child ) {
			$child_id   = (int) $child->term_id;
			$prefixes[] = array(
				'id'      => $child_id,
				'name'    => $child->name,
				'enabled' => isset( $allowed_map[ $child_id ] ),
			);
		}

		return array(
			'unitId'           => $unit_term_id,
			'allowedPrefixIds' => $allowed,
			'prefixes'         => $prefixes,
		);
	}

	/**
	 * When this node is a direct child of a Basiseinheit unit (e.g. Meter/Praefix), return that unit id.
	 */
	public static function resolve_parent_basiseinheit_unit( string $taxonomy, int $term_id ): int {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || ! $term->parent ) {
			return 0;
		}

		$parent_id = (int) $term->parent;
		return self::is_basiseinheit_unit_node( $taxonomy, $parent_id ) ? $parent_id : 0;
	}

	/**
	 * Sibling Basiseinheit member with a fixed unit (set context), else 0.
	 */
	public static function resolve_sibling_fixed_basiseinheit( string $taxonomy, int $term_id ): int {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || ! $term->parent ) {
			return 0;
		}

		$parent_id = (int) $term->parent;
		if ( ! self::is_set_typed( $taxonomy, $parent_id ) ) {
			return 0;
		}

		$siblings = self::get_direct_child_terms( $taxonomy, $parent_id );
		foreach ( $siblings as $sibling ) {
			$sibling_id = (int) $sibling->term_id;
			if ( $sibling_id === $term_id ) {
				continue;
			}
			$type = self::get_assignment( $taxonomy, $sibling_id );
			if ( null === $type || 'basiseinheit' !== strtolower( $type['name'] ) ) {
				continue;
			}
			if ( ! self::is_fixed_enabled( $sibling_id ) ) {
				continue;
			}
			$fixed_id = self::get_fixed_node_id( $sibling_id );
			if ( $fixed_id > 0 && self::is_basiseinheit_unit_node( $taxonomy, $fixed_id ) ) {
				return $fixed_id;
			}
		}

		return 0;
	}

	/**
	 * @param array{typeId:int,typeName:string,children:array<int, array{id:int,name:string,enabled:bool}>} $branch
	 * @return array{typeId:int,typeName:string,children:array<int, array{id:int,name:string,enabled:bool}>,unitFilter?:bool,unitAllowlistEdit?:bool,unitId?:int,unitName?:string}
	 */
	private static function apply_unit_prefix_filter_to_branch( string $taxonomy, int $term_id, array $branch ): array {
		$type_name = strtolower( (string) ( $branch['typeName'] ?? '' ) );
		if ( 'praefixe' !== $type_name ) {
			return $branch;
		}

		// Praefix child under Meter/Ohm/… — edit the unit allowlist via this underknot’s type branch.
		$parent_unit = self::resolve_parent_basiseinheit_unit( $taxonomy, $term_id );
		if ( $parent_unit > 0 ) {
			$allowed     = self::get_allowed_prefix_ids( $parent_unit );
			$allowed_map = array_fill_keys( $allowed, true );
			$unit        = get_term( $parent_unit, $taxonomy );
			$children    = array();
			foreach ( $branch['children'] as $child ) {
				$id               = (int) ( $child['id'] ?? 0 );
				$child['enabled'] = isset( $allowed_map[ $id ] );
				$children[]       = $child;
			}
			$branch['children']            = $children;
			$branch['unitAllowlistEdit']   = true;
			$branch['unitId']              = $parent_unit;
			$branch['unitName']            = $unit instanceof \WP_Term ? $unit->name : '';
			$branch['unitPrefixRootToSi']  = self::get_prefix_root_to_si( $parent_unit );
			return $branch;
		}

		// Praefix member next to fixed Einheit (e.g. Kondensator) — read-only filter from unit allowlist.
		$unit_id = self::resolve_sibling_fixed_basiseinheit( $taxonomy, $term_id );
		if ( $unit_id <= 0 ) {
			return $branch;
		}

		$allowed     = self::get_allowed_prefix_ids( $unit_id );
		$allowed_map = array_fill_keys( $allowed, true );
		$unit        = get_term( $unit_id, $taxonomy );

		$children = array();
		foreach ( $branch['children'] as $child ) {
			$id = (int) ( $child['id'] ?? 0 );
			// L1: empty allowlist ⇒ nothing enabled; also respect local disabled_branch.
			$locally_on       = ! empty( $child['enabled'] );
			$child['enabled'] = $locally_on && isset( $allowed_map[ $id ] );
			$children[]       = $child;
		}
		$branch['children']   = $children;
		$branch['unitFilter'] = true;
		$branch['unitId']     = $unit_id;
		$branch['unitName']   = $unit instanceof \WP_Term ? $unit->name : '';

		return $branch;
	}

	/**
	 * Branch children for a type id (draft UI / preview before save).
	 *
	 * @param array<int, int> $disabled_ids
	 * @return array{typeId:int,typeName:string,children:array<int, array{id:int,name:string,enabled:bool}>}|null
	 */
	public static function build_type_branch( string $taxonomy, int $type_id, array $disabled_ids = array() ): ?array {
		if ( $type_id <= 0 ) {
			return null;
		}

		$type = get_term( $type_id, $taxonomy );
		if ( ! $type instanceof \WP_Term ) {
			return null;
		}

		$children = self::get_direct_child_terms( $taxonomy, $type_id );
		if ( empty( $children ) ) {
			return null;
		}

		$disabled = array();
		foreach ( $disabled_ids as $id ) {
			if ( is_numeric( $id ) ) {
				$disabled[] = (int) $id;
			}
		}
		$disabled = array_values( array_unique( $disabled ) );

		$list = array();
		foreach ( $children as $child ) {
			$child_id = (int) $child->term_id;
			$list[]   = array(
				'id'            => $child_id,
				'name'          => $child->name,
				'shortDescription' => Tree_Model::get_short_description( $child_id ),
				'enabled'       => ! in_array( $child_id, $disabled, true ),
				'multiplikator' => self::get_multiplikator( $child_id ),
			);
		}

		return array(
			'typeId'   => $type_id,
			'typeName' => $type->name,
			'children' => $list,
		);
	}

	/**
	 * Persist editable node settings in one step (name, type, required, fixed, footer, branch filters).
	 *
	 * @param array<string, mixed> $settings
	 * @return true|\WP_Error
	 */
	public static function save_node_settings( string $taxonomy, int $term_id, array $settings ) {
		$name        = array_key_exists( 'name', $settings ) ? (string) $settings['name'] : null;
		$description = array_key_exists( 'description', $settings ) ? (string) $settings['description'] : null;
		if ( null !== $name || null !== $description ) {
			$result = Tree_Model::update_term_fields( $taxonomy, $term_id, $name, $description );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( array_key_exists( 'short_description', $settings ) ) {
			$result = Tree_Model::set_short_description( $taxonomy, $term_id, (string) $settings['short_description'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$type_id = isset( $settings['type_id'] ) ? (int) $settings['type_id'] : 0;
		$result  = self::set_type_id( $taxonomy, $term_id, $type_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$type_term = $type_id > 0 ? get_term( $type_id, $taxonomy ) : null;
		$type_name = $type_term instanceof \WP_Term ? $type_term->name : '';

		$required = ! empty( $settings['required'] );
		if ( self::is_display_only_type_name( $type_name ) ) {
			$required = false;
		}
		$result = self::set_required( $taxonomy, $term_id, $required );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$fixed_enabled = ! empty( $settings['fixed_enabled'] );
		if ( self::is_display_only_type_name( $type_name ) ) {
			$fixed_enabled = false;
		}
		$fixed_literal = isset( $settings['fixed_literal'] ) ? (string) $settings['fixed_literal'] : '';
		$fixed_node_id = isset( $settings['fixed_node_id'] ) ? (int) $settings['fixed_node_id'] : 0;
		$result        = self::set_fixed_value( $taxonomy, $term_id, $fixed_enabled, $fixed_literal, $fixed_node_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$has_footer = ! empty( $settings['has_footer'] );
		$result     = self::set_has_footer( $taxonomy, $term_id, $has_footer );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if (
			array_key_exists( 'set_separator', $settings )
			|| array_key_exists( 'set_join_units', $settings )
			|| array_key_exists( 'set_label_children', $settings )
		) {
			if ( self::is_set_typed( $taxonomy, $term_id ) ) {
				if ( array_key_exists( 'set_separator', $settings ) ) {
					$result = self::set_set_separator( $taxonomy, $term_id, (string) $settings['set_separator'] );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				if ( array_key_exists( 'set_join_units', $settings ) ) {
					$result = self::set_set_join_units( $taxonomy, $term_id, ! empty( $settings['set_join_units'] ) );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				if ( array_key_exists( 'set_label_children', $settings ) ) {
					$result = self::set_set_label_children( $taxonomy, $term_id, ! empty( $settings['set_label_children'] ) );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
			}
		}

		$disabled_input = array();
		if ( isset( $settings['disabled_branch_ids'] ) && is_array( $settings['disabled_branch_ids'] ) ) {
			foreach ( $settings['disabled_branch_ids'] as $id ) {
				if ( is_numeric( $id ) ) {
					$disabled_input[] = (int) $id;
				}
			}
		}

		// Preferred: Basiseinheit unit parent saves allowlist + multiplikators (child extras on parent).
		// Use raw disabled ids (Präfix catalog), not filtered against set-type children.
		if ( self::is_basiseinheit_unit_node( $taxonomy, $term_id ) ) {
			$prefixes_root = self::find_prefixes_root( $taxonomy, $term_id );
			if ( $prefixes_root > 0 ) {
				$result = self::save_unit_prefix_settings(
					$taxonomy,
					$term_id,
					$prefixes_root,
					$disabled_input,
					$settings
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		$parent_unit = self::resolve_parent_basiseinheit_unit( $taxonomy, $term_id );
		if ( $parent_unit > 0 && 'praefixe' === strtolower( $type_name ) && $type_id > 0 ) {
			// Legacy path: editing on Praefix underknot still works; prefer parent unit UI.
			$result = self::save_unit_prefix_settings(
				$taxonomy,
				$parent_unit,
				$type_id,
				$disabled_input,
				$settings
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return self::set_disabled_branch_ids( $taxonomy, $term_id, array() );
		}

		// Validate disabled ids are children of the (new) type when a branch type is set.
		$disabled = array();
		if ( $type_id > 0 && ! empty( $disabled_input ) ) {
			foreach ( $disabled_input as $child_id ) {
				if ( self::is_direct_child_of( $taxonomy, $child_id, $type_id ) ) {
					$disabled[] = $child_id;
				}
			}
		}

		$result = self::set_disabled_branch_ids( $taxonomy, $term_id, $disabled );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Persist Q51 allowlist + Praefix multiplikators + unit prefix_root_to_si.
	 *
	 * @param array<int, int>      $disabled_prefix_ids Unchecked Präfix ids.
	 * @param array<string, mixed> $settings            May include prefix_multiplikators, prefix_root_to_si.
	 * @return true|\WP_Error
	 */
	private static function save_unit_prefix_settings(
		string $taxonomy,
		int $unit_term_id,
		int $prefixes_root_id,
		array $disabled_prefix_ids,
		array $settings
	) {
		$disabled_map = array_fill_keys( $disabled_prefix_ids, true );
		$allowed      = array();
		foreach ( self::get_direct_child_terms( $taxonomy, $prefixes_root_id ) as $prefix_term ) {
			$pid = (int) $prefix_term->term_id;
			if ( ! isset( $disabled_map[ $pid ] ) ) {
				$allowed[] = $pid;
			}
		}
		$result = self::set_allowed_prefix_ids( $taxonomy, $unit_term_id, $allowed );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $settings['prefix_multiplikators'] ) && is_array( $settings['prefix_multiplikators'] ) ) {
			foreach ( $settings['prefix_multiplikators'] as $prefix_id => $factor ) {
				$prefix_id = (int) $prefix_id;
				if ( $prefix_id <= 0 || ! self::is_direct_child_of( $taxonomy, $prefix_id, $prefixes_root_id ) ) {
					continue;
				}
				if ( ! is_numeric( $factor ) ) {
					continue;
				}
				$factor = (float) $factor;
				if ( $factor > 0.0 ) {
					self::set_multiplikator( $prefix_id, $factor );
				}
			}
		}

		if ( array_key_exists( 'prefix_root_to_si', $settings ) && is_numeric( $settings['prefix_root_to_si'] ) ) {
			self::set_prefix_root_to_si( $unit_term_id, (float) $settings['prefix_root_to_si'] );
		}

		return true;
	}

	/**
	 * @return array<int, \WP_Term>
	 */
	private static function get_direct_child_terms( string $taxonomy, int $parent_id ): array {
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

		$children = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$children[] = $term;
			}
		}

		usort(
			$children,
			static function ( \WP_Term $a, \WP_Term $b ): int {
				$pa = Tree_Model::get_position( (int) $a->term_id );
				$pb = Tree_Model::get_position( (int) $b->term_id );
				if ( $pa !== $pb ) {
					return $pa <=> $pb;
				}
				return strcasecmp( $a->name, $b->name );
			}
		);

		return $children;
	}

	private static function is_direct_child_of( string $taxonomy, int $child_id, int $parent_id ): bool {
		$child = get_term( $child_id, $taxonomy );
		return $child instanceof \WP_Term && (int) $child->parent === $parent_id;
	}

	/**
	 * Resolve a direct child of $parent_id by name (demo seeding / branch filters).
	 */
	public static function find_direct_child_by_name( string $taxonomy, int $parent_id, string $name ): int {
		$name = trim( $name );
		if ( '' === $name || $parent_id <= 0 ) {
			return 0;
		}

		foreach ( self::get_direct_child_terms( $taxonomy, $parent_id ) as $child ) {
			if ( 0 === strcasecmp( $child->name, $name ) ) {
				return (int) $child->term_id;
			}
		}

		return 0;
	}

	public static function is_required( int $term_id ): bool {
		$value = get_term_meta( $term_id, self::META_KEY_REQUIRED, true );
		return '1' === (string) $value || 1 === $value || true === $value;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_required( string $taxonomy, int $term_id, bool $required ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		if ( $required ) {
			update_term_meta( $term_id, self::META_KEY_REQUIRED, '1' );
		} else {
			delete_term_meta( $term_id, self::META_KEY_REQUIRED );
		}

		return true;
	}

	public static function has_footer( int $term_id ): bool {
		$value = get_term_meta( $term_id, self::META_KEY_HAS_FOOTER, true );
		return '1' === (string) $value || 1 === $value || true === $value;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_has_footer( string $taxonomy, int $term_id, bool $has_footer ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		if ( $has_footer ) {
			update_term_meta( $term_id, self::META_KEY_HAS_FOOTER, '1' );
		} else {
			delete_term_meta( $term_id, self::META_KEY_HAS_FOOTER );
		}

		return true;
	}

	/**
	 * Separator between set members in labels and display (default `/`).
	 * Empty string is allowed once the meta has been written.
	 */
	public static function get_set_separator( int $term_id ): string {
		if ( ! metadata_exists( 'term', $term_id, self::META_KEY_SET_SEPARATOR ) ) {
			return '/';
		}
		$value = get_term_meta( $term_id, self::META_KEY_SET_SEPARATOR, true );
		return is_string( $value ) ? $value : '/';
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_set_separator( string $taxonomy, int $term_id, string $separator ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		// Soft length cap — display glue, not prose.
		$separator = substr( $separator, 0, 16 );
		update_term_meta( $term_id, self::META_KEY_SET_SEPARATOR, $separator );
		return true;
	}

	/**
	 * Join shared unit once in set display when members share a type (default on).
	 */
	public static function get_set_join_units( int $term_id ): bool {
		if ( ! metadata_exists( 'term', $term_id, self::META_KEY_SET_JOIN_UNITS ) ) {
			return true;
		}
		$value = get_term_meta( $term_id, self::META_KEY_SET_JOIN_UNITS, true );
		return '1' === (string) $value || 1 === $value || true === $value;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_set_join_units( string $taxonomy, int $term_id, bool $join_units ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		update_term_meta( $term_id, self::META_KEY_SET_JOIN_UNITS, $join_units ? '1' : '0' );
		return true;
	}

	/**
	 * Include child names in set labels like Abmessung (L/B/H) (default on).
	 */
	public static function get_set_label_children( int $term_id ): bool {
		if ( ! metadata_exists( 'term', $term_id, self::META_KEY_SET_LABEL_CHILDREN ) ) {
			return true;
		}
		$value = get_term_meta( $term_id, self::META_KEY_SET_LABEL_CHILDREN, true );
		return '1' === (string) $value || 1 === $value || true === $value;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_set_label_children( string $taxonomy, int $term_id, bool $include_children ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		update_term_meta( $term_id, self::META_KEY_SET_LABEL_CHILDREN, $include_children ? '1' : '0' );
		return true;
	}

	public static function get_fixed_node_id( int $term_id ): int {
		$value = get_term_meta( $term_id, self::META_KEY_FIXED_NODE, true );
		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		return max( 0, (int) $value );
	}

	public static function is_fixed_enabled( int $term_id ): bool {
		$value = get_term_meta( $term_id, self::META_KEY_FIXED_ENABLED, true );
		if ( '0' === (string) $value || 0 === $value || false === $value ) {
			return false;
		}
		if ( '1' === (string) $value || 1 === $value || true === $value ) {
			return true;
		}

		// Backward compat: node id alone counted as fixed before the radio existed.
		return self::get_fixed_node_id( $term_id ) > 0 || '' !== self::get_fixed_literal( $term_id );
	}

	public static function get_fixed_literal( int $term_id ): string {
		$value = get_term_meta( $term_id, self::META_KEY_FIXED_LITERAL, true );
		return is_string( $value ) ? $value : ( is_numeric( $value ) ? (string) $value : '' );
	}

	/**
	 * @return array{id:int,name:string,path:string}|null
	 */
	public static function get_fixed_assignment( string $taxonomy, int $term_id ): ?array {
		if ( ! self::is_fixed_enabled( $term_id ) ) {
			return null;
		}

		$literal = self::get_fixed_literal( $term_id );
		if ( '' !== $literal ) {
			return array(
				'id'   => 0,
				'name' => $literal,
				'path' => $literal,
			);
		}

		$fixed_id = self::get_fixed_node_id( $term_id );
		if ( $fixed_id <= 0 ) {
			return null;
		}

		$fixed = get_term( $fixed_id, $taxonomy );
		if ( ! $fixed instanceof \WP_Term ) {
			return null;
		}

		return array(
			'id'   => (int) $fixed->term_id,
			'name' => $fixed->name,
			'path' => self::term_path_from_typen( $taxonomy, (int) $fixed->term_id ),
			'shortDescription' => Tree_Model::get_short_description( (int) $fixed->term_id ),
		);
	}

	private static function clear_fixed_value( int $term_id ): void {
		delete_term_meta( $term_id, self::META_KEY_FIXED_ENABLED );
		delete_term_meta( $term_id, self::META_KEY_FIXED_LITERAL );
		delete_term_meta( $term_id, self::META_KEY_FIXED_NODE );
	}

	/**
	 * Activate fixed value via explicit flag; simple types use literal, catalog types use node id.
	 *
	 * @return true|\WP_Error
	 */
	public static function set_fixed_value( string $taxonomy, int $term_id, bool $enabled, string $literal, int $fixed_node_id ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		if ( ! $enabled ) {
			self::clear_fixed_value( $term_id );
			return true;
		}

		$type_id = self::get_type_id( $term_id );
		$type    = $type_id > 0 ? get_term( $type_id, $taxonomy ) : null;
		$type_name = $type instanceof \WP_Term ? strtolower( $type->name ) : '';

		if ( self::is_display_only_type_name( $type_name ) ) {
			return new \WP_Error(
				'wtt_bad_fixed',
				__( 'display_node_name always shows the node name — fixed value is not used.', 'wp-taxonomy-tree' )
			);
		}

		if ( self::is_simple_type_name( $type_name ) ) {
			$literal = self::normalize_simple_literal( $type_name, $literal );
			if ( '' === $literal ) {
				return new \WP_Error(
					'wtt_bad_fixed',
					__( 'Enter a fixed value, or choose “No fixed value”.', 'wp-taxonomy-tree' )
				);
			}
			update_term_meta( $term_id, self::META_KEY_FIXED_ENABLED, '1' );
			update_term_meta( $term_id, self::META_KEY_FIXED_LITERAL, $literal );
			delete_term_meta( $term_id, self::META_KEY_FIXED_NODE );
			return true;
		}

		if ( $fixed_node_id <= 0 ) {
			return new \WP_Error(
				'wtt_bad_fixed',
				__( 'Choose a fixed Typen node, or choose “No fixed value”.', 'wp-taxonomy-tree' )
			);
		}

		$fixed = get_term( $fixed_node_id, $taxonomy );
		if ( ! $fixed instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_bad_fixed', __( 'Fixed value node not found.', 'wp-taxonomy-tree' ) );
		}

		if ( ! self::is_fixed_value_candidate( $taxonomy, $term_id, $fixed_node_id ) ) {
			return new \WP_Error(
				'wtt_bad_fixed',
				__( 'Fixed value must be a concrete node under the Typen branch (e.g. Ohm under Basiseinheit).', 'wp-taxonomy-tree' )
			);
		}

		update_term_meta( $term_id, self::META_KEY_FIXED_ENABLED, '1' );
		update_term_meta( $term_id, self::META_KEY_FIXED_NODE, $fixed_node_id );
		delete_term_meta( $term_id, self::META_KEY_FIXED_LITERAL );
		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_fixed_node_id( string $taxonomy, int $term_id, int $fixed_node_id ) {
		if ( $fixed_node_id <= 0 ) {
			return self::set_fixed_value( $taxonomy, $term_id, false, '', 0 );
		}

		return self::set_fixed_value( $taxonomy, $term_id, true, '', $fixed_node_id );
	}

	public static function is_simple_type_name( string $type_name ): bool {
		$name = self::normalize_type_name( $type_name );

		return in_array(
			$name,
			array( 'int', 'double', 'text', 'textarea', 'char', 'bool', 'quantity', 'display_node_name' ),
			true
		);
	}

	public static function is_display_only_type_name( string $type_name ): bool {
		return 'display_node_name' === self::normalize_type_name( $type_name );
	}

	private static function normalize_type_name( string $type_name ): string {
		$name = strtolower( trim( $type_name ) );
		if ( 'integer' === $name ) {
			return 'int';
		}
		if ( in_array( $name, array( 'float', 'number' ), true ) ) {
			return 'double';
		}
		if ( 'boolean' === $name ) {
			return 'bool';
		}
		if ( in_array( $name, array( 'string', 'varchar' ), true ) ) {
			return 'text';
		}
		if ( in_array( $name, array( 'display node name', 'displayname', 'node_name' ), true ) ) {
			return 'display_node_name';
		}

		return $name;
	}

	private static function normalize_simple_literal( string $type_name, string $literal ): string {
		$literal = trim( $literal );
		$name    = self::normalize_type_name( $type_name );
		if ( 'bool' === $name ) {
			return in_array( $literal, array( '1', 'true', 'yes', 'on' ), true ) ? '1' : '0';
		}
		if ( 'int' === $name ) {
			return '' === $literal ? '' : (string) (int) $literal;
		}
		if ( 'char' === $name && '' !== $literal ) {
			return function_exists( 'mb_substr' ) ? mb_substr( $literal, 0, 1 ) : substr( $literal, 0, 1 );
		}

		return $literal;
	}

	/**
	 * Candidates for fixed-value picker (concrete Typen nodes — units, prefixes, Bauformen values, …).
	 *
	 * @return array<int, array{id:int,name:string,path:string}>
	 */
	public static function get_fixed_picker_options( string $taxonomy, int $context_term_id ): array {
		$options = self::get_picker_options( $taxonomy, $context_term_id );
		$typen_id = self::resolve_typen_root( $taxonomy, $context_term_id );
		if ( $typen_id <= 0 ) {
			return $options;
		}

		$seen = array();
		foreach ( $options as $opt ) {
			$seen[ (int) $opt['id'] ] = true;
		}

		$typen_children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $typen_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $typen_children ) ) {
			return self::filter_fixed_options_by_unit_allowlist( $taxonomy, $context_term_id, $options );
		}

		foreach ( $typen_children as $branch ) {
			if ( ! $branch instanceof \WP_Term ) {
				continue;
			}
			$name = strtolower( $branch->name );
			if ( 'datentypen' === $name || 'basiseinheit' === $name ) {
				// Basiseinheit children already in type picker; Datentypen values are types, not fixed catalogs.
				continue;
			}
			self::add_term_children_as_options( $taxonomy, (int) $branch->term_id, $typen_id, $options, $seen );
		}

		usort(
			$options,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['path'], $b['path'] );
			}
		);

		return self::filter_fixed_options_by_unit_allowlist( $taxonomy, $context_term_id, $options );
	}

	/**
	 * When the node is typed Praefixe and a sibling Einheit is fixed, only allowed prefixes.
	 *
	 * @param array<int, array{id:int,name:string,path:string}> $options
	 * @return array<int, array{id:int,name:string,path:string}>
	 */
	private static function filter_fixed_options_by_unit_allowlist( string $taxonomy, int $context_term_id, array $options ): array {
		$type = self::get_assignment( $taxonomy, $context_term_id );
		if ( null === $type || 'praefixe' !== strtolower( $type['name'] ) ) {
			return $options;
		}

		$unit_id = self::resolve_sibling_fixed_basiseinheit( $taxonomy, $context_term_id );
		if ( $unit_id <= 0 ) {
			return $options;
		}

		$allowed     = self::get_allowed_prefix_ids( $unit_id );
		$allowed_map = array_fill_keys( $allowed, true );
		$filtered    = array();
		foreach ( $options as $opt ) {
			$id = (int) ( $opt['id'] ?? 0 );
			if ( isset( $allowed_map[ $id ] ) ) {
				$filtered[] = $opt;
			}
		}

		return $filtered;
	}

	public static function is_fixed_value_candidate( string $taxonomy, int $context_term_id, int $node_id ): bool {
		if ( self::is_typen_type_node( $taxonomy, $context_term_id, $node_id ) ) {
			return true;
		}

		// Values under catalog branches (Praefixe / Bauformen / …) may be fixed constants.
		$typen_id = self::resolve_typen_root( $taxonomy, $context_term_id );
		if ( $typen_id <= 0 || ! self::is_descendant_of( $taxonomy, $node_id, $typen_id ) ) {
			return false;
		}

		$datentypen_id = self::resolve_datentypen_root( $taxonomy, $context_term_id );
		if ( $datentypen_id > 0 && ( (int) $node_id === $datentypen_id || self::is_descendant_of( $taxonomy, $node_id, $datentypen_id ) ) ) {
			return false;
		}

		return true;
	}

	public static function is_assignable_type( string $taxonomy, int $context_term_id, int $type_id ): bool {
		return self::is_typen_type_node( $taxonomy, $context_term_id, $type_id );
	}

	/**
	 * Children of a set-typed node with their own type assignments.
	 *
	 * @return array<int, array{id:int,name:string,typeId:int,required:bool,type:array{id:int,name:string,path:string}|null}>
	 */
	public static function get_set_members( string $taxonomy, int $term_id ): array {
		if ( ! self::is_set_typed( $taxonomy, $term_id ) ) {
			return array();
		}

		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( ! is_array( $children ) ) {
			return array();
		}

		$terms = array();
		foreach ( $children as $child ) {
			if ( $child instanceof \WP_Term ) {
				$terms[] = $child;
			}
		}

		usort(
			$terms,
			static function ( \WP_Term $a, \WP_Term $b ): int {
				$pa = Tree_Model::get_position( (int) $a->term_id );
				$pb = Tree_Model::get_position( (int) $b->term_id );
				if ( $pa !== $pb ) {
					return $pa <=> $pb;
				}
				return strcasecmp( $a->name, $b->name );
			}
		);

		$members = array();
		foreach ( $terms as $child ) {
			$child_id = (int) $child->term_id;
			$type_id  = self::get_type_id( $child_id );
			$unit_qty = self::is_basiseinheit_unit_node( $taxonomy, $type_id );
			$members[] = array(
				'id'             => $child_id,
				'name'           => $child->name,
				'description'    => $child->description,
				'shortDescription' => Tree_Model::get_short_description( $child_id ),
				'typeId'         => $type_id,
				'required'       => self::is_required( $child_id ),
				'type'           => self::get_assignment( $taxonomy, $child_id ),
				'fixedEnabled'   => self::is_fixed_enabled( $child_id ),
				'fixedLiteral'   => self::get_fixed_literal( $child_id ),
				'fixedNodeId'    => self::get_fixed_node_id( $child_id ),
				'fixed'          => self::get_fixed_assignment( $taxonomy, $child_id ),
				/* Unit quantity types use trinity preview — not a filterable type branch. */
				'typeBranch'     => $unit_qty ? null : self::get_type_branch( $taxonomy, $child_id ),
				'quantitySchema' => $unit_qty ? self::get_quantity_schema_for_type( $taxonomy, $type_id ) : null,
			);
		}

		return $members;
	}

	/**
	 * @var array<string, array{unitId:int,unitName:string,members:array<int, array<string, mixed>}>|null>
	 */
	private static $quantity_schema_cache = array();

	/**
	 * When a field’s type is a Basiseinheit unit (Meter, Ohm, …), expose that unit’s
	 * Typ + Praefix + Kuerzel schema so the UI can render the quantity trinity.
	 *
	 * @return array{unitId:int,unitName:string,members:array<int, array<string, mixed>}>|null
	 */
	public static function get_quantity_schema_for_type( string $taxonomy, int $type_id ): ?array {
		$cache_key = $taxonomy . ':' . $type_id;
		if ( array_key_exists( $cache_key, self::$quantity_schema_cache ) ) {
			return self::$quantity_schema_cache[ $cache_key ];
		}

		if ( $type_id <= 0 || ! self::is_basiseinheit_unit_node( $taxonomy, $type_id ) ) {
			self::$quantity_schema_cache[ $cache_key ] = null;
			return null;
		}

		$unit = get_term( $type_id, $taxonomy );
		if ( ! $unit instanceof \WP_Term ) {
			self::$quantity_schema_cache[ $cache_key ] = null;
			return null;
		}

		$members = self::get_unit_quantity_members( $taxonomy, $type_id );
		if ( empty( $members ) ) {
			self::$quantity_schema_cache[ $cache_key ] = null;
			return null;
		}

		$result = array(
			'unitId'   => $type_id,
			'unitName' => $unit->name,
			'members'  => $members,
		);
		self::$quantity_schema_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Schema members of a Basiseinheit unit (Typ / Praefix / Kuerzel) — no nested quantitySchema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_unit_quantity_members( string $taxonomy, int $unit_term_id ): array {
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $unit_term_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $children ) ) {
			return array();
		}

		$terms = array();
		foreach ( $children as $child ) {
			if ( $child instanceof \WP_Term ) {
				$terms[] = $child;
			}
		}

		usort(
			$terms,
			static function ( \WP_Term $a, \WP_Term $b ): int {
				$pa = Tree_Model::get_position( (int) $a->term_id );
				$pb = Tree_Model::get_position( (int) $b->term_id );
				if ( $pa !== $pb ) {
					return $pa <=> $pb;
				}
				return strcasecmp( $a->name, $b->name );
			}
		);

		$members = array();
		foreach ( $terms as $child ) {
			$child_id = (int) $child->term_id;
			$members[] = array(
				'id'           => $child_id,
				'name'         => $child->name,
				'description'  => $child->description,
				'typeId'       => self::get_type_id( $child_id ),
				'required'     => self::is_required( $child_id ),
				'type'         => self::get_assignment( $taxonomy, $child_id ),
				'fixedEnabled' => self::is_fixed_enabled( $child_id ),
				'fixedLiteral' => self::get_fixed_literal( $child_id ),
				'fixedNodeId'  => self::get_fixed_node_id( $child_id ),
				'fixed'        => self::get_fixed_assignment( $taxonomy, $child_id ),
				'typeBranch'   => self::get_type_branch( $taxonomy, $child_id ),
			);
		}

		return $members;
	}

	/**
	 * @return array{id:int,name:string}|null
	 */
	public static function get_set_parent( string $taxonomy, int $term_id ): ?array {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || ! $term->parent ) {
			return null;
		}

		$parent_id = (int) $term->parent;
		if ( ! self::is_set_typed( $taxonomy, $parent_id ) ) {
			return null;
		}

		$parent = get_term( $parent_id, $taxonomy );
		if ( ! $parent instanceof \WP_Term ) {
			return null;
		}

		return array(
			'id'   => $parent_id,
			'name' => $parent->name,
		);
	}

	public static function has_type_named( string $taxonomy, int $term_id, string $type_name ): bool {
		$type_id = self::get_type_id( $term_id );
		if ( $type_id <= 0 ) {
			return false;
		}

		$type = get_term( $type_id, $taxonomy );
		if ( ! $type instanceof \WP_Term ) {
			return false;
		}

		return strtolower( $type->name ) === strtolower( $type_name );
	}

	/**
	 * True when the node is typed as Collection `set` or a concrete set schema (e.g. Abmessung).
	 */
	public static function is_set_typed( string $taxonomy, int $term_id ): bool {
		if ( self::has_type_named( $taxonomy, $term_id, 'set' ) ) {
			return true;
		}

		$type_id = self::get_type_id( $term_id );
		if ( $type_id <= 0 ) {
			return false;
		}

		// Concrete set schemas live under Complex and are themselves typed as `set`.
		return self::has_type_named( $taxonomy, $type_id, 'set' );
	}

	/**
	 * Direct children for help popovers (name, type, fixed, required, description).
	 * Set-typed children include one nested level of their members.
	 *
	 * @return array<int, array{name:string,description:string,typeName:string,fixed:string,required:bool,children?:array<int, array<string, mixed>>}>
	 */
	public static function get_help_children( string $taxonomy, int $term_id, int $depth = 0 ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$sorted = array();
		foreach ( $terms as $child ) {
			if ( $child instanceof \WP_Term ) {
				$sorted[] = $child;
			}
		}

		usort(
			$sorted,
			static function ( \WP_Term $a, \WP_Term $b ): int {
				$pa = Tree_Model::get_position( (int) $a->term_id );
				$pb = Tree_Model::get_position( (int) $b->term_id );
				if ( $pa !== $pb ) {
					return $pa <=> $pb;
				}
				return strcasecmp( $a->name, $b->name );
			}
		);

		$list = array();
		foreach ( $sorted as $child ) {
			$child_id = (int) $child->term_id;
			$type     = self::get_assignment( $taxonomy, $child_id );
			$fixed    = self::get_fixed_assignment( $taxonomy, $child_id );
			$entry    = array(
				'name'        => $child->name,
				'description' => $child->description,
				'shortDescription' => Tree_Model::get_short_description( $child_id ),
				'typeName'    => $type['name'] ?? '',
				'fixed'       => $fixed['name'] ?? '',
				'required'    => self::is_required( $child_id ),
			);
			if ( $depth < 1 && self::is_set_typed( $taxonomy, $child_id ) ) {
				$nested = self::get_help_children( $taxonomy, $child_id, $depth + 1 );
				if ( ! empty( $nested ) ) {
					$entry['children'] = $nested;
				}
			}
			$list[] = $entry;
		}

		return $list;
	}

	/**
	 * Flat list for the detail-panel select (Typen: Datentypen + Praefixe + Basiseinheit + custom branches).
	 *
	 * @return array<int, array{id:int,name:string,path:string}>
	 */
	public static function get_picker_options( string $taxonomy, int $context_term_id ): array {
		$typen_id      = self::resolve_typen_root( $taxonomy, $context_term_id );
		$datentypen_id = self::resolve_datentypen_root( $taxonomy, $context_term_id );
		if ( $typen_id <= 0 || $datentypen_id <= 0 ) {
			return array();
		}

		/** @var array<int, array{id:int,name:string,path:string}> $options */
		$options = array();
		$seen    = array();

		$simple_id  = self::find_named_child( $taxonomy, $datentypen_id, 'Simple' );
		$complex_id = self::find_named_child( $taxonomy, $datentypen_id, 'Complex' );

		if ( $simple_id > 0 ) {
			self::add_term_children_as_options( $taxonomy, $simple_id, $typen_id, $options, $seen );
		}

		if ( $complex_id > 0 ) {
			$complex_children = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $complex_id,
					'hide_empty' => false,
					'orderby'    => 'name',
					'order'      => 'ASC',
				)
			);

			if ( is_array( $complex_children ) ) {
				foreach ( $complex_children as $child ) {
					if ( ! $child instanceof \WP_Term ) {
						continue;
					}
					self::push_option( $taxonomy, $child, $typen_id, $options, $seen );

					if ( 'collection' === strtolower( $child->name ) ) {
						self::add_collection_kind_options( $taxonomy, (int) $child->term_id, $typen_id, $options, $seen );
					}
				}
			}
		}

		$prefixes_id = self::find_prefixes_root( $taxonomy, $context_term_id );
		if ( $prefixes_id > 0 ) {
			$prefixes = get_term( $prefixes_id, $taxonomy );
			if ( $prefixes instanceof \WP_Term ) {
				self::push_option( $taxonomy, $prefixes, $typen_id, $options, $seen );
			}
		}

		$base_units_id = self::find_base_units_root( $taxonomy, $context_term_id );
		if ( $base_units_id > 0 ) {
			$base_units = get_term( $base_units_id, $taxonomy );
			if ( $base_units instanceof \WP_Term ) {
				self::push_option( $taxonomy, $base_units, $typen_id, $options, $seen );
			}
			self::add_term_children_as_options( $taxonomy, $base_units_id, $typen_id, $options, $seen );
		}

		// Custom catalog branches under Typen (e.g. Bauformen) — not Datentypen.
		$typen_children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $typen_id,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => 0,
			)
		);
		if ( is_array( $typen_children ) ) {
			$builtin = array( 'datentypen', 'praefixe', 'basiseinheit' );
			foreach ( $typen_children as $child ) {
				if ( ! $child instanceof \WP_Term ) {
					continue;
				}
				if ( in_array( strtolower( $child->name ), $builtin, true ) ) {
					continue;
				}
				self::push_option( $taxonomy, $child, $typen_id, $options, $seen );
			}
		}

		usort(
			$options,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['path'], $b['path'] );
			}
		);

		return $options;
	}

	/**
	 * Resolve a type term by name under the Typen branch (for demo seeding).
	 */
	public static function find_type_by_name( string $taxonomy, int $context_term_id, string $type_name ): int {
		$type_name = trim( $type_name );
		if ( '' === $type_name ) {
			return 0;
		}

		$typen_id = self::resolve_typen_root( $taxonomy, $context_term_id );
		if ( $typen_id <= 0 ) {
			return 0;
		}

		$matches = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => $type_name,
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( ! is_array( $matches ) ) {
			return 0;
		}

		foreach ( $matches as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$type_id = (int) $term->term_id;
			if ( self::is_typen_type_node( $taxonomy, $context_term_id, $type_id ) ) {
				return $type_id;
			}
		}

		return 0;
	}

	/**
	 * Resolve a fixed-value catalog node by name (e.g. Praefix "m", Basiseinheit "Ohm").
	 */
	public static function find_fixed_by_name( string $taxonomy, int $context_term_id, string $name ): int {
		$name = trim( $name );
		if ( '' === $name ) {
			return 0;
		}

		$matches = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => $name,
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( ! is_array( $matches ) ) {
			return 0;
		}

		foreach ( $matches as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$node_id = (int) $term->term_id;
			if ( self::is_fixed_value_candidate( $taxonomy, $context_term_id, $node_id ) ) {
				return $node_id;
			}
		}

		return 0;
	}

	public static function term_path_from_typen( string $taxonomy, int $term_id ): string {
		$typen_id = self::resolve_typen_root( $taxonomy, $term_id );
		if ( $typen_id <= 0 ) {
			return self::term_breadcrumb( $taxonomy, $term_id );
		}

		$parts   = array();
		$current = get_term( $term_id, $taxonomy );
		$guard   = 0;

		while ( $current instanceof \WP_Term && $guard++ < 64 ) {
			array_unshift( $parts, $current->name );
			if ( (int) $current->term_id === $typen_id ) {
				break;
			}
			if ( ! $current->parent ) {
				break;
			}
			$current = get_term( (int) $current->parent, $taxonomy );
		}

		return implode( ' / ', $parts );
	}

	/** @deprecated Use term_path_from_typen(). */
	public static function term_path_from_datentypen( string $taxonomy, int $term_id ): string {
		return self::term_path_from_typen( $taxonomy, $term_id );
	}

	private static function is_typen_type_node( string $taxonomy, int $context_term_id, int $type_id ): bool {
		$typen_id = self::resolve_typen_root( $taxonomy, $context_term_id );
		if ( $typen_id <= 0 ) {
			return false;
		}

		if ( ! self::is_descendant_of( $taxonomy, $type_id, $typen_id ) && (int) $type_id !== $typen_id ) {
			return false;
		}

		if ( (int) $type_id === $typen_id ) {
			return false;
		}

		$datentypen_id = self::resolve_datentypen_root( $taxonomy, $context_term_id );
		if ( $datentypen_id > 0 && self::is_descendant_of( $taxonomy, $type_id, $datentypen_id ) ) {
			if ( (int) $type_id === $datentypen_id ) {
				return false;
			}
			$blocked = array( 'Simple', 'Complex', 'Collection' );
			$type    = get_term( $type_id, $taxonomy );
			if ( $type instanceof \WP_Term && in_array( $type->name, $blocked, true ) ) {
				return false;
			}
			return true;
		}

		$prefixes_id = self::find_prefixes_root( $taxonomy, $context_term_id );
		if ( $prefixes_id > 0 && (int) $type_id === $prefixes_id ) {
			return true;
		}

		$base_units_id = self::find_base_units_root( $taxonomy, $context_term_id );
		if ( $base_units_id > 0 ) {
			if ( (int) $type_id === $base_units_id ) {
				return true;
			}
			if ( self::is_descendant_of( $taxonomy, $type_id, $base_units_id ) ) {
				return true;
			}
		}

		// Custom branch under Typen (e.g. Bauformen) — assignable like Praefixe.
		$type = get_term( $type_id, $taxonomy );
		if ( $type instanceof \WP_Term && (int) $type->parent === $typen_id ) {
			if ( 'datentypen' !== strtolower( $type->name ) ) {
				return true;
			}
		}

		return false;
	}

	private static function term_breadcrumb( string $taxonomy, int $term_id ): string {
		$parts   = array();
		$current = get_term( $term_id, $taxonomy );
		$guard   = 0;

		while ( $current instanceof \WP_Term && $guard++ < 64 ) {
			array_unshift( $parts, $current->name );
			if ( ! $current->parent ) {
				break;
			}
			$current = get_term( (int) $current->parent, $taxonomy );
		}

		return implode( ' / ', $parts );
	}

	private static function resolve_datentypen_root( string $taxonomy, int $context_term_id ): int {
		$from_context = self::find_datentypen_from_term( $taxonomy, $context_term_id );
		if ( $from_context > 0 ) {
			return $from_context;
		}

		return self::find_any_datentypen_root( $taxonomy );
	}

	private static function resolve_typen_root( string $taxonomy, int $context_term_id ): int {
		$project_root = self::find_project_root( $taxonomy, $context_term_id );
		if ( $project_root <= 0 ) {
			return self::find_any_typen_root( $taxonomy );
		}

		$typen_id = self::find_named_child( $taxonomy, $project_root, 'Typen' );
		if ( $typen_id > 0 ) {
			return $typen_id;
		}

		return self::find_any_typen_root( $taxonomy );
	}

	private static function find_prefixes_root( string $taxonomy, int $context_term_id ): int {
		$typen_id = self::resolve_typen_root( $taxonomy, $context_term_id );
		if ( $typen_id <= 0 ) {
			return 0;
		}

		return self::find_named_child( $taxonomy, $typen_id, 'Praefixe' );
	}

	private static function find_base_units_root( string $taxonomy, int $context_term_id ): int {
		$typen_id = self::resolve_typen_root( $taxonomy, $context_term_id );
		if ( $typen_id <= 0 ) {
			return 0;
		}

		return self::find_named_child( $taxonomy, $typen_id, 'Basiseinheit' );
	}

	private static function find_any_typen_root( string $taxonomy ): int {
		$candidates = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => 'Typen',
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( ! is_array( $candidates ) || empty( $candidates ) ) {
			return 0;
		}

		$first = $candidates[0];
		return $first instanceof \WP_Term ? (int) $first->term_id : 0;
	}

	private static function find_datentypen_from_term( string $taxonomy, int $term_id ): int {
		$project_root = self::find_project_root( $taxonomy, $term_id );
		if ( $project_root <= 0 ) {
			return 0;
		}

		$typen_id = self::find_named_child( $taxonomy, $project_root, 'Typen' );
		if ( $typen_id <= 0 ) {
			return 0;
		}

		return self::find_named_child( $taxonomy, $typen_id, 'Datentypen' );
	}

	private static function find_any_datentypen_root( string $taxonomy ): int {
		$candidates = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => 'Datentypen',
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( ! is_array( $candidates ) ) {
			return 0;
		}

		foreach ( $candidates as $candidate ) {
			if ( ! $candidate instanceof \WP_Term ) {
				continue;
			}
			$parent = get_term( (int) $candidate->parent, $taxonomy );
			if ( $parent instanceof \WP_Term && 'Typen' === $parent->name ) {
				return (int) $candidate->term_id;
			}
		}

		return 0;
	}

	private static function find_project_root( string $taxonomy, int $term_id ): int {
		$current = get_term( $term_id, $taxonomy );
		$guard   = 0;

		while ( $current instanceof \WP_Term && $guard++ < 64 ) {
			if ( ! $current->parent ) {
				return (int) $current->term_id;
			}
			$current = get_term( (int) $current->parent, $taxonomy );
		}

		return 0;
	}

	private static function find_named_child( string $taxonomy, int $parent_id, string $name ): int {
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( ! is_array( $children ) ) {
			return 0;
		}

		foreach ( $children as $child ) {
			if ( $child instanceof \WP_Term && $child->name === $name ) {
				return (int) $child->term_id;
			}
		}

		return 0;
	}

	private static function is_descendant_of( string $taxonomy, int $term_id, int $ancestor_id ): bool {
		if ( $term_id === $ancestor_id ) {
			return true;
		}

		$current = get_term( $term_id, $taxonomy );
		$guard   = 0;

		while ( $current instanceof \WP_Term && $guard++ < 64 ) {
			if ( (int) $current->parent === $ancestor_id ) {
				return true;
			}
			if ( ! $current->parent ) {
				return false;
			}
			$current = get_term( (int) $current->parent, $taxonomy );
		}

		return false;
	}

	/**
	 * @param array<int, array{id:int,name:string,path:string}> $options
	 * @param array<int, true>                                  $seen
	 */
	private static function add_term_children_as_options(
		string $taxonomy,
		int $parent_id,
		int $typen_id,
		array &$options,
		array &$seen
	): void {
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( ! is_array( $children ) ) {
			return;
		}

		foreach ( $children as $child ) {
			if ( $child instanceof \WP_Term ) {
				self::push_option( $taxonomy, $child, $typen_id, $options, $seen );
			}
		}
	}

	/**
	 * Collection kinds (list/table/enum/set) and their concrete types.
	 *
	 * @param array<int, array{id:int,name:string,path:string}> $options
	 * @param array<int, true>                                  $seen
	 */
	private static function add_collection_kind_options(
		string $taxonomy,
		int $collection_id,
		int $typen_id,
		array &$options,
		array &$seen
	): void {
		$kinds = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $collection_id,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( ! is_array( $kinds ) ) {
			return;
		}

		foreach ( $kinds as $kind ) {
			if ( ! $kind instanceof \WP_Term ) {
				continue;
			}
			self::push_option( $taxonomy, $kind, $typen_id, $options, $seen );
			self::add_term_children_as_options( $taxonomy, (int) $kind->term_id, $typen_id, $options, $seen );
		}
	}

	/**
	 * @param array<int, array{id:int,name:string,path:string}> $options
	 * @param array<int, true>                                  $seen
	 */
	private static function push_option(
		string $taxonomy,
		\WP_Term $term,
		int $typen_id,
		array &$options,
		array &$seen
	): void {
		$id = (int) $term->term_id;
		if ( isset( $seen[ $id ] ) ) {
			return;
		}

		$seen[ $id ] = true;
		$options[]   = array(
			'id'   => $id,
			'name' => $term->name,
			'path' => self::term_path_from_typen( $taxonomy, $id ),
			'shortDescription' => Tree_Model::get_short_description( $id ),
		);
	}
}
