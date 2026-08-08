<?php
/**
 * Node attributes trial (Q87): Name + Type + Mult. via besteht_aus / aggregation.
 * Inherit along host child_of (Q86). Slots are NOT hierarchy children of the host.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attributes on a host Node = outgoing besteht_aus / aggregation members with a required type.
 */
final class Attribute {

	public const DEFAULT_MULTIPLICITY = '1';

	/** Default Bindung: composition (dies with object). */
	public const DEFAULT_BINDING = 'besteht_aus';

	/** Allowed attribute Bindung keys (RelationType slugs). */
	public const BINDINGS = array( 'besteht_aus', 'aggregation' );

	/** Term meta on host: list of inherited attribute term ids hidden on this node. */
	public const META_KEY_HIDDEN = '_wtt_hidden_attributes';

	/** Term meta on host: ordered list of own attribute term ids (display order). */
	public const META_KEY_ORDER = '_wtt_attribute_order';

	/**
	 * Term meta on host: map attribute name → string|list of default values.
	 * (Internal key kept as fixed_values for compatibility.)
	 * Survives type override; scoped to the current node.
	 */
	public const META_KEY_FIXED_VALUES = '_wtt_attribute_fixed_values';

	/**
	 * Term meta on host: list of attribute term ids marked readonly on this node.
	 */
	public const META_KEY_READONLY = '_wtt_attribute_readonly';

	/**
	 * Marks a term as an attribute slot (besteht_aus / aggregation only — not child_of host).
	 */
	public const META_KEY_SLOT = '_wtt_attribute_slot';

	/**
	 * Term meta on host: map attribute term id → type extras
	 * (dateMode, choiceFilter, compute). Same host-map pattern as fixed values.
	 */
	public const META_KEY_TYPE_EXTRAS = '_wtt_attribute_type_extras';

	/**
	 * Normalize Bindung to aggregation | besteht_aus (composition alias → besteht_aus).
	 */
	public static function normalize_binding( string $binding ): string {
		$key = strtolower( trim( $binding ) );
		if ( Relation::type_keys_match( $key, Relation::TYPE_COMPOSITION ) || 'composition' === $key ) {
			return Relation::TYPE_COMPOSITION;
		}
		if ( Relation::type_keys_match( $key, Relation::TYPE_AGGREGATION ) || Relation::TYPE_AGGREGATION === $key ) {
			return Relation::TYPE_AGGREGATION;
		}
		return self::DEFAULT_BINDING;
	}

	/**
	 * Whether a RelationType key is an attribute Bindung.
	 */
	public static function is_attribute_binding( string $type_key ): bool {
		$key = strtolower( trim( $type_key ) );
		foreach ( self::BINDINGS as $allowed ) {
			if ( Relation::type_keys_match( $key, $allowed ) ) {
				return true;
			}
		}
		return 'composition' === $key;
	}

	/**
	 * Effective attributes: own + ancestors along WP parent / child_of.
	 * Child overrides ancestor by attribute name (child wins). Hidden inherited stay listed.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function list( string $taxonomy, int $host_id ): array {
		return self::effective_list( $taxonomy, $host_id );
	}

	/**
	 * Attributes defined on this host only (no inheritance).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function list_own( string $taxonomy, int $host_id ): array {
		$out = array();
		foreach ( self::list_own_raw( $taxonomy, $host_id ) as $row ) {
			$out[] = self::decorate_row( $row, $taxonomy, $host_id );
		}
		return $out;
	}

	/**
	 * Move an own attribute up (delta -1) or down (delta +1) in host display order.
	 *
	 * @return true|\WP_Error
	 */
	public static function reorder( string $taxonomy, int $host_id, int $attr_id, int $delta ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}
		if ( $attr_id <= 0 || 0 === $delta ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Invalid attribute move.', 'wp-taxonomy-tree' ) );
		}
		if ( ! self::is_own_member( $taxonomy, $host_id, $attr_id ) ) {
			return new \WP_Error(
				'wtt_bad_attribute',
				__( 'Only own attributes can be reordered.', 'wp-taxonomy-tree' )
			);
		}

		$ids = array();
		foreach ( self::list_own_raw( $taxonomy, $host_id ) as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		$index = array_search( $attr_id, $ids, true );
		if ( false === $index ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}
		$target = (int) $index + $delta;
		if ( $target < 0 || $target >= count( $ids ) ) {
			return true;
		}
		$tmp            = $ids[ $index ];
		$ids[ $index ]  = $ids[ $target ];
		$ids[ $target ] = $tmp;
		self::store_order_ids( $host_id, $ids );
		Tree_Model::touch_modified( $host_id );

		return true;
	}

	/**
	 * Merge attributes from root → … → host along child_of; same name → closer node wins.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function effective_list( string $taxonomy, int $host_id ): array {
		if ( $host_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return array();
		}

		$chain = self::ancestor_chain_root_to_self( $taxonomy, $host_id );
		if ( empty( $chain ) ) {
			return array();
		}

		$hidden_ids   = self::get_hidden_ids( $host_id );
		$readonly_ids = self::get_readonly_ids( $host_id );

		/** @var array<string, array<string, mixed>> $by_name */
		$by_name = array();
		foreach ( $chain as $node_id ) {
			$is_self = ( $node_id === $host_id );
			foreach ( self::list_own_raw( $taxonomy, $node_id ) as $row ) {
				$row['inherited']     = ! $is_self;
				$row['definedOnId']   = $node_id;
				$def                  = get_term( $node_id, $taxonomy );
				$row['definedOnName'] = $def instanceof \WP_Term ? $def->name : '';
				$row['hidden']        = false;
				$row['readonly']      = false;
				$by_name[ $row['name'] ] = $row;
			}
		}

		$out = array();
		foreach ( $by_name as $row ) {
			$attr_id = (int) ( $row['id'] ?? 0 );
			if ( ! empty( $row['inherited'] ) && isset( $hidden_ids[ $attr_id ] ) ) {
				$row['hidden'] = true;
			}
			if ( isset( $readonly_ids[ $attr_id ] ) ) {
				$row['readonly'] = true;
				/* Keep slot meta in sync when host already had RO before slot sync existed. */
				if ( $attr_id > 0 && ! Node_Type::has_readonly_meta( $attr_id ) ) {
					Node_Type::set_readonly( $taxonomy, $attr_id, true );
				}
			} elseif (
				$attr_id > 0
				&& empty( $row['inherited'] )
				&& Node_Type::is_fixed_enabled( $attr_id )
			) {
				/*
				 * Lean migration: own slot with Fixed-as-lock → host RO + slot RO.
				 * Does not delete fixed* meta. Inherited stays host-override only (OQ-A3).
				 */
				Node_Type::maybe_migrate_fixed_lock_to_readonly( $attr_id );
				$ids                 = self::get_readonly_ids( $host_id );
				$ids[ $attr_id ]     = true;
				self::store_readonly_ids( $host_id, array_keys( $ids ) );
				$readonly_ids[ $attr_id ] = true;
				$row['readonly']          = true;
			}
			$out[] = self::decorate_row( $row, $taxonomy, $host_id );
		}

