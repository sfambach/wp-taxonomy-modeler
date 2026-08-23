<?php
/**
 * Node attributes (Q87 / Q123): Name + Type + Mult. via besteht_aus / aggregation.
 * Product: Attribute = Relation only (name + target type) — no slot terms.
 * Inherit along host child_of (Q86). Scaffold slots = migrate debt (Attribute_Q123_Migrate).
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

	/** Default Bindung: aggregation (Model rule — composition only for list Position). */
	public const DEFAULT_BINDING = 'aggregation';

	/** Allowed attribute Bindung keys (RelationType slugs). */
	public const BINDINGS = array( 'besteht_aus', 'aggregation' );

	/**
	 * Host term meta: inherited attribute edge ids covered up on this node (OQ-W5 / Q105).
	 *
	 * Storage key kept for back-compat. **Inherited overrides only** — own-attr
	 * Background-only lives on Relation `edge.hidden` (≈ 0.0.431; no own read fallback).
	 */
	public const META_KEY_HIDDEN = '_wtt_hidden_attributes';

	/** Term meta on host: ordered list of own attribute edge ids (display order). */
	public const META_KEY_ORDER = '_wtt_attribute_order';

	/**
	 * Host term meta: edge id → Settings-walk summary snapshot (compute-on-write).
	 *
	 * Built when an attribute is attached / type target changes (and on Options
	 * first open if missing). get_node decorate reads this instead of deep live
	 * recursion. Invalidate / rebuild when:
	 * - attribute type/target changes
	 * - Walk-Wizard deltas change (settings.nested / depth-0 keys that affect summary)
	 * - type composition structure under the target changes (structureFp mismatch)
	 * - attribute removed (key dropped)
	 * Type-tree edits under a shared catalog type may leave other hosts stale until
	 * their Options open or an explicit rebuild (structureFp check).
	 *
	 * @var string
	 */
	public const META_KEY_WALK_CACHE = '_wtt_attribute_walk_cache';

	/** Walk cache payload version. */
	/** Bumped when summary shape changes (v5: walk includes child_of-inherited attrs). */
	public const WALK_CACHE_VERSION = 5;

	/**
	 * Host term meta: map attribute **name** → string|list of default values.
	 * (Internal key kept as fixed_values for compatibility.)
	 *
	 * **Inherited Default overrides only** (by name). Own-attr SoT ≈ 0.0.425/0.0.431
	 * → Relation edge field `default` (OQ-W4 / Q106); no own host-map read fallback.
	 */
	public const META_KEY_FIXED_VALUES = '_wtt_attribute_fixed_values';

	/**
	 * Host term meta: inherited attribute edge ids marked readonly on this node (OQ-A3).
	 *
	 * **Inherited RO overrides only.** Own-attr RO lives on Relation `edge.readOnly`
	 * (≈ 0.0.431; no own host-map read fallback).
	 */
	public const META_KEY_READONLY = '_wtt_attribute_readonly';

	/**
	 * Preferred Mult when applying the Q105 fix `set_mult_01`.
	 * Background-only (Hide) is allowed for any single-valued Mult — see
	 * {@see multiplicity_allows_background_only()}.
	 */
	public const BACKGROUND_ONLY_MULTIPLICITY = '0..1';

	/**
	 * Q105: Hide / Background-only is allowed when Mult is a single value
	 * (`0..1` or `1`). Many (`0..*` / `1..*`) stay blocked — collections are not BO.
	 * Calc / constant fields (e.g. Präfix multiplikator) use Mult `1` + Hide.
	 */
	public static function multiplicity_allows_background_only( string $mult ): bool {
		$m = Relation::normalize_multiplicity( $mult );
		return in_array( $m, array( '0..1', '1' ), true );
	}

	/** Guard: shallow typeProperties only (avoid recursive Attribute::list). */
	private static bool $type_props_loading = false;

	/**
	 * When true, decorate_row skips deep Settings_Walk summary (Options lazy-load).
	 * Still resolves depth-0 Preferred + settingsResolved for table/preview paint.
	 */
	private static bool $slim_walk_summary = true;

	/**
	 * Request-scoped Attribute::effective_list memo.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private static array $effective_list_cache = array();

	/**
	 * Request-scoped type_has_attributes memo.
	 *
	 * @var array<string, bool>
	 */
	private static array $type_has_attrs_cache = array();

	/** Drop request memos after mutations (see Tree_Model::touch_modified). */
	public static function bust_request_caches(): void {
		self::$effective_list_cache = array();
		self::$type_has_attrs_cache = array();
		if ( class_exists( Settings_Walk::class ) ) {
			Settings_Walk::bust_request_caches();
		}
		if ( class_exists( Relation::class ) && method_exists( Relation::class, 'bust_request_caches' ) ) {
			Relation::bust_request_caches();
		}
	}

	/**
	 * Toggle slim walk summary for the current request (tests / full decorate).
	 */
	public static function set_slim_walk_summary( bool $slim ): void {
		self::$slim_walk_summary = $slim;
	}

	/**
	 * Legacy marker on slot terms (pre-Q123). Prefer Relation-only; migrate removes these.
	 */
	public const META_KEY_SLOT = '_wtt_attribute_slot';

	/**
	 * Q90 parked table-band leftover names (Zeile/Kopf/Fuss + English aliases).
	 * Untyped slot leftovers on catalog `table` hosts — not product attributes.
	 *
	 * @var list<string>
	 */
	public const PARKED_TABLE_BAND_NAMES = array( 'Zeile', 'Kopf', 'Fuss', 'Head', 'Line', 'Foot' );

	/**
	 * Normalize attribute identity to Relation edge id (Q123). Accepts legacy numeric slot ids.
	 *
	 * @param mixed $attr_id Edge id string or legacy slot term id.
	 */
	public static function normalize_attr_id( $attr_id ): string {
		if ( is_int( $attr_id ) ) {
			return $attr_id > 0 ? (string) $attr_id : '';
		}
		$raw = trim( (string) $attr_id );
		if ( '' === $raw ) {
			return '';
		}
		if ( ctype_digit( $raw ) ) {
			$n = (int) $raw;
			return $n > 0 ? (string) $n : '';
		}
		return sanitize_key( $raw );
	}

	/**
	 * Host term meta: map attribute edge id → type extras
	 * (dateMode, choiceFilter, compute, validators, preferredConverter).
	 *
	 * **Inherited typeExtras overrides only** (+ migrate fold source historically).
	 * Own attrs → Relation `Settings.data`/`view` only (edge-only read ≈ 0.0.431).
	 */
	public const META_KEY_TYPE_EXTRAS = '_wtt_attribute_type_extras';

	/**
	 * Host term meta: inherited attr edge id → Settings deltas (view/data/nested).
	 *
	 * Q66 / OQ-W5 heir overrides for Preferred + Walk-Wizard. Father’s Relation
	 * edge stays untouched; decorate merges host map over defining-edge settings.
	 */
	public const META_KEY_SETTINGS_OVERRIDES = '_wtt_attribute_settings_overrides';

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
		if ( class_exists( Attribute_Q123_Migrate::class ) ) {
			Attribute_Q123_Migrate::maybe_migrate( $taxonomy );
		}
		return self::effective_list( $taxonomy, $host_id );
	}

	/**
	 * Attributes defined on this host only (no inheritance).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function list_own( string $taxonomy, int $host_id ): array {
		if ( class_exists( Attribute_Q123_Migrate::class ) ) {
			Attribute_Q123_Migrate::maybe_migrate( $taxonomy );
		}
		$out = array();
		foreach ( self::list_own_raw( $taxonomy, $host_id ) as $row ) {
			$out[] = self::decorate_row( $row, $taxonomy, $host_id );
		}
		return $out;
	}

	/**
	 * Move an attribute up (delta -1) or down (delta +1) in this host's display order.
	 * Host-local: works for own and inherited attrs (heir display order; father unchanged).
	 *
	 * @param int|string $attr_id
	 * @return true|\WP_Error
	 */
	public static function reorder( string $taxonomy, int $host_id, $attr_id, int $delta ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}
		$attr_id = self::normalize_attr_id( $attr_id );
		if ( '' === $attr_id || 0 === $delta ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Invalid attribute move.', 'wp-taxonomy-tree' ) );
		}

		/* Bust cache so we read current display order. */
		$cache_key = $taxonomy . ':' . $host_id;
		unset( self::$effective_list_cache[ $cache_key ] );

		$ids = array();
		foreach ( self::effective_list( $taxonomy, $host_id ) as $row ) {
			$id = self::normalize_attr_id( $row['id'] ?? '' );
			if ( '' !== $id ) {
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
		unset( self::$effective_list_cache[ $cache_key ] );

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
		$cache_key = $taxonomy . ':' . $host_id;
		if ( isset( self::$effective_list_cache[ $cache_key ] ) ) {
			return self::$effective_list_cache[ $cache_key ];
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
				/* Keep edge.readOnly / edge.hidden from list_own_raw (OQ-W4). */
				$row['hidden']               = ! empty( $row['hidden'] );
				$row['readonly']             = ! empty( $row['readonly'] );
				$row['shadowsInherited']     = false;
				$row['shadowsDefinedOnId']   = 0;
				$row['shadowsDefinedOnName'] = '';
				$row['shadowedAttrId']       = '';

				$name_key = (string) ( $row['name'] ?? '' );
				/*
				 * Own row with the same name as an ancestor definition hides the
				 * inherited slot (child wins). Flag shadowing so the UI can warn.
				 */
				if (
					$is_self
					&& '' !== $name_key
					&& isset( $by_name[ $name_key ] )
					&& ! empty( $by_name[ $name_key ]['inherited'] )
				) {
					$prev = $by_name[ $name_key ];
					$row['shadowsInherited']     = true;
					$row['shadowsDefinedOnId']   = (int) ( $prev['definedOnId'] ?? 0 );
					$row['shadowsDefinedOnName'] = (string) ( $prev['definedOnName'] ?? '' );
					/* Q123: shadowed id is Relation edge UUID (or legacy slot int string). */
					$row['shadowedAttrId']       = self::normalize_attr_id( $prev['id'] ?? '' );
				}

				$by_name[ $name_key ] = $row;
			}
		}

		$out = array();
		foreach ( $by_name as $row ) {
			$attr_id   = self::normalize_attr_id( $row['id'] ?? '' );
			$inherited = ! empty( $row['inherited'] );
			/*
			 * Hide / RO: own attrs = Relation edge only (≈ 0.0.431).
			 * Host maps apply only as inherited cover-up / heir overrides.
			 */
			if ( $inherited && '' !== $attr_id && isset( $hidden_ids[ $attr_id ] ) ) {
				$row['hidden'] = true;
			}
			if ( $inherited && '' !== $attr_id && isset( $readonly_ids[ $attr_id ] ) ) {
				$row['readonly'] = true;
			} elseif (
				'' !== $attr_id
				&& ! $inherited
				&& empty( $row['readonly'] )
				&& ! empty( $row['legacySlotId'] )
				&& Node_Type::is_fixed_enabled( (int) $row['legacySlotId'] )
			) {
				/*
				 * Legacy only: own slot with Fixed-as-lock → edge RO.
				 * Do not call set_readonly here (would re-enter effective_list).
				 */
				Node_Type::maybe_migrate_fixed_lock_to_readonly( (int) $row['legacySlotId'] );
				Relation::update_read_only( $taxonomy, $host_id, $attr_id, true );
				self::clear_readonly_host_key( $host_id, $attr_id );
				$row['readonly'] = true;
			}
			$out[] = self::decorate_row( $row, $taxonomy, $host_id );
		}

		$ordered = self::apply_order( $host_id, $out );
		self::$effective_list_cache[ $cache_key ] = $ordered;
		return $ordered;
	}

	/**
	 * Hide or show an attribute on $host_id.
	 *
	 * Own edge → Relation.hidden (Q105 BO; Mult must be single-valued when enabling).
	 * Inherited → host `_wtt_hidden_attributes` cover-up (does not mutate father edge).
	 *
	 * @return true|\WP_Error
	 */
	public static function set_hidden( string $taxonomy, int $host_id, $attr_id, bool $hidden ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}
		$attr_id = self::normalize_attr_id( $attr_id );
		if ( '' === $attr_id ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute not found.', 'wp-taxonomy-tree' ) );
		}

		$found = self::find_effective_row( $taxonomy, $host_id, $attr_id );
		if ( null === $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}

		$own_edge = self::find_edge_by_id( $taxonomy, $host_id, $attr_id );
		if ( null !== $own_edge ) {
			if ( $hidden ) {
				$mult = Relation::normalize_multiplicity(
					(string) ( $own_edge['multiplicity'] ?? self::DEFAULT_MULTIPLICITY )
				);
				if ( ! self::multiplicity_allows_background_only( $mult ) ) {
					return new \WP_Error(
						'wtt_bo_mult',
						__( 'Background-only (Hide) requires multiplicity 0..1 or 1.', 'wp-taxonomy-tree' )
					);
				}
			}
			$result = Relation::update_hidden( $taxonomy, $host_id, $attr_id, $hidden );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			self::clear_hidden_host_key( $host_id, $attr_id );
			self::ensure_settings_walk_cache( $taxonomy, $host_id, $attr_id, true );
			self::refresh_settings_walk_caches_for_type_node( $taxonomy, $host_id, true );
			Tree_Model::touch_modified( $host_id );
			return true;
		}

		if ( empty( $found['inherited'] ) ) {
			return new \WP_Error(
				'wtt_bad_attribute',
				__( 'Attribute edge not found on this node.', 'wp-taxonomy-tree' )
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
	 *
	 * Own Relation edge → edge.readOnly (OQ-W4); clears host map key.
	 * Inherited → host `_wtt_attribute_readonly` override only (OQ-A3; does not
	 * mutate father’s edge). Legacy slot RO mirror when still present.
	 *
	 * @return true|\WP_Error
	 */
	public static function set_readonly( string $taxonomy, int $host_id, $attr_id, bool $readonly ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}
		$attr_id = self::normalize_attr_id( $attr_id );
		if ( '' === $attr_id ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute not found.', 'wp-taxonomy-tree' ) );
		}

		$found = self::find_effective_row( $taxonomy, $host_id, $attr_id );
		if ( null === $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}

		$own_edge = self::find_edge_by_id( $taxonomy, $host_id, $attr_id );
		if ( null !== $own_edge ) {
			$result = Relation::update_read_only( $taxonomy, $host_id, $attr_id, $readonly );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			self::clear_readonly_host_key( $host_id, $attr_id );
			self::ensure_settings_walk_cache( $taxonomy, $host_id, $attr_id, true );
			self::refresh_settings_walk_caches_for_type_node( $taxonomy, $host_id, true );
		} else {
			$ids = self::get_readonly_ids( $host_id );
			if ( $readonly ) {
				$ids[ $attr_id ] = true;
			} else {
				unset( $ids[ $attr_id ] );
			}
			self::store_readonly_ids( $host_id, array_keys( $ids ) );
		}

		/* Legacy slot RO mirror when still present. */
		$legacy = (int) ( $found['legacySlotId'] ?? 0 );
		if ( $legacy > 0 && self::is_slot( $legacy ) ) {
			if ( $readonly ) {
				Node_Type::set_readonly( $taxonomy, $legacy, true );
			} elseif ( ! self::slot_readonly_held_elsewhere( $taxonomy, $host_id, $attr_id, $legacy ) ) {
				Node_Type::set_readonly( $taxonomy, $legacy, false );
			}
		}

		Tree_Model::touch_modified( $host_id );

		return true;
	}

	/**
	 * Whether another host on the inheritance chain still marks this attribute RO
	 * (OQ-A3: do not clear slot RO when an ancestor/owner still holds it).
	 *
	 * @param string $attr_id Edge id (or legacy slot id string).
	 * @param int    $legacy_slot Slot term id for edge lookup on ancestors.
	 */
	private static function slot_readonly_held_elsewhere(
		string $taxonomy,
		int $host_id,
		string $attr_id,
		int $legacy_slot = 0
	): bool {
		$chain = self::ancestor_chain_root_to_self( $taxonomy, $host_id );
		foreach ( $chain as $node_id ) {
			$node_id = (int) $node_id;
			if ( $node_id === $host_id ) {
				continue;
			}
			$ids = self::get_readonly_ids( $node_id );
			if ( isset( $ids[ $attr_id ] ) ) {
				return true;
			}
			$edge = self::find_edge_by_id( $taxonomy, $node_id, $attr_id );
			if ( null === $edge && $legacy_slot > 0 ) {
				foreach ( Relation::list_outgoing( $taxonomy, $node_id ) as $candidate ) {
					if ( (int) ( $candidate['toId'] ?? 0 ) === $legacy_slot ) {
						$edge = $candidate;
						break;
					}
				}
			}
			if ( null !== $edge && ( ! empty( $edge['readOnly'] ) || ! empty( $edge['readonly'] ) ) ) {
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
	 * Set default value template(s) for an effective attribute (Q106 / OQ-W4).
	 *
	 * Always a list sized by Mult: `0`/`1` → at most one entry; `0..*`/`1..*` → many.
	 * Scalars store strings; related Mult may store nested value maps (default rows).
	 *
	 * Own Relation edge → `edge.default` (clears host name-map key).
	 * Inherited → host `_wtt_attribute_fixed_values` by name (does not mutate father edge).
	 *
	 * @param list<string|array<string,string>>|string|null $values Null / empty clears.
	 * @return true|\WP_Error
	 */
	public static function set_fixed_values( string $taxonomy, int $host_id, $attr_id, $values ) {
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

		$own_edge = self::find_edge_by_id( $taxonomy, $host_id, $attr_id );
		if ( null === $own_edge ) {
			$own_edge = self::find_edge( $taxonomy, $host_id, $attr_id );
		}
		if ( null !== $own_edge ) {
			$edge_id = self::normalize_attr_id( $own_edge['id'] ?? '' );
			if ( '' === $edge_id ) {
				return new \WP_Error( 'wtt_bad_relation', __( 'Missing relation id.', 'wp-taxonomy-tree' ) );
			}
			$result = Relation::update_default( $taxonomy, $host_id, $edge_id, $normalized );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			self::clear_fixed_values_host_key( $host_id, $name );
			self::ensure_settings_walk_cache( $taxonomy, $host_id, $edge_id, true );
			self::refresh_settings_walk_caches_for_type_node( $taxonomy, $host_id, true );
			Tree_Model::touch_modified( $host_id );
			return true;
		}

		if ( empty( $found['inherited'] ) ) {
			return new \WP_Error(
				'wtt_bad_attribute',
				__( 'Attribute edge not found on this node.', 'wp-taxonomy-tree' )
			);
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
	 * Normalize Q106 default templates (public for Relation edge hydrate / write).
	 *
	 * @param mixed $values
	 * @return list<string|array<string,string>>
	 */
	public static function normalize_default_seed( $values ): array {
		return self::normalize_fixed_values_input( $values );
	}

	/**
	 * Drop a host name-map default key (after fold / own-edge write).
	 */
	public static function clear_fixed_values_host_key( int $host_id, string $name ): void {
		$name = Relation::normalize_edge_name( $name );
		if ( '' === $name || $host_id <= 0 ) {
			return;
		}
		$map = self::get_fixed_values_map( $host_id );
		if ( ! isset( $map[ $name ] ) ) {
			return;
		}
		unset( $map[ $name ] );
		self::store_fixed_values_map( $host_id, $map );
	}

	/**
	 * Inherited Default override map (name → value|list). Same storage as META_KEY_FIXED_VALUES.
	 * Prefer this name in new code — not own-attr SoT.
	 *
	 * @return array<string, string|list<string|array<string,string>>>
	 */
	public static function get_inherited_fixed_values_map( int $host_id ): array {
		return self::get_fixed_values_map( $host_id );
	}

	/**
	 * Host `_wtt_attribute_fixed_values` map (name → value|list).
	 * Public for migrate fold / smoke. Alias: {@see get_inherited_fixed_values_map()}.
	 *
	 * @return array<string, string|list<string|array<string,string>>>
	 */
	public static function get_fixed_values_host_map( int $host_id ): array {
		return self::get_fixed_values_map( $host_id );
	}

	/**
	 * Inherited typeExtras override map (edge id → bag). Same storage as META_KEY_TYPE_EXTRAS.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_inherited_type_extras_map( int $host_id ): array {
		return self::get_type_extras_map( $host_id );
	}

	/**
	 * Keep host display order when an inherited attr is overridden by a new own edge.
	 *
	 * `add()` appends the new edge id; without retarget that jumps the row to the end
	 * (or to the front when order was empty and only the new id remains ranked).
	 *
	 * @param string $old_id Inherited (father) edge id that was visible before override.
	 * @param string $new_id Newly created own edge id.
	 */
	private static function retarget_order_after_override( int $host_id, string $old_id, string $new_id ): void {
		$old_id = self::normalize_attr_id( $old_id );
		$new_id = self::normalize_attr_id( $new_id );
		if ( $host_id <= 0 || '' === $new_id ) {
			return;
		}
		if ( $old_id === $new_id ) {
			return;
		}

		$order = get_term_meta( $host_id, self::META_KEY_ORDER, true );
		if ( ! is_array( $order ) ) {
			$order = array();
		}

		$next      = array();
		$replaced  = false;
		$saw_other = false;
		foreach ( $order as $id ) {
			$key = self::normalize_attr_id( $id );
			if ( '' === $key ) {
				continue;
			}
			if ( $key === $new_id ) {
				/* Drop auto-append from add(); re-insert only via replace. */
				continue;
			}
			if ( $key === $old_id ) {
				$next[]    = $new_id;
				$replaced  = true;
				$saw_other = true;
				continue;
			}
			$next[]    = $key;
			$saw_other = true;
		}

		if ( $replaced ) {
			self::store_order_ids( $host_id, $next );
			self::bust_request_caches();
			return;
		}

		/*
		 * No old id in order (empty or stale). If the only ranked entry would be the
		 * new override, clear order so apply_order stays a no-op (stable merge order).
		 */
		if ( ! $saw_other ) {
			delete_term_meta( $host_id, self::META_KEY_ORDER );
			self::bust_request_caches();
			return;
		}

		/* Partial order without old id — keep peers, do not force new id to an end slot. */
		self::store_order_ids( $host_id, $next );
		self::bust_request_caches();
	}

	/**
	 * Change Bindung: own edge updates RelationType; inherited creates local override.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function set_binding( string $taxonomy, int $host_id, $attr_id, string $binding ) {
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

		$old_id = self::normalize_attr_id( $found['id'] ?? $attr_id );
		$result = self::add(
			$taxonomy,
			$host_id,
			(string) $found['name'],
			$type_id,
			(string) ( $found['multiplicity'] ?? self::DEFAULT_MULTIPLICITY ),
			$binding
		);
		if ( ! is_wp_error( $result ) ) {
			self::retarget_order_after_override(
				$host_id,
				$old_id,
				self::normalize_attr_id( $result['id'] ?? '' )
			);
		}
		return $result;
	}

	/**
	 * Change type: own attr updates type_id; inherited creates local override (same name).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function set_type( string $taxonomy, int $host_id, $attr_id, int $type_id ) {
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
		$old_id = self::normalize_attr_id( $found['id'] ?? $attr_id );
		$result = self::add(
			$taxonomy,
			$host_id,
			(string) $found['name'],
			$type_id,
			(string) ( $found['multiplicity'] ?? self::DEFAULT_MULTIPLICITY ),
			(string) ( $found['binding'] ?? self::DEFAULT_BINDING )
		);
		if ( ! is_wp_error( $result ) ) {
			self::retarget_order_after_override(
				$host_id,
				$old_id,
				self::normalize_attr_id( $result['id'] ?? '' )
			);
		}
		return $result;
	}

	/**
	 * Change multiplicity: own edge updates; inherited creates local override (same name + type).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function set_multiplicity( string $taxonomy, int $host_id, $attr_id, string $multiplicity ) {
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
				self::trim_fixed_values_to_multiplicity(
					$taxonomy,
					$host_id,
					self::normalize_attr_id( $result['id'] ?? $attr_id ),
					(string) ( $result['name'] ?? '' ),
					$multiplicity
				);
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

		$old_id = self::normalize_attr_id( $found['id'] ?? $attr_id );
		$result = self::add(
			$taxonomy,
			$host_id,
			(string) $found['name'],
			$type_id,
			$multiplicity,
			(string) ( $found['binding'] ?? self::DEFAULT_BINDING )
		);
		if ( ! is_wp_error( $result ) ) {
			self::retarget_order_after_override(
				$host_id,
				$old_id,
				self::normalize_attr_id( $result['id'] ?? '' )
			);
			self::trim_fixed_values_to_multiplicity(
				$taxonomy,
				$host_id,
				self::normalize_attr_id( $result['id'] ?? '' ),
				(string) $found['name'],
				$multiplicity
			);
		}
		return $result;
	}

	/**
	 * Drop excess Festwert entries when multiplicity no longer allows many.
	 * Own edge → trim `edge.default`; else host name-map (inherited override).
	 */
	private static function trim_fixed_values_to_multiplicity(
		string $taxonomy,
		int $host_id,
		string $attr_id,
		string $name,
		string $multiplicity
	): void {
		if ( self::multiplicity_allows_many( $multiplicity ) ) {
			return;
		}
		$own_edge = '' !== $attr_id ? self::find_edge_by_id( $taxonomy, $host_id, $attr_id ) : null;
		if ( null !== $own_edge ) {
			$values = self::default_seed_from_edge( $own_edge );
			if ( count( $values ) <= 1 ) {
				return;
			}
			Relation::update_default(
				$taxonomy,
				$host_id,
				self::normalize_attr_id( $own_edge['id'] ?? $attr_id ),
				array_slice( $values, 0, 1 )
			);
			return;
		}
		if ( '' === $name ) {
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
	 * Inherited Hide cover-up map on this host (edge id → true).
	 * Alias of {@see get_hidden_ids()} — prefer this name in new code.
	 *
	 * @return array<string, true>
	 */
	public static function get_inherited_hidden_ids( int $host_id ): array {
		return self::get_hidden_ids( $host_id );
	}

	/**
	 * Inherited Hide cover-up map (META_KEY_HIDDEN). Not own-attr SoT.
	 *
	 * @return array<string, true>
	 */
	public static function get_hidden_ids( int $host_id ): array {
		$raw = get_term_meta( $host_id, self::META_KEY_HIDDEN, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $id ) {
			$key = self::normalize_attr_id( $id );
			if ( '' !== $key ) {
				$out[ $key ] = true;
			}
		}
		return $out;
	}

	/**
	 * Inherited RO override map on this host (edge id → true).
	 * Alias of {@see get_readonly_ids()} — prefer this name in new code.
	 *
	 * @return array<string, true>
	 */
	public static function get_inherited_readonly_ids( int $host_id ): array {
		return self::get_readonly_ids( $host_id );
	}

	/**
	 * Inherited RO override map (META_KEY_READONLY). Not own-attr SoT.
	 *
	 * @return array<string, true>
	 */
	public static function get_readonly_ids( int $host_id ): array {
		$raw = get_term_meta( $host_id, self::META_KEY_READONLY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $id ) {
			$key = self::normalize_attr_id( $id );
			if ( '' !== $key ) {
				$out[ $key ] = true;
			}
		}
		return $out;
	}

	/**
	 * @param list<int|string> $ids
	 */
	private static function store_readonly_ids( int $host_id, array $ids ): void {
		$clean = array();
		$seen  = array();
		foreach ( $ids as $id ) {
			$key = self::normalize_attr_id( $id );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$clean[]      = $key;
		}
		if ( empty( $clean ) ) {
			delete_term_meta( $host_id, self::META_KEY_READONLY );
			return;
		}
		update_term_meta( $host_id, self::META_KEY_READONLY, $clean );
	}

	/**
	 * Drop one attr id from the host readonly map (after folding onto own edge).
	 */
	public static function clear_readonly_host_key( int $host_id, string $attr_id ): void {
		$attr_id = self::normalize_attr_id( $attr_id );
		if ( '' === $attr_id ) {
			return;
		}
		$ids = self::get_readonly_ids( $host_id );
		if ( ! isset( $ids[ $attr_id ] ) ) {
			return;
		}
		unset( $ids[ $attr_id ] );
		self::store_readonly_ids( $host_id, array_keys( $ids ) );
	}

	/**
	 * Drop one attr id from the host hidden map (after folding onto own edge).
	 */
	public static function clear_hidden_host_key( int $host_id, string $attr_id ): void {
		$attr_id = self::normalize_attr_id( $attr_id );
		if ( '' === $attr_id ) {
			return;
		}
		$ids = self::get_hidden_ids( $host_id );
		if ( ! isset( $ids[ $attr_id ] ) ) {
			return;
		}
		unset( $ids[ $attr_id ] );
		self::store_hidden_ids( $host_id, array_keys( $ids ) );
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
	 * Create attribute: named besteht_aus / aggregation Relation → type target (Q123).
	 * No slot term. Optional `$readonly` / `$fixed_values` applied on the host after create.
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

		$name = Relation::normalize_edge_name( $name );
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

		$edge_id = Relation::add(
			$taxonomy,
			$host_id,
			$binding_type,
			$type_id,
			$multiplicity,
			$name
		);
		if ( is_wp_error( $edge_id ) ) {
			return $edge_id;
		}
		$edge_id = self::normalize_attr_id( $edge_id );
		if ( '' === $edge_id ) {
			return new \WP_Error( 'wtt_create_failed', __( 'Could not create attribute relation.', 'wp-taxonomy-tree' ) );
		}

		/* Append to host display order. */
		$order   = get_term_meta( $host_id, self::META_KEY_ORDER, true );
		$order   = is_array( $order ) ? $order : array();
		$order[] = $edge_id;
		self::store_order_ids( $host_id, $order );

		Node_Type::promote_class_datatype( $taxonomy, $host_id );
		Tree_Model::touch_modified( $host_id );

		if ( $readonly ) {
			$ro = self::set_readonly( $taxonomy, $host_id, $edge_id, true );
			if ( is_wp_error( $ro ) ) {
				return $ro;
			}
		}

		if ( null !== $fixed_values ) {
			$normalized = self::normalize_fixed_values_input( $fixed_values );
			if ( ! empty( $normalized ) ) {
				$fv = self::set_fixed_values( $taxonomy, $host_id, $edge_id, $normalized );
				if ( is_wp_error( $fv ) ) {
					return $fv;
				}
			}
		}

		/* Compute-on-write: Settings walk summary for Options / get_node cache hit. */
		self::ensure_settings_walk_cache( $taxonomy, $host_id, $edge_id, true );
		/* Host may be used as a type elsewhere → refresh consumer walks. */
		self::refresh_settings_walk_caches_for_type_node( $taxonomy, $host_id, true );

		return self::find_own_row( $taxonomy, $host_id, $edge_id );
	}

	/**
	 * @param int|string                                           $attr_id Relation edge id (Q123).
	 * @param array{name?:string,typeId?:int,multiplicity?:string} $changes
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update( string $taxonomy, int $host_id, $attr_id, array $changes ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}

		$edge = self::find_edge( $taxonomy, $host_id, $attr_id );
		if ( null === $edge ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}
		$edge_id  = self::normalize_attr_id( $edge['id'] ?? '' );
		$old_name = Relation::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
		if ( '' === $old_name ) {
			$to = get_term( (int) ( $edge['toId'] ?? 0 ), $taxonomy );
			$old_name = $to instanceof \WP_Term ? $to->name : '';
		}

		if ( array_key_exists( 'name', $changes ) ) {
			$new_name = Relation::normalize_edge_name( (string) $changes['name'] );
			if ( '' === $new_name ) {
				return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute name is required.', 'wp-taxonomy-tree' ) );
			}
			if ( $new_name !== $old_name ) {
				foreach ( self::list_own_raw( $taxonomy, $host_id ) as $row ) {
					if (
						self::normalize_attr_id( $row['id'] ?? '' ) !== $edge_id
						&& (string) ( $row['name'] ?? '' ) === $new_name
					) {
						return new \WP_Error(
							'wtt_name_conflict',
							__( 'An attribute with this name already exists on this node.', 'wp-taxonomy-tree' )
						);
					}
				}
				$renamed = Relation::update_name( $taxonomy, $host_id, $edge_id, $new_name );
				if ( is_wp_error( $renamed ) ) {
					return $renamed;
				}
				self::rename_fixed_values_key( $host_id, $old_name, $new_name );
				/* Legacy slot rename when still present. */
				$legacy = (int) ( $edge['toId'] ?? 0 );
				if ( $legacy > 0 && self::is_slot( $legacy ) ) {
					wp_update_term( $legacy, $taxonomy, array( 'name' => $new_name ) );
				}
			}
		}

		if ( array_key_exists( 'typeId', $changes ) ) {
			$type_id = (int) $changes['typeId'];
			if ( $type_id <= 0 ) {
				return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute type is required.', 'wp-taxonomy-tree' ) );
			}
			$type = get_term( $type_id, $taxonomy );
			if ( ! $type instanceof \WP_Term ) {
				return new \WP_Error( 'wtt_bad_type', __( 'Data type not found.', 'wp-taxonomy-tree' ) );
			}
			$to_id = (int) ( $edge['toId'] ?? 0 );
			if ( self::is_slot( $to_id ) ) {
				$typed = Node_Type::set_type_id( $taxonomy, $to_id, $type_id );
				if ( is_wp_error( $typed ) ) {
					return $typed;
				}
				delete_term_meta( $to_id, Node_Type::META_KEY_PREFERRED_RENDER );
			} else {
				$retarget = Relation::update_to( $taxonomy, $host_id, $type_id, $edge_id );
				if ( is_wp_error( $retarget ) ) {
					return $retarget;
				}
			}
			/* Type/target change → rebuild walk snapshot. */
			self::clear_settings_walk_cache( $host_id, $edge_id );
			self::ensure_settings_walk_cache( $taxonomy, $host_id, $edge_id, true );
		}

		if ( array_key_exists( 'multiplicity', $changes ) ) {
			$mult = Relation::normalize_multiplicity( (string) $changes['multiplicity'] );
			if ( '' === $edge_id ) {
				return new \WP_Error( 'wtt_bad_relation', __( 'Missing relation id.', 'wp-taxonomy-tree' ) );
			}
			/* Q105: BO (edge.hidden) ⇒ single-valued Mult only (0..1 or 1). */
			if (
				! empty( $edge['hidden'] )
				&& ! self::multiplicity_allows_background_only( $mult )
			) {
				return new \WP_Error(
					'wtt_bo_mult',
					__( 'Background-only (Hide) attributes must keep multiplicity 0..1 or 1. Clear Hide first.', 'wp-taxonomy-tree' )
				);
			}
			$updated = Relation::update_multiplicity( $taxonomy, $host_id, $edge_id, $mult );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		Tree_Model::touch_modified( $host_id );
		/* Type/structure of this host may have changed for consumers. */
		self::refresh_settings_walk_caches_for_type_node( $taxonomy, $host_id, true );
		return self::find_own_row( $taxonomy, $host_id, $edge_id );
	}

	/**
	 * @param int|string $attr_id Relation edge id (Q123).
	 * @return true|\WP_Error
	 */
	public static function remove( string $taxonomy, int $host_id, $attr_id ) {
		$edge = self::find_edge( $taxonomy, $host_id, $attr_id );
		if ( null === $edge ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}

		$edge_id   = self::normalize_attr_id( $edge['id'] ?? '' );
		$to_id     = (int) ( $edge['toId'] ?? 0 );
		$name      = Relation::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
		if ( '' === $name ) {
			$to   = get_term( $to_id, $taxonomy );
			$name = $to instanceof \WP_Term ? $to->name : '';
		}

		/*
		 * If this own edge shadowed an inherited attr, keep display position:
		 * retarget order own → father edge (drop alone would leave the heir unranked → bottom).
		 */
		$restore_id = '';
		$eff        = self::find_effective_row( $taxonomy, $host_id, $attr_id );
		if ( is_array( $eff ) && ! empty( $eff['shadowsInherited'] ) ) {
			$restore_id = self::normalize_attr_id( $eff['shadowedAttrId'] ?? '' );
		}
		if ( '' === $restore_id && '' !== $name ) {
			$restore_id = self::inherited_edge_id_by_name( $taxonomy, $host_id, $name );
		}

		$edge_type_id = (int) ( $edge['typeId'] ?? 0 );
		$removed      = Relation::remove( $taxonomy, $host_id, $edge_type_id, $to_id, $edge_id );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}

		/* Legacy slot cleanup only — never delete catalog type targets. */
		if ( $to_id > 0 && self::is_slot( $to_id ) ) {
			$still_used = false;
			foreach ( Relation::list_incoming( $taxonomy, $to_id ) as $incoming ) {
				$key = strtolower( (string) ( $incoming['typeKey'] ?? $incoming['typeName'] ?? '' ) );
				if ( self::is_attribute_binding( $key ) ) {
					$still_used = true;
					break;
				}
			}
			if ( ! $still_used ) {
				Node_Type::set_deletable( $to_id, true );
				wp_delete_term( $to_id, $taxonomy );
			}
		}

		if ( '' !== $name ) {
			$map = self::get_fixed_values_map( $host_id );
			if ( isset( $map[ $name ] ) ) {
				unset( $map[ $name ] );
				self::store_fixed_values_map( $host_id, $map );
			}
		}

		if ( '' !== $restore_id ) {
			/*
			 * Order only — never Relation::update_* on the ancestor edge.
			 * Deleting a child override must not rewrite father Mult/Binding/type.
			 */
			self::retarget_order_after_override( $host_id, $edge_id, $restore_id );
		}
		self::drop_host_edge_keys( $host_id, $edge_id );

		Tree_Model::touch_modified( $host_id );
		self::refresh_settings_walk_caches_for_type_node( $taxonomy, $host_id, true );
		return true;
	}

	/**
	 * Closest ancestor own-edge id for an attribute name (root → parent; nearer wins).
	 */
	private static function inherited_edge_id_by_name( string $taxonomy, int $host_id, string $name ): string {
		$name = Relation::normalize_edge_name( $name );
		if ( '' === $name || $host_id <= 0 ) {
			return '';
		}
		$found = '';
		foreach ( self::ancestor_chain_root_to_self( $taxonomy, $host_id ) as $node_id ) {
			if ( (int) $node_id === $host_id ) {
				continue;
			}
			foreach ( self::list_own_raw( $taxonomy, (int) $node_id ) as $row ) {
				if ( (string) ( $row['name'] ?? '' ) === $name ) {
					$found = self::normalize_attr_id( $row['id'] ?? '' );
				}
			}
		}
		return $found;
	}

	/**
	 * Move an own attribute Relation from one host to another (Q123).
	 * Edge SoT fields (settings, readOnly, hidden, default) transfer with the edge.
	 * Host-scoped inherited override maps stay on $from_id (not transferred).
	 * No slot terms created — toId stays the type (or leftover legacy slot target).
	 *
	 * @param int|string $attr_id Relation edge id.
	 * @return array<string, mixed>|\WP_Error Decorated own row on $to_id.
	 */
	public static function move_to_node( string $taxonomy, $attr_id, int $from_id, int $to_id ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$attr_id = self::normalize_attr_id( $attr_id );
		if ( $from_id <= 0 || $to_id <= 0 || '' === $attr_id ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Invalid attribute move.', 'wp-taxonomy-tree' ) );
		}
		if ( $from_id === $to_id ) {
			return new \WP_Error(
				'wtt_bad_target',
				__( 'Source and target node must differ.', 'wp-taxonomy-tree' )
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

		$attr_name = Relation::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
		$to_type   = (int) ( $edge['toId'] ?? 0 );
		if ( '' === $attr_name ) {
			$term = get_term( $to_type, $taxonomy );
			$attr_name = $term instanceof \WP_Term ? $term->name : '';
		}
		foreach ( self::list_own_raw( $taxonomy, $to_id ) as $row ) {
			if ( (string) ( $row['name'] ?? '' ) === $attr_name ) {
				return new \WP_Error(
					'wtt_name_conflict',
					__( 'The target already has an attribute with this name.', 'wp-taxonomy-tree' )
				);
			}
		}

		$edge_type_id = (int) ( $edge['typeId'] ?? 0 );
		$edge_id      = self::normalize_attr_id( $edge['id'] ?? '' );
		$multiplicity = Relation::normalize_multiplicity(
			(string) ( $edge['multiplicity'] ?? self::DEFAULT_MULTIPLICITY )
		);
		if ( '' === $multiplicity ) {
			$multiplicity = self::DEFAULT_MULTIPLICITY;
		}
		if ( $edge_type_id <= 0 || $to_type <= 0 || '' === $edge_id ) {
			return new \WP_Error( 'wtt_bad_relation', __( 'Missing relation type.', 'wp-taxonomy-tree' ) );
		}

		$settings    = isset( $edge['settings'] ) && is_array( $edge['settings'] ) ? $edge['settings'] : null;
		$edge_fields = self::edge_fields_from_edge( $edge );

		$removed = Relation::remove( $taxonomy, $from_id, $edge_type_id, $to_type, $edge_id );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}

		$new_id = Relation::add(
			$taxonomy,
			$to_id,
			$edge_type_id,
			$to_type,
			$multiplicity,
			$attr_name,
			$settings,
			$edge_fields
		);
		if ( is_wp_error( $new_id ) ) {
			Relation::add(
				$taxonomy,
				$from_id,
				$edge_type_id,
				$to_type,
				$multiplicity,
				$attr_name,
				$settings,
				$edge_fields
			);
			return $new_id;
		}
		$new_id = self::normalize_attr_id( $new_id );
		if ( '' === $new_id ) {
			return new \WP_Error( 'wtt_create_failed', __( 'Could not create attribute relation.', 'wp-taxonomy-tree' ) );
		}

		/* Drop stale order / leftover host-map keys for the old edge id on source. */
		self::drop_host_edge_keys( $from_id, $edge_id );
		/* Append new edge to target display order. */
		$order   = get_term_meta( $to_id, self::META_KEY_ORDER, true );
		$order   = is_array( $order ) ? $order : array();
		$order[] = $new_id;
		self::store_order_ids( $to_id, $order );

		Node_Type::promote_class_datatype( $taxonomy, $to_id );
		Tree_Model::touch_modified( $from_id );
		Tree_Model::touch_modified( $to_id );

		self::ensure_settings_walk_cache( $taxonomy, $to_id, $new_id, true );
		self::refresh_settings_walk_caches_for_type_node( $taxonomy, $from_id, true );
		self::refresh_settings_walk_caches_for_type_node( $taxonomy, $to_id, true );

		return self::find_own_row( $taxonomy, $to_id, $new_id );
	}

	/**
	 * OQ-W4 edge fields to re-apply on Relation::add (move / rollback).
	 *
	 * @param array<string, mixed> $edge
	 * @return array<string, mixed>
	 */
	private static function edge_fields_from_edge( array $edge ): array {
		$fields = array();
		if ( ! empty( $edge['readOnly'] ) || ! empty( $edge['readonly'] ) ) {
			$fields['readOnly'] = true;
		}
		if ( ! empty( $edge['hidden'] ) ) {
			$fields['hidden'] = true;
		}
		$default = null;
		if ( array_key_exists( 'default', $edge ) ) {
			$default = $edge['default'];
		} elseif ( array_key_exists( 'defaultSeed', $edge ) ) {
			$default = $edge['defaultSeed'];
		}
		if ( null !== $default ) {
			$normalized = self::normalize_default_seed( $default );
			if ( ! empty( $normalized ) ) {
				$fields['default'] = $normalized;
			}
		}
		return $fields;
	}

	/**
	 * Drop host order + leftover host-map keys for an edge id (after remove / move).
	 */
	private static function drop_host_edge_keys( int $host_id, string $edge_id ): void {
		$edge_id = self::normalize_attr_id( $edge_id );
		if ( $host_id <= 0 || '' === $edge_id ) {
			return;
		}
		$order = get_term_meta( $host_id, self::META_KEY_ORDER, true );
		if ( is_array( $order ) ) {
			$order = array_values(
				array_filter(
					$order,
					static fn( $id ) => self::normalize_attr_id( $id ) !== $edge_id
				)
			);
			self::store_order_ids( $host_id, $order );
		}
		$hidden = self::get_hidden_ids( $host_id );
		if ( isset( $hidden[ $edge_id ] ) ) {
			unset( $hidden[ $edge_id ] );
			self::store_hidden_ids( $host_id, array_keys( $hidden ) );
		}
		$ro = self::get_readonly_ids( $host_id );
		if ( isset( $ro[ $edge_id ] ) ) {
			unset( $ro[ $edge_id ] );
			self::store_readonly_ids( $host_id, array_keys( $ro ) );
		}
		$extras = self::get_type_extras_map( $host_id );
		if ( isset( $extras[ $edge_id ] ) ) {
			unset( $extras[ $edge_id ] );
			self::store_type_extras_map( $host_id, $extras );
		}
		$settings_ov = self::get_settings_overrides_map( $host_id );
		if ( isset( $settings_ov[ $edge_id ] ) ) {
			unset( $settings_ov[ $edge_id ] );
			self::store_settings_overrides_map( $host_id, $settings_ov );
		}
		self::clear_settings_walk_cache( $host_id, $edge_id );
	}

	/**
	 * Move an own attribute to the host's WP hierarchy parent (Q86/Q87).
	 *
	 * @return array<string, mixed>|\WP_Error Decorated own row on the parent.
	 */
	public static function move_to_parent( string $taxonomy, int $host_id, $attr_id ) {
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
	public static function move_to_child( string $taxonomy, int $host_id, $attr_id, int $child_id ) {
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
		$cache_key = $taxonomy . ':has:' . $type_id;
		if ( array_key_exists( $cache_key, self::$type_has_attrs_cache ) ) {
			return self::$type_has_attrs_cache[ $cache_key ];
		}
		$chain  = self::ancestor_chain_root_to_self( $taxonomy, $type_id );
		$has    = false;
		foreach ( $chain as $node_id ) {
			if ( array() !== self::list_own_raw( $taxonomy, $node_id ) ) {
				$has = true;
				break;
			}
		}
		self::$type_has_attrs_cache[ $cache_key ] = $has;
		return $has;
	}

	/**
	 * Effective composition/aggregation edges for Settings_Walk (Q88 inheritance).
	 * Merges own + ancestor child_of attributes (closer name wins) — no decorate_row,
	 * so safe from walk recursion (unlike Attribute::list).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function effective_edges_for_settings_walk( string $taxonomy, int $type_id ): array {
		if ( $type_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$chain = self::ancestor_chain_root_to_self( $taxonomy, $type_id );
		if ( empty( $chain ) ) {
			return array();
		}
		/** @var array<string, array<string, mixed>> $by_name */
		$by_name = array();
		foreach ( $chain as $node_id ) {
			foreach ( self::list_own_raw( $taxonomy, $node_id ) as $row ) {
				$name_key = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
				if ( '' === $name_key ) {
					continue;
				}
				/* Root → self: later (closer) overwrites — child wins. */
				$by_name[ $name_key ] = $row;
			}
		}
		return array_values( $by_name );
	}

	/**
	 * Whether a type with both attributes and specialization children should paint
	 * as Structure (Unit type / size / quantity) rather than CatalogChoice (Bauformen).
	 */
	public static function prefers_structure_over_catalog( string $taxonomy, int $type_id ): bool {
		if ( $type_id <= 0 || ! self::type_has_attributes( $taxonomy, $type_id ) ) {
			return false;
		}
		$pref = Node_Type::normalize_preferred_render( Node_Type::get_preferred_render( $type_id ) );
		/* ChildList = CatalogChoice of children — never Structure embed (With prefix). */
		if ( Renderer::ChildList->value === $pref ) {
			return false;
		}
		if (
			in_array(
				$pref,
				array(
					Renderer::Quantity->value,
					Renderer::Unit->value,
					'quantity',
					'unit',
				),
				true
			)
		) {
			return true;
		}
		$term = get_term( $type_id, $taxonomy );
		if ( ! ( $term instanceof \WP_Term ) ) {
			return false;
		}
		return in_array(
			$term->name,
			array(
				'Unit type',
				'Unit Type',
				'UnitType',
				'size',
				'quantity',
				'Größe',
				'Groesse',
				'Preis',
				'Kontakt',
			),
			true
		);
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
				$edge_id = self::normalize_attr_id( $edge['id'] ?? '' );
				$to_id   = (int) ( $edge['toId'] ?? 0 );
				if ( '' === $edge_id || $to_id <= 0 || isset( $seen[ $edge_id ] ) ) {
					continue;
				}
				$to = get_term( $to_id, $taxonomy );
				if ( ! $to instanceof \WP_Term ) {
					continue;
				}
				$seen[ $edge_id ] = true;

				/*
				 * Q123: toId = type node; name on edge.
				 * Legacy: toId = slot term with _wtt_type_id (until migrate).
				 */
				$legacy_slot = self::is_slot( $to_id );
				if ( $legacy_slot ) {
					$type_id = Node_Type::get_type_id( $to_id );
					/*
					 * Q90 parked table bands (Zeile/Kopf/Fuss): untyped slot leftovers.
					 * Keep edges for legacy table chrome; hide from Attribute UI.
					 */
					if ( $type_id <= 0 || self::is_parked_table_band_term( $taxonomy, $to_id ) ) {
						continue;
					}
					$type_name = '';
					$type      = get_term( $type_id, $taxonomy );
					if ( $type instanceof \WP_Term ) {
						$type_name = $type->name;
					}
					$name = $to->name;
				} else {
					$type_id   = $to_id;
					$type_name = $to->name;
					$name      = Relation::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
					if ( '' === $name ) {
						$name = $to->name;
					}
				}

				$binding = self::normalize_binding(
					(string) ( $edge['typeKey'] ?? $edge['typeName'] ?? $binding_key )
				);

				$row = array(
					'id'            => $edge_id,
					'name'          => $name,
					'typeId'        => $type_id,
					'typeName'      => $type_name,
					'multiplicity'  => Relation::normalize_multiplicity( $edge['multiplicity'] ?? self::DEFAULT_MULTIPLICITY ),
					'binding'       => $binding,
					'edgeId'        => $edge_id,
					'inherited'     => false,
					'definedOnId'   => $host_id,
					'definedOnName' => $host_name,
					'hidden'        => ! empty( $edge['hidden'] ),
					'readonly'      => ! empty( $edge['readOnly'] ) || ! empty( $edge['readonly'] ),
					'settings'      => isset( $edge['settings'] ) && is_array( $edge['settings'] ) ? $edge['settings'] : array(),
				);
				if ( self::edge_has_default_key( $edge ) ) {
					$row['default'] = self::default_seed_from_edge( $edge );
				}
				if ( $legacy_slot ) {
					$row['legacySlotId'] = $to_id;
				}
				$out[] = $row;
			}
		}

		return self::apply_order( $host_id, $out );
	}

	/**
	 * Apply host display order (own + inherited). Heir order is local — father unchanged.
	 *
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
			$key = self::normalize_attr_id( $id );
			if ( '' !== $key && ! isset( $rank[ $key ] ) ) {
				$rank[ $key ] = $i;
				++$i;
			}
		}
		if ( empty( $rank ) ) {
			return $rows;
		}

		$decorated = array();
		foreach ( $rows as $index => $row ) {
			$decorated[] = array(
				'index' => $index,
				'row'   => $row,
			);
		}
		usort(
			$decorated,
			static function ( array $a, array $b ) use ( $rank ): int {
				$ka = self::normalize_attr_id( $a['row']['id'] ?? '' );
				$kb = self::normalize_attr_id( $b['row']['id'] ?? '' );
				$ia = '' !== $ka && isset( $rank[ $ka ] ) ? $rank[ $ka ] : PHP_INT_MAX;
				$ib = '' !== $kb && isset( $rank[ $kb ] ) ? $rank[ $kb ] : PHP_INT_MAX;
				if ( $ia === $ib ) {
					return $a['index'] <=> $b['index'];
				}
				return $ia < $ib ? -1 : 1;
			}
		);

		$out = array();
		foreach ( $decorated as $item ) {
			$out[] = $item['row'];
		}
		return $out;
	}

	/**
	 * @param list<int|string> $ids
	 */
	private static function store_order_ids( int $host_id, array $ids ): void {
		$clean = array();
		$seen  = array();
		foreach ( $ids as $id ) {
			$key = self::normalize_attr_id( $id );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$clean[]      = $key;
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
	/**
	 * Host walk-cache map (edge id → snapshot).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_walk_cache_map( int $host_id ): array {
		if ( $host_id <= 0 ) {
			return array();
		}
		$raw = get_term_meta( $host_id, self::META_KEY_WALK_CACHE, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $edge_id => $entry ) {
			$id = self::normalize_attr_id( $edge_id );
			if ( '' === $id || ! is_array( $entry ) ) {
				continue;
			}
			$out[ $id ] = $entry;
		}
		return $out;
	}

	/**
	 * @param array<string, array<string, mixed>> $map
	 */
	private static function store_walk_cache_map( int $host_id, array $map ): void {
		if ( $host_id <= 0 ) {
			return;
		}
		if ( array() === $map ) {
			delete_term_meta( $host_id, self::META_KEY_WALK_CACHE );
			return;
		}
		Json_Meta::update_term_meta( $host_id, self::META_KEY_WALK_CACHE, $map );
	}

	/**
	 * Fingerprint of type composition structure (attribute edge ids + targets).
	 * Stale when catalog type gains/loses composition members.
	 */
	public static function type_structure_fingerprint( string $taxonomy, int $type_id, int $max_depth = 4 ): string {
		if ( $type_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return '0';
		}
		$parts = array();
		self::collect_structure_fp( $taxonomy, $type_id, 0, max( 0, $max_depth ), array(), $parts );
		sort( $parts );
		return md5( implode( '|', $parts ) );
	}

	/**
	 * @param list<int>    $seen
	 * @param list<string> $parts
	 */
	private static function collect_structure_fp(
		string $taxonomy,
		int $node_id,
		int $depth,
		int $max_depth,
		array $seen,
		array &$parts
	): void {
		if ( $node_id <= 0 || $depth > $max_depth || in_array( $node_id, $seen, true ) ) {
			return;
		}
		$seen[]  = $node_id;
		$parts[] = 'n:' . $node_id;
		foreach ( self::BINDINGS as $binding_key ) {
			foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $node_id, $binding_key ) as $edge ) {
				$edge_id = self::normalize_attr_id( $edge['id'] ?? '' );
				$to_id   = (int) ( $edge['toId'] ?? 0 );
				if ( '' === $edge_id || $to_id <= 0 ) {
					continue;
				}
				$child = $to_id;
				if ( self::is_slot( $to_id ) ) {
					$slot_type = Node_Type::get_type_id( $to_id );
					if ( $slot_type <= 0 ) {
						continue;
					}
					$child = $slot_type;
				}
				$parts[] = $depth . ':' . $edge_id . '>' . $child;
				self::collect_structure_fp( $taxonomy, $child, $depth + 1, $max_depth, $seen, $parts );
			}
		}
	}

	/**
	 * Drop persisted walk snapshot for one attribute edge on a host.
	 *
	 * @param int|string $attr_id
	 */
	public static function clear_settings_walk_cache( int $host_id, $attr_id ): void {
		$edge_id = self::normalize_attr_id( $attr_id );
		if ( $host_id <= 0 || '' === $edge_id ) {
			return;
		}
		$map = self::get_walk_cache_map( $host_id );
		if ( ! isset( $map[ $edge_id ] ) ) {
			return;
		}
		unset( $map[ $edge_id ] );
		self::store_walk_cache_map( $host_id, $map );
	}

	/**
	 * Whether composition walk from $root_type_id reaches $needle_id.
	 */
	public static function type_structure_contains_node(
		string $taxonomy,
		int $root_type_id,
		int $needle_id,
		int $max_depth = 4
	): bool {
		if ( $root_type_id <= 0 || $needle_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		if ( $root_type_id === $needle_id ) {
			return true;
		}
		return self::type_structure_contains_node_walk(
			$taxonomy,
			$root_type_id,
			$needle_id,
			0,
			max( 0, $max_depth ),
			array()
		);
	}

	/**
	 * @param list<int> $seen
	 */
	private static function type_structure_contains_node_walk(
		string $taxonomy,
		int $node_id,
		int $needle_id,
		int $depth,
		int $max_depth,
		array $seen
	): bool {
		if ( $node_id <= 0 || $depth > $max_depth || in_array( $node_id, $seen, true ) ) {
			return false;
		}
		$seen[] = $node_id;
		foreach ( self::BINDINGS as $binding_key ) {
			foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $node_id, $binding_key ) as $edge ) {
				$to_id = (int) ( $edge['toId'] ?? 0 );
				if ( $to_id <= 0 ) {
					continue;
				}
				$child = $to_id;
				if ( self::is_slot( $to_id ) ) {
					$slot_type = Node_Type::get_type_id( $to_id );
					if ( $slot_type <= 0 ) {
						continue;
					}
					$child = $slot_type;
				}
				if ( $child === $needle_id ) {
					return true;
				}
				if ( self::type_structure_contains_node_walk( $taxonomy, $child, $needle_id, $depth + 1, $max_depth, $seen ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * After the type-chosen node changes (attrs / defaults / walk settings),
	 * clear+rebuild walk caches of attributes that target that type (or a
	 * composition graph that includes it). Compute-on-write for consumers.
	 *
	 * @return int Number of cache entries refreshed (or cleared when $rebuild is false).
	 */
	public static function refresh_settings_walk_caches_for_type_node(
		string $taxonomy,
		int $type_node_id,
		bool $rebuild = true
	): int {
		if ( $type_node_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$hosts = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- rare write-path invalidation.
					array(
						'key'     => self::META_KEY_WALK_CACHE,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		if ( is_wp_error( $hosts ) || ! is_array( $hosts ) || array() === $hosts ) {
			return 0;
		}

		$count    = 0;
		$fp_cache = array();

		foreach ( $hosts as $host_raw ) {
			$host_id = (int) $host_raw;
			$map     = self::get_walk_cache_map( $host_id );
			if ( array() === $map ) {
				continue;
			}

			$to_refresh = array();
			foreach ( $map as $edge_id => $entry ) {
				$type_id = (int) ( $entry['typeId'] ?? 0 );
				if ( $type_id <= 0 ) {
					continue;
				}

				$affects = ( $type_id === $type_node_id )
					|| self::type_structure_contains_node( $taxonomy, $type_id, $type_node_id );

				if ( ! $affects ) {
					/* Safety: structure fingerprint drifted without a direct hit. */
					if ( ! isset( $fp_cache[ $type_id ] ) ) {
						$fp_cache[ $type_id ] = self::type_structure_fingerprint( $taxonomy, $type_id );
					}
					$stored = (string) ( $entry['structureFp'] ?? '' );
					if ( '' === $stored || $stored === $fp_cache[ $type_id ] ) {
						continue;
					}
				}

				$to_refresh[] = (string) $edge_id;
			}

			foreach ( $to_refresh as $edge_id ) {
				self::clear_settings_walk_cache( $host_id, $edge_id );
				if ( $rebuild ) {
					self::ensure_settings_walk_cache( $taxonomy, $host_id, $edge_id, true );
				}
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Build (or return fresh) Settings-walk summary for an attribute Relation.
	 * Compute-on-write / Options hydrate — not on every get_node.
	 *
	 * @param int|string $attr_id
	 * @return array{settingsWalk:list<array<string,mixed>>,settingsWalkMeta:array<string,mixed>,cached:bool}|\WP_Error
	 */
	public static function ensure_settings_walk_cache(
		string $taxonomy,
		int $host_id,
		$attr_id,
		bool $force = false
	) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$attr_id = self::normalize_attr_id( $attr_id );
		if ( $host_id <= 0 || '' === $attr_id ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Invalid attribute.', 'wp-taxonomy-tree' ) );
		}

		$edge = self::find_edge( $taxonomy, $host_id, $attr_id );
		$cache_host = $host_id;
		$row_for_merge = null;
		if ( null === $edge ) {
			/* Inherited: father edge + optional host Settings-override map. */
			$found = self::find_effective_row( $taxonomy, $host_id, $attr_id );
			if ( null === $found ) {
				return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
			}
			$row_for_merge = $found;
			$defined_on    = (int) ( $found['definedOnId'] ?? 0 );
			if ( $defined_on <= 0 ) {
				$defined_on = $host_id;
			}
			$edge = self::find_edge( $taxonomy, $defined_on, $attr_id );
			if ( null === $edge ) {
				return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
			}
			$host_override = self::get_settings_override_for_attr( $host_id, $attr_id );
			/* With heir overrides, cache on the child host; else reuse father snapshot. */
			$cache_host = null !== $host_override ? $host_id : $defined_on;
		}

		$type_id = (int) ( $edge['toId'] ?? 0 );
		if ( $type_id > 0 && self::is_slot( $type_id ) ) {
			$slot_type = Node_Type::get_type_id( $type_id );
			$type_id   = $slot_type > 0 ? $slot_type : 0;
		}
		$edge_settings = isset( $edge['settings'] ) && is_array( $edge['settings'] ) ? $edge['settings'] : null;
		if ( is_array( $row_for_merge ) ) {
			$row_for_merge['settings'] = $edge_settings;
			$edge_settings             = self::effective_settings_deltas_for_row( $host_id, $row_for_merge );
		}
		$structure_fp  = self::type_structure_fingerprint( $taxonomy, $type_id );
		$deltas_fp     = '0';
		if ( is_array( $edge_settings ) ) {
			$encoded = wp_json_encode( Relation::normalize_settings_deltas( $edge_settings ) );
			$deltas_fp = is_string( $encoded ) ? md5( $encoded ) : '0';
		}
		/* Depth-0 default also affects summary enrichment. */
		$default_fp = md5( wp_json_encode( self::default_seed_from_edge( $edge ) ) ?: '[]' );

		if ( ! $force ) {
			$existing = self::read_settings_walk_cache_entry(
				$cache_host,
				$attr_id,
				$type_id,
				$structure_fp,
				$deltas_fp,
				$default_fp
			);
			if ( null !== $existing ) {
				return $existing;
			}
		}

		$prev_slim = self::$slim_walk_summary;
		self::$slim_walk_summary = false;
		try {
			$resolved = Settings_Walk::resolve_preferred_render(
				$taxonomy,
				$type_id,
				$edge_settings,
				(int) ( $edge['legacySlotId'] ?? 0 ),
				true
			);
		} finally {
			self::$slim_walk_summary = $prev_slim;
		}

		$summary = isset( $resolved['walkSummary'] ) && is_array( $resolved['walkSummary'] )
			? $resolved['walkSummary']
			: array();
		$meta    = isset( $resolved['walkMeta'] ) && is_array( $resolved['walkMeta'] )
			? $resolved['walkMeta']
			: array(
				'nodeCount'            => 0,
				'cycleStops'           => 0,
				'depth'                => 0,
				'preferredSource'      => 'type',
				'hasPreferredOverride' => false,
			);
		unset( $meta['lazy'] );

		/* Enrich depth-0 Default like decorate_row (edge.default wins). */
		$tmp = array(
			'settingsWalk' => $summary,
			'fixedValues'  => self::default_seed_from_edge( $edge ),
			'default'      => $edge['default'] ?? null,
			'settings'     => is_array( $edge_settings ) ? $edge_settings : array(),
		);
		self::enrich_walk_default_overrides( $tmp );
		$summary = isset( $tmp['settingsWalk'] ) && is_array( $tmp['settingsWalk'] )
			? $tmp['settingsWalk']
			: $summary;

		$entry = array(
			'v'           => self::WALK_CACHE_VERSION,
			'typeId'      => $type_id,
			'structureFp' => $structure_fp,
			'deltasFp'    => $deltas_fp,
			'defaultFp'   => $default_fp,
			'builtAt'     => time(),
			'summary'     => $summary,
			'meta'        => $meta,
		);
		$map            = self::get_walk_cache_map( $cache_host );
		$map[ $attr_id ] = $entry;
		self::store_walk_cache_map( $cache_host, $map );

		return array(
			'settingsWalk'     => $summary,
			'settingsWalkMeta' => $meta,
			'cached'           => true,
		);
	}

	/**
	 * @return array{settingsWalk:list<array<string,mixed>>,settingsWalkMeta:array<string,mixed>,cached:bool}|null
	 */
	private static function read_settings_walk_cache_entry(
		int $host_id,
		string $attr_id,
		int $type_id,
		string $structure_fp,
		string $deltas_fp,
		string $default_fp
	): ?array {
		$map   = self::get_walk_cache_map( $host_id );
		$entry = isset( $map[ $attr_id ] ) && is_array( $map[ $attr_id ] ) ? $map[ $attr_id ] : null;
		if ( null === $entry ) {
			return null;
		}
		if ( (int) ( $entry['v'] ?? 0 ) !== self::WALK_CACHE_VERSION ) {
			return null;
		}
		if ( (int) ( $entry['typeId'] ?? 0 ) !== $type_id ) {
			return null;
		}
		if ( (string) ( $entry['structureFp'] ?? '' ) !== $structure_fp ) {
			return null;
		}
		if ( (string) ( $entry['deltasFp'] ?? '' ) !== $deltas_fp ) {
			return null;
		}
		if ( (string) ( $entry['defaultFp'] ?? '' ) !== $default_fp ) {
			return null;
		}
		$summary = isset( $entry['summary'] ) && is_array( $entry['summary'] ) ? $entry['summary'] : array();
		$meta    = isset( $entry['meta'] ) && is_array( $entry['meta'] ) ? $entry['meta'] : array();
		$meta['fromCache'] = true;
		return array(
			'settingsWalk'     => $summary,
			'settingsWalkMeta' => $meta,
			'cached'           => true,
		);
	}

	/**
	 * Apply cached walk summary onto a decorate row when slim (no live deep walk).
	 *
	 * @param array<string, mixed> $row
	 */
	private static function apply_persisted_walk_cache_to_row(
		array &$row,
		string $taxonomy,
		int $host_id
	): void {
		if ( ! empty( $row['settingsWalk'] ) && is_array( $row['settingsWalk'] ) ) {
			$row['settingsWalkLazy'] = false;
			return;
		}
		$attr_id = self::normalize_attr_id( $row['id'] ?? '' );
		if ( '' === $attr_id ) {
			return;
		}
		$cache_host = ! empty( $row['inherited'] )
			? (int) ( $row['definedOnId'] ?? $host_id )
			: $host_id;
		if ( $cache_host <= 0 ) {
			$cache_host = $host_id;
		}
		/*
		 * Heir Settings overrides live on the child host — cache there so Passiv’s
		 * snapshot does not hide Kondensator deltas.
		 */
		$host_override = self::get_settings_override_for_attr(
			$host_id,
			self::normalize_attr_id( $row['id'] ?? '' )
		);
		if ( ! empty( $row['inherited'] ) && null !== $host_override ) {
			$cache_host = $host_id;
		}
		/* Cheap miss: no snapshot yet → stay lazy (Options / ensure builds it). */
		$map = self::get_walk_cache_map( $cache_host );
		if ( ! isset( $map[ $attr_id ] ) ) {
			return;
		}
		$type_id       = (int) ( $row['typeId'] ?? 0 );
		$edge_settings = self::effective_settings_deltas_for_row( $host_id, $row );
		$structure_fp  = self::type_structure_fingerprint( $taxonomy, $type_id );
		$deltas_fp     = '0';
		if ( is_array( $edge_settings ) ) {
			$encoded   = wp_json_encode( Relation::normalize_settings_deltas( $edge_settings ) );
			$deltas_fp = is_string( $encoded ) ? md5( $encoded ) : '0';
		}
		$default_fp = md5( wp_json_encode( self::normalize_default_seed( $row['fixedValues'] ?? array() ) ) ?: '[]' );
		$hit        = self::read_settings_walk_cache_entry(
			$cache_host,
			$attr_id,
			$type_id,
			$structure_fp,
			$deltas_fp,
			$default_fp
		);
		if ( null === $hit ) {
			return;
		}
		$row['settingsWalk']     = $hit['settingsWalk'];
		$row['settingsWalkMeta'] = array_merge(
			is_array( $row['settingsWalkMeta'] ?? null ) ? $row['settingsWalkMeta'] : array(),
			$hit['settingsWalkMeta']
		);
		unset( $row['settingsWalkMeta']['lazy'] );
		$row['settingsWalkLazy']   = false;
		$row['settingsWalkCached'] = true;
	}

	/**
	 * Paint nested Settings.nested defaults / prefix allowlists without a deep walk.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function apply_nested_settings_deltas_to_row( string $taxonomy, array &$row ): void {
		$nested = isset( $row['settings']['nested'] ) && is_array( $row['settings']['nested'] )
			? $row['settings']['nested']
			: array();
		if ( array() === $nested || empty( $row['typeProperties'] ) || ! is_array( $row['typeProperties'] ) ) {
			return;
		}
		foreach ( $nested as $path => $bag ) {
			$path = Settings_Walk::normalize_walk_path( (string) $path );
			if ( '' === $path || ! is_array( $bag ) ) {
				continue;
			}
			/* Shallow typeProperties only match depth-1 paths (single edge id). */
			if ( false !== strpos( $path, '/' ) ) {
				continue;
			}
			$edge_id = self::normalize_attr_id( $path );
			if ( '' === $edge_id ) {
				continue;
			}
			$data = isset( $bag['data'] ) && is_array( $bag['data'] )
				? Settings_Walk::normalize_data_bag( $bag['data'] )
				: array();
			foreach ( $row['typeProperties'] as &$prop ) {
				if ( ! is_array( $prop ) ) {
					continue;
				}
				if ( self::normalize_attr_id( $prop['id'] ?? '' ) !== $edge_id ) {
					continue;
				}
				if ( array_key_exists( 'default', $data ) ) {
					$prop['fixedValues']         = self::normalize_default_seed( $data['default'] );
					$prop['hasDefaultOverride']  = true;
					$prop['walkDefaultOverride'] = true;
				}
				if ( array_key_exists( 'allowedPrefixIds', $data ) ) {
					$ids = Settings_Walk::normalize_allowed_prefix_ids( $data['allowedPrefixIds'] );
					if ( ! empty( $prop['fixedOptions'] ) && is_array( $prop['fixedOptions'] ) ) {
						$prop['fixedOptions'] = self::intersect_fixed_options_allowed_prefixes(
							$prop['fixedOptions'],
							$ids
						);
					}
					if ( is_array( $prop['quantitySchema'] ?? null ) ) {
						$prop['quantitySchema'] = Node_Type::apply_allowed_prefix_ids_to_quantity_schema(
							$prop['quantitySchema'],
							$ids
						);
					}
				}
				if (
					array_key_exists( 'choiceFilter', $data )
					&& is_array( $data['choiceFilter'] )
					&& ! empty( $prop['fixedOptions'] )
					&& is_array( $prop['fixedOptions'] )
				) {
					$scope = (int) ( $prop['typeId'] ?? 0 );
					$prop['fixedOptions'] = self::apply_choice_filter(
						$taxonomy,
						$scope,
						$prop['fixedOptions'],
						$data['choiceFilter']
					);
					$prop['hasChoiceFilterOverride'] = true;
					$prop['choiceFilter']            = self::normalize_choice_filter( $data['choiceFilter'] );
				}
				if ( array_key_exists( 'hidden', $data ) ) {
					$prop['hidden']             = ! empty( $data['hidden'] );
					$prop['hasHiddenOverride']  = true;
					$prop['walkHiddenOverride'] = true;
				}
				if ( array_key_exists( 'readOnly', $data ) ) {
					$prop['readonly']             = ! empty( $data['readOnly'] );
					$prop['hasReadOnlyOverride']  = true;
					$prop['walkReadOnlyOverride'] = true;
				}
			}
			unset( $prop );
		}
	}

	private static function decorate_row( array $row, string $taxonomy, int $host_id ): array {
		$name   = (string) ( $row['name'] ?? '' );
		$values = self::resolve_fixed_values( $taxonomy, $host_id, $row );
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
		 * - structure: composed type host (Unit type, size, …) → Form/Table of schema
		 * - catalog: CatalogChoice among specialization children (Bauformen, With prefix, …)
		 *   even when the type also defines inheritable attributes
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
				/*
				 * Unit/With|Without prefix may own Praefix+Kuerzel composition attrs
				 * (OQ-W11), but Quantity Unit slots pick concrete unit leaves under
				 * the bucket (Meter, Ohm, …) with allowedPrefixes — not nested Form.
				 */
				if ( Node_Type::is_unit_prefix_bucket( $taxonomy, $type_id ) ) {
					$row['fixedMode']    = 'catalog';
					$row['fixedRootId']  = $type_id;
					$row['fixedOptions'] = self::unit_leaf_options_under_type( $taxonomy, $type_id );
				} else {
					$choice_opts = self::catalog_choice_options_for_type( $taxonomy, $type_id );
					$has_attrs   = self::type_has_attributes( $taxonomy, $type_id );
					if (
						array() !== $choice_opts
						&& ( ! $has_attrs || ! self::prefers_structure_over_catalog( $taxonomy, $type_id ) )
					) {
						$row['fixedMode']    = 'catalog';
						$row['fixedRootId']  = self::resolve_catalog_choice_root( $taxonomy, $type_id );
						$row['fixedOptions'] = $choice_opts;
					} elseif ( $has_attrs ) {
						$row['fixedMode'] = 'structure';
					} else {
						$row['fixedMode']    = 'catalog';
						$row['fixedRootId']  = self::resolve_catalog_choice_root( $taxonomy, $type_id );
						$row['fixedOptions'] = self::fixed_options_under_type( $taxonomy, $type_id );
					}
				}
			}
		}

		/*
		 * Preferred via Q123 Settings walk: hybrid live type Settings.view +
		 * Relation override deltas (OQ-W2/W16). Full Options walk UI = debt.
		 */
		$legacy_slot = (int) ( $row['legacySlotId'] ?? 0 );
		$edge_settings = self::effective_settings_deltas_for_row( $host_id, $row );
		/* Expose merged settings so Options / Walk UI see heir overrides. */
		if ( null !== $edge_settings ) {
			$row['settings'] = $edge_settings;
		}
		$row['hasHostSettingsOverride'] = ! empty( $row['inherited'] )
			&& null !== self::get_settings_override_for_attr(
				$host_id,
				self::normalize_attr_id( $row['id'] ?? '' )
			);
		/*
		 * get_node / list: skip deep Walk summary (Options lazy-loads).
		 * typeProperties children always shallow (no nested walk summary).
		 */
		$include_walk_summary = ! self::$slim_walk_summary && ! self::$type_props_loading;
		$pref_resolved        = Settings_Walk::resolve_preferred_render(
			$taxonomy,
			$type_id,
			$edge_settings,
			$legacy_slot,
			$include_walk_summary
		);
		$row['typePreferredRender']     = (string) ( $pref_resolved['typePreferred'] ?? Renderer::Form->value );
		$row['preferredRender']         = (string) ( $pref_resolved['value'] ?? $row['typePreferredRender'] );
		$row['preferredRenderOverride'] = ! empty( $pref_resolved['hasOverride'] );
		$row['settingsResolved']        = isset( $pref_resolved['resolved'] ) && is_array( $pref_resolved['resolved'] )
			? $pref_resolved['resolved']
			: array(
				'data' => array(),
				'view' => array(),
			);
		$row['settingsWalkMeta'] = isset( $pref_resolved['walkMeta'] ) && is_array( $pref_resolved['walkMeta'] )
			? $pref_resolved['walkMeta']
			: array(
				'nodeCount'             => 0,
				'cycleStops'            => 0,
				'depth'                 => 0,
				'preferredSource'       => 'type',
				'hasPreferredOverride'  => false,
			);
		/* Bounded walk levels (names + preferred) for Options fold — not full tree. */
		$row['settingsWalk'] = isset( $pref_resolved['walkSummary'] ) && is_array( $pref_resolved['walkSummary'] )
			? $pref_resolved['walkSummary']
			: array();
		$row['settingsWalkLazy']   = ! empty( $row['settingsWalkMeta']['lazy'] );
		$row['settingsWalkCached'] = false;
		/* Prefer compute-on-write snapshot over live deep recursion on get_node. */
		if ( self::$slim_walk_summary && ! self::$type_props_loading ) {
			self::apply_persisted_walk_cache_to_row( $row, $taxonomy, $host_id );
		}
		if (
			Renderer::Multistep->value === Node_Type::normalize_preferred_render( (string) ( $row['typePreferredRender'] ?? '' ) )
			&& $type_id > 0
			&& empty( $row['fixedOptions'] )
			&& ! Node_Type::is_basiseinheit_unit_node( $taxonomy, $type_id )
			&& ! self::is_scalar_type_key( (string) $row['typeKey'] )
		) {
			$row['fixedRootId']  = $type_id;
			$row['fixedOptions'] = self::fixed_options_under_type( $taxonomy, $type_id );
		}
		if (
			Renderer::Multistep->value === Node_Type::normalize_preferred_render( (string) ( $row['typePreferredRender'] ?? '' ) )
			&& $type_id > 0
		) {
			$row['multistepMode'] = Node_Type::get_multistep_mode( $type_id );
		}

		$attr_id = self::normalize_attr_id( $row['id'] ?? '' );
		/*
		 * Q123 ≈ 0.0.431: own attrs = edge Settings deltas only.
		 * Inherited: host typeExtras map override + defining-edge fill (hybrid).
		 */
		$host_extras_local = array();
		if ( ! empty( $row['inherited'] ) ) {
			$host_extras_local = self::get_type_extras_for_attr( $host_id, $attr_id );
			$host_extras       = $host_extras_local;
			if ( empty( $host_extras ) ) {
				$defined_on = (int) ( $row['definedOnId'] ?? 0 );
				if ( $defined_on > 0 && $defined_on !== $host_id ) {
					$host_extras = self::get_type_extras_for_attr( $defined_on, $attr_id );
				}
			}
			$extras = Settings_Walk::merge_type_extras_hybrid( $edge_settings, $host_extras );
		} else {
			$extras = Settings_Walk::type_extras_from_deltas( $edge_settings );
		}
		$row['typeExtras'] = $extras;

		/* Which host-map keys actively override this inherited attr on $host_id. */
		$row['inheritedHostOverride'] = self::inherited_host_override_flags(
			$host_id,
			$row,
			$attr_id,
			$name,
			$host_extras_local
		);

		/* Choice filter (include|exclude subtrees) against catalog fixedOptions. */
		if (
			(
				'catalog' === (string) ( $row['fixedMode'] ?? '' )
				|| Renderer::Multistep->value === Node_Type::normalize_preferred_render( (string) ( $row['typePreferredRender'] ?? '' ) )
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
			$date_node = $type_id > 0 ? $type_id : $legacy_slot;
			$cfg       = $date_node > 0
				? Node_Type::get_date_config_for_node( $taxonomy, $date_node )
				: null;
			if ( is_array( $cfg ) && isset( $cfg['mode'] ) ) {
				$type_mode = Node_Type::normalize_date_mode( (string) $cfg['mode'] );
			}
			$mode = $type_mode;
			$delta_data = ( is_array( $edge_settings ) && isset( $edge_settings['data'] ) && is_array( $edge_settings['data'] ) )
				? $edge_settings['data']
				: array();
			if ( isset( $delta_data['dateMode'] ) && is_string( $delta_data['dateMode'] ) && '' !== $delta_data['dateMode'] ) {
				$mode = Node_Type::normalize_date_mode( (string) $delta_data['dateMode'] );
			} elseif ( isset( $extras['dateMode'] ) && is_string( $extras['dateMode'] ) && '' !== $extras['dateMode'] ) {
				$mode = Node_Type::normalize_date_mode( (string) $extras['dateMode'] );
			}
			$row['dateConfig'] = array(
				'mode'        => $mode,
				'typeMode'    => $type_mode,
				'hasOverride' => isset( $delta_data['dateMode'] ) || isset( $extras['dateMode'] ),
			);
		}

		if ( 'textarea' === (string) $row['typeKey'] ) {
			$type_cols = Node_Type::TEXTAREA_COLS_DEFAULT;
			$type_rows = Node_Type::TEXTAREA_ROWS_DEFAULT;
			$ta_node   = $type_id > 0 ? $type_id : $legacy_slot;
			$ta_cfg    = $ta_node > 0
				? Node_Type::get_textarea_config_for_node( $taxonomy, $ta_node )
				: null;
			if ( is_array( $ta_cfg ) ) {
				$type_cols = Node_Type::normalize_textarea_cols( $ta_cfg['cols'] ?? $type_cols );
				$type_rows = Node_Type::normalize_textarea_rows( $ta_cfg['rows'] ?? $type_rows );
			}
			$cols       = $type_cols;
			$rows       = $type_rows;
			$delta_data = ( is_array( $edge_settings ) && isset( $edge_settings['data'] ) && is_array( $edge_settings['data'] ) )
				? $edge_settings['data']
				: array();
			$has_override = false;
			if ( isset( $delta_data['textareaCols'] ) || isset( $delta_data['textareaRows'] ) ) {
				$has_override = true;
				if ( isset( $delta_data['textareaCols'] ) ) {
					$cols = Node_Type::normalize_textarea_cols( $delta_data['textareaCols'] );
				}
				if ( isset( $delta_data['textareaRows'] ) ) {
					$rows = Node_Type::normalize_textarea_rows( $delta_data['textareaRows'] );
				}
			} elseif ( isset( $extras['textareaCols'] ) || isset( $extras['textareaRows'] ) ) {
				$has_override = true;
				if ( isset( $extras['textareaCols'] ) ) {
					$cols = Node_Type::normalize_textarea_cols( $extras['textareaCols'] );
				}
				if ( isset( $extras['textareaRows'] ) ) {
					$rows = Node_Type::normalize_textarea_rows( $extras['textareaRows'] );
				}
			}
			$row['textareaConfig'] = array(
				'cols'        => $cols,
				'rows'        => $rows,
				'typeCols'    => $type_cols,
				'typeRows'    => $type_rows,
				'hasOverride' => $has_override,
			);
		}

		if (
			'node_presentation' === (string) $row['typeKey']
			|| 'display_node_name' === (string) $row['typeKey']
		) {
			$type_ctx = 'form';
			if ( $type_id > 0 ) {
				$type_ctx = Node_Type::get_presentation_context( $type_id );
			}
			$ctx        = $type_ctx;
			$delta_data = ( is_array( $edge_settings ) && isset( $edge_settings['data'] ) && is_array( $edge_settings['data'] ) )
				? $edge_settings['data']
				: array();
			if ( isset( $delta_data['presentationContext'] ) && is_string( $delta_data['presentationContext'] ) && '' !== $delta_data['presentationContext'] ) {
				$ctx = Node_Type::normalize_presentation_context( (string) $delta_data['presentationContext'] );
			} elseif ( isset( $extras['presentationContext'] ) && is_string( $extras['presentationContext'] ) && '' !== $extras['presentationContext'] ) {
				$ctx = Node_Type::normalize_presentation_context( (string) $extras['presentationContext'] );
			}
			$row['presentationConfig'] = array(
				'context'     => $ctx,
				'typeContext' => $type_ctx,
				'hasOverride' => isset( $delta_data['presentationContext'] ) || isset( $extras['presentationContext'] ),
			);
		}

		if ( 'int' === (string) $row['typeKey'] || 'integer' === (string) $row['typeKey'] ) {
			$type_format = Int_Value::DEFAULT_FORMAT;
			$int_node    = $type_id > 0 ? $type_id : $legacy_slot;
			$cfg         = $int_node > 0
				? Node_Type::get_int_config_for_node( $taxonomy, $int_node )
				: null;
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
			/* Relation Settings.view.preferredConverter wins over typeExtras (Q123 hybrid). */
			$resolved_view = isset( $row['settingsResolved']['view'] ) && is_array( $row['settingsResolved']['view'] )
				? $row['settingsResolved']['view']
				: array();
			$delta_view    = ( is_array( $edge_settings ) && isset( $edge_settings['view'] ) && is_array( $edge_settings['view'] ) )
				? Settings_Walk::normalize_view_bag( $edge_settings['view'] )
				: array();
			$view_conv     = Settings_Walk::view_string( $delta_view, 'preferredConverter' );
			$has_view_conv = Settings_Walk::bag_has_key( $delta_view, 'preferredConverter' );
			if ( $has_view_conv && '' !== $view_conv ) {
				$format = Int_Value::normalize_format_id( $view_conv );
			} elseif ( isset( $extras['preferredConverter'] ) && is_string( $extras['preferredConverter'] ) && '' !== $extras['preferredConverter'] ) {
				$format = Int_Value::normalize_format_id( (string) $extras['preferredConverter'] );
			} elseif ( isset( $extras['displayFormat'] ) && is_string( $extras['displayFormat'] ) && '' !== $extras['displayFormat'] ) {
				$format = Int_Value::normalize_format_id( (string) $extras['displayFormat'] );
			} else {
				$from_resolved = Settings_Walk::view_string( $resolved_view, 'preferredConverter' );
				if ( '' !== $from_resolved ) {
					$format = Int_Value::normalize_format_id( $from_resolved );
				}
			}
			$has_conv_override =
				$has_view_conv
				|| ( isset( $extras['preferredConverter'] ) && '' !== (string) $extras['preferredConverter'] )
				|| ( isset( $extras['displayFormat'] ) && '' !== (string) $extras['displayFormat'] );
			$row['intConfig'] = array(
				'displayFormat' => $format,
				'typeFormat'    => $type_format,
				'hasOverride'   => $has_conv_override,
			);
			$row['displayFormat']          = $format;
			$row['preferredConverter']     = $format;
			$row['typePreferredConverter'] = $type_format;
		}

		$type_validators = $type_id > 0
			? Node_Type::get_validators_for_node( $taxonomy, $type_id )
			: array();
		$delta_validators = null;
		if (
			is_array( $edge_settings )
			&& isset( $edge_settings['data']['validators'] )
			&& is_array( $edge_settings['data']['validators'] )
		) {
			$delta_validators = $edge_settings['data']['validators'];
		}
		$has_validators_override = null !== $delta_validators
			|| ( isset( $extras['validators'] ) && is_array( $extras['validators'] ) );
		$row['validatorsOverride'] = $has_validators_override;
		$row['typeValidators']     = $type_validators;
		if ( null !== $delta_validators ) {
			$row['validators'] = Validator::effective_list(
				Validator::normalize_list( $delta_validators ),
				(string) ( $row['typeKey'] ?? '' )
			);
		} elseif ( isset( $extras['validators'] ) && is_array( $extras['validators'] ) ) {
			$row['validators'] = Validator::effective_list(
				Validator::normalize_list( $extras['validators'] ),
				(string) ( $row['typeKey'] ?? '' )
			);
		} else {
			$row['validators'] = $type_validators;
		}

		/* Basiseinheit unit type → Typ/Praefix/Kuerzel schema for quantity paint. */
		$row['quantitySchema'] = null;
		if ( $type_id > 0 && Node_Type::is_basiseinheit_unit_node( $taxonomy, $type_id ) ) {
			$row['quantitySchema'] = Node_Type::get_quantity_schema_for_type( $taxonomy, $type_id );
		}

		/*
		 * Structure type (has own attributes, e.g. size = Value + Unit): shallow
		 * typeProperties so Quantity Preferred can compose without a second round-trip.
		 * Nested typeProperties stay empty (one level only).
		 */
		$row['typeProperties'] = array();
		if (
			$type_id > 0
			&& ! Node_Type::is_basiseinheit_unit_node( $taxonomy, $type_id )
			&& self::type_has_attributes( $taxonomy, $type_id )
			&& ! self::$type_props_loading
		) {
			self::$type_props_loading = true;
			try {
				foreach ( self::list( $taxonomy, $type_id ) as $child_row ) {
					if ( ! is_array( $child_row ) || ! empty( $child_row['hidden'] ) ) {
						continue;
					}
					$child_row['typeProperties'] = array();
					$row['typeProperties'][]     = $child_row;
				}
			} finally {
				self::$type_props_loading = false;
			}
		}

		/*
		 * Depth-0 Default hybrid: edge.default (Q106 SoT) wins over leftover
		 * settings.data.default; patch walk summary so Options UI can edit/reset.
		 */
		self::enrich_walk_default_overrides( $row );

		/*
		 * Walk Settings.data.allowedPrefixIds → paint (quantitySchema / Unit fixedOptions).
		 * Critical: UI override is fake unless paint honors the Relation path delta.
		 */
		self::apply_walk_prefix_allowlist_to_row( $taxonomy, $row );

		/* Nested walk Defaults → typeProperties.fixedValues so paint/seed sees overrides. */
		self::apply_walk_defaults_to_row( $row );

		/* Walk choiceFilter → typeProperties.fixedOptions (Base unit / Praefix Choices). */
		self::apply_walk_choice_filters_to_row( $taxonomy, $row );

		/* Walk RO / Hide → typeProperties so Preferred Preview omits Background-only fields. */
		self::apply_walk_visibility_to_row( $row );

		/*
		 * When slim get_node omitted walk levels, still honour settings.nested for paint
		 * (depth-1 typeProperties) without a deep live walk.
		 */
		if ( empty( $row['settingsWalk'] ) ) {
			self::apply_nested_settings_deltas_to_row( $taxonomy, $row );
		}

		if ( ! empty( $extras['compute'] ) && is_array( $extras['compute'] ) ) {
			$row['compute']  = $extras['compute'];
			$row['readonly'] = true;
			$row['computed'] = true;
		} else {
			$row['compute']  = null;
			$row['computed'] = false;
		}

		/*
		 * Nested structure embed: Q117 host for typeProperties is the *type*
		 * node — never the outer host. Preview Compact/Form must resolve
		 * Presentation from this map (settings cascade).
		 */
		if ( $type_id > 0 ) {
			if ( class_exists( Node_Presentation::class ) ) {
				$row['typePresentation'] = Node_Presentation::map_for_term_resolved( $taxonomy, $type_id );
			}
			if ( class_exists( Tree_Model::class ) ) {
				$row['typeShortDescription'] = Tree_Model::get_short_description( $type_id );
			}
			$row['compactShowLabels'] = Node_Type::get_compact_show_labels( $type_id );
			if ( empty( $row['typeLabel'] ) ) {
				$row['typeLabel'] = (string) ( $row['typeName'] ?? '' );
			}
		}

		return $row;
	}

	/**
	 * Patch settingsWalk depth-0 Default from edge.default (+ leftover settings.data.default).
	 *
	 * @param array<string, mixed> $row Attribute row (by ref).
	 */
	private static function enrich_walk_default_overrides( array &$row ): void {
		$levels = isset( $row['settingsWalk'] ) && is_array( $row['settingsWalk'] )
			? $row['settingsWalk']
			: array();
		if ( array() === $levels ) {
			return;
		}

		$edge_seed = self::normalize_default_seed( $row['fixedValues'] ?? array() );
		$edge_has  = array() !== $edge_seed;
		/* Own-attr edge key presence (even empty after normalize = no seed). */
		if ( ! $edge_has && isset( $row['default'] ) ) {
			$edge_seed = self::normalize_default_seed( $row['default'] );
			$edge_has  = array() !== $edge_seed;
		}

		$settings_data = ( isset( $row['settings']['data'] ) && is_array( $row['settings']['data'] ) )
			? Settings_Walk::normalize_data_bag( $row['settings']['data'] )
			: array();
		$settings_has  = array_key_exists( 'default', $settings_data );
		$settings_seed = $settings_has
			? self::normalize_default_seed( $settings_data['default'] )
			: array();

		foreach ( $levels as $i => $level ) {
			if ( ! is_array( $level ) ) {
				continue;
			}
			$depth = (int) ( $level['depth'] ?? 0 );
			if ( 0 !== $depth ) {
				continue;
			}
			$type_default = isset( $level['typeDefault'] )
				? self::normalize_default_seed( $level['typeDefault'] )
				: array();
			if ( $edge_has ) {
				$levels[ $i ]['hasDefaultOverride'] = true;
				$levels[ $i ]['default']            = $edge_seed;
				$levels[ $i ]['defaultSource']      = 'edge';
				$levels[ $i ]['hasPathOverride']    = true;
				$levels[ $i ]['hasDelta']           = true;
			} elseif ( $settings_has ) {
				$levels[ $i ]['hasDefaultOverride'] = true;
				$levels[ $i ]['default']            = $settings_seed;
				$levels[ $i ]['defaultSource']      = 'settings';
				$levels[ $i ]['hasPathOverride']    = true;
				$levels[ $i ]['hasDelta']           = true;
			} else {
				$levels[ $i ]['hasDefaultOverride'] = false;
				$levels[ $i ]['default']            = $type_default;
				$levels[ $i ]['defaultSource']      = 'type';
			}
			$levels[ $i ]['typeDefault'] = $type_default;
		}
		$row['settingsWalk'] = $levels;
	}

	/**
	 * Match a shallow typeProperty to a walk level by attribute edge id only.
	 *
	 * Walk `nodeId` is the *type* target — many siblings share Simple types (text).
	 * Matching by typeId would apply one field's Hide/Default onto every peer of
	 * that type (e.g. Strasse Hide → Name dropped from Compact). Never do that.
	 *
	 * @param array<string, mixed> $prop    typeProperty row.
	 * @param string               $edge_id Walk level edgeId.
	 * @param string               $path    Walk path (depth-1 = edge id).
	 * @param int                  $node_id Walk type node id (unique-sibling fallback only).
	 * @param list<array<string, mixed>> $siblings All typeProperties for uniqueness check.
	 */
	private static function type_prop_matches_walk_level(
		array $prop,
		string $edge_id,
		string $path,
		int $node_id,
		array $siblings
	): bool {
		$prop_id = self::normalize_attr_id( $prop['id'] ?? '' );
		if ( '' !== $edge_id && $prop_id === $edge_id ) {
			return true;
		}
		if ( '' !== $path && $prop_id === self::normalize_attr_id( $path ) ) {
			return true;
		}
		/*
		 * Legacy fallback: typeId only when no edge/path and exactly one sibling
		 * has that type (unique Quantity/Unit slots). Shared Simple types never match.
		 */
		if ( '' !== $edge_id || '' !== $path || $node_id <= 0 ) {
			return false;
		}
		$prop_type_id = (int) ( $prop['typeId'] ?? 0 );
		if ( $prop_type_id !== $node_id ) {
			return false;
		}
		$same = 0;
		foreach ( $siblings as $sib ) {
			if ( is_array( $sib ) && (int) ( $sib['typeId'] ?? 0 ) === $node_id ) {
				++$same;
			}
		}
		return 1 === $same;
	}

	/**
	 * Apply nested Walk Default overrides onto shallow typeProperties (paint/seed).
	 *
	 * @param array<string, mixed> $row Attribute row (by ref).
	 */
	private static function apply_walk_defaults_to_row( array &$row ): void {
		$levels = isset( $row['settingsWalk'] ) && is_array( $row['settingsWalk'] )
			? $row['settingsWalk']
			: array();
		if ( array() === $levels || empty( $row['typeProperties'] ) || ! is_array( $row['typeProperties'] ) ) {
			return;
		}

		$siblings = $row['typeProperties'];
		foreach ( $levels as $level ) {
			if ( ! is_array( $level ) || empty( $level['hasDefaultOverride'] ) ) {
				continue;
			}
			$depth = (int) ( $level['depth'] ?? 0 );
			if ( $depth <= 0 ) {
				continue; /* Depth 0 already on row.fixedValues / edge.default. */
			}
			$seed    = self::normalize_default_seed( $level['default'] ?? array() );
			$edge_id = self::normalize_attr_id( $level['edgeId'] ?? '' );
			$node_id = (int) ( $level['nodeId'] ?? 0 );
			$path    = Settings_Walk::normalize_walk_path( (string) ( $level['path'] ?? '' ) );
			if ( '' !== $path && false !== strpos( $path, '/' ) ) {
				continue;
			}

			foreach ( $row['typeProperties'] as &$prop ) {
				if ( ! is_array( $prop ) ) {
					continue;
				}
				if ( ! self::type_prop_matches_walk_level( $prop, $edge_id, $path, $node_id, $siblings ) ) {
					continue;
				}
				$prop['fixedValues']         = $seed;
				$prop['hasDefaultOverride']  = true;
				$prop['walkDefaultOverride'] = true;
			}
			unset( $prop );
		}
	}

	/**
	 * Apply Walk-Wizard nested RO / Hide onto shallow typeProperties (Preview paint).
	 * Settings walk UI still lists hidden rows for configuration; paint skips them.
	 *
	 * @param array<string, mixed> $row Attribute row (by ref).
	 */
	private static function apply_walk_visibility_to_row( array &$row ): void {
		$levels = isset( $row['settingsWalk'] ) && is_array( $row['settingsWalk'] )
			? $row['settingsWalk']
			: array();
		if ( array() === $levels || empty( $row['typeProperties'] ) || ! is_array( $row['typeProperties'] ) ) {
			return;
		}

		$siblings = $row['typeProperties'];
		foreach ( $levels as $level ) {
			if ( ! is_array( $level ) ) {
				continue;
			}
			$depth = (int) ( $level['depth'] ?? 0 );
			if ( $depth <= 0 ) {
				continue;
			}
			$edge_id = self::normalize_attr_id( $level['edgeId'] ?? '' );
			$node_id = (int) ( $level['nodeId'] ?? 0 );
			$path    = Settings_Walk::normalize_walk_path( (string) ( $level['path'] ?? '' ) );
			/* Depth-1 only matches shallow typeProperties (no nested embeds). */
			if ( '' !== $path && false !== strpos( $path, '/' ) ) {
				continue;
			}

			foreach ( $row['typeProperties'] as &$prop ) {
				if ( ! is_array( $prop ) ) {
					continue;
				}
				if ( ! self::type_prop_matches_walk_level( $prop, $edge_id, $path, $node_id, $siblings ) ) {
					continue;
				}
				/* Resolved walk Hide / RO (type default + path override). */
				$prop['hidden']   = ! empty( $level['hidden'] );
				$prop['readonly'] = ! empty( $level['readOnly'] );
				if ( ! empty( $level['hasHiddenOverride'] ) ) {
					$prop['hasHiddenOverride']  = true;
					$prop['walkHiddenOverride'] = true;
				}
				if ( ! empty( $level['hasReadOnlyOverride'] ) ) {
					$prop['hasReadOnlyOverride']  = true;
					$prop['walkReadOnlyOverride'] = true;
				}
			}
			unset( $prop );
		}
	}

	/**
	 * Apply Walk-Wizard choiceFilter overrides onto CatalogChoice typeProperties (paint).
	 * Without this, preview still offers the full Base unit / Praefix catalog.
	 *
	 * @param array<string, mixed> $row Attribute row (by ref).
	 */
	private static function apply_walk_choice_filters_to_row( string $taxonomy, array &$row ): void {
		$levels = isset( $row['settingsWalk'] ) && is_array( $row['settingsWalk'] )
			? $row['settingsWalk']
			: array();
		if ( array() === $levels ) {
			/* Depth-0 catalog attr: choiceFilter already applied via typeExtras above. */
			return;
		}
		if ( empty( $row['typeProperties'] ) || ! is_array( $row['typeProperties'] ) ) {
			return;
		}

		$siblings = $row['typeProperties'];
		foreach ( $levels as $level ) {
			if ( ! is_array( $level ) ) {
				continue;
			}
			$filter = null;
			if ( ! empty( $level['hasChoiceFilterOverride'] ) && isset( $level['choiceFilter'] ) && is_array( $level['choiceFilter'] ) ) {
				$filter = $level['choiceFilter'];
			} elseif ( isset( $level['choiceFilter'] ) && is_array( $level['choiceFilter'] ) ) {
				/* Resolved filter from type edge (may be empty / no override). */
				$norm = self::normalize_choice_filter( $level['choiceFilter'] );
				if ( ! empty( $norm['ids'] ) ) {
					$filter = $norm;
				}
			}
			if ( null === $filter ) {
				continue;
			}

			$edge_id = self::normalize_attr_id( $level['edgeId'] ?? '' );
			$node_id = (int) ( $level['nodeId'] ?? 0 );
			$path    = Settings_Walk::normalize_walk_path( (string) ( $level['path'] ?? '' ) );
			$depth   = (int) ( $level['depth'] ?? 0 );
			if ( $depth <= 0 ) {
				continue;
			}
			if ( '' !== $path && false !== strpos( $path, '/' ) ) {
				continue;
			}

			foreach ( $row['typeProperties'] as &$prop ) {
				if ( ! is_array( $prop ) ) {
					continue;
				}
				if ( ! self::type_prop_matches_walk_level( $prop, $edge_id, $path, $node_id, $siblings ) ) {
					continue;
				}
				if ( empty( $prop['fixedOptions'] ) || ! is_array( $prop['fixedOptions'] ) ) {
					continue;
				}
				$prop_type_id         = (int) ( $prop['typeId'] ?? 0 );
				$scope                = $prop_type_id > 0 ? $prop_type_id : $node_id;
				$prop['fixedOptions'] = self::apply_choice_filter(
					$taxonomy,
					$scope,
					$prop['fixedOptions'],
					$filter
				);
				$prop['hasChoiceFilterOverride'] = ! empty( $level['hasChoiceFilterOverride'] );
				$prop['choiceFilter']            = self::normalize_choice_filter( $filter );
			}
			unset( $prop );
		}
	}

	/**
	 * Apply Walk-Wizard allowedPrefixIds overrides onto quantity / Unit paint payloads.
	 *
	 * @param array<string, mixed> $row Attribute row (by ref).
	 */
	private static function apply_walk_prefix_allowlist_to_row( string $taxonomy, array &$row ): void {
		$levels = isset( $row['settingsWalk'] ) && is_array( $row['settingsWalk'] )
			? $row['settingsWalk']
			: array();
		if ( array() === $levels ) {
			/* Non-nested unit attr: depth-0 Settings.data only. */
			$resolved_data = isset( $row['settingsResolved']['data'] ) && is_array( $row['settingsResolved']['data'] )
				? Settings_Walk::normalize_data_bag( $row['settingsResolved']['data'] )
				: array();
			$edge_settings = isset( $row['settings'] ) && is_array( $row['settings'] ) ? $row['settings'] : null;
			$delta_data    = ( is_array( $edge_settings ) && isset( $edge_settings['data'] ) && is_array( $edge_settings['data'] ) )
				? Settings_Walk::normalize_data_bag( $edge_settings['data'] )
				: array();
			if ( array_key_exists( 'allowedPrefixIds', $delta_data ) && is_array( $row['quantitySchema'] ?? null ) ) {
				$row['quantitySchema'] = Node_Type::apply_allowed_prefix_ids_to_quantity_schema(
					$row['quantitySchema'],
					Settings_Walk::normalize_allowed_prefix_ids( $delta_data['allowedPrefixIds'] )
				);
			} elseif (
				isset( $resolved_data['allowedPrefixIds'] )
				&& is_array( $row['quantitySchema'] ?? null )
				&& Node_Type::is_basiseinheit_unit_node( $taxonomy, (int) ( $row['typeId'] ?? 0 ) )
			) {
				/* Hybrid resolved already includes live unit meta — keep schema as built. */
			}
			return;
		}

		foreach ( $levels as $level ) {
			if ( ! is_array( $level ) || empty( $level['hasAllowedPrefixIdsOverride'] ) ) {
				continue;
			}
			$ids     = Settings_Walk::normalize_allowed_prefix_ids( $level['allowedPrefixIds'] ?? array() );
			$edge_id = self::normalize_attr_id( $level['edgeId'] ?? '' );
			$node_id = (int) ( $level['nodeId'] ?? 0 );
			$depth   = (int) ( $level['depth'] ?? 0 );

			if ( 0 === $depth && is_array( $row['quantitySchema'] ?? null ) ) {
				$row['quantitySchema'] = Node_Type::apply_allowed_prefix_ids_to_quantity_schema(
					$row['quantitySchema'],
					$ids
				);
			}

			if ( empty( $row['typeProperties'] ) || ! is_array( $row['typeProperties'] ) ) {
				continue;
			}
			$path     = Settings_Walk::normalize_walk_path( (string) ( $level['path'] ?? '' ) );
			$siblings = $row['typeProperties'];
			if ( '' !== $path && false !== strpos( $path, '/' ) ) {
				continue;
			}
			foreach ( $row['typeProperties'] as &$prop ) {
				if ( ! is_array( $prop ) ) {
					continue;
				}
				if ( ! self::type_prop_matches_walk_level( $prop, $edge_id, $path, $node_id, $siblings ) ) {
					continue;
				}
				if ( ! empty( $prop['fixedOptions'] ) && is_array( $prop['fixedOptions'] ) ) {
					$prop['fixedOptions'] = self::intersect_fixed_options_allowed_prefixes(
						$prop['fixedOptions'],
						$ids
					);
				}
				if ( is_array( $prop['quantitySchema'] ?? null ) ) {
					$prop['quantitySchema'] = Node_Type::apply_allowed_prefix_ids_to_quantity_schema(
						$prop['quantitySchema'],
						$ids
					);
				}
			}
			unset( $prop );
		}
	}

	/**
	 * Intersect each catalog unit option's allowedPrefixes with an attribute Walk override.
	 *
	 * @param list<array<string, mixed>> $options
	 * @param array<int, int>            $allowed_ids
	 * @return list<array<string, mixed>>
	 */
	private static function intersect_fixed_options_allowed_prefixes( array $options, array $allowed_ids ): array {
		$allowed_map = array_fill_keys( array_map( 'intval', $allowed_ids ), true );
		$out         = array();
		foreach ( $options as $opt ) {
			if ( ! is_array( $opt ) ) {
				continue;
			}
			if ( isset( $opt['allowedPrefixes'] ) && is_array( $opt['allowedPrefixes'] ) ) {
				$filtered = array();
				foreach ( $opt['allowedPrefixes'] as $prefix ) {
					if ( ! is_array( $prefix ) ) {
						continue;
					}
					$pid = (int) ( $prefix['id'] ?? 0 );
					if ( $pid > 0 && isset( $allowed_map[ $pid ] ) ) {
						$prefix['enabled'] = true;
						$filtered[]        = $prefix;
					}
				}
				$opt['allowedPrefixes'] = $filtered;
			}
			$out[] = $opt;
		}
		return $out;
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
	 * Kind options for MultistepRenderer (pick+create; legacy EmbeddedRenderer).
	 * Specialization children when present; otherwise the type itself (e.g. Kontakt).
	 *
	 * @return list<array{id:int,name:string,path:string}>
	 */
	public static function embed_choice_options_for_type( string $taxonomy, int $type_id ): array {
		if ( $type_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$opts = self::choice_options_under_type( $taxonomy, $type_id );
		if ( array() !== $opts ) {
			return $opts;
		}
		if ( ! self::type_has_attributes( $taxonomy, $type_id ) ) {
			return array();
		}
		$term = get_term( $type_id, $taxonomy );
		if ( ! ( $term instanceof \WP_Term ) ) {
			return array();
		}
		return array(
			array(
				'id'   => $type_id,
				'name' => (string) $term->name,
				'path' => (string) $term->name,
			),
		);
	}

	/**
	 * CatalogChoice / embed pick list: descendants under a type host (by id).
	 *
	 * @return list<array{id:int,name:string,path:string,shortDescription?:string}>
	 */
	public static function choice_options_under_type( string $taxonomy, int $type_id ): array {
		return self::fixed_options_under_type( $taxonomy, $type_id );
	}

	/**
	 * Concrete Basiseinheit unit leaves under a Unit prefix bucket (With/Without prefix).
	 * Used by Quantity Unit slots — skips non-unit hierarchy noise.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function unit_leaf_options_under_type( string $taxonomy, int $type_id ): array {
		$out = array();
		foreach ( self::fixed_options_under_type( $taxonomy, $type_id ) as $opt ) {
			if ( ! is_array( $opt ) ) {
				continue;
			}
			$id = (int) ( $opt['id'] ?? 0 );
			if ( $id <= 0 || ! Node_Type::is_basiseinheit_unit_node( $taxonomy, $id ) ) {
				continue;
			}
			$out[] = $opt;
		}
		return $out;
	}

	/**
	 * CatalogChoice options for a type host (unit bucket leaves or hierarchy kids).
	 *
	 * @return list<array{id:int,name:string,path?:string,shortDescription?:string}>
	 */
	public static function catalog_choice_options_for_type( string $taxonomy, int $type_id ): array {
		if ( $type_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		if ( Node_Type::is_unit_prefix_bucket( $taxonomy, $type_id ) ) {
			$options = self::unit_leaf_options_under_type( $taxonomy, $type_id );
		} else {
			$options = self::fixed_options_under_type( $taxonomy, $type_id );
		}
		$filter = Node_Type::get_choice_filter( $type_id );
		if ( is_array( $filter ) && ! empty( $filter['ids'] ) ) {
			$options = self::apply_choice_filter( $taxonomy, $type_id, $options, $filter );
		}
		return $options;
	}

	private static function fixed_options_under_type( string $taxonomy, int $type_id ): array {
		if ( $type_id <= 0 ) {
			return array();
		}
		$type_id = self::resolve_catalog_choice_root( $taxonomy, $type_id );
		if ( $type_id <= 0 ) {
			return array();
		}
		$options = array();
		$seen    = array();
		self::collect_descendants_as_fixed_options( $taxonomy, $type_id, array(), $options, $seen );
		return $options;
	}

	/**
	 * Empty legacy catalog folders (Präfixe / Basiseinheiten) → live Konstanten roots.
	 */
	private static function resolve_catalog_choice_root( string $taxonomy, int $type_id ): int {
		if ( $type_id <= 0 ) {
			return 0;
		}
		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $type_id,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 1,
			)
		);
		if ( is_array( $kids ) && array() !== $kids ) {
			return $type_id;
		}

		$term = get_term( $type_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return $type_id;
		}
		$name = (string) $term->name;
		if ( in_array( $name, array( 'Präfixe', 'Praefixe' ), true ) ) {
			$live = class_exists( Case_Data::class )
				? Case_Data::find_catalog_folder( $taxonomy, 'prefixes' )
				: 0;
			return $live > 0 ? $live : $type_id;
		}
		if ( in_array( $name, array( 'Basiseinheiten', 'Basiseinheit', 'Unit' ), true ) ) {
			$live = class_exists( Case_Data::class )
				? Case_Data::find_catalog_folder( $taxonomy, 'basiseinheiten' )
				: 0;
			return $live > 0 ? $live : $type_id;
		}

		return $type_id;
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
			/* Soft-trashed heirs must not appear as CatalogChoice (ghost C1). */
			if ( class_exists( Trash::class ) && Trash::is_trashed( $id ) ) {
				continue;
			}
			/* Attribute slots are never CatalogChoice leaves (child_of = inheritance only). */
			if ( self::is_slot( $id ) ) {
				continue;
			}
			$name = $kid->name;
			$next = array_merge( $path_parts, array( $name ) );
			if ( empty( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$option      = array(
					'id'               => $id,
					'name'             => $name,
					'path'             => implode( ' / ', $next ),
					'shortDescription' => Tree_Model::get_short_description( $id ),
				);
				if ( Node_Type::is_basiseinheit_unit_node( $taxonomy, $id ) ) {
					$option['allowedPrefixes'] = self::unit_allowed_prefix_options(
						$taxonomy,
						$id
					);
				}
				$options[] = $option;
			}
			self::collect_descendants_as_fixed_options( $taxonomy, $id, $next, $options, $seen );
		}
	}

	/**
	 * Enabled SI prefixes for a unit leaf (empty = no prefix chrome).
	 *
	 * @return list<array{id:int,name:string,shortDescription:string,multiplikator:?float,enabled:bool}>
	 */
	private static function unit_allowed_prefix_options( string $taxonomy, int $unit_id ): array {
		$allow = Node_Type::get_prefix_allowlist( $taxonomy, $unit_id );
		if ( ! is_array( $allow ) || empty( $allow['prefixes'] ) || ! is_array( $allow['prefixes'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $allow['prefixes'] as $row ) {
			if ( ! is_array( $row ) || empty( $row['enabled'] ) ) {
				continue;
			}
			$pid = (int) ( $row['id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}
			$mult = Node_Type::get_multiplikator( $pid );
			$out[] = array(
				'id'               => $pid,
				'name'             => (string) ( $row['name'] ?? '' ),
				'shortDescription' => Tree_Model::get_short_description( $pid ),
				'multiplikator'    => null !== $mult && $mult > 0.0 ? $mult : null,
				'enabled'          => true,
			);
		}
		return $out;
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
			$qty_label = self::format_quantity_wire_label( $v );
			if ( '' !== $qty_label ) {
				$labels[] = $qty_label;
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
	 * Compact label for Quantity/Unit store JSON {mag,prefix,unit}.
	 */
	private static function format_quantity_wire_label( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw || '{' !== $raw[0] ) {
			return '';
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		$mag    = isset( $decoded['mag'] ) ? trim( (string) $decoded['mag'] ) : '';
		$prefix = isset( $decoded['prefix'] ) ? trim( (string) $decoded['prefix'] ) : '';
		$unit   = isset( $decoded['unit'] ) ? trim( (string) $decoded['unit'] ) : '';
		if ( '' === $mag && '' === $prefix && '' === $unit ) {
			return '';
		}
		$parts = array_filter( array( $mag, $prefix, $unit ), static fn( $p ) => '' !== $p );
		return implode( ' ', $parts );
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
			array( 'int', 'double', 'text', 'email', 'textarea', 'char', 'bool', 'date', 'quantity', 'media', 'node_presentation', 'display_node_name' ),
			true
		);
	}

	/**
	 * Flags: which inherited-override host maps apply on this host for one attr.
	 * Own attrs always return all-false (edge SoT; host maps ignored on read).
	 *
	 * @param array<string, mixed> $row Decorated/effective row.
	 * @param array<string, mixed> $host_type_extras_local Host-map bag for this attr (may be empty).
	 * @return array{hidden:bool,readonly:bool,default:bool,typeExtras:bool,any:bool}
	 */
	private static function inherited_host_override_flags(
		int $host_id,
		array $row,
		string $attr_id,
		string $name,
		array $host_type_extras_local
	): array {
		$empty = array(
			'hidden'            => false,
			'readonly'          => false,
			'default'           => false,
			'typeExtras'        => false,
			'settingsOverrides' => false,
			'any'               => false,
		);
		if ( empty( $row['inherited'] ) || $host_id <= 0 ) {
			return $empty;
		}

		$hidden   = '' !== $attr_id && isset( self::get_inherited_hidden_ids( $host_id )[ $attr_id ] );
		$readonly = '' !== $attr_id && isset( self::get_inherited_readonly_ids( $host_id )[ $attr_id ] );
		$default  = '' !== $name && isset( self::get_inherited_fixed_values_map( $host_id )[ $name ] );
		$extras   = ! empty( $host_type_extras_local );
		$settings = null !== self::get_settings_override_for_attr( $host_id, $attr_id );

		return array(
			'hidden'            => $hidden,
			'readonly'          => $readonly,
			'default'           => $default,
			'typeExtras'        => $extras,
			'settingsOverrides' => $settings,
			'any'               => $hidden || $readonly || $default || $extras || $settings,
		);
	}

	/**
	 * Resolve Q106 defaults for a decorated attribute row (OQ-W4).
	 *
	 * Own: Relation `edge.default` only (≈ 0.0.431; no host name-map fallback).
	 * Inherited: host name-map override first, else defining edge `default`.
	 *
	 * @param array<string, mixed> $row
	 * @return list<string|array<string,string>>
	 */
	private static function resolve_fixed_values( string $taxonomy, int $host_id, array $row ): array {
		$name      = (string) ( $row['name'] ?? '' );
		$attr_id   = self::normalize_attr_id( $row['id'] ?? '' );
		$inherited = ! empty( $row['inherited'] );
		$defined   = (int) ( $row['definedOnId'] ?? $host_id );

		if ( $inherited ) {
			$host_vals = self::fixed_values_for_name( $host_id, $name );
			if ( ! empty( $host_vals ) ) {
				return $host_vals;
			}
			if ( $defined > 0 && '' !== $attr_id ) {
				$edge = self::find_edge_by_id( $taxonomy, $defined, $attr_id );
				return self::default_seed_from_edge( $edge );
			}
			return array();
		}

		$edge = null;
		if ( '' !== $attr_id ) {
			$edge = self::find_edge_by_id( $taxonomy, $host_id, $attr_id );
		}
		if ( null !== $edge && self::edge_has_default_key( $edge ) ) {
			return self::default_seed_from_edge( $edge );
		}
		/* Hydrated list_own_raw may already expose edge.default on the row. */
		if ( self::edge_has_default_key( $row ) ) {
			return self::default_seed_from_edge( $row );
		}
		return array();
	}

	/**
	 * @param array<string, mixed>|null $edge
	 * @return list<string|array<string,string>>
	 */
	private static function default_seed_from_edge( ?array $edge ): array {
		if ( null === $edge ) {
			return array();
		}
		if ( array_key_exists( 'default', $edge ) ) {
			return self::normalize_fixed_values_input( $edge['default'] );
		}
		if ( array_key_exists( 'defaultSeed', $edge ) ) {
			return self::normalize_fixed_values_input( $edge['defaultSeed'] );
		}
		return array();
	}

	/**
	 * @param array<string, mixed> $edge
	 */
	private static function edge_has_default_key( array $edge ): bool {
		return array_key_exists( 'default', $edge ) || array_key_exists( 'defaultSeed', $edge );
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
			$attr_key = self::normalize_attr_id( $key );
			if ( '' === $attr_key ) {
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
		$seen  = array();
		foreach ( $ids as $id ) {
			$key = self::normalize_attr_id( $id );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$clean[]      = $key;
		}
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
	 * @param int|string $attr_id Relation edge id (Q123) or legacy slot id.
	 * @return array<string, mixed>|null
	 */
	private static function find_effective_row( string $taxonomy, int $host_id, $attr_id ): ?array {
		$key = self::normalize_attr_id( $attr_id );
		if ( '' === $key ) {
			return null;
		}
		foreach ( self::effective_list( $taxonomy, $host_id ) as $row ) {
			if ( self::normalize_attr_id( $row['id'] ?? '' ) === $key ) {
				return $row;
			}
			/* Legacy: callers still pass slot term id before/during migrate. */
			if ( ! empty( $row['legacySlotId'] ) && (string) (int) $row['legacySlotId'] === $key ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * @param int|string $attr_id
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function find_own_row( string $taxonomy, int $host_id, $attr_id ) {
		$key = self::normalize_attr_id( $attr_id );
		foreach ( self::list_own( $taxonomy, $host_id ) as $row ) {
			if ( self::normalize_attr_id( $row['id'] ?? '' ) === $key ) {
				return $row;
			}
			if ( ! empty( $row['legacySlotId'] ) && (string) (int) $row['legacySlotId'] === $key ) {
				return $row;
			}
		}
		return new \WP_Error( 'wtt_attribute_missing', __( 'Attribute not found after update.', 'wp-taxonomy-tree' ) );
	}

	/**
	 * Legacy: whether $term_id is a slot still targeted by host (pre-Q123).
	 * After migrate, attribute edges target types — always false for hierarchy Q88.
	 */
	public static function is_own_member( string $taxonomy, int $host_id, int $term_id ): bool {
		if ( $term_id <= 0 || ! self::is_slot( $term_id ) ) {
			return false;
		}
		return null !== self::find_edge_by_to( $taxonomy, $host_id, $term_id );
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
	 * Q90 parked table band leftover (Zeile/Kopf/Fuss) — keep edges for legacy
	 * table chrome; never treat as a normal Attribute UI row.
	 */
	public static function is_parked_table_band_term( string $taxonomy, int $term_id ): bool {
		if ( $term_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return false;
		}
		return in_array( $term->name, self::PARKED_TABLE_BAND_NAMES, true );
	}

	/**
	 * Outgoing attribute-binding edge targeting a parked Q90 table band slot.
	 *
	 * @param array<string, mixed> $edge Hydrated or raw edge row.
	 */
	public static function is_parked_table_band_edge( string $taxonomy, array $edge ): bool {
		$to_id = (int) ( $edge['toId'] ?? 0 );
		if ( $to_id <= 0 || ! self::is_parked_table_band_term( $taxonomy, $to_id ) ) {
			return false;
		}
		/* Prefer slot leftovers; also accept name match if marker was cleared. */
		if ( ! self::is_slot( $to_id ) && Node_Type::get_type_id( $to_id ) > 0 ) {
			return false;
		}
		$type_key = (string) ( $edge['typeKey'] ?? $edge['typeName'] ?? $edge['type'] ?? '' );
		return self::is_attribute_binding( $type_key );
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
				/* Referenced structure terms = type targets (+ legacy slots). */
				$tid = (int) ( $row['typeId'] ?? 0 );
				if ( $tid > 0 ) {
					$used[ $tid ] = true;
				}
				$legacy = (int) ( $row['legacySlotId'] ?? 0 );
				if ( $legacy > 0 ) {
					$used[ $legacy ] = true;
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
	 *
	 * @deprecated Q123 Relation-only attributes — legacy leftover slots / migrate only.
	 *             Do not call from new Attribute::add or product hot paths.
	 *             Remains for detach_from_hierarchy_parent / migrate_detach_hierarchy
	 *             until parked Q90 bands and any leftover `_wtt_attribute_slot` terms are gone.
	 */
	public static function mark_as_slot( int $term_id ): void {
		if ( $term_id > 0 ) {
			update_term_meta( $term_id, self::META_KEY_SLOT, '1' );
		}
	}

	/**
	 * Clear WP parent so the slot is not a child_of of any host (parent → 0).
	 *
	 * Legacy slot terms only (Q123). No-op when $attr_id is not a slot term —
	 * Relation edge ids must never be treated as deletable/detachable slot terms.
	 */
	public static function detach_from_hierarchy_parent( string $taxonomy, int $attr_id ): void {
		if ( $attr_id <= 0 || ! self::is_slot( $attr_id ) ) {
			return;
		}
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
				/* Legacy slots only — Q123 attributes have no slot term. */
				$attr_id = (int) ( $row['legacySlotId'] ?? 0 );
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
					Json_Meta::update_term_meta( $host_id, Relation::META_KEY, array_values( $next ) );
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
	public static function duplicate( string $taxonomy, int $host_id, $attr_id ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}

		$attr_id = self::normalize_attr_id( $attr_id );
		$found   = self::find_effective_row( $taxonomy, $host_id, $attr_id );
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

		$new_id = self::normalize_attr_id( $created['id'] ?? '' );
		if ( '' === $new_id ) {
			return new \WP_Error( 'wtt_create_failed', __( 'Could not create attribute relation.', 'wp-taxonomy-tree' ) );
		}

		/* Copy type extras from source attribute id (own host map or ancestor). */
		$source_extras = self::resolve_type_extras_for_attr( $taxonomy, $host_id, $attr_id, $found );
		if ( ! empty( $source_extras ) ) {
			$set = self::set_type_extras( $taxonomy, $host_id, $new_id, $source_extras );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
		}

		/* Copy default seed onto the new own edge (resolve source via hybrid). */
		$fixed = self::resolve_fixed_values( $taxonomy, $host_id, $found );
		if ( ! empty( $fixed ) ) {
			$set = self::set_fixed_values( $taxonomy, $host_id, $new_id, $fixed );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
		}

		/* Copy own-edge RO / Hide (Q105: Hide only when Mult allows 0..1). */
		if ( ! empty( $found['readonly'] ) ) {
			$ro = self::set_readonly( $taxonomy, $host_id, $new_id, true );
			if ( is_wp_error( $ro ) ) {
				return $ro;
			}
		}
		if ( ! empty( $found['hidden'] ) ) {
			$hide = self::set_hidden( $taxonomy, $host_id, $new_id, true );
			if ( is_wp_error( $hide ) ) {
				/* Non-fatal when Mult blocks BO — duplicate still succeeded. */
				if ( 'wtt_bo_mult' !== $hide->get_error_code() ) {
					return $hide;
				}
			}
		}

		Tree_Model::touch_modified( $host_id );
		$row = self::find_own_row( $taxonomy, $host_id, $new_id );
		return is_array( $row ) ? $row : $created;
	}

	/**
	 * Preferred render override on an own Relation edge (`settings.view.preferredRenderer`).
	 *
	 * Empty / `inherit` / `default` deletes the delta key (not an empty-string override).
	 * Also clears leftover slot `_wtt_preferred_render` on legacy toId so slot meta cannot
	 * re-assert hasOverride after migrate. No `is_slot( $attr_id )` gate — attr id is the edge UUID.
	 *
	 * @param int|string $attr_id Relation edge id (Q123) or legacy slot id lookup.
	 * @return true|\WP_Error
	 */
	public static function set_preferred_render( string $taxonomy, int $host_id, $attr_id, string $layout ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}

		$attr_id = self::normalize_attr_id( $attr_id );
		$edge    = self::find_edge( $taxonomy, $host_id, $attr_id );
		$raw     = trim( $layout );
		$clear   = ( '' === $raw || 'inherit' === strtolower( $raw ) || 'default' === strtolower( $raw ) );

		/* Inherited: store Preferred on host Settings-override map (father edge untouched). */
		if ( null === $edge ) {
			$found = self::find_effective_row( $taxonomy, $host_id, $attr_id );
			if ( null === $found || empty( $found['inherited'] ) ) {
				return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
			}
			$current = self::get_settings_override_for_attr( $host_id, $attr_id );
			$applied = Settings_Walk::apply_walk_settings_key(
				$current,
				'',
				'view',
				'preferredRenderer',
				$clear ? null : Node_Type::normalize_preferred_render( $raw )
			);
			self::store_settings_override_for_attr( $host_id, $attr_id, $applied );
			self::ensure_settings_walk_cache( $taxonomy, $host_id, $attr_id, true );
			Tree_Model::touch_modified( $host_id );
			return true;
		}

		$edge_id = self::normalize_attr_id( $edge['id'] ?? '' );
		if ( '' === $edge_id ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}

		$settings = isset( $edge['settings'] ) && is_array( $edge['settings'] ) ? $edge['settings'] : array();
		$view     = isset( $settings['view'] ) && is_array( $settings['view'] )
			? Settings_Walk::normalize_view_bag( $settings['view'] )
			: array();

		if ( $clear ) {
			unset( $view['preferredRenderer'] );
			if ( empty( $view ) ) {
				unset( $settings['view'] );
			} else {
				$settings['view'] = $view;
			}
			$result = Relation::update_settings(
				$taxonomy,
				$host_id,
				$edge_id,
				empty( $settings ) ? null : $settings
			);
		} else {
			$view['preferredRenderer'] = Node_Type::normalize_preferred_render( $raw );
			$settings['view']          = $view;
			$result                    = Relation::update_settings( $taxonomy, $host_id, $edge_id, $settings );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/*
		 * Leftover slot path: preferred lived on slot term meta. Edge is SoT now —
		 * drop slot meta so resolve_preferred_render cannot keep hasOverride via legacy.
		 */
		$to_id = (int) ( $edge['toId'] ?? 0 );
		if ( $to_id > 0 && self::is_slot( $to_id ) ) {
			delete_term_meta( $to_id, Node_Type::META_KEY_PREFERRED_RENDER );
		}

		Tree_Model::touch_modified( $host_id );
		return true;
	}

	/**
	 * Walk-Wizard override: set/clear one Settings.view or Settings.data key on the
	 * attribute Relation (OQ-W16). Empty path = depth-0 top-level bag; non-empty path
	 * = `settings.nested[<edgeUuid[/edgeUuid…]>]`. Never writes nested type nodes.
	 *
	 * @param int|string $attr_id  Relation edge id.
	 * @param string     $path     Walk path ("" or `/`-joined child edge ids).
	 * @param string     $namespace `view` or `data`.
	 * @param string     $key      e.g. preferredRenderer, preferredConverter, validators, dateMode.
	 * @param mixed      $value    Null / empty string (for scalar keys) clears the delta key.
	 * @return true|\WP_Error
	 */
	public static function set_walk_settings_key(
		string $taxonomy,
		int $host_id,
		$attr_id,
		string $path,
		string $namespace,
		string $key,
		$value
	) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}

		$attr_id = self::normalize_attr_id( $attr_id );
		$edge    = self::find_edge( $taxonomy, $host_id, $attr_id );
		$inherited_host = false;
		$defined_on     = $host_id;
		if ( null === $edge ) {
			$found = self::find_effective_row( $taxonomy, $host_id, $attr_id );
			if ( null === $found || empty( $found['inherited'] ) ) {
				return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
			}
			$inherited_host = true;
			$defined_on     = (int) ( $found['definedOnId'] ?? 0 );
			if ( $defined_on <= 0 ) {
				return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
			}
			$edge = self::find_edge( $taxonomy, $defined_on, $attr_id );
			if ( null === $edge ) {
				return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
			}
		}
		$edge_id = self::normalize_attr_id( $edge['id'] ?? '' );
		if ( '' === $edge_id ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}

		$path = Settings_Walk::normalize_walk_path( $path );
		$ns   = strtolower( trim( $namespace ) );
		if ( 'view' !== $ns && 'data' !== $ns ) {
			return new \WP_Error( 'wtt_bad_request', __( 'Invalid Settings namespace.', 'wp-taxonomy-tree' ) );
		}
		$key = Relation::sanitize_settings_key( $key );
		if ( '' === $key ) {
			return new \WP_Error( 'wtt_bad_request', __( 'Invalid Settings key.', 'wp-taxonomy-tree' ) );
		}
		if ( 'view' === $ns ) {
			if ( 'preferredrenderer' === strtolower( $key ) ) {
				$key = 'preferredRenderer';
			} elseif ( 'preferredconverter' === strtolower( $key ) ) {
				$key = 'preferredConverter';
			}
		} elseif ( 'data' === $ns && 'allowedprefixids' === strtolower( $key ) ) {
			$key = 'allowedPrefixIds';
		} elseif ( 'data' === $ns && 'default' === strtolower( $key ) ) {
			$key = 'default';
		} elseif ( 'data' === $ns && 'readonly' === strtolower( $key ) ) {
			$key = 'readOnly';
		} elseif ( 'data' === $ns && 'hidden' === strtolower( $key ) ) {
			$key = 'hidden';
		}

		$type_id = (int) ( $edge['toId'] ?? 0 );
		if ( $type_id > 0 && self::is_slot( $type_id ) ) {
			$slot_type = Node_Type::get_type_id( $type_id );
			$type_id   = $slot_type > 0 ? $slot_type : 0;
		}
		$father_settings = isset( $edge['settings'] ) && is_array( $edge['settings'] ) ? $edge['settings'] : array();
		/*
		 * Inherited: mutate host Settings-override map only. Path validity uses the
		 * hybrid bag (father + host) so Walk levels stay addressable.
		 */
		$host_override = $inherited_host
			? self::get_settings_override_for_attr( $host_id, $attr_id )
			: null;
		$settings_for_path = Settings_Walk::merge_settings_deltas_hybrid(
			$father_settings,
			$host_override
		);
		if ( ! Settings_Walk::walk_path_exists( $taxonomy, $type_id, $settings_for_path, $path ) ) {
			return new \WP_Error( 'wtt_bad_path', __( 'Invalid settings walk path.', 'wp-taxonomy-tree' ) );
		}
		$settings = $inherited_host
			? ( is_array( $host_override ) ? $host_override : array() )
			: $father_settings;

		$clear = false;
		if ( null === $value ) {
			$clear = true;
		} elseif ( is_string( $value ) ) {
			$trim = trim( $value );
			$clear = ( '' === $trim || 'inherit' === strtolower( $trim ) || 'default' === strtolower( $trim ) );
			if ( ! $clear ) {
				$value = $trim;
			}
		} elseif (
			is_array( $value )
			&& array() === $value
			&& ( 'validators' === $key || 'allowedPrefixIds' === $key )
		) {
			/* Empty list still counts as an override (explicit none / L1). Keep it. */
			$clear = false;
		}

		if ( ! $clear && 'data' === $ns && ( 'readOnly' === $key || 'hidden' === $key ) ) {
			if ( is_bool( $value ) ) {
				/* keep */
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$value = (int) $value !== 0;
			} elseif ( is_string( $value ) ) {
				$trim  = strtolower( trim( $value ) );
				$value = ! ( '' === $trim || '0' === $trim || 'false' === $trim || 'no' === $trim || 'off' === $trim );
			} else {
				$value = ! empty( $value );
			}
		}

		/*
		 * Default seed hybrid (OQ-W4 / Walk):
		 * - depth 0 → Relation edge.default (own) or host fixed-values map (inherited)
		 * - nested → settings.nested[path].data.default (empty list = clear key)
		 */
		if ( 'data' === $ns && 'default' === $key ) {
			$seed = $clear ? array() : self::normalize_default_seed( $value );
			if ( array() === $seed ) {
				$clear = true;
			}
			if ( '' === $path ) {
				if ( $inherited_host ) {
					$result = self::set_fixed_values(
						$taxonomy,
						$host_id,
						$attr_id,
						$clear ? array() : $seed
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					self::ensure_settings_walk_cache( $taxonomy, $host_id, $edge_id, true );
					Tree_Model::touch_modified( $host_id );
					return true;
				}
				$result = Relation::update_default( $taxonomy, $host_id, $edge_id, $clear ? array() : $seed );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				/* Drop dual-SoT leftover if an older slice wrote settings.data.default. */
				$applied = Settings_Walk::apply_walk_settings_key( $settings, '', 'data', 'default', null );
				$cleared = Relation::update_settings( $taxonomy, $host_id, $edge_id, $applied );
				if ( is_wp_error( $cleared ) ) {
					return $cleared;
				}
				$name = Relation::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
				if ( '' !== $name ) {
					self::clear_fixed_values_host_key( $host_id, $name );
				}
				self::ensure_settings_walk_cache( $taxonomy, $host_id, $edge_id, true );
				self::refresh_settings_walk_caches_for_type_node( $taxonomy, $host_id, true );
				Tree_Model::touch_modified( $host_id );
				return true;
			}
			$value = $seed;
		}

		if ( ! $clear ) {
			if ( 'view' === $ns && 'preferredRenderer' === $key && ( is_string( $value ) || is_numeric( $value ) ) ) {
				$value = Node_Type::normalize_preferred_render( (string) $value );
			} elseif ( 'view' === $ns && 'preferredConverter' === $key && ( is_string( $value ) || is_numeric( $value ) ) ) {
				$value = sanitize_key( (string) $value );
			} elseif ( 'data' === $ns && 'dateMode' === $key && ( is_string( $value ) || is_numeric( $value ) ) ) {
				$value = Node_Type::normalize_date_mode( (string) $value );
			} elseif ( 'data' === $ns && 'textareaCols' === $key ) {
				$value = Node_Type::normalize_textarea_cols( $value );
			} elseif ( 'data' === $ns && 'textareaRows' === $key ) {
				$value = Node_Type::normalize_textarea_rows( $value );
			} elseif ( 'data' === $ns && 'presentationContext' === $key && ( is_string( $value ) || is_numeric( $value ) ) ) {
				$value = Node_Type::normalize_presentation_context( (string) $value );
			} elseif ( 'data' === $ns && 'validators' === $key ) {
				if ( ! is_array( $value ) ) {
					return new \WP_Error( 'wtt_bad_request', __( 'Validators must be a list.', 'wp-taxonomy-tree' ) );
				}
				$value = Validator::normalize_list( $value );
			} elseif ( 'data' === $ns && 'allowedPrefixIds' === $key ) {
				if ( ! is_array( $value ) ) {
					return new \WP_Error( 'wtt_bad_request', __( 'Allowed prefixes must be a list of ids.', 'wp-taxonomy-tree' ) );
				}
				$value = Settings_Walk::normalize_allowed_prefix_ids( $value );
			} elseif ( 'data' === $ns && 'default' === $key ) {
				$value = self::normalize_default_seed( $value );
			}
		}

		$applied = Settings_Walk::apply_walk_settings_key(
			$settings,
			$path,
			$ns,
			$key,
			$clear ? null : $value
		);
		if ( $inherited_host ) {
			self::store_settings_override_for_attr( $host_id, $attr_id, $applied );
		} else {
			$result = Relation::update_settings( $taxonomy, $host_id, $edge_id, $applied );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			/* Depth-0 Preferred clear: drop leftover slot meta so legacy cannot re-assert. */
			if ( '' === $path && 'view' === $ns && 'preferredRenderer' === $key && $clear ) {
				$to_id = (int) ( $edge['toId'] ?? 0 );
				if ( $to_id > 0 && self::is_slot( $to_id ) ) {
					delete_term_meta( $to_id, Node_Type::META_KEY_PREFERRED_RENDER );
				}
			}
		}

		/* Walk deltas changed → refresh persisted Options summary. */
		self::ensure_settings_walk_cache( $taxonomy, $host_id, $edge_id, true );
		if ( ! $inherited_host ) {
			self::refresh_settings_walk_caches_for_type_node( $taxonomy, $host_id, true );
		}

		Tree_Model::touch_modified( $host_id );
		return true;
	}

	/**
	 * Set / replace type extras for one attribute on a host.
	 *
	 * Own Relation edges: write Settings.data/view only (edge = SoT); clear host
	 * `_wtt_attribute_type_extras` key on success. Inherited attrs (no local edge):
	 * host map override only. Hide / readonly: own → edge fields; inherited → host maps.
	 *
	 * @param array<string, mixed>|null $extras Null clears.
	 * @return true|\WP_Error
	 */
	public static function set_type_extras( string $taxonomy, int $host_id, $attr_id, $extras ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Host node not found.', 'wp-taxonomy-tree' ) );
		}
		$attr_id = self::normalize_attr_id( $attr_id );
		$found   = self::find_effective_row( $taxonomy, $host_id, $attr_id );
		if ( null === $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Attribute not found on this node.', 'wp-taxonomy-tree' ) );
		}

		$normalized = array();
		if ( null !== $extras && array() !== $extras ) {
			$normalized = self::normalize_type_extras( $extras );
		}

		/*
		 * Q123: own edge → Settings only (stop dual-write). Inherited → host map
		 * override (edge lives on definedOn). Own reads are edge-only (≈ 0.0.431).
		 */
		$own_edge = self::find_edge_by_id( $taxonomy, $host_id, $attr_id );
		if ( null !== $own_edge ) {
			$current = isset( $own_edge['settings'] ) && is_array( $own_edge['settings'] )
				? $own_edge['settings']
				: null;
			$applied = Settings_Walk::apply_type_extras_to_settings(
				$current,
				array() === $normalized ? null : $normalized
			);
			$settings_result = Relation::update_settings( $taxonomy, $host_id, $attr_id, $applied );
			if ( is_wp_error( $settings_result ) ) {
				return $settings_result;
			}
			self::clear_type_extras_host_key( $host_id, $attr_id );
		} else {
			$map = self::get_type_extras_map( $host_id );
			if ( array() === $normalized ) {
				unset( $map[ $attr_id ] );
			} else {
				$map[ $attr_id ] = $normalized;
			}
			self::store_type_extras_map( $host_id, $map );
		}

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
			$id = self::normalize_attr_id( $row['id'] ?? '' );
			if ( '' !== $id ) {
				$by_id[ $id ] = $row;
			}
		}

		$flat = array();
		foreach ( $sources as $src ) {
			if ( ! is_array( $src ) ) {
				continue;
			}
			$kind = isset( $src['kind'] ) ? sanitize_key( (string) $src['kind'] ) : 'attr';
			$aid  = isset( $src['attrId'] ) ? self::normalize_attr_id( $src['attrId'] ) : '';
			if ( '' === $aid ) {
				continue;
			}
			$raw = $values[ $aid ] ?? null;
			if ( 'attrPath' === $kind ) {
				$path_id = isset( $src['pathAttrId'] ) ? self::normalize_attr_id( $src['pathAttrId'] ) : '';
				if ( '' === $path_id ) {
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
	 * @param mixed  $item Linked object values map or scalar.
	 * @param string $path_attr_id Relation edge id of the nested attribute.
	 */
	private static function extract_numeric_from_path_item( $item, string $path_attr_id ): ?float {
		if ( ! is_array( $item ) || '' === $path_attr_id ) {
			return null;
		}
		$raw = $item[ $path_attr_id ] ?? null;
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
	public static function get_type_extras_for_attr( int $host_id, $attr_id ): array {
		$key = self::normalize_attr_id( $attr_id );
		if ( '' === $key ) {
			return array();
		}
		$map = self::get_type_extras_map( $host_id );
		if ( ! isset( $map[ $key ] ) || ! is_array( $map[ $key ] ) ) {
			return array();
		}
		return self::normalize_type_extras( $map[ $key ] );
	}

	/**
	 * Inherited Settings deltas on this host for one attr (Preferred / Walk-Wizard).
	 *
	 * @param int|string $attr_id
	 * @return array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}|null
	 */
	public static function get_settings_override_for_attr( int $host_id, $attr_id ): ?array {
		$key = self::normalize_attr_id( $attr_id );
		if ( $host_id <= 0 || '' === $key ) {
			return null;
		}
		$map = self::get_settings_overrides_map( $host_id );
		if ( ! isset( $map[ $key ] ) || ! is_array( $map[ $key ] ) ) {
			return null;
		}
		return Relation::normalize_settings_deltas( $map[ $key ] );
	}

	/**
	 * @return array<string, array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}>
	 */
	public static function get_inherited_settings_overrides_map( int $host_id ): array {
		return self::get_settings_overrides_map( $host_id );
	}

	/**
	 * @return array<string, array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}>
	 */
	private static function get_settings_overrides_map( int $host_id ): array {
		$raw = get_term_meta( $host_id, self::META_KEY_SETTINGS_OVERRIDES, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $key => $val ) {
			$key = self::normalize_attr_id( $key );
			if ( '' === $key || ! is_array( $val ) ) {
				continue;
			}
			$norm = Relation::normalize_settings_deltas( $val );
			if ( null !== $norm ) {
				$out[ $key ] = $norm;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}> $map Map.
	 */
	private static function store_settings_overrides_map( int $host_id, array $map ): void {
		if ( empty( $map ) ) {
			delete_term_meta( $host_id, self::META_KEY_SETTINGS_OVERRIDES );
			return;
		}
		update_term_meta( $host_id, self::META_KEY_SETTINGS_OVERRIDES, $map );
	}

	/**
	 * Store / clear inherited Settings deltas for one attr on this host.
	 *
	 * @param int|string                                                                                          $attr_id
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}|null $settings
	 */
	private static function store_settings_override_for_attr( int $host_id, $attr_id, ?array $settings ): void {
		$key = self::normalize_attr_id( $attr_id );
		if ( $host_id <= 0 || '' === $key ) {
			return;
		}
		$map  = self::get_settings_overrides_map( $host_id );
		$norm = Relation::normalize_settings_deltas( $settings );
		if ( null === $norm || array() === $norm ) {
			unset( $map[ $key ] );
		} else {
			$map[ $key ] = $norm;
		}
		self::store_settings_overrides_map( $host_id, $map );
	}

	/**
	 * Effective Settings deltas for decorate / walk: own edge, or father edge + host heir override.
	 *
	 * @param array<string, mixed> $row Effective / list row (may include settings + inherited).
	 * @return array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}|null
	 */
	private static function effective_settings_deltas_for_row( int $host_id, array $row ): ?array {
		$base = isset( $row['settings'] ) && is_array( $row['settings'] ) ? $row['settings'] : null;
		if ( empty( $row['inherited'] ) ) {
			return Relation::normalize_settings_deltas( $base );
		}
		$attr_id   = self::normalize_attr_id( $row['id'] ?? '' );
		$override  = '' !== $attr_id ? self::get_settings_override_for_attr( $host_id, $attr_id ) : null;
		return Settings_Walk::merge_settings_deltas_hybrid( $base, $override );
	}

	/**
	 * Resolve typeExtras when copying / duplicating an attribute.
	 * Prefers this host's inherited override map; else defining host map.
	 * Own-attr SoT is Relation Settings (not this helper).
	 *
	 * @param array<string, mixed> $found Effective row.
	 * @return array<string, mixed>
	 */
	private static function resolve_type_extras_for_attr(
		string $taxonomy,
		int $host_id,
		$attr_id,
		array $found
	): array {
		$attr_id = self::normalize_attr_id( $attr_id );
		$local   = self::get_type_extras_for_attr( $host_id, $attr_id );
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
			$key = self::normalize_attr_id( $key );
			if ( '' === $key || ! is_array( $val ) ) {
				continue;
			}
			$out[ $key ] = $val;
		}
		return $out;
	}

	/**
	 * Drop one attr key from host typeExtras debt map (edge write succeeded).
	 *
	 * @param int|string $attr_id
	 */
	private static function clear_type_extras_host_key( int $host_id, $attr_id ): void {
		$key = self::normalize_attr_id( $attr_id );
		if ( '' === $key ) {
			return;
		}
		$map = self::get_type_extras_map( $host_id );
		if ( ! isset( $map[ $key ] ) ) {
			return;
		}
		unset( $map[ $key ] );
		self::store_type_extras_map( $host_id, $map );
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

		if ( array_key_exists( 'textareaCols', $extras ) ) {
			$out['textareaCols'] = Node_Type::normalize_textarea_cols( $extras['textareaCols'] );
		}
		if ( array_key_exists( 'textareaRows', $extras ) ) {
			$out['textareaRows'] = Node_Type::normalize_textarea_rows( $extras['textareaRows'] );
		}

		if ( array_key_exists( 'presentationContext', $extras ) ) {
			$ctx = (string) $extras['presentationContext'];
			if ( '' !== $ctx ) {
				$out['presentationContext'] = Node_Type::normalize_presentation_context( $ctx );
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

		if ( isset( $extras['validators'] ) && is_array( $extras['validators'] ) ) {
			$validators = Validator::normalize_list( $extras['validators'] );
			if ( array() !== $validators ) {
				$out['validators'] = $validators;
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
		/*
		 * Product UI is allow-all + uncheck to exclude (mode=exclude).
		 * Legacy mode=include is still applied for stored extras.
		 */
		$mode = isset( $filter['mode'] ) ? strtolower( sanitize_key( (string) $filter['mode'] ) ) : 'exclude';
		if ( 'include' !== $mode ) {
			$mode = 'exclude';
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
			$aid  = isset( $src['attrId'] ) ? self::normalize_attr_id( $src['attrId'] ) : '';
			if ( '' === $aid ) {
				continue;
			}
			if ( 'attrPath' === $kind ) {
				$path = isset( $src['pathAttrId'] ) ? self::normalize_attr_id( $src['pathAttrId'] ) : '';
				if ( '' === $path ) {
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
	 * Own attribute edge matched by Relation edge id only (no legacy toId fallback).
	 *
	 * @param int|string $attr_id
	 * @return array<string, mixed>|null
	 */
	private static function find_edge_by_id( string $taxonomy, int $host_id, $attr_id ): ?array {
		$key = self::normalize_attr_id( $attr_id );
		if ( '' === $key ) {
			return null;
		}
		foreach ( self::BINDINGS as $binding_key ) {
			foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $host_id, $binding_key ) as $edge ) {
				if ( self::normalize_attr_id( $edge['id'] ?? '' ) === $key ) {
					return $edge;
				}
			}
		}
		return null;
	}

	/**
	 * Find attribute edge by Relation edge id (Q123) or legacy slot toId.
	 *
	 * @param int|string $attr_id
	 * @return array<string, mixed>|null
	 */
	private static function find_edge( string $taxonomy, int $host_id, $attr_id ): ?array {
		$key = self::normalize_attr_id( $attr_id );
		if ( '' === $key ) {
			return null;
		}
		$by_id = self::find_edge_by_id( $taxonomy, $host_id, $key );
		if ( null !== $by_id ) {
			return $by_id;
		}
		/* Legacy slot id lookup. */
		if ( ctype_digit( $key ) ) {
			return self::find_edge_by_to( $taxonomy, $host_id, (int) $key );
		}
		return null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function find_edge_by_to( string $taxonomy, int $host_id, int $to_id ): ?array {
		if ( $to_id <= 0 ) {
			return null;
		}
		foreach ( self::BINDINGS as $binding_key ) {
			foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $host_id, $binding_key ) as $edge ) {
				if ( (int) ( $edge['toId'] ?? 0 ) === $to_id ) {
					return $edge;
				}
			}
		}
		return null;
	}
}