		return self::apply_order( $host_id, $out );
	}

	/**
	 * Hide or show an inherited attribute on $host_id (does not delete the parent definition).
	 *
	 * @return true|\WP_Error
	 */
	public static function set_hidden( string $taxonomy, int $host_id, int $attr_id, bool $hidden ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}
		if ( $attr_id <= 0 ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute not found.', 'wp-taxonomy-tree' ) );
		}

		$found = self::find_effective_row( $taxonomy, $host_id, $attr_id );
		if ( null === $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}
		if ( empty( $found['inherited'] ) ) {
			return new \WP_Error(
				'wtt_bad_attribute',
				__( 'Only inherited attributes can be hidden. Remove own attributes instead.', 'wp-taxonomy-tree' )
			);
		}

		$ids = self::get_hidden_ids( $host_id );
		if ( $hidden ) {
			$ids[ $attr_id ] = true;
		} else {
			unset( $ids[ $attr_id ] );
		}
		self::store_hidden_ids( $host_id, array_keys( $ids ) );
		Tree_Model::touch_modified( $host_id );

		return true;
	}

	/**
	 * Mark an effective attribute readonly on this host (own or inherited).
	 * Syncs the lock onto the attribute slot term. Host list remains SoT for
	 * OQ-A3 (RO default off; heir may switch on without mutating father’s override).
	 *
	 * @return true|\WP_Error
	 */
	public static function set_readonly( string $taxonomy, int $host_id, int $attr_id, bool $readonly ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}
		if ( $attr_id <= 0 ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute not found.', 'wp-taxonomy-tree' ) );
		}

		$found = self::find_effective_row( $taxonomy, $host_id, $attr_id );
		if ( null === $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}

		$ids = self::get_readonly_ids( $host_id );
		if ( $readonly ) {
			$ids[ $attr_id ] = true;
			self::store_readonly_ids( $host_id, array_keys( $ids ) );
			$slot = Node_Type::set_readonly( $taxonomy, $attr_id, true );
			if ( is_wp_error( $slot ) ) {
				return $slot;
			}
		} else {
			unset( $ids[ $attr_id ] );
			self::store_readonly_ids( $host_id, array_keys( $ids ) );
			if ( ! self::slot_readonly_held_elsewhere( $taxonomy, $host_id, $attr_id ) ) {
				$slot = Node_Type::set_readonly( $taxonomy, $attr_id, false );
				if ( is_wp_error( $slot ) ) {
					return $slot;
				}
			}
		}
		Tree_Model::touch_modified( $host_id );

		return true;
	}

	/**
	 * Whether another host on the inheritance chain still marks this attribute RO
	 * (OQ-A3: do not clear slot RO when an ancestor/owner still holds it).
	 */
	private static function slot_readonly_held_elsewhere( string $taxonomy, int $host_id, int $attr_id ): bool {
		$chain = self::ancestor_chain_root_to_self( $taxonomy, $host_id );
		foreach ( $chain as $node_id ) {
			if ( (int) $node_id === $host_id ) {
				continue;
			}
			$ids = self::get_readonly_ids( (int) $node_id );
			if ( isset( $ids[ $attr_id ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * When slot-node readonly is toggled from node settings, mirror onto owning hosts’
	 * `_wtt_attribute_readonly` lists (meta only — no Nested set_readonly).
	 */
	public static function sync_hosts_readonly_from_slot( string $taxonomy, int $slot_id, bool $readonly ): void {
		if ( $slot_id <= 0 || ! self::is_slot( $slot_id ) || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		foreach ( Relation::list_incoming( $taxonomy, $slot_id ) as $edge ) {
			$type_key = (string) ( $edge['typeKey'] ?? '' );
			if ( ! self::is_attribute_binding( $type_key ) ) {
				continue;
			}
			$host_id = (int) ( $edge['fromId'] ?? 0 );
			if ( $host_id <= 0 ) {
				continue;
			}
			$ids = self::get_readonly_ids( $host_id );
			if ( $readonly ) {
				$ids[ $slot_id ] = true;
			} else {
				unset( $ids[ $slot_id ] );
			}
			self::store_readonly_ids( $host_id, array_keys( $ids ) );
			Tree_Model::touch_modified( $host_id );
		}
	}

	/**
	 * Set default value template(s) on the current host for an effective attribute (Q106).
	 *
	 * Always a list sized by Mult: `0`/`1` → at most one entry; `0..*`/`1..*` → many.
	 * Scalars store strings; related Mult may store nested value maps (default rows).
	 *
	 * @param list<string|array<string,string>>|string|null $values Null / empty clears.
	 * @return true|\WP_Error
	 */
	public static function set_fixed_values( string $taxonomy, int $host_id, int $attr_id, $values ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}

		$found = self::find_effective_row( $taxonomy, $host_id, $attr_id );
		if ( null === $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}

		$name = (string) ( $found['name'] ?? '' );
		if ( '' === $name ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute name is required.', 'wp-taxonomy-tree' ) );
		}

		$normalized = self::normalize_fixed_values_input( $values );
		$many       = self::multiplicity_allows_many( (string) ( $found['multiplicity'] ?? self::DEFAULT_MULTIPLICITY ) );
		if ( ! $many && count( $normalized ) > 1 ) {
			$normalized = array_slice( $normalized, 0, 1 );
		}

		$map = self::get_fixed_values_map( $host_id );
		if ( empty( $normalized ) ) {
			unset( $map[ $name ] );
		} elseif ( $many ) {
			$map[ $name ] = $normalized;
		} else {
			$map[ $name ] = $normalized[0];
		}
		self::store_fixed_values_map( $host_id, $map );
		Tree_Model::touch_modified( $host_id );

		return true;
	}

	/**
	 * Change Bindung: own edge updates RelationType; inherited creates local override.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function set_binding( string $taxonomy, int $host_id, int $attr_id, string $binding ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}

		$binding      = self::normalize_binding( $binding );
		$binding_type = Relation::find_type_id_by_name( $taxonomy, $binding );
		if ( $binding_type <= 0 ) {
			return new \WP_Error(
				'wtt_no_binding_type',
				/* translators: %s: RelationType key */
				sprintf( __( 'RelationType %s is missing.', 'wp-taxonomy-tree' ), $binding )
			);
		}

		$edge = self::find_edge( $taxonomy, $host_id, $attr_id );
		if ( null !== $edge ) {
			$edge_id = (string) ( $edge['id'] ?? '' );
			if ( '' === $edge_id ) {
				return new \WP_Error( 'wtt_bad_relation', __( 'Missing relation id.', 'wp-taxonomy-tree' ) );
			}
			$updated = Relation::update_type( $taxonomy, $host_id, $edge_id, $binding_type );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			return self::find_own_row( $taxonomy, $host_id, $attr_id );
		}

		$found = self::find_effective_row( $taxonomy, $host_id, $attr_id );
		if ( null === $found || empty( $found['inherited'] ) ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}

		$type_id = (int) ( $found['typeId'] ?? 0 );
		if ( $type_id <= 0 ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute type is required.', 'wp-taxonomy-tree' ) );
		}

		return self::add(
			$taxonomy,
			$host_id,
			(string) $found['name'],
			$type_id,
			(string) ( $found['multiplicity'] ?? self::DEFAULT_MULTIPLICITY ),
			$binding
		);
	}

	/**
	 * Change type: own attr updates type_id; inherited creates local override (same name).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function set_type( string $taxonomy, int $host_id, int $attr_id, int $type_id ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		if ( $type_id <= 0 ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute type is required.', 'wp-taxonomy-tree' ) );
		}
		$type = get_term( $type_id, $taxonomy );
		if ( ! $type instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_bad_type', __( 'Data type not found.', 'wp-taxonomy-tree' ) );
		}
		if ( ! Node_Type::is_assignable_type( $taxonomy, $host_id, $type_id ) ) {
			return new \WP_Error(
				'wtt_bad_type',
				__( 'Choose an existing type node.', 'wp-taxonomy-tree' )
			);
		}

		$edge = self::find_edge( $taxonomy, $host_id, $attr_id );
		if ( null !== $edge ) {
			return self::update( $taxonomy, $host_id, $attr_id, array( 'typeId' => $type_id ) );
		}

		$found = self::find_effective_row( $taxonomy, $host_id, $attr_id );
		if ( null === $found || empty( $found['inherited'] ) ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}

		/* Local override by name (child wins in effective_list). */
		return self::add(
			$taxonomy,
			$host_id,
			(string) $found['name'],
			$type_id,
			(string) ( $found['multiplicity'] ?? self::DEFAULT_MULTIPLICITY ),
			(string) ( $found['binding'] ?? self::DEFAULT_BINDING )
		);
	}

	/**
	 * Change multiplicity: own edge updates; inherited creates local override (same name + type).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function set_multiplicity( string $taxonomy, int $host_id, int $attr_id, string $multiplicity ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}

		$multiplicity = Relation::normalize_multiplicity( $multiplicity );
		if ( '' === $multiplicity ) {
			$multiplicity = self::DEFAULT_MULTIPLICITY;
		}

		$edge = self::find_edge( $taxonomy, $host_id, $attr_id );
		if ( null !== $edge ) {
			$result = self::update( $taxonomy, $host_id, $attr_id, array( 'multiplicity' => $multiplicity ) );
			if ( ! is_wp_error( $result ) ) {
				self::trim_fixed_values_to_multiplicity( $host_id, (string) ( $result['name'] ?? '' ), $multiplicity );
			}
			return $result;
		}

		$found = self::find_effective_row( $taxonomy, $host_id, $attr_id );
		if ( null === $found || empty( $found['inherited'] ) ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}

		$type_id = (int) ( $found['typeId'] ?? 0 );
		if ( $type_id <= 0 ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute type is required.', 'wp-taxonomy-tree' ) );
		}

		$result = self::add(
			$taxonomy,
			$host_id,
			(string) $found['name'],
			$type_id,
			$multiplicity,
			(string) ( $found['binding'] ?? self::DEFAULT_BINDING )
		);
		if ( ! is_wp_error( $result ) ) {
			self::trim_fixed_values_to_multiplicity( $host_id, (string) $found['name'], $multiplicity );
		}
		return $result;
	}

	/**
	 * Drop excess Festwert entries when multiplicity no longer allows many.
	 */
	private static function trim_fixed_values_to_multiplicity( int $host_id, string $name, string $multiplicity ): void {
		if ( '' === $name ) {
			return;
		}
		if ( self::multiplicity_allows_many( $multiplicity ) ) {
			return;
		}
		$map = self::get_fixed_values_map( $host_id );
		if ( ! isset( $map[ $name ] ) ) {
			return;
		}
		$values = self::normalize_fixed_values_input( $map[ $name ] );
		if ( count( $values ) <= 1 ) {
			if ( 1 === count( $values ) ) {
				$map[ $name ] = $values[0];
				self::store_fixed_values_map( $host_id, $map );
			}
			return;
		}
		$map[ $name ] = $values[0];
		self::store_fixed_values_map( $host_id, $map );
	}

	/**
	 * @return array<int, true>
	 */
	public static function get_hidden_ids( int $host_id ): array {
		$raw = get_term_meta( $host_id, self::META_KEY_HIDDEN, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[ $id ] = true;
			}
		}
		return $out;
	}

	/**
	 * @return array<int, true>
	 */
	public static function get_readonly_ids( int $host_id ): array {
		$raw = get_term_meta( $host_id, self::META_KEY_READONLY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[ $id ] = true;
			}
		}
		return $out;
	}

	/**
	 * @param list<int> $ids
	 */
	private static function store_readonly_ids( int $host_id, array $ids ): void {
		$clean = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		if ( empty( $clean ) ) {
			delete_term_meta( $host_id, self::META_KEY_READONLY );
			return;
		}
		update_term_meta( $host_id, self::META_KEY_READONLY, $clean );
	}

	/**
	 * Whether multiplicity allows more than one value.
	 */
	public static function multiplicity_allows_many( string $multiplicity ): bool {
		$m = Relation::normalize_multiplicity( $multiplicity );
		return in_array( $m, array( '0..*', '1..*' ), true );
	}

	/**
	 * Whether an empty value is allowed (optional cardinalities).
	 * Required single `1` / required many `1..*` → false (swap / keep ≥1, never clear).
	 */
	public static function multiplicity_allows_empty( string $multiplicity ): bool {
		$m = Relation::normalize_multiplicity( $multiplicity );
		return in_array( $m, array( '0..1', '0..*' ), true );
	}

	/**
	 * Create attribute: slot term + type + Bindung edge (no hierarchy parent).
	 *
	 * Optional `$readonly` and `$fixed_values` are applied on the host after create
	 * (same semantics as set_readonly / set_fixed_values).
	 *
	 * @param list<string>|string|null $fixed_values Null skips; empty clears (no-op on new).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function add(
		string $taxonomy,
		int $host_id,
		string $name,
		int $type_id,
		string $multiplicity = self::DEFAULT_MULTIPLICITY,
		string $binding = self::DEFAULT_BINDING,
		bool $readonly = false,
		$fixed_values = null
	) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}

		$name = trim( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute name is required.', 'wp-taxonomy-tree' ) );
		}
		if ( $type_id <= 0 ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute type is required.', 'wp-taxonomy-tree' ) );
		}

		$type = get_term( $type_id, $taxonomy );
		if ( ! $type instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_bad_type', __( 'Data type not found.', 'wp-taxonomy-tree' ) );
		}
		if ( ! Node_Type::is_assignable_type( $taxonomy, $host_id, $type_id ) ) {
			return new \WP_Error(
				'wtt_bad_type',
				__( 'Choose an existing type node.', 'wp-taxonomy-tree' )
			);
		}

		$binding      = self::normalize_binding( $binding );
		$binding_type = Relation::find_type_id_by_name( $taxonomy, $binding );
		if ( $binding_type <= 0 ) {
			return new \WP_Error(
				'wtt_no_binding_type',
				/* translators: %s: RelationType key */
				sprintf( __( 'RelationType %s is missing.', 'wp-taxonomy-tree' ), $binding )
			);
		}

		$multiplicity = Relation::normalize_multiplicity( $multiplicity );
		if ( '' === $multiplicity ) {
			$multiplicity = self::DEFAULT_MULTIPLICITY;
		}

		$existing_own = self::list_own_raw( $taxonomy, $host_id );
		foreach ( $existing_own as $row ) {
			if ( (string) ( $row['name'] ?? '' ) === $name ) {
				return new \WP_Error(
					'wtt_name_conflict',
					__( 'An attribute with this name already exists on this node.', 'wp-taxonomy-tree' )
				);
			}
		}

		/*
		 * Attribute slots are NOT hierarchy children of the host (no child_of).
		 * They are terms marked as slots + besteht_aus / aggregation edges only.
		 */
		$inserted = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'parent'      => 0,
				'description' => '',
			)
		);
		if ( is_wp_error( $inserted ) ) {
			/* Name clash at root — append unique slug. */
			$inserted = wp_insert_term(
				$name,
				$taxonomy,
				array(
					'parent' => 0,
					'slug'   => sanitize_title( $name . '-' . $host_id . '-' . wp_generate_password( 4, false ) ),
				)
			);
			if ( is_wp_error( $inserted ) ) {
				return $inserted;
			}
		}
		$attr_id = (int) ( $inserted['term_id'] ?? 0 );
		$created = true;
		if ( $attr_id <= 0 ) {
			return new \WP_Error( 'wtt_create_failed', __( 'Could not create attribute node.', 'wp-taxonomy-tree' ) );
		}
		self::mark_as_slot( $attr_id );

		/*
		 * Link besteht_aus / aggregation BEFORE set_type_id.
		 * Q88 locks hierarchy children to parent datatype; attribute members are
		 * excluded via is_own_member — which needs this edge first.
		 */
		$edge = self::find_edge( $taxonomy, $host_id, $attr_id );
		if ( null !== $edge ) {
			$edge_id = (string) ( $edge['id'] ?? '' );
			if ( '' !== $edge_id ) {
				$mult_ok = Relation::update_multiplicity( $taxonomy, $host_id, $edge_id, $multiplicity );
				if ( is_wp_error( $mult_ok ) ) {
					return $mult_ok;
				}
				$type_ok = Relation::update_type( $taxonomy, $host_id, $edge_id, $binding_type );
				if ( is_wp_error( $type_ok ) ) {
					return $type_ok;
				}
			}
		} else {
			$linked = Relation::add( $taxonomy, $host_id, $binding_type, $attr_id, $multiplicity );
			if ( is_wp_error( $linked ) ) {
				if ( $created ) {
					wp_delete_term( $attr_id, $taxonomy );
				}
				return $linked;
			}
		}

		$typed = Node_Type::set_type_id( $taxonomy, $attr_id, $type_id );
		if ( is_wp_error( $typed ) ) {
			if ( $created ) {
				Relation::remove( $taxonomy, $host_id, $binding_type, $attr_id );
				wp_delete_term( $attr_id, $taxonomy );
			}
			return $typed;
		}

		/*
		 * Preferred render: inherit type node's preferred until the slot overrides
		 * (no meta written here — Attributes UI can change it).
		 */

		/*
		 * Class with attributes is a datatype; hierarchy children type as this parent.
		 */
		Node_Type::promote_class_datatype( $taxonomy, $host_id );
		Tree_Model::touch_modified( $host_id );

		if ( $readonly ) {
			$ro = self::set_readonly( $taxonomy, $host_id, $attr_id, true );
			if ( is_wp_error( $ro ) ) {
				return $ro;
			}
		}

		if ( null !== $fixed_values ) {
			$normalized = self::normalize_fixed_values_input( $fixed_values );
			if ( ! empty( $normalized ) ) {
				$fv = self::set_fixed_values( $taxonomy, $host_id, $attr_id, $normalized );
				if ( is_wp_error( $fv ) ) {
					return $fv;
				}
			}
		}

		return self::find_own_row( $taxonomy, $host_id, $attr_id );
	}

	/**
	 * @param array{name?:string,typeId?:int,multiplicity?:string} $changes
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update( string $taxonomy, int $host_id, int $attr_id, array $changes ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}

		$edge = self::find_edge( $taxonomy, $host_id, $attr_id );
		if ( null === $edge ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}

		$term = get_term( $attr_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute node not found.', 'wp-taxonomy-tree' ) );
		}

		$old_name = $term->name;

		if ( array_key_exists( 'name', $changes ) ) {
			$new_name = trim( (string) $changes['name'] );
			if ( '' === $new_name ) {
				return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute name is required.', 'wp-taxonomy-tree' ) );
			}
			if ( $new_name !== $term->name ) {
				$renamed = wp_update_term( $attr_id, $taxonomy, array( 'name' => $new_name ) );
				if ( is_wp_error( $renamed ) ) {
					return $renamed;
				}
				self::rename_fixed_values_key( $host_id, $old_name, $new_name );
			}
		}

		if ( array_key_exists( 'typeId', $changes ) ) {
			$type_id = (int) $changes['typeId'];
			if ( $type_id <= 0 ) {
				return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute type is required.', 'wp-taxonomy-tree' ) );
			}
			$typed = Node_Type::set_type_id( $taxonomy, $attr_id, $type_id );
			if ( is_wp_error( $typed ) ) {
				return $typed;
			}
			/* Type changed — drop slot override so Preferred follows the new type default. */
			delete_term_meta( $attr_id, Node_Type::META_KEY_PREFERRED_RENDER );
		}

		if ( array_key_exists( 'multiplicity', $changes ) ) {
			$mult    = Relation::normalize_multiplicity( (string) $changes['multiplicity'] );
			$edge_id = (string) ( $edge['id'] ?? '' );
			if ( '' === $edge_id ) {
				return new \WP_Error( 'wtt_bad_relation', __( 'Missing relation id.', 'wp-taxonomy-tree' ) );
			}
			$updated = Relation::update_multiplicity( $taxonomy, $host_id, $edge_id, $mult );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		Tree_Model::touch_modified( $host_id );
		return self::find_own_row( $taxonomy, $host_id, $attr_id );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function remove( string $taxonomy, int $host_id, int $attr_id ) {
		$edge = self::find_edge( $taxonomy, $host_id, $attr_id );
		if ( null === $edge ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}

		$term = get_term( $attr_id, $taxonomy );
		$name = $term instanceof \WP_Term ? $term->name : '';

		$edge_type_id = (int) ( $edge['typeId'] ?? 0 );
		$edge_id      = (string) ( $edge['id'] ?? '' );
		$removed      = Relation::remove( $taxonomy, $host_id, $edge_type_id, $attr_id, $edge_id );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}

		if ( $term instanceof \WP_Term ) {
			$still_used = false;
			foreach ( Relation::list_incoming( $taxonomy, $attr_id ) as $incoming ) {
				$key = strtolower( (string) ( $incoming['typeKey'] ?? $incoming['typeName'] ?? '' ) );
				if ( self::is_attribute_binding( $key ) ) {
					$still_used = true;
					break;
				}
			}
			if ( ! $still_used ) {
				Node_Type::set_deletable( $attr_id, true );
				wp_delete_term( $attr_id, $taxonomy );
			} else {
				self::detach_from_hierarchy_parent( $taxonomy, $attr_id );
			}
		}

		if ( '' !== $name ) {
			$map = self::get_fixed_values_map( $host_id );
			if ( isset( $map[ $name ] ) ) {
				unset( $map[ $name ] );
				self::store_fixed_values_map( $host_id, $map );
			}
		}

		Tree_Model::touch_modified( $host_id );
		return true;
	}

	/**
	 * Move an own attribute membership from one host node to another.
	 *
	 * Used by move-to-parent and move-to-child. Type, Bindung, and multiplicity
	 * stay on the attribute term / new edge. Host-scoped Festwert / Hide meta
	 * stay on $from_id (not transferred).
	 *
	 * @return array<string, mixed>|\WP_Error Decorated own row on $to_id.
	 */
	public static function move_to_node( string $taxonomy, int $attr_id, int $from_id, int $to_id ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		if ( $from_id <= 0 || $to_id <= 0 || $attr_id <= 0 ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Invalid attribute move.', 'wp-taxonomy-tree' ) );
		}
		if ( $from_id === $to_id ) {
			return new \WP_Error(
				'wtt_bad_target',
				__( 'Source and target node must differ.', 'wp-taxonomy-tree' )
			);
		}
		if ( $to_id === $attr_id ) {
			return new \WP_Error(
				'wtt_bad_target',
				__( 'Cannot move an attribute onto itself.', 'wp-taxonomy-tree' )
			);
		}

		$from = get_term( $from_id, $taxonomy );
		$to   = get_term( $to_id, $taxonomy );
		if ( ! $from instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}
		if ( ! $to instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Target node not found.', 'wp-taxonomy-tree' ) );
		}

		if ( class_exists( __NAMESPACE__ . '\\Trash' ) ) {
			if ( Trash::is_trash_node( $to_id ) || Trash::is_trashed( $to_id ) ) {
				return new \WP_Error(
					'wtt_bad_target',
					__( 'Cannot move an attribute onto a trashed node.', 'wp-taxonomy-tree' )
				);
			}
		}

		$edge = self::find_edge( $taxonomy, $from_id, $attr_id );
		if ( null === $edge ) {
			return new \WP_Error(
				'wtt_not_found',
				__( 'Only own attributes can be moved.', 'wp-taxonomy-tree' )
			);
		}

		$term = get_term( $attr_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute node not found.', 'wp-taxonomy-tree' ) );
		}

		$attr_name = $term->name;
		foreach ( self::list_own_raw( $taxonomy, $to_id ) as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $attr_id ) {
				return new \WP_Error(
					'wtt_duplicate_attribute',
					__( 'The target already owns this attribute.', 'wp-taxonomy-tree' )
				);
			}
			if ( (string) ( $row['name'] ?? '' ) === $attr_name ) {
				return new \WP_Error(
					'wtt_name_conflict',
					__( 'The target already has an attribute with this name.', 'wp-taxonomy-tree' )
				);
			}
		}

		$edge_type_id = (int) ( $edge['typeId'] ?? 0 );
		$edge_id      = (string) ( $edge['id'] ?? '' );
		$multiplicity = Relation::normalize_multiplicity(
			(string) ( $edge['multiplicity'] ?? self::DEFAULT_MULTIPLICITY )
		);
		if ( '' === $multiplicity ) {
			$multiplicity = self::DEFAULT_MULTIPLICITY;
		}
		if ( $edge_type_id <= 0 ) {
			return new \WP_Error( 'wtt_bad_relation', __( 'Missing relation type.', 'wp-taxonomy-tree' ) );
		}

		$removed = Relation::remove( $taxonomy, $from_id, $edge_type_id, $attr_id, $edge_id );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}

		if ( (int) $term->parent === $from_id ) {
			self::detach_from_hierarchy_parent( $taxonomy, $attr_id );
		}

		$linked = Relation::add( $taxonomy, $to_id, $edge_type_id, $attr_id, $multiplicity );
		if ( is_wp_error( $linked ) ) {
			Relation::add( $taxonomy, $from_id, $edge_type_id, $attr_id, $multiplicity );
			return $linked;
		}

		self::mark_as_slot( $attr_id );
		Node_Type::promote_class_datatype( $taxonomy, $to_id );
		Tree_Model::touch_modified( $from_id );
		Tree_Model::touch_modified( $to_id );

		return self::find_own_row( $taxonomy, $to_id, $attr_id );
	}

	/**
	 * Move an own attribute to the host's WP hierarchy parent (Q86/Q87).
	 *
	 * @return array<string, mixed>|\WP_Error Decorated own row on the parent.
	 */
	public static function move_to_parent( string $taxonomy, int $host_id, int $attr_id ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}

		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}

		$parent_id = (int) $host->parent;
		if ( $parent_id <= 0 ) {
			return new \WP_Error(
				'wtt_no_parent',
				__( 'This node has no parent to move the attribute to.', 'wp-taxonomy-tree' )
			);
		}

		return self::move_to_node( $taxonomy, $attr_id, $host_id, $parent_id );
	}

	/**
	 * Move an own attribute onto a direct hierarchy child of the host.
	 *
	 * Target must be a direct WP child that is not an attribute member of the host.
	 *
	 * @return array<string, mixed>|\WP_Error Decorated own row on the child.
	 */
	public static function move_to_child( string $taxonomy, int $host_id, int $attr_id, int $child_id ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}

		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}

		if ( ! self::is_eligible_move_child( $taxonomy, $host_id, $child_id ) ) {
			return new \WP_Error(
				'wtt_bad_target',
				__( 'Choose a direct hierarchy child that can own attributes.', 'wp-taxonomy-tree' )
			);
		}

		return self::move_to_node( $taxonomy, $attr_id, $host_id, $child_id );
	}

	/**
	 * Direct hierarchy children of $host_id that can receive a moved attribute.
	 * Excludes own attribute members, Trash, and soft-deleted terms.
	 *
	 * @return list<array{id:int,name:string}>
	 */
	public static function list_eligible_move_children( string $taxonomy, int $host_id ): array {
		if ( $host_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $host_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $child ) {
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			$child_id = (int) $child->term_id;
			if ( ! self::is_eligible_move_child( $taxonomy, $host_id, $child_id ) ) {
				continue;
			}
			$out[] = array(
				'id'   => $child_id,
				'name' => $child->name,
			);
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return $out;
	}

	/**
	 * Whether $child_id is a direct hierarchy child of $host_id that can own attributes.
	 */
	public static function is_eligible_move_child( string $taxonomy, int $host_id, int $child_id ): bool {
		if ( $host_id <= 0 || $child_id <= 0 || $host_id === $child_id ) {
			return false;
		}
		$child = get_term( $child_id, $taxonomy );
		if ( ! $child instanceof \WP_Term ) {
			return false;
		}
		if ( (int) $child->parent !== $host_id ) {
			return false;
		}
		if ( class_exists( __NAMESPACE__ . '\\Trash' ) ) {
			if ( Trash::is_trash_node( $child_id ) || Trash::is_trashed( $child_id ) ) {
				return false;
			}
		}
		/* Attribute members are composition slots, not class hosts for this move. */
		if ( self::is_own_member( $taxonomy, $host_id, $child_id ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a type node carries attribute members (own or inherited along child_of).
	 * Shallow: uses raw edges only — safe to call from decorate_row (no recursion).
	 */
	public static function type_has_attributes( string $taxonomy, int $type_id ): bool {
		if ( $type_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		$chain = self::ancestor_chain_root_to_self( $taxonomy, $type_id );
		foreach ( $chain as $node_id ) {
			if ( array() !== self::list_own_raw( $taxonomy, $node_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Own rows without Festwert decoration (used while merging ancestors).
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function list_own_raw( string $taxonomy, int $host_id ): array {
		if ( $host_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return array();
		}

		$host_name = $host->name;
		$seen      = array();
		$out       = array();

		foreach ( self::BINDINGS as $binding_key ) {
			foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $host_id, $binding_key ) as $edge ) {
				$attr_id = (int) ( $edge['toId'] ?? 0 );
				if ( $attr_id <= 0 || isset( $seen[ $attr_id ] ) ) {
					continue;
				}
				$term = get_term( $attr_id, $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$seen[ $attr_id ] = true;

				$type_id   = Node_Type::get_type_id( $attr_id );
				$type_name = '';
				if ( $type_id > 0 ) {
					$type = get_term( $type_id, $taxonomy );
					if ( $type instanceof \WP_Term ) {
						$type_name = $type->name;
					}
				}

				$binding = self::normalize_binding(
					(string) ( $edge['typeKey'] ?? $edge['typeName'] ?? $binding_key )
				);

				$out[] = array(
					'id'            => $attr_id,
					'name'          => $term->name,
					'typeId'        => $type_id,
					'typeName'      => $type_name,
					'multiplicity'  => Relation::normalize_multiplicity( $edge['multiplicity'] ?? self::DEFAULT_MULTIPLICITY ),
					'binding'       => $binding,
					'edgeId'        => (string) ( $edge['id'] ?? '' ),
					'inherited'     => false,
					'definedOnId'   => $host_id,
					'definedOnName' => $host_name,
					'hidden'        => false,
				);
			}
		}

		return self::apply_order( $host_id, $out );
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private static function apply_order( int $host_id, array $rows ): array {
		$order = get_term_meta( $host_id, self::META_KEY_ORDER, true );
		if ( ! is_array( $order ) || empty( $order ) ) {
			return $rows;
		}
		$rank = array();
		$i    = 0;
		foreach ( $order as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! isset( $rank[ $id ] ) ) {
				$rank[ $id ] = $i;
				++$i;
			}
		}
		if ( empty( $rank ) ) {
			return $rows;
		}

		/*
		 * Reorder only own (non-inherited) rows among their existing slots so
		 * inherited attributes keep their merge positions.
		 */
		$own_slots = array();
		foreach ( $rows as $index => $row ) {
			if ( empty( $row['inherited'] ) ) {
				$own_slots[] = array(
					'index' => $index,
					'row'   => $row,
				);
			}
		}
		if ( empty( $own_slots ) ) {
			return $rows;
		}

		usort(
			$own_slots,
			static function ( array $a, array $b ) use ( $rank ): int {
				$ia = $rank[ (int) ( $a['row']['id'] ?? 0 ) ] ?? PHP_INT_MAX;
				$ib = $rank[ (int) ( $b['row']['id'] ?? 0 ) ] ?? PHP_INT_MAX;
				if ( $ia === $ib ) {
					return $a['index'] <=> $b['index'];
				}
				return $ia < $ib ? -1 : 1;
			}
		);

		$out      = $rows;
		$slot_i  = 0;
		foreach ( $rows as $index => $row ) {
			if ( empty( $row['inherited'] ) ) {
				$out[ $index ] = $own_slots[ $slot_i ]['row'];
				++$slot_i;
			}
		}

		return array_values( $out );
	}

	/**
	 * @param list<int|string> $ids
	 */
	private static function store_order_ids( int $host_id, array $ids ): void {
		$clean = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $clean, true ) ) {
				$clean[] = $id;
			}
		}
		if ( empty( $clean ) ) {
			delete_term_meta( $host_id, self::META_KEY_ORDER );
			return;
		}
		update_term_meta( $host_id, self::META_KEY_ORDER, $clean );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function decorate_row( array $row, string $taxonomy, int $host_id ): array {
		$name   = (string) ( $row['name'] ?? '' );
		$values = self::fixed_values_for_name( $host_id, $name );
		$row['fixedValues'] = $values;
		$row['allowsMany']  = self::multiplicity_allows_many( (string) ( $row['multiplicity'] ?? self::DEFAULT_MULTIPLICITY ) );
		$row['allowsEmpty'] = self::multiplicity_allows_empty( (string) ( $row['multiplicity'] ?? self::DEFAULT_MULTIPLICITY ) );

		$row['binding']      = self::normalize_binding( (string) ( $row['binding'] ?? self::DEFAULT_BINDING ) );
		$row['bindingLabel'] = self::binding_label( $row['binding'] );

		$type_id = (int) ( $row['typeId'] ?? 0 );
		$row['typeKey'] = '';
		if ( $type_id > 0 ) {
			/* Q96: prefer builtin.* binding; leaf name = debt fallback. */
			$row['typeKey'] = Node_Type::registry_id_for_type_term( $taxonomy, $type_id );
		}

		$row['fixedLabel'] = self::format_fixed_label( $taxonomy, $values, (string) $row['typeKey'] );

		/*
		 * Festwert / value presentation mode (generic — no name hardcoding):
		 * - literal: scalar / unit quantity → enter value(s)
		 * - structure: type itself has attributes → Form(1) / Table(n) of that schema
		 * - catalog: type has no attributes but hierarchy specializations → CatalogChoice
		 */
		$row['fixedMode']    = 'literal';
		$row['fixedOptions'] = array();
		$row['fixedRootId']  = 0;
		if ( $type_id > 0 ) {
			$is_unit = Node_Type::is_basiseinheit_unit_node( $taxonomy, $type_id );
			if ( Node_Type::is_concrete_enum_type( $taxonomy, $type_id ) ) {
				$row['fixedMode']    = 'catalog';
				$row['fixedRootId']  = $type_id;
				$row['fixedOptions'] = self::enum_options_as_fixed_options(
					Node_Type::get_enum_options( $taxonomy, $type_id )
				);
			} elseif ( ! $is_unit && ! self::is_scalar_type_key( (string) $row['typeKey'] ) ) {
				if ( self::type_has_attributes( $taxonomy, $type_id ) ) {
					$row['fixedMode'] = 'structure';
				} else {
					$row['fixedMode']    = 'catalog';
					$row['fixedRootId']  = $type_id;
					$row['fixedOptions'] = self::fixed_options_under_type( $taxonomy, $type_id );
				}
			}
		}

		/* Slot preferred: own meta = override; else inherit type preferred (editable in UI). */
		$row['typePreferredRender'] = $type_id > 0
			? Node_Type::get_preferred_render( $type_id )
			: 'form';
		$attr_id_for_pref = (int) ( $row['id'] ?? 0 );
		$has_pref_override = $attr_id_for_pref > 0
			&& metadata_exists( 'term', $attr_id_for_pref, Node_Type::META_KEY_PREFERRED_RENDER );
		$row['preferredRenderOverride'] = $has_pref_override;
		$row['preferredRender']         = $has_pref_override
			? Node_Type::get_preferred_render( $attr_id_for_pref )
			: (string) $row['typePreferredRender'];
		if (
			'embed' === $row['typePreferredRender']
			&& $type_id > 0
			&& empty( $row['fixedOptions'] )
			&& ! Node_Type::is_basiseinheit_unit_node( $taxonomy, $type_id )
			&& ! self::is_scalar_type_key( (string) $row['typeKey'] )
		) {
			$row['fixedRootId']  = $type_id;
			$row['fixedOptions'] = self::fixed_options_under_type( $taxonomy, $type_id );
		}

		$attr_id = (int) ( $row['id'] ?? 0 );
		$extras  = self::get_type_extras_for_attr( $host_id, $attr_id );
		if ( empty( $extras ) && ! empty( $row['inherited'] ) ) {
			$defined_on = (int) ( $row['definedOnId'] ?? 0 );
			if ( $defined_on > 0 && $defined_on !== $host_id ) {
				$extras = self::get_type_extras_for_attr( $defined_on, $attr_id );
			}
		}
		$row['typeExtras'] = $extras;

		/* Choice filter (include|exclude subtrees) against catalog fixedOptions. */
		if (
			(
				'catalog' === (string) ( $row['fixedMode'] ?? '' )
				|| 'embed' === (string) ( $row['typePreferredRender'] ?? '' )
			)
			&& isset( $extras['choiceFilter'] )
			&& is_array( $extras['choiceFilter'] )
			&& ! empty( $row['fixedOptions'] )
		) {
			$row['fixedOptions'] = self::apply_choice_filter(
				$taxonomy,
				$type_id,
				isset( $row['fixedOptions'] ) && is_array( $row['fixedOptions'] )
					? $row['fixedOptions']
					: array(),
				$extras['choiceFilter']
			);
		}

		$row['choiceDepth'] = self::choice_depth_from_options(
			isset( $row['fixedOptions'] ) && is_array( $row['fixedOptions'] )
				? $row['fixedOptions']
				: array()
		);

		if ( 'date' === (string) $row['typeKey'] ) {
			$type_mode = 'date';
			$cfg       = Node_Type::get_date_config_for_node( $taxonomy, $type_id > 0 ? $type_id : $attr_id );
			if ( is_array( $cfg ) && isset( $cfg['mode'] ) ) {
				$type_mode = Node_Type::normalize_date_mode( (string) $cfg['mode'] );
			}
			$mode = $type_mode;
			if ( isset( $extras['dateMode'] ) && is_string( $extras['dateMode'] ) && '' !== $extras['dateMode'] ) {
				$mode = Node_Type::normalize_date_mode( (string) $extras['dateMode'] );
			}
			$row['dateConfig'] = array(
				'mode'        => $mode,
				'typeMode'    => $type_mode,
				'hasOverride' => isset( $extras['dateMode'] ),
			);
		}

		if ( 'int' === (string) $row['typeKey'] || 'integer' === (string) $row['typeKey'] ) {
			$type_format = Int_Value::DEFAULT_FORMAT;
			$cfg         = Node_Type::get_int_config_for_node( $taxonomy, $type_id > 0 ? $type_id : $attr_id );
			if ( is_array( $cfg ) && isset( $cfg['displayFormat'] ) ) {
				$type_format = Int_Value::normalize_format_id( (string) $cfg['displayFormat'] );
			}
			if ( $type_id > 0 ) {
				$from_type = Node_Type::get_preferred_converter( $type_id );
				if ( '' !== $from_type ) {
					$type_format = Int_Value::normalize_format_id( $from_type );
				}
			}
			$format = $type_format;
			if ( isset( $extras['preferredConverter'] ) && is_string( $extras['preferredConverter'] ) && '' !== $extras['preferredConverter'] ) {
				$format = Int_Value::normalize_format_id( (string) $extras['preferredConverter'] );
			} elseif ( isset( $extras['displayFormat'] ) && is_string( $extras['displayFormat'] ) && '' !== $extras['displayFormat'] ) {
				$format = Int_Value::normalize_format_id( (string) $extras['displayFormat'] );
			}
			$has_conv_override =
				( isset( $extras['preferredConverter'] ) && '' !== (string) $extras['preferredConverter'] )
				|| ( isset( $extras['displayFormat'] ) && '' !== (string) $extras['displayFormat'] );
			$row['intConfig'] = array(
				'displayFormat' => $format,
				'typeFormat'    => $type_format,
				'hasOverride'   => $has_conv_override,
			);
			$row['displayFormat']       = $format;
			$row['preferredConverter']  = $format;
			$row['typePreferredConverter'] = $type_format;
		}

		$row['validators'] = $type_id > 0
			? Node_Type::get_validators_for_node( $taxonomy, $type_id )
			: array();

		/* Basiseinheit unit type → Typ/Praefix/Kuerzel schema for quantity paint. */
		$row['quantitySchema'] = null;
		if ( $type_id > 0 && Node_Type::is_basiseinheit_unit_node( $taxonomy, $type_id ) ) {
			$row['quantitySchema'] = Node_Type::get_quantity_schema_for_type( $taxonomy, $type_id );
		}

		if ( ! empty( $extras['compute'] ) && is_array( $extras['compute'] ) ) {
			$row['compute']  = $extras['compute'];
			$row['readonly'] = true;
			$row['computed'] = true;
		} else {
			$row['compute']  = null;
			$row['computed'] = false;
		}

		return $row;
	}

	/**
	 * CatalogChoice depth from fixedOptions paths (Q90).
	 * Shared by Attribute::list payloads and Object_Render view DTOs.
	 *
	 * @param list<array<string, mixed>> $options Fixed / catalog options.
	 */
	public static function choice_depth_from_options( array $options ): int {
		if ( array() === $options ) {
			return 0;
		}
		$paths = array();
		foreach ( $options as $opt ) {
			if ( ! is_array( $opt ) ) {
				continue;
			}
			$path  = (string) ( $opt['path'] ?? $opt['name'] ?? '' );
			$path  = trim( preg_replace( '/\s*\/\s*/', '/', $path ) ?? '' );
			$path  = trim( $path, '/' );
			$parts = '' !== $path
				? array_values( array_filter( explode( '/', $path ), static fn( $p ) => '' !== $p ) )
				: array( (string) ( $opt['name'] ?? $opt['id'] ?? '' ) );
			if ( array() !== $parts ) {
				$paths[] = $parts;
			}
		}
		if ( array() === $paths ) {
			return 0;
		}
		$common = $paths[0];
		foreach ( $paths as $parts ) {
			$n = 0;
			while (
				$n < count( $common )
				&& $n < count( $parts )
				&& $common[ $n ] === $parts[ $n ]
			) {
				++$n;
			}
			$common = array_slice( $common, 0, $n );
		}
		$max_rel = 0;
		foreach ( $paths as $parts ) {
			$rel   = max( 0, count( $parts ) - count( $common ) );
			$depth = max( 1, $rel );
			if ( $depth > $max_rel ) {
				$max_rel = $depth;
			}
		}
		return $max_rel;
	}

	/**
	 * Map enum option leaves to attribute Festwert catalog picker rows.
	 *
	 * @param list<array{id:int,name:string}> $options Enum options.
	 * @return list<array{id:int,name:string,path:string}>
	 */
	private static function enum_options_as_fixed_options( array $options ): array {
		$out = array();
		foreach ( $options as $opt ) {
			$id = (int) ( $opt['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$name  = (string) ( $opt['name'] ?? '' );
			$out[] = array(
				'id'   => $id,
				'name' => $name,
				'path' => $name,
			);
		}
		return $out;
	}

	/**
	 * Human label for Bindung (optional UI field).
	 */
	private static function binding_label( string $binding ): string {
		$binding = self::normalize_binding( $binding );
		if ( Relation::TYPE_AGGREGATION === $binding ) {
			return __( 'Aggregation', 'wp-taxonomy-tree' );
		}
		return __( 'Composition', 'wp-taxonomy-tree' );
	}

	/**
	 * Catalog Festwert candidates = descendants of the attribute type node.
	 *
	 * @return list<array{id:int,name:string,path:string,shortDescription?:string}>
	 */
	/**
	 * CatalogChoice / embed pick list: descendants under a type host (by id).
	 *
	 * @return list<array{id:int,name:string,path:string,shortDescription?:string}>
	 */
	public static function choice_options_under_type( string $taxonomy, int $type_id ): array {
		return self::fixed_options_under_type( $taxonomy, $type_id );
	}

	private static function fixed_options_under_type( string $taxonomy, int $type_id ): array {
		if ( $type_id <= 0 ) {
			return array();
		}
		$options = array();
		$seen    = array();
		self::collect_descendants_as_fixed_options( $taxonomy, $type_id, array(), $options, $seen );
		return $options;
	}

	/**
	 * @param list<string>                                                        $path_parts
	 * @param list<array{id:int,name:string,path:string,shortDescription?:string}> $options
	 * @param array<int, true>                                                    $seen
	 */
	private static function collect_descendants_as_fixed_options(
		string $taxonomy,
		int $parent_id,
		array $path_parts,
		array &$options,
		array &$seen
	): void {
		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $kids ) ) {
			return;
		}
		foreach ( $kids as $kid ) {
			if ( ! $kid instanceof \WP_Term ) {
				continue;
			}
			$id = (int) $kid->term_id;
			/* Attribute slots are never CatalogChoice leaves (child_of = inheritance only). */
			if ( self::is_slot( $id ) ) {
				continue;
			}
			$name = $kid->name;
			$next = array_merge( $path_parts, array( $name ) );
			if ( empty( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$options[]   = array(
					'id'               => $id,
					'name'             => $name,
					'path'             => implode( ' / ', $next ),
					'shortDescription' => Tree_Model::get_short_description( $id ),
				);
			}
			self::collect_descendants_as_fixed_options( $taxonomy, $id, $next, $options, $seen );
		}
	}

	/**
	 * @param list<string> $values
	 */
	/**
	 * Human label for stored Festwert / default values.
	 *
	 * Bool → true/false (i18n). Catalog picks → term names. Digits are only
	 * treated as term ids when > 0 (so bool "0" is never a term lookup).
	 *
	 * @param list<string|array<string,string>> $values Stored wire values / nested maps.
	 */
	private static function format_fixed_label( string $taxonomy, array $values, string $type_key = '' ): string {
		if ( empty( $values ) ) {
			return '';
		}
		$type_key = strtolower( trim( $type_key ) );
		$labels   = array();
		foreach ( $values as $v ) {
			if ( is_array( $v ) ) {
				/* Q106 nested default row — compact placeholder until Form/Table editor lands. */
				$labels[] = __( '(default row)', 'wp-taxonomy-tree' );
				continue;
			}
			$v = (string) $v;
			if ( '' === $v ) {
				continue;
			}
			if ( 'bool' === $type_key ) {
				$labels[] = self::is_truthy_bool( $v )
					? __( 'true', 'wp-taxonomy-tree' )
					: __( 'false', 'wp-taxonomy-tree' );
				continue;
			}
			if ( ctype_digit( $v ) ) {
				$term_id = (int) $v;
				if ( $term_id > 0 ) {
					$term = get_term( $term_id, $taxonomy );
					if ( $term instanceof \WP_Term ) {
						$labels[] = $term->name;
						continue;
					}
				}
			}
			$labels[] = $v;
		}
		return implode( ', ', $labels );
	}

	/**
	 * Wire bool truthiness (0/1, true/false, yes/no).
	 */
	private static function is_truthy_bool( string $value ): bool {
		$s = strtolower( trim( $value ) );
		return in_array( $s, array( '1', 'true', 'yes', 'on' ), true );
	}

	private static function is_scalar_type_key( string $key ): bool {
		return in_array(
			$key,
			array( 'int', 'double', 'text', 'email', 'textarea', 'char', 'bool', 'date', 'quantity', 'media', 'display_node_name' ),
			true
		);
	}

	/**
	 * @return list<string|array<string,string>>
	 */
	private static function fixed_values_for_name( int $host_id, string $name ): array {
		if ( '' === $name ) {
			return array();
		}
		$map = self::get_fixed_values_map( $host_id );
		if ( ! isset( $map[ $name ] ) ) {
			return array();
		}
		return self::normalize_fixed_values_input( $map[ $name ] );
	}

	/**
	 * @return array<string, string|list<string>>
	 */
	private static function get_fixed_values_map( int $host_id ): array {
		$raw = get_term_meta( $host_id, self::META_KEY_FIXED_VALUES, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $key => $val ) {
			$key = is_string( $key ) ? $key : (string) $key;
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ] = $val;
		}
		return $out;
	}

	/**
	 * @param array<string, string|list<string>> $map
	 */
	private static function store_fixed_values_map( int $host_id, array $map ): void {
		if ( empty( $map ) ) {
			delete_term_meta( $host_id, self::META_KEY_FIXED_VALUES );
			return;
		}
		update_term_meta( $host_id, self::META_KEY_FIXED_VALUES, $map );
	}

	private static function rename_fixed_values_key( int $host_id, string $old_name, string $new_name ): void {
		if ( '' === $old_name || $old_name === $new_name ) {
			return;
		}
		$map = self::get_fixed_values_map( $host_id );
		if ( ! isset( $map[ $old_name ] ) ) {
			return;
		}
		$map[ $new_name ] = $map[ $old_name ];
		unset( $map[ $old_name ] );
		self::store_fixed_values_map( $host_id, $map );
	}

	/**
	 * Normalize default templates (Q106): scalar strings and/or nested value maps.
	 *
	 * @param mixed $values
	 * @return list<string|array<string,string>>
	 */
	private static function normalize_fixed_values_input( $values ): array {
		if ( null === $values || false === $values ) {
			return array();
		}
		if ( is_string( $values ) || is_numeric( $values ) ) {
			$s = trim( (string) $values );
			return '' === $s ? array() : array( $s );
		}
		if ( ! is_array( $values ) ) {
			return array();
		}
		$out          = array();
		$seen_scalars = array();
		foreach ( $values as $v ) {
			if ( is_array( $v ) ) {
				$map = self::normalize_fixed_value_map( $v );
				if ( array() !== $map ) {
					$out[] = $map;
				}
				continue;
			}
			$s = trim( (string) $v );
			if ( '' === $s || isset( $seen_scalars[ $s ] ) ) {
				continue;
			}
			$seen_scalars[ $s ] = true;
			$out[]              = $s;
		}
		return array_values( $out );
	}

	/**
	 * One related-Mult default row: attr id → scalar string.
	 *
	 * @param array<mixed, mixed> $raw
	 * @return array<string, string>
	 */
	private static function normalize_fixed_value_map( array $raw ): array {
		$map = array();
		foreach ( $raw as $key => $val ) {
			if ( is_array( $val ) ) {
				continue;
			}
			$attr_key = is_int( $key ) || ( is_string( $key ) && ctype_digit( $key ) )
				? (string) absint( $key )
				: sanitize_key( (string) $key );
			if ( '' === $attr_key || '0' === $attr_key ) {
				continue;
			}
			$map[ $attr_key ] = sanitize_text_field( (string) $val );
		}
		return $map;
	}

	/**
	 * @param list<int|string> $ids
	 */
	private static function store_hidden_ids( int $host_id, array $ids ): void {
		$clean = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		if ( empty( $clean ) ) {
			delete_term_meta( $host_id, self::META_KEY_HIDDEN );
			return;
		}
		update_term_meta( $host_id, self::META_KEY_HIDDEN, $clean );
	}

	/**
	 * @return list<int>
	 */
	private static function ancestor_chain_root_to_self( string $taxonomy, int $host_id ): array {
		$term = get_term( $host_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return array();
		}

		$up    = array();
		$cur   = $host_id;
		$guard = 0;
		while ( $cur > 0 && $guard < 64 ) {
			++$guard;
			array_unshift( $up, $cur );
			$node = get_term( $cur, $taxonomy );
			if ( ! $node instanceof \WP_Term ) {
				break;
			}
			$cur = (int) $node->parent;
		}

		return $up;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function find_effective_row( string $taxonomy, int $host_id, int $attr_id ): ?array {
		foreach ( self::effective_list( $taxonomy, $host_id ) as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $attr_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function find_own_row( string $taxonomy, int $host_id, int $attr_id ) {
		foreach ( self::list_own( $taxonomy, $host_id ) as $row ) {
			if ( (int) $row['id'] === $attr_id ) {
				return $row;
			}
		}
		return new \WP_Error( 'wtt_attribute_missing', __( 'Attribute not found after update.', 'wp-taxonomy-tree' ) );
	}

	/**
	 * Whether $term_id is an own attribute member of $host_id (besteht_aus / aggregation).
	 */
	public static function is_own_member( string $taxonomy, int $host_id, int $term_id ): bool {
		return null !== self::find_edge( $taxonomy, $host_id, $term_id );
	}

	/**
	 * Whether this term is an attribute slot definition (not a hierarchy class node).
	 */
	public static function is_slot( int $term_id ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		return '1' === (string) get_term_meta( $term_id, self::META_KEY_SLOT, true );
	}

	/**
	 * Term ids still referenced as live attribute slots or prop-bound bands.
	 *
	 * Uses own besteht_aus/aggregation members (including nested hosts that are
	 * themselves slots, e.g. BOM Tabelle → Zeile → fields) + prop bindings.
	 * Raw edges to parent=0 duplicates that lost the name merge are still
	 * counted when they remain on a host’s own list — orphan cleanup dedupes
	 * separately.
	 *
	 * @return list<int>
	 */
	public static function collect_referenced_term_ids( string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

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

		$used = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$host_id = (int) $term->term_id;
			if ( $host_id <= 0 ) {
				continue;
			}

			/*
			 * Nested composition hosts (Zeile under Tabelle) are often marked
			 * as slots after Q87 detach — still walk their own members.
			 */
			foreach ( self::list_own_raw( $taxonomy, $host_id ) as $row ) {
				$aid = (int) ( $row['id'] ?? 0 );
				if ( $aid > 0 ) {
					$used[ $aid ] = true;
				}
			}

			foreach ( Node_Type::get_prop_bindings( $host_id ) as $child_id ) {
				$cid = (int) $child_id;
				if ( $cid > 0 ) {
					$used[ $cid ] = true;
				}
			}
		}

		/*
		 * Attribute-binding edges whose targets are still hierarchy children
		 * (parent > 0) remain live structure — keep them. parent=0 edge targets
		 * only count when already kept via list/props (effective winners).
		 */
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$host_id = (int) $term->term_id;
			foreach ( Relation::list_outgoing( $taxonomy, $host_id ) as $edge ) {
				$key = (string) ( $edge['typeKey'] ?? $edge['typeName'] ?? '' );
				if ( ! self::is_attribute_binding( $key ) ) {
					continue;
				}
				$to = (int) ( $edge['toId'] ?? 0 );
				if ( $to <= 0 || isset( $used[ $to ] ) ) {
					continue;
				}
				$target = get_term( $to, $taxonomy );
				if ( $target instanceof \WP_Term && (int) $target->parent > 0 ) {
					$used[ $to ] = true;
				}
			}
		}

		$ids = array_map( 'intval', array_keys( $used ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Mark term as attribute slot (hidden from hierarchy tree under hosts).
	 */
	public static function mark_as_slot( int $term_id ): void {
		if ( $term_id > 0 ) {
			update_term_meta( $term_id, self::META_KEY_SLOT, '1' );
		}
	}

	/**
	 * Clear WP parent so the slot is not a child_of of any host (parent → 0).
	 */
	public static function detach_from_hierarchy_parent( string $taxonomy, int $attr_id ): void {
		$term = get_term( $attr_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || (int) $term->parent <= 0 ) {
			return;
		}
		wp_update_term( $attr_id, $taxonomy, array( 'parent' => 0 ) );
		self::mark_as_slot( $attr_id );
	}

	/**
	 * Detach all besteht_aus / aggregation targets that are still WP children of their host.
	 * Attributes must not use hierarchy child_of — only Bindung edges.
	 *
	 * @return int Number of terms reparented to root (parent=0).
	 */
	public static function migrate_detach_hierarchy( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $terms ) ) {
			return 0;
		}

		$fixed = 0;
		foreach ( $terms as $host ) {
			if ( ! $host instanceof \WP_Term ) {
				continue;
			}
			$host_id = (int) $host->term_id;
			foreach ( self::list_own_raw( $taxonomy, $host_id ) as $row ) {
				$attr_id = (int) ( $row['id'] ?? 0 );
				if ( $attr_id <= 0 ) {
					continue;
				}
				self::mark_as_slot( $attr_id );
				$attr = get_term( $attr_id, $taxonomy );
				if ( ! $attr instanceof \WP_Term ) {
					continue;
				}
				if ( (int) $attr->parent === $host_id ) {
					$upd = wp_update_term( $attr_id, $taxonomy, array( 'parent' => 0 ) );
					if ( ! is_wp_error( $upd ) ) {
						++$fixed;
					}
				}
			}
		}

		return $fixed;
	}

	/**
	 * Drop besteht_aus / aggregation edges whose target term no longer exists.
	 *
	 * @return int Number of edges removed across all hosts.
	 */
	public static function prune_dangling_edges( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $terms ) ) {
			return 0;
		}

		$pruned = 0;
		foreach ( $terms as $host ) {
			if ( ! $host instanceof \WP_Term ) {
				continue;
			}
			$host_id = (int) $host->term_id;
			$raw     = get_term_meta( $host_id, Relation::META_KEY, true );
			if ( is_string( $raw ) && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				$raw     = is_array( $decoded ) ? $decoded : array();
			}
			if ( ! is_array( $raw ) || empty( $raw ) ) {
				continue;
			}
			$next = array();
			$host_pruned = 0;
			foreach ( $raw as $edge ) {
				if ( ! is_array( $edge ) ) {
					continue;
				}
				$to_id        = (int) ( $edge['toId'] ?? 0 );
				$key          = strtolower( (string) ( $edge['typeKey'] ?? '' ) );
				$is_attr_edge = self::is_attribute_binding( $key )
					|| Relation::type_keys_match( $key, Relation::TYPE_COMPOSITION )
					|| 'composition' === $key;
				if ( $is_attr_edge && $to_id > 0 ) {
					$to = get_term( $to_id, $taxonomy );
					if ( ! $to instanceof \WP_Term ) {
						++$host_pruned;
						continue;
					}
				}
				$next[] = $edge;
			}
			if ( $host_pruned > 0 ) {
				$pruned += $host_pruned;
				if ( empty( $next ) ) {
					delete_term_meta( $host_id, Relation::META_KEY );
				} else {
					update_term_meta( $host_id, Relation::META_KEY, wp_json_encode( array_values( $next ) ) );
				}
			}
		}

		return $pruned;
	}

	/**
	 * Duplicate an effective attribute onto this host as a new own attribute.
	 * Inherited rows become a local own copy (not an override of the same name).
	 *
	 * @return array<string, mixed>|\WP_Error Decorated own row.
	 */
	public static function duplicate( string $taxonomy, int $host_id, int $attr_id ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}

		$found = self::find_effective_row( $taxonomy, $host_id, $attr_id );
		if ( null === $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}

		$type_id = (int) ( $found['typeId'] ?? 0 );
		if ( $type_id <= 0 ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute type is required.', 'wp-taxonomy-tree' ) );
		}

		$base_name = (string) ( $found['name'] ?? '' );
		if ( '' === $base_name ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute name is required.', 'wp-taxonomy-tree' ) );
		}

		$new_name = self::unique_copy_name( $taxonomy, $host_id, $base_name );
		$created  = self::add(
			$taxonomy,
			$host_id,
			$new_name,
			$type_id,
			(string) ( $found['multiplicity'] ?? self::DEFAULT_MULTIPLICITY ),
			(string) ( $found['binding'] ?? self::DEFAULT_BINDING )
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$new_id = (int) ( $created['id'] ?? 0 );
		if ( $new_id <= 0 ) {
			return new \WP_Error( 'wtt_create_failed', __( 'Could not create attribute node.', 'wp-taxonomy-tree' ) );
		}

		/* Copy type extras from source attribute id (own host map or ancestor). */
		$source_extras = self::resolve_type_extras_for_attr( $taxonomy, $host_id, $attr_id, $found );
		if ( ! empty( $source_extras ) ) {
			$set = self::set_type_extras( $taxonomy, $host_id, $new_id, $source_extras );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
		}

		/* Copy fixed/default values under the new name when present on this host for the source name. */
		$fixed = self::fixed_values_for_name( $host_id, $base_name );
		if ( ! empty( $fixed ) ) {
			$map              = self::get_fixed_values_map( $host_id );
			$map[ $new_name ] = count( $fixed ) > 1 ? $fixed : $fixed[0];
			self::store_fixed_values_map( $host_id, $map );
		}

		Tree_Model::touch_modified( $host_id );
		$row = self::find_own_row( $taxonomy, $host_id, $new_id );
		return is_array( $row ) ? $row : $created;
	}

	/**
	 * Set / replace type extras for one attribute on a host.
	 *
	 * @param array<string, mixed>|null $extras Null clears.
	 * @return true|\WP_Error
	 */
	public static function set_type_extras( string $taxonomy, int $host_id, int $attr_id, $extras ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}
		$found = self::find_effective_row( $taxonomy, $host_id, $attr_id );
		if ( null === $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}

		$map        = self::get_type_extras_map( $host_id );
		$key        = (string) $attr_id;
		$normalized = array();
		if ( null === $extras || array() === $extras ) {
			unset( $map[ $key ] );
		} else {
			$normalized = self::normalize_type_extras( $extras );
			if ( array() === $normalized ) {
				unset( $map[ $key ] );
			} else {
				$map[ $key ] = $normalized;
			}
		}
		self::store_type_extras_map( $host_id, $map );

		/* Computed ⇒ force readonly on this host. */
		if ( isset( $normalized['compute'] ) && is_array( $normalized['compute'] ) ) {
			self::set_readonly( $taxonomy, $host_id, $attr_id, true );
		}

		Tree_Model::touch_modified( $host_id );
		return true;
	}

	/**
	 * Flat-list compute: collect numeric contributions then apply Footer_Ops.
	 *
	 * @param list<array<string, mixed>> $attributes Effective attribute rows.
	 * @param array<string, mixed>       $values     Attr id → scalar|list|nested values map.
	 * @return string|null Display string or null when nothing to compute.
	 */
	public static function evaluate_compute( array $attr_row, array $attributes, array $values ): ?string {
		$extras  = isset( $attr_row['typeExtras'] ) && is_array( $attr_row['typeExtras'] )
			? $attr_row['typeExtras']
			: array();
		$compute = isset( $extras['compute'] ) && is_array( $extras['compute'] )
			? $extras['compute']
			: ( isset( $attr_row['compute'] ) && is_array( $attr_row['compute'] ) ? $attr_row['compute'] : null );
		if ( null === $compute ) {
			return null;
		}
		$op = isset( $compute['op'] ) ? strtolower( sanitize_key( (string) $compute['op'] ) ) : '';
		if ( '' === $op || ! isset( Footer_Ops::catalog()[ $op ] ) ) {
			return null;
		}
		$sources = isset( $compute['sources'] ) && is_array( $compute['sources'] ) ? $compute['sources'] : array();
		$by_id   = array();
		foreach ( $attributes as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id > 0 ) {
				$by_id[ $id ] = $row;
			}
		}

		$flat = array();
		foreach ( $sources as $src ) {
			if ( ! is_array( $src ) ) {
				continue;
			}
			$kind = isset( $src['kind'] ) ? sanitize_key( (string) $src['kind'] ) : 'attr';
			$aid  = isset( $src['attrId'] ) ? (int) $src['attrId'] : 0;
			if ( $aid <= 0 ) {
				continue;
			}
			$raw = $values[ (string) $aid ] ?? $values[ $aid ] ?? null;
			if ( 'attrPath' === $kind ) {
				$path_id = isset( $src['pathAttrId'] ) ? (int) $src['pathAttrId'] : 0;
				if ( $path_id <= 0 ) {
					continue;
				}
				$items = is_array( $raw ) ? $raw : ( null === $raw || '' === $raw ? array() : array( $raw ) );
				foreach ( $items as $item ) {
					$n = self::extract_numeric_from_path_item( $item, $path_id );
					if ( null !== $n ) {
						$flat[] = $n;
					}
				}
				continue;
			}
			foreach ( self::flatten_numeric_values( $raw ) as $n ) {
				$flat[] = $n;
			}
		}

		return Footer_Ops::evaluate( $op, $flat );
	}

	/**
	 * @param mixed $raw Scalar or list.
	 * @return list<float>
	 */
	private static function flatten_numeric_values( $raw ): array {
		if ( null === $raw || '' === $raw || false === $raw ) {
			return array();
		}
		$items = is_array( $raw ) ? $raw : array( $raw );
		$out   = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				continue;
			}
			if ( is_numeric( $item ) ) {
				$out[] = (float) $item;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $item Linked object values map or scalar.
	 */
	private static function extract_numeric_from_path_item( $item, int $path_attr_id ): ?float {
		if ( ! is_array( $item ) ) {
			return null;
		}
		$raw = $item[ (string) $path_attr_id ] ?? $item[ $path_attr_id ] ?? null;
		if ( is_array( $raw ) ) {
			$raw = $raw[0] ?? null;
		}
		if ( null === $raw || '' === $raw || ! is_numeric( $raw ) ) {
			return null;
		}
		return (float) $raw;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_type_extras_for_attr( int $host_id, int $attr_id ): array {
		if ( $attr_id <= 0 ) {
			return array();
		}
		$map = self::get_type_extras_map( $host_id );
		$key = (string) $attr_id;
		if ( ! isset( $map[ $key ] ) || ! is_array( $map[ $key ] ) ) {
			return array();
		}
		return self::normalize_type_extras( $map[ $key ] );
	}

	/**
	 * Prefer host map; for inherited attrs also try definedOnId map.
	 *
	 * @param array<string, mixed> $found Effective row.
	 * @return array<string, mixed>
	 */
	private static function resolve_type_extras_for_attr(
		string $taxonomy,
		int $host_id,
		int $attr_id,
		array $found
	): array {
		$local = self::get_type_extras_for_attr( $host_id, $attr_id );
		if ( ! empty( $local ) ) {
			return $local;
		}
		$defined_on = (int) ( $found['definedOnId'] ?? 0 );
		if ( $defined_on > 0 && $defined_on !== $host_id ) {
			return self::get_type_extras_for_attr( $defined_on, $attr_id );
		}
		unset( $taxonomy );
		return array();
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_type_extras_map( int $host_id ): array {
		$raw = get_term_meta( $host_id, self::META_KEY_TYPE_EXTRAS, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $key => $val ) {
			$key = (string) $key;
			if ( '' === $key || ! is_array( $val ) ) {
				continue;
			}
			$out[ $key ] = $val;
		}
		return $out;
	}

	/**
	 * @param array<string, array<string, mixed>> $map Map.
	 */
	private static function store_type_extras_map( int $host_id, array $map ): void {
		if ( empty( $map ) ) {
			delete_term_meta( $host_id, self::META_KEY_TYPE_EXTRAS );
			return;
		}
		update_term_meta( $host_id, self::META_KEY_TYPE_EXTRAS, $map );
	}

	/**
	 * @param array<string, mixed> $extras Raw extras.
	 * @return array<string, mixed>
	 */
	public static function normalize_type_extras( array $extras ): array {
		$out = array();

		if ( array_key_exists( 'dateMode', $extras ) ) {
			$mode = (string) $extras['dateMode'];
			if ( '' !== $mode ) {
				$out['dateMode'] = Node_Type::normalize_date_mode( $mode );
			}
		}

		if ( array_key_exists( 'preferredConverter', $extras ) ) {
			$fmt = trim( (string) $extras['preferredConverter'] );
			if ( '' !== $fmt ) {
				$out['preferredConverter'] = Node_Type::normalize_preferred_converter( $fmt );
				$out['displayFormat']      = Int_Value::normalize_format_id( $out['preferredConverter'] );
			}
		} elseif ( array_key_exists( 'displayFormat', $extras ) ) {
			$fmt = trim( (string) $extras['displayFormat'] );
			if ( '' !== $fmt ) {
				$out['displayFormat']      = Int_Value::normalize_format_id( $fmt );
				$out['preferredConverter'] = $out['displayFormat'];
			}
		}

		if ( isset( $extras['choiceFilter'] ) && is_array( $extras['choiceFilter'] ) ) {
			$cf = self::normalize_choice_filter( $extras['choiceFilter'] );
			if ( ! empty( $cf['ids'] ) ) {
				$out['choiceFilter'] = $cf;
			}
		}

		if ( isset( $extras['compute'] ) && is_array( $extras['compute'] ) ) {
			$compute = self::normalize_compute( $extras['compute'] );
			if ( null !== $compute ) {
				$out['compute'] = $compute;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $filter Filter.
	 * @return array{mode:string,ids:list<int>}
	 */
	public static function normalize_choice_filter( array $filter ): array {
		$mode = isset( $filter['mode'] ) ? strtolower( sanitize_key( (string) $filter['mode'] ) ) : 'include';
		if ( 'exclude' !== $mode ) {
			$mode = 'include';
		}
		$ids = array();
		$raw = isset( $filter['ids'] ) && is_array( $filter['ids'] ) ? $filter['ids'] : array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return array(
			'mode' => $mode,
			'ids'  => array_values( array_unique( $ids ) ),
		);
	}

	/**
	 * @param array<string, mixed> $compute Compute config.
	 * @return array{op:string,sources:list<array<string,mixed>>}|null
	 */
	public static function normalize_compute( array $compute ): ?array {
		$op = isset( $compute['op'] ) ? strtolower( sanitize_key( (string) $compute['op'] ) ) : '';
		$allowed = array(
			Footer_Ops::SUM,
			Footer_Ops::AVG,
			Footer_Ops::MIN,
			Footer_Ops::MAX,
			Footer_Ops::COUNT,
		);
		if ( ! in_array( $op, $allowed, true ) ) {
			return null;
		}
		$sources = array();
		$raw     = isset( $compute['sources'] ) && is_array( $compute['sources'] ) ? $compute['sources'] : array();
		foreach ( $raw as $src ) {
			if ( ! is_array( $src ) ) {
				continue;
			}
			$kind = isset( $src['kind'] ) ? sanitize_key( (string) $src['kind'] ) : 'attr';
			$aid  = isset( $src['attrId'] ) ? (int) $src['attrId'] : 0;
			if ( $aid <= 0 ) {
				continue;
			}
			if ( 'attrPath' === $kind ) {
				$path = isset( $src['pathAttrId'] ) ? (int) $src['pathAttrId'] : 0;
				if ( $path <= 0 ) {
					continue;
				}
				$sources[] = array(
					'kind'       => 'attrPath',
					'attrId'     => $aid,
					'pathAttrId' => $path,
				);
				continue;
			}
			$sources[] = array(
				'kind'   => 'attr',
				'attrId' => $aid,
			);
		}
		if ( empty( $sources ) ) {
			return null;
		}
		return array(
			'op'      => $op,
			'sources' => $sources,
		);
	}

	/**
	 * Filter catalog options by include|exclude subtree roots (descendants included).
	 *
	 * @param list<array<string, mixed>> $options Options.
	 * @param array{mode?:string,ids?:list<int>} $filter Filter.
	 * @return list<array<string, mixed>>
	 */
	public static function apply_choice_filter(
		string $taxonomy,
		int $scope_id,
		array $options,
		array $filter
	): array {
		$filter = self::normalize_choice_filter( $filter );
		$ids    = $filter['ids'];
		if ( empty( $ids ) ) {
			return $options;
		}
		$mode   = $filter['mode'];
		$out    = array();
		foreach ( $options as $opt ) {
			if ( ! is_array( $opt ) ) {
				continue;
			}
			$oid = (int) ( $opt['id'] ?? 0 );
			if ( $oid <= 0 ) {
				continue;
			}
			$under = Node_Type::is_ref_candidate_under_allowlist( $taxonomy, $oid, $scope_id, $ids );
			if ( 'exclude' === $mode ) {
				if ( ! $under ) {
					$out[] = $opt;
				}
			} elseif ( $under ) {
				$out[] = $opt;
			}
		}
		return $out;
	}

	/**
	 * Unique copy name on host (own attributes).
	 */
	private static function unique_copy_name( string $taxonomy, int $host_id, string $base ): string {
		/* translators: %s: original attribute name */
		$suffix = __( ' (copy)', 'wp-taxonomy-tree' );
		$taken  = array();
		foreach ( self::list_own_raw( $taxonomy, $host_id ) as $row ) {
			$n = (string) ( $row['name'] ?? '' );
			if ( '' !== $n ) {
				$taken[ $n ] = true;
			}
		}
		$candidate = $base . $suffix;
		if ( ! isset( $taken[ $candidate ] ) ) {
			return $candidate;
		}
		$n = 2;
		while ( $n < 100 ) {
			$candidate = $base . $suffix . ' ' . (string) $n;
			if ( ! isset( $taken[ $candidate ] ) ) {
				return $candidate;
			}
			++$n;
		}
		return $base . $suffix . ' ' . wp_generate_password( 4, false );
	}

	/**
	 * Find attribute edge under either Bindung (besteht_aus or aggregation).
	 *
	 * @return array{id:string,typeId:int,typeName:string,typeKey:string,toId:int,toName:string,multiplicity:string,index:int}|null
	 */
	private static function find_edge( string $taxonomy, int $host_id, int $attr_id ): ?array {
		foreach ( self::BINDINGS as $binding_key ) {
			foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $host_id, $binding_key ) as $edge ) {
				if ( (int) ( $edge['toId'] ?? 0 ) === $attr_id ) {
					return $edge;
				}
			}
		}
		return null;
	}
}
