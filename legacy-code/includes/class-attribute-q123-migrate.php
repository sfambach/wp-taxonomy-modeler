<?php
/**
 * Q123 migrate: attribute slot terms → named composition/aggregation Relations.
 *
 * Idempotent. Rewrites edges (toId = type, name = former slot name), remaps host
 * maps + Model_Data value keys from slot term id → Relation edge id, then deletes
 * orphan slot terms.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-shot / ensure-time migrate for Relation-only attributes.
 */
final class Attribute_Q123_Migrate {

	public const OPTION_FLAG = 'wtt_q123_attr_migrated';

	/** One-shot: host typeExtras folded into Relation Settings deltas (+ orphan key sweep). */
	public const OPTION_TYPE_EXTRAS_FOLDED = 'wtt_q123_type_extras_folded_v2';

	/** One-shot: drop host typeExtras keys fully covered by edge Settings (post dual-write stop). */
	public const OPTION_TYPE_EXTRAS_PRUNED = 'wtt_q123_type_extras_pruned_v1';

	/**
	 * One-shot: fold host readonly/hidden map entries onto own Relation edges (OQ-W4).
	 * Inherited cover-up / RO overrides stay on host maps.
	 */
	public const OPTION_EDGE_FLAGS_FOLDED = 'wtt_q123_edge_flags_folded_v1';

	/**
	 * One-shot: fold host name-keyed `_wtt_attribute_fixed_values` onto own edge.default (OQ-W4 / Q106).
	 * Inherited-only name keys stay on the host map as overrides.
	 */
	public const OPTION_DEFAULTS_FOLDED = 'wtt_q123_defaults_folded_v1';

	/**
	 * One-shot safe cleanup after folds: drop own-attr host-map keys already covered by edge
	 * fields (RO/Hide/default/typeExtras), delete empty typeExtras maps. Inherited overrides kept.
	 */
	public const OPTION_HOST_MAPS_PRUNED = 'wtt_q123_host_maps_pruned_v1';

	/**
	 * One-shot: hard-delete true orphan `_wtt_attribute_slot` terms.
	 * Keeps parked Q90 table bands (Zeile/Kopf/Fuss), any edge toId target, catalog types.
	 */
	public const OPTION_ORPHAN_SLOTS_PURGED = 'wtt_q123_orphan_slots_purged_v1';

	/**
	 * One-shot before own-attr edge-only reads (≈ 0.0.431):
	 * fold remaining own host-map Hide (incl. Q105 Mult ≠ 0..1 debt) + leftover
	 * own RO / default / typeExtras onto the Relation edge, then clear those own keys.
	 * Inherited host-map overrides stay.
	 */
	public const OPTION_OWN_EDGE_READ_SOT = 'wtt_q123_own_edge_read_sot_v1';

	/**
	 * One-shot: ensure parked Q90 band edges (Zeile/Kopf/Fuss) have Relation.name
	 * matching the leftover slot term (clarity in Relations panel).
	 */
	public const OPTION_PARKED_BAND_NAMES = 'wtt_q123_parked_band_names_v1';

	/**
	 * Run migrate when needed (ensure / first Attribute::list).
	 *
	 * @return array{migrated:bool,edges:int,slotsDeleted:int,valueKeys:int}
	 */
	public static function maybe_migrate( string $taxonomy ): array {
		$empty = array(
			'migrated'     => false,
			'edges'        => 0,
			'slotsDeleted' => 0,
			'valueKeys'    => 0,
		);
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $empty;
		}
		$flags = get_option( self::OPTION_FLAG, array() );
		if ( ! is_array( $flags ) ) {
			$flags = array();
		}
		if ( ! empty( $flags[ $taxonomy ] ) && ! self::taxonomy_still_has_slot_edges( $taxonomy ) ) {
			self::maybe_fold_type_extras( $taxonomy );
			self::maybe_prune_redundant_type_extras( $taxonomy );
			self::maybe_fold_edge_flags( $taxonomy );
			self::maybe_fold_defaults( $taxonomy );
			self::maybe_prune_host_maps( $taxonomy );
			self::maybe_purge_orphan_slots( $taxonomy );
			self::maybe_fold_own_edge_read_sot( $taxonomy );
			self::maybe_name_parked_band_edges( $taxonomy );
			return $empty;
		}

		$result               = self::migrate_taxonomy( $taxonomy );
		$flags[ $taxonomy ]   = 1;
		update_option( self::OPTION_FLAG, $flags, false );
		self::maybe_fold_type_extras( $taxonomy );
		self::maybe_prune_redundant_type_extras( $taxonomy );
		self::maybe_fold_edge_flags( $taxonomy );
		self::maybe_fold_defaults( $taxonomy );
		self::maybe_prune_host_maps( $taxonomy );
		self::maybe_purge_orphan_slots( $taxonomy );
		self::maybe_fold_own_edge_read_sot( $taxonomy );
		self::maybe_name_parked_band_edges( $taxonomy );
		$result['migrated'] = true;
		return $result;
	}

	/**
	 * Force migrate (ignore flag).
	 *
	 * @return array{migrated:bool,edges:int,slotsDeleted:int,valueKeys:int}
	 */
	public static function migrate_taxonomy( string $taxonomy ): array {
		$stats = array(
			'migrated'     => true,
			'edges'        => 0,
			'slotsDeleted' => 0,
			'valueKeys'    => 0,
		);
		if ( ! taxonomy_exists( $taxonomy ) ) {
			$stats['migrated'] = false;
			return $stats;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $terms ) ) {
			return $stats;
		}

		/** @var array<int, string> $slot_to_edge global remap for Model_Data */
		$slot_to_edge = array();
		$slots_seen   = array();

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
			if ( ! is_array( $raw ) || array() === $raw ) {
				continue;
			}

			$changed = false;
			$local   = array(); // slotId => edgeId on this host
			foreach ( $raw as $i => $edge ) {
				if ( ! is_array( $edge ) ) {
					continue;
				}
				$to_id = (int) ( $edge['toId'] ?? 0 );
				if ( $to_id <= 0 || ! Attribute::is_slot( $to_id ) ) {
					continue;
				}
				$type_key = strtolower( (string) ( $edge['typeKey'] ?? '' ) );
				if ( '' === $type_key ) {
					$type_term = get_term( (int) ( $edge['typeId'] ?? 0 ), $taxonomy );
					$type_key  = $type_term instanceof \WP_Term ? strtolower( $type_term->name ) : '';
				}
				if ( ! Attribute::is_attribute_binding( $type_key ) ) {
					continue;
				}

				$slot = get_term( $to_id, $taxonomy );
				if ( ! $slot instanceof \WP_Term ) {
					continue;
				}

				$edge_id = isset( $edge['id'] ) ? sanitize_key( (string) $edge['id'] ) : '';
				if ( '' === $edge_id ) {
					$edge_id = function_exists( 'wp_generate_uuid4' )
						? sanitize_key( str_replace( '-', '', wp_generate_uuid4() ) )
						: sanitize_key( uniqid( 'rel', true ) );
					$raw[ $i ]['id'] = $edge_id;
					$changed         = true;
				}

				$type_id = Node_Type::get_type_id( $to_id );
				if ( $type_id <= 0 ) {
					/*
					 * Untyped slot (e.g. parked table Zeile/Kopf/Fuss bands):
					 * cannot rewrite toId → keep target, but ensure Relation.name.
					 */
					$slot_name = Relation::normalize_edge_name( $slot->name );
					if ( '' !== $slot_name && (string) ( $raw[ $i ]['name'] ?? '' ) !== $slot_name ) {
						$raw[ $i ]['name'] = $slot_name;
						$changed           = true;
					}
					continue;
				}
				$type = get_term( $type_id, $taxonomy );
				if ( ! $type instanceof \WP_Term ) {
					continue;
				}

				$raw[ $i ]['toId'] = $type_id;
				$raw[ $i ]['name'] = Relation::normalize_edge_name( $slot->name );
				$changed           = true;
				++$stats['edges'];
				$local[ $to_id ]        = $edge_id;
				$slot_to_edge[ $to_id ] = $edge_id;
				$slots_seen[ $to_id ]   = true;

				/* Optional: fold Preferred on slot into edge Settings.view */
				$pref = (string) get_term_meta( $to_id, Node_Type::META_KEY_PREFERRED_RENDER, true );
				if ( '' !== $pref ) {
					$settings = isset( $raw[ $i ]['settings'] ) && is_array( $raw[ $i ]['settings'] )
						? $raw[ $i ]['settings']
						: array();
					if ( ! isset( $settings['view'] ) || ! is_array( $settings['view'] ) ) {
						$settings['view'] = array();
					}
					if ( ! isset( $settings['view']['preferredRenderer'] ) && ! isset( $settings['view']['preferredrenderer'] ) ) {
						$settings['view']['preferredRenderer'] = Node_Type::normalize_preferred_render( $pref );
						$raw[ $i ]['settings']                 = $settings;
					}
				}
			}

			if ( $changed ) {
				Json_Meta::update_term_meta( $host_id, Relation::META_KEY, array_values( $raw ) );
				if ( ! empty( $local ) ) {
					self::remap_host_id_lists( $host_id, $local );
					self::remap_type_extras( $taxonomy, $host_id, $local );
				}
				Tree_Model::touch_modified( $host_id );
			}
		}

		if ( ! empty( $slot_to_edge ) ) {
			$stats['valueKeys'] = self::remap_model_data_keys( $taxonomy, $slot_to_edge );
		}

		/* Delete rewritten slots + any orphan slot terms with no attribute incoming. */
		$stats['slotsDeleted'] += self::delete_orphan_slots( $taxonomy, array_keys( $slots_seen ) );

		return $stats;
	}

	/**
	 * True when migratable typed slot targets remain (forces re-run).
	 * Untyped leftovers (parked table bands) do not block the flag.
	 */
	private static function taxonomy_still_has_slot_edges( string $taxonomy ): bool {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $terms ) ) {
			return false;
		}
		foreach ( $terms as $host_id ) {
			$host_id = (int) $host_id;
			foreach ( Relation::list_outgoing( $taxonomy, $host_id ) as $edge ) {
				if ( ! Attribute::is_attribute_binding( (string) ( $edge['typeKey'] ?? '' ) ) ) {
					continue;
				}
				$to = (int) ( $edge['toId'] ?? 0 );
				if ( $to <= 0 || ! Attribute::is_slot( $to ) ) {
					continue;
				}
				/* Only typed slots are rewritable — untyped table bands stay until Q90 cleanup. */
				if ( Node_Type::get_type_id( $to ) > 0 ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param list<int|string> $prefer Slot ids to try first (just rewritten).
	 */
	private static function delete_orphan_slots( string $taxonomy, array $prefer = array() ): int {
		$candidates = array();
		foreach ( $prefer as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$candidates[ $id ] = true;
			}
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $tid ) {
				$tid = (int) $tid;
				if ( $tid > 0 && Attribute::is_slot( $tid ) ) {
					$candidates[ $tid ] = true;
				}
			}
		}

		$deleted = 0;
		foreach ( array_keys( $candidates ) as $slot_id ) {
			$slot_id = (int) $slot_id;
			if ( $slot_id <= 0 || ! self::slot_safe_to_purge( $taxonomy, $slot_id ) ) {
				continue;
			}
			$del = wp_delete_term( $slot_id, $taxonomy );
			if ( ! is_wp_error( $del ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Idempotent one-shot: purge true orphan slots after main migrate already ran.
	 */
	private static function maybe_purge_orphan_slots( string $taxonomy ): void {
		$flags = get_option( self::OPTION_ORPHAN_SLOTS_PURGED, array() );
		if ( ! is_array( $flags ) ) {
			$flags = array();
		}
		if ( ! empty( $flags[ $taxonomy ] ) ) {
			return;
		}
		self::purge_orphan_slots( $taxonomy );
		$flags[ $taxonomy ] = 1;
		update_option( self::OPTION_ORPHAN_SLOTS_PURGED, $flags, false );
	}

	/**
	 * Delete leftover `_wtt_attribute_slot` terms that are safe orphans.
	 *
	 * @return int Number deleted.
	 */
	public static function purge_orphan_slots( string $taxonomy ): int {
		return self::delete_orphan_slots( $taxonomy, array() );
	}

	/**
	 * @param array<int, string> $local slotId => edgeId
	 */
	private static function remap_host_id_lists( int $host_id, array $local ): void {
		foreach ( array( Attribute::META_KEY_HIDDEN, Attribute::META_KEY_READONLY, Attribute::META_KEY_ORDER ) as $meta_key ) {
			$raw = get_term_meta( $host_id, $meta_key, true );
			if ( ! is_array( $raw ) || array() === $raw ) {
				continue;
			}
			$next = array();
			$seen = array();
			foreach ( $raw as $id ) {
				$slot = (int) $id;
				$key  = isset( $local[ $slot ] ) ? (string) $local[ $slot ] : (string) $id;
				$key  = sanitize_key( $key );
				if ( '' === $key || isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$next[]       = $key;
			}
			if ( empty( $next ) ) {
				delete_term_meta( $host_id, $meta_key );
			} else {
				update_term_meta( $host_id, $meta_key, $next );
			}
		}
	}

	/**
	 * Remap host typeExtras keys slot→edge. Fold into Settings via maybe_fold_type_extras.
	 *
	 * @param array<int, string> $local slotId => edgeId
	 */
	private static function remap_type_extras( string $taxonomy, int $host_id, array $local ): void {
		unset( $taxonomy );
		$raw = get_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, true );
		if ( ! is_array( $raw ) || array() === $raw ) {
			return;
		}
		$next = array();
		foreach ( $raw as $key => $val ) {
			if ( ! is_array( $val ) ) {
				continue;
			}
			$slot = (int) $key;
			$nk   = isset( $local[ $slot ] ) ? (string) $local[ $slot ] : sanitize_key( (string) $key );
			if ( '' === $nk ) {
				continue;
			}
			$next[ $nk ] = $val;
		}
		if ( empty( $next ) ) {
			delete_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS );
		} else {
			update_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, $next );
		}
	}

	/**
	 * Idempotent one-shot: copy host typeExtras into Relation Settings when edge lacks them.
	 */
	private static function maybe_fold_type_extras( string $taxonomy ): void {
		$flags = get_option( self::OPTION_TYPE_EXTRAS_FOLDED, array() );
		if ( ! is_array( $flags ) ) {
			$flags = array();
		}
		if ( ! empty( $flags[ $taxonomy ] ) ) {
			return;
		}
		self::fold_type_extras_into_edges( $taxonomy );
		$flags[ $taxonomy ] = 1;
		update_option( self::OPTION_TYPE_EXTRAS_FOLDED, $flags, false );
	}

	/**
	 * After fold: drop host map entries whose normalized bag is fully covered by edge deltas.
	 * Keeps host-only / divergent keys as read fallback. Does not touch hide/readonly maps.
	 */
	private static function maybe_prune_redundant_type_extras( string $taxonomy ): void {
		$flags = get_option( self::OPTION_TYPE_EXTRAS_PRUNED, array() );
		if ( ! is_array( $flags ) ) {
			$flags = array();
		}
		if ( ! empty( $flags[ $taxonomy ] ) ) {
			return;
		}
		self::prune_redundant_type_extras( $taxonomy );
		$flags[ $taxonomy ] = 1;
		update_option( self::OPTION_TYPE_EXTRAS_PRUNED, $flags, false );
	}

	/**
	 * Idempotent one-shot: move own-attr RO/Hide from host maps onto Relation edge fields.
	 */
	private static function maybe_fold_edge_flags( string $taxonomy ): void {
		$flags = get_option( self::OPTION_EDGE_FLAGS_FOLDED, array() );
		if ( ! is_array( $flags ) ) {
			$flags = array();
		}
		if ( ! empty( $flags[ $taxonomy ] ) ) {
			return;
		}
		self::fold_edge_flags_into_edges( $taxonomy );
		$flags[ $taxonomy ] = 1;
		update_option( self::OPTION_EDGE_FLAGS_FOLDED, $flags, false );
	}

	/**
	 * Idempotent one-shot: move own-attr name-keyed defaults onto Relation edge.default.
	 */
	private static function maybe_fold_defaults( string $taxonomy ): void {
		$flags = get_option( self::OPTION_DEFAULTS_FOLDED, array() );
		if ( ! is_array( $flags ) ) {
			$flags = array();
		}
		if ( ! empty( $flags[ $taxonomy ] ) ) {
			return;
		}
		self::fold_defaults_into_edges( $taxonomy );
		$flags[ $taxonomy ] = 1;
		update_option( self::OPTION_DEFAULTS_FOLDED, $flags, false );
	}

	/**
	 * Idempotent one-shot: remove redundant own-attr host-map keys already on the edge.
	 * Does not fold new values; does not touch inherited override keys.
	 */
	private static function maybe_prune_host_maps( string $taxonomy ): void {
		$flags = get_option( self::OPTION_HOST_MAPS_PRUNED, array() );
		if ( ! is_array( $flags ) ) {
			$flags = array();
		}
		if ( ! empty( $flags[ $taxonomy ] ) ) {
			return;
		}
		self::prune_redundant_host_maps( $taxonomy );
		$flags[ $taxonomy ] = 1;
		update_option( self::OPTION_HOST_MAPS_PRUNED, $flags, false );
	}

	/**
	 * Idempotent one-shot: finish own-attr edge SoT so reads can drop host-map fallback.
	 * Folds Q105 Hide Mult debt (skipped by edge_flags_folded_v1) and clears leftover own keys.
	 */
	private static function maybe_fold_own_edge_read_sot( string $taxonomy ): void {
		$flags = get_option( self::OPTION_OWN_EDGE_READ_SOT, array() );
		if ( ! is_array( $flags ) ) {
			$flags = array();
		}
		if ( ! empty( $flags[ $taxonomy ] ) ) {
			return;
		}
		self::fold_own_edge_read_sot( $taxonomy );
		$flags[ $taxonomy ] = 1;
		update_option( self::OPTION_OWN_EDGE_READ_SOT, $flags, false );
	}

	/**
	 * Move remaining own host-map flags/bags onto Relation edges, then clear own keys.
	 *
	 * @return array{readonly:int,hidden:int,defaults:int,typeExtras:int}
	 */
	private static function fold_own_edge_read_sot( string $taxonomy ): array {
		$stats = array(
			'readonly'   => 0,
			'hidden'     => 0,
			'defaults'   => 0,
			'typeExtras' => 0,
		);
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $terms ) ) {
			return $stats;
		}

		foreach ( $terms as $host_id ) {
			$host_id = (int) $host_id;

			/** @var array<string, array<string, mixed>> $edges_by_id */
			$edges_by_id = array();
			/** @var array<string, array<string, mixed>> $edges_by_name */
			$edges_by_name = array();
			foreach ( Attribute::BINDINGS as $binding_key ) {
				foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $host_id, $binding_key ) as $edge ) {
					$eid = Attribute::normalize_attr_id( $edge['id'] ?? '' );
					if ( '' === $eid ) {
						continue;
					}
					$edges_by_id[ $eid ] = $edge;
					$ename               = Relation::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
					if ( '' !== $ename ) {
						$edges_by_name[ $ename ] = $edge;
					}
				}
			}
			if ( empty( $edges_by_id ) ) {
				continue;
			}

			foreach ( array_keys( Attribute::get_readonly_ids( $host_id ) ) as $attr_id ) {
				if ( ! isset( $edges_by_id[ $attr_id ] ) ) {
					continue;
				}
				$edge = $edges_by_id[ $attr_id ];
				if ( empty( $edge['readOnly'] ) && empty( $edge['readonly'] ) ) {
					$written = Relation::update_read_only( $taxonomy, $host_id, $attr_id, true );
					if ( is_wp_error( $written ) ) {
						continue;
					}
				}
				Attribute::clear_readonly_host_key( $host_id, $attr_id );
				++$stats['readonly'];
			}

			/*
			 * Hide: fold even when Mult ≠ 0..1 (Q105 debt → edge.hidden; validator/fixes remain).
			 * Prior fold skipped those keys; edge-only own reads require them on the edge.
			 */
			foreach ( array_keys( Attribute::get_hidden_ids( $host_id ) ) as $attr_id ) {
				if ( ! isset( $edges_by_id[ $attr_id ] ) ) {
					continue;
				}
				$edge = $edges_by_id[ $attr_id ];
				if ( empty( $edge['hidden'] ) ) {
					$written = Relation::update_hidden( $taxonomy, $host_id, $attr_id, true );
					if ( is_wp_error( $written ) ) {
						continue;
					}
				}
				Attribute::clear_hidden_host_key( $host_id, $attr_id );
				++$stats['hidden'];
			}

			$map = Attribute::get_fixed_values_host_map( $host_id );
			foreach ( $map as $name => $_val ) {
				$ename = Relation::normalize_edge_name( (string) $name );
				if ( '' === $ename || ! isset( $edges_by_name[ $ename ] ) ) {
					continue;
				}
				$edge    = $edges_by_name[ $ename ];
				$edge_id = Attribute::normalize_attr_id( $edge['id'] ?? '' );
				if ( '' === $edge_id ) {
					continue;
				}
				$has_default = array_key_exists( 'default', $edge ) || array_key_exists( 'defaultSeed', $edge );
				if ( ! $has_default ) {
					$seed    = Attribute::normalize_default_seed( $_val );
					$written = Relation::update_default( $taxonomy, $host_id, $edge_id, $seed );
					if ( is_wp_error( $written ) ) {
						continue;
					}
				}
				Attribute::clear_fixed_values_host_key( $host_id, $ename );
				++$stats['defaults'];
			}

			$tx_map = get_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, true );
			if ( ! is_array( $tx_map ) ) {
				$tx_map = array();
			}
			$tx_dirty = false;
			$tx_next  = array();
			foreach ( $tx_map as $key => $extras ) {
				$edge_id = Attribute::normalize_attr_id( $key );
				if ( '' === $edge_id || ! is_array( $extras ) ) {
					$tx_dirty = true;
					continue;
				}
				if ( ! isset( $edges_by_id[ $edge_id ] ) ) {
					/* Inherited / orphan keys stay on host map. */
					$tx_next[ $edge_id ] = $extras;
					if ( (string) $key !== $edge_id ) {
						$tx_dirty = true;
					}
					continue;
				}
				$edge        = $edges_by_id[ $edge_id ];
				$current     = isset( $edge['settings'] ) && is_array( $edge['settings'] ) ? $edge['settings'] : null;
				$edge_extras = Settings_Walk::type_extras_from_deltas( $current );
				$host_norm   = Attribute::normalize_type_extras( $extras );
				$host_only   = array();
				foreach ( $host_norm as $ek => $ev ) {
					if ( ! array_key_exists( $ek, $edge_extras ) ) {
						$host_only[ $ek ] = $ev;
					}
				}
				if ( ! empty( $host_only ) ) {
					$applied = Settings_Walk::apply_type_extras_to_settings( $current, $host_only );
					$written = Relation::update_settings( $taxonomy, $host_id, $edge_id, $applied );
					if ( is_wp_error( $written ) ) {
						$tx_next[ $edge_id ] = $extras;
						continue;
					}
				}
				$tx_dirty = true;
				++$stats['typeExtras'];
			}
			if ( $tx_dirty ) {
				if ( empty( $tx_next ) ) {
					delete_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS );
				} else {
					update_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, $tx_next );
				}
			}
		}

		return $stats;
	}

	/**
	 * Safe cleanup after edge folds:
	 * - Own RO/Hide host keys cleared only when edge.readOnly / edge.hidden already true.
	 * - Own fixed_values name keys cleared only when edge.default already present.
	 * - Empty `_wtt_attribute_type_extras` maps deleted; covered own keys dropped.
	 * - Inherited host-map entries (no local own edge) kept.
	 * - Own Hide host keys with Mult ≠ 0..1 and no edge.hidden left as Q105 debt.
	 *
	 * @return array{readonly:int,hidden:int,defaults:int,typeExtras:int,emptyTypeExtrasMaps:int,keptInherited:int}
	 */
	private static function prune_redundant_host_maps( string $taxonomy ): array {
		$stats = array(
			'readonly'             => 0,
			'hidden'               => 0,
			'defaults'             => 0,
			'typeExtras'           => 0,
			'emptyTypeExtrasMaps'  => 0,
			'keptInherited'        => 0,
		);
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $terms ) ) {
			return $stats;
		}

		foreach ( $terms as $host_id ) {
			$host_id = (int) $host_id;

			/** @var array<string, array<string, mixed>> $edges_by_id */
			$edges_by_id = array();
			/** @var array<string, array<string, mixed>> $edges_by_name */
			$edges_by_name = array();
			foreach ( Attribute::BINDINGS as $binding_key ) {
				foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $host_id, $binding_key ) as $edge ) {
					$eid = Attribute::normalize_attr_id( $edge['id'] ?? '' );
					if ( '' === $eid ) {
						continue;
					}
					$edges_by_id[ $eid ] = $edge;
					$name                = Relation::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
					if ( '' === $name ) {
						$to   = get_term( (int) ( $edge['toId'] ?? 0 ), $taxonomy );
						$name = $to instanceof \WP_Term ? Relation::normalize_edge_name( $to->name ) : '';
					}
					if ( '' !== $name && ! isset( $edges_by_name[ $name ] ) ) {
						$edges_by_name[ $name ] = $edge;
					}
				}
			}

			/* --- RO: own + edge.readOnly already true → drop host key --- */
			foreach ( array_keys( Attribute::get_readonly_ids( $host_id ) ) as $attr_id ) {
				if ( ! isset( $edges_by_id[ $attr_id ] ) ) {
					++$stats['keptInherited'];
					continue;
				}
				$edge = $edges_by_id[ $attr_id ];
				if ( empty( $edge['readOnly'] ) && empty( $edge['readonly'] ) ) {
					/* Edge not yet SoT — leave host key (fold debt / intentional). */
					continue;
				}
				Attribute::clear_readonly_host_key( $host_id, $attr_id );
				++$stats['readonly'];
			}

			/* --- Hide: own + edge.hidden already true → drop host key --- */
			foreach ( array_keys( Attribute::get_hidden_ids( $host_id ) ) as $attr_id ) {
				if ( ! isset( $edges_by_id[ $attr_id ] ) ) {
					++$stats['keptInherited'];
					continue;
				}
				$edge = $edges_by_id[ $attr_id ];
				if ( empty( $edge['hidden'] ) ) {
					/* Q105 Mult debt or not folded — keep host key. */
					continue;
				}
				Attribute::clear_hidden_host_key( $host_id, $attr_id );
				++$stats['hidden'];
			}

			/* --- Defaults: own name + edge.default present → drop host name key --- */
			$map = Attribute::get_fixed_values_host_map( $host_id );
			foreach ( $map as $name => $_raw ) {
				$name = is_string( $name ) ? Relation::normalize_edge_name( $name ) : '';
				if ( '' === $name ) {
					continue;
				}
				if ( ! isset( $edges_by_name[ $name ] ) ) {
					++$stats['keptInherited'];
					continue;
				}
				$edge = $edges_by_name[ $name ];
				if ( ! array_key_exists( 'default', $edge ) && ! array_key_exists( 'defaultSeed', $edge ) ) {
					continue;
				}
				Attribute::clear_fixed_values_host_key( $host_id, $name );
				++$stats['defaults'];
			}

			/* --- typeExtras: drop covered own keys; delete empty maps --- */
			$extras_raw = get_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, true );
			if ( ! is_array( $extras_raw ) ) {
				continue;
			}
			if ( array() === $extras_raw ) {
				delete_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS );
				++$stats['emptyTypeExtrasMaps'];
				continue;
			}

			$next_map  = array();
			$map_dirty = false;
			foreach ( $extras_raw as $key => $extras ) {
				if ( ! is_array( $extras ) ) {
					$map_dirty = true;
					++$stats['typeExtras'];
					continue;
				}
				$edge_id = Attribute::normalize_attr_id( $key );
				if ( '' === $edge_id || ! isset( $edges_by_id[ $edge_id ] ) ) {
					/* Inherited override / orphan non-own — keep. */
					$keep_key             = '' !== $edge_id ? $edge_id : (string) $key;
					$next_map[ $keep_key ] = $extras;
					if ( (string) $key !== $keep_key ) {
						$map_dirty = true;
					}
					++$stats['keptInherited'];
					continue;
				}
				$edge        = $edges_by_id[ $edge_id ];
				$current     = isset( $edge['settings'] ) && is_array( $edge['settings'] ) ? $edge['settings'] : null;
				$edge_extras = Settings_Walk::type_extras_from_deltas( $current );
				$host_norm   = Attribute::normalize_type_extras( $extras );
				if ( Settings_Walk::host_type_extras_covered_by_edge( $host_norm, $edge_extras ) ) {
					$map_dirty = true;
					++$stats['typeExtras'];
					continue;
				}
				$next_map[ $edge_id ] = $host_norm;
				if ( (string) $key !== $edge_id || $host_norm !== $extras ) {
					$map_dirty = true;
				}
			}
			if ( $map_dirty || empty( $next_map ) ) {
				if ( empty( $next_map ) ) {
					delete_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS );
					++$stats['emptyTypeExtrasMaps'];
				} else {
					update_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, $next_map );
				}
			}
		}

		return $stats;
	}

	/**
	 * For each host name-map key matching an own attribute edge name: write edge.default and clear key.
	 * Names without a local own edge stay on the host map (inherited overrides / orphans).
	 * When edge already has `default`, host key is cleared without overwrite.
	 *
	 * @return array{folded:int,clearedOnly:int,keptInherited:int}
	 */
	private static function fold_defaults_into_edges( string $taxonomy ): array {
		$stats = array(
			'folded'         => 0,
			'clearedOnly'    => 0,
			'keptInherited'  => 0,
		);
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $terms ) ) {
			return $stats;
		}
		foreach ( $terms as $host_id ) {
			$host_id = (int) $host_id;
			$map     = Attribute::get_fixed_values_host_map( $host_id );
			if ( empty( $map ) ) {
				continue;
			}

			/** @var array<string, array<string, mixed>> $by_name */
			$by_name = array();
			foreach ( Attribute::BINDINGS as $binding_key ) {
				foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $host_id, $binding_key ) as $edge ) {
					$eid  = Attribute::normalize_attr_id( $edge['id'] ?? '' );
					$name = Relation::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
					if ( '' === $eid ) {
						continue;
					}
					if ( '' === $name ) {
						$to = get_term( (int) ( $edge['toId'] ?? 0 ), $taxonomy );
						$name = $to instanceof \WP_Term ? $to->name : '';
					}
					if ( '' === $name || isset( $by_name[ $name ] ) ) {
						continue;
					}
					$by_name[ $name ] = $edge;
				}
			}

			foreach ( $map as $name => $raw ) {
				$name = is_string( $name ) ? Relation::normalize_edge_name( $name ) : '';
				if ( '' === $name ) {
					continue;
				}
				if ( ! isset( $by_name[ $name ] ) ) {
					++$stats['keptInherited'];
					continue;
				}
				$edge    = $by_name[ $name ];
				$edge_id = Attribute::normalize_attr_id( $edge['id'] ?? '' );
				if ( '' === $edge_id ) {
					continue;
				}
				$normalized = Attribute::normalize_default_seed( $raw );
				$has_edge   = array_key_exists( 'default', $edge ) || array_key_exists( 'defaultSeed', $edge );
				if ( ! $has_edge && ! empty( $normalized ) ) {
					$written = Relation::update_default( $taxonomy, $host_id, $edge_id, $normalized );
					if ( is_wp_error( $written ) ) {
						continue;
					}
					++$stats['folded'];
				} elseif ( $has_edge ) {
					++$stats['clearedOnly'];
				} elseif ( empty( $normalized ) ) {
					/* Empty host value for own name — drop stale key. */
					++$stats['clearedOnly'];
				} else {
					continue;
				}
				Attribute::clear_fixed_values_host_key( $host_id, $name );
			}
		}
		return $stats;
	}

	/**
	 * For each host map id that matches an own attribute edge: set edge flag and clear map key.
	 * Inherited-only keys (no local edge) stay on the host map.
	 * Own Hide fold skipped when Mult ≠ 0..1 (Q105) — key left on host map as debt.
	 *
	 * @return array{readonly:int,hidden:int,hiddenSkipped:int}
	 */
	private static function fold_edge_flags_into_edges( string $taxonomy ): array {
		$stats = array(
			'readonly'       => 0,
			'hidden'         => 0,
			'hiddenSkipped'  => 0,
		);
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $terms ) ) {
			return $stats;
		}
		foreach ( $terms as $host_id ) {
			$host_id = (int) $host_id;
			$own_ids = array();
			$edges   = array();
			foreach ( Attribute::BINDINGS as $binding_key ) {
				foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $host_id, $binding_key ) as $edge ) {
					$eid = Attribute::normalize_attr_id( $edge['id'] ?? '' );
					if ( '' === $eid ) {
						continue;
					}
					$own_ids[ $eid ] = true;
					$edges[ $eid ]   = $edge;
				}
			}
			if ( empty( $own_ids ) ) {
				continue;
			}

			$ro_ids = Attribute::get_readonly_ids( $host_id );
			foreach ( array_keys( $ro_ids ) as $attr_id ) {
				if ( ! isset( $own_ids[ $attr_id ] ) ) {
					continue;
				}
				$edge = $edges[ $attr_id ];
				if ( empty( $edge['readOnly'] ) && empty( $edge['readonly'] ) ) {
					$written = Relation::update_read_only( $taxonomy, $host_id, $attr_id, true );
					if ( is_wp_error( $written ) ) {
						continue;
					}
				}
				Attribute::clear_readonly_host_key( $host_id, $attr_id );
				++$stats['readonly'];
			}

			$hidden_ids = Attribute::get_hidden_ids( $host_id );
			foreach ( array_keys( $hidden_ids ) as $attr_id ) {
				if ( ! isset( $own_ids[ $attr_id ] ) ) {
					continue;
				}
				$edge = $edges[ $attr_id ];
				$mult = Relation::normalize_multiplicity(
					(string) ( $edge['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY )
				);
				if ( Attribute::BACKGROUND_ONLY_MULTIPLICITY !== $mult ) {
					++$stats['hiddenSkipped'];
					continue;
				}
				if ( empty( $edge['hidden'] ) ) {
					$written = Relation::update_hidden( $taxonomy, $host_id, $attr_id, true );
					if ( is_wp_error( $written ) ) {
						continue;
					}
				}
				Attribute::clear_hidden_host_key( $host_id, $attr_id );
				++$stats['hidden'];
			}
		}
		return $stats;
	}

	/**
	 * @return int Number of host map keys removed.
	 */
	private static function prune_redundant_type_extras( string $taxonomy ): int {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $terms ) ) {
			return 0;
		}
		$removed = 0;
		foreach ( $terms as $host_id ) {
			$host_id = (int) $host_id;
			$map     = get_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, true );
			if ( ! is_array( $map ) || array() === $map ) {
				continue;
			}
			$edges_by_id = array();
			foreach ( Attribute::BINDINGS as $binding_key ) {
				foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $host_id, $binding_key ) as $candidate ) {
					$eid = Attribute::normalize_attr_id( $candidate['id'] ?? '' );
					if ( '' !== $eid ) {
						$edges_by_id[ $eid ] = $candidate;
					}
				}
			}
			$next_map  = array();
			$map_dirty = false;
			foreach ( $map as $key => $extras ) {
				if ( ! is_array( $extras ) ) {
					$map_dirty = true;
					++$removed;
					continue;
				}
				$edge_id = Attribute::normalize_attr_id( $key );
				if ( '' === $edge_id || ! isset( $edges_by_id[ $edge_id ] ) ) {
					/* Orphan / non-own key: leave for read fallback (inherited override). */
					$next_map[ $edge_id !== '' ? $edge_id : (string) $key ] = $extras;
					if ( (string) $key !== $edge_id && '' !== $edge_id ) {
						$map_dirty = true;
					}
					continue;
				}
				$edge        = $edges_by_id[ $edge_id ];
				$current     = isset( $edge['settings'] ) && is_array( $edge['settings'] ) ? $edge['settings'] : null;
				$edge_extras = Settings_Walk::type_extras_from_deltas( $current );
				$host_norm   = Attribute::normalize_type_extras( $extras );
				if ( Settings_Walk::host_type_extras_covered_by_edge( $host_norm, $edge_extras ) ) {
					$map_dirty = true;
					++$removed;
					continue;
				}
				$next_map[ $edge_id ] = $host_norm;
				if ( (string) $key !== $edge_id || $host_norm !== $extras ) {
					$map_dirty = true;
				}
			}
			if ( $map_dirty ) {
				if ( empty( $next_map ) ) {
					delete_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS );
				} else {
					update_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, $next_map );
				}
			}
		}
		return $removed;
	}

	/**
	 * For each host map entry keyed by edge id, merge missing Settings.data/view keys onto the edge.
	 * Does not delete the host map (debt fallback). Drops orphan numeric keys that match no edge.
	 */
	private static function fold_type_extras_into_edges( string $taxonomy ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $terms ) ) {
			return;
		}
		foreach ( $terms as $host_id ) {
			$host_id = (int) $host_id;
			$map     = get_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, true );
			if ( ! is_array( $map ) || array() === $map ) {
				continue;
			}
			$edges_by_id = array();
			foreach ( Attribute::BINDINGS as $binding_key ) {
				foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $host_id, $binding_key ) as $candidate ) {
					$eid = Attribute::normalize_attr_id( $candidate['id'] ?? '' );
					if ( '' !== $eid ) {
						$edges_by_id[ $eid ] = $candidate;
					}
				}
			}
			$next_map = array();
			$map_dirty = false;
			foreach ( $map as $key => $extras ) {
				if ( ! is_array( $extras ) ) {
					$map_dirty = true;
					continue;
				}
				$edge_id = Attribute::normalize_attr_id( $key );
				if ( '' === $edge_id ) {
					$map_dirty = true;
					continue;
				}
				/* Orphan legacy slot id (no matching edge) — drop from host map. */
				if ( ! isset( $edges_by_id[ $edge_id ] ) ) {
					$map_dirty = true;
					continue;
				}
				$next_map[ $edge_id ] = $extras;
				if ( (string) $key !== $edge_id ) {
					$map_dirty = true;
				}

				$edge        = $edges_by_id[ $edge_id ];
				$current     = isset( $edge['settings'] ) && is_array( $edge['settings'] ) ? $edge['settings'] : null;
				$edge_extras = Settings_Walk::type_extras_from_deltas( $current );
				$host_norm   = Attribute::normalize_type_extras( $extras );
				$host_only   = array();
				foreach ( $host_norm as $ek => $ev ) {
					if ( ! array_key_exists( $ek, $edge_extras ) ) {
						$host_only[ $ek ] = $ev;
					}
				}
				if ( empty( $host_only ) ) {
					continue;
				}
				$applied = Settings_Walk::apply_type_extras_to_settings(
					$current,
					array_merge( $edge_extras, $host_only )
				);
				Relation::update_settings( $taxonomy, $host_id, $edge_id, $applied );
			}
			if ( $map_dirty ) {
				if ( empty( $next_map ) ) {
					delete_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS );
				} else {
					update_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, $next_map );
				}
			}
		}
	}

	/**
	 * @param array<int, string> $slot_to_edge
	 */
	private static function remap_model_data_keys( string $taxonomy, array $slot_to_edge ): int {
		if ( ! class_exists( Model_Data::class ) || empty( $slot_to_edge ) ) {
			return 0;
		}
		$option = Model_Data::OPTION_KEY;
		$all    = get_option( $option, array() );
		if ( ! is_array( $all ) ) {
			return 0;
		}
		$changed = 0;
		$prefix  = $taxonomy . ':';
		foreach ( $all as $bag_key => $bag ) {
			if ( ! is_string( $bag_key ) || 0 !== strpos( $bag_key, $prefix ) || ! is_array( $bag ) ) {
				continue;
			}
			if ( empty( $bag['instances'] ) || ! is_array( $bag['instances'] ) ) {
				continue;
			}
			foreach ( $bag['instances'] as $iid => $inst ) {
				if ( ! is_array( $inst ) || empty( $inst['values'] ) || ! is_array( $inst['values'] ) ) {
					continue;
				}
				$values = $inst['values'];
				$next   = array();
				$dirty  = false;
				foreach ( $values as $vk => $vv ) {
					$slot = (int) $vk;
					if ( $slot > 0 && isset( $slot_to_edge[ $slot ] ) ) {
						$next[ $slot_to_edge[ $slot ] ] = $vv;
						$dirty                          = true;
						++$changed;
					} else {
						$next[ (string) $vk ] = $vv;
					}
				}
				if ( $dirty ) {
					$all[ $bag_key ]['instances'][ $iid ]['values'] = $next;
				}
			}
		}
		if ( $changed > 0 ) {
			update_option( $option, $all, false );
		}
		return $changed;
	}

	/**
	 * True when any Relation edge still points at this term as toId
	 * (attribute binding or otherwise).
	 */
	private static function slot_still_targeted( string $taxonomy, int $slot_id ): bool {
		$incoming = Relation::list_incoming( $taxonomy, $slot_id );
		return array() !== $incoming;
	}

	/**
	 * Safe hard-delete candidate: leftover slot, not parked band, not catalog, not edge toId.
	 */
	private static function slot_safe_to_purge( string $taxonomy, int $slot_id ): bool {
		if ( $slot_id <= 0 || ! Attribute::is_slot( $slot_id ) ) {
			return false;
		}
		if ( self::is_parked_table_band_slot( $taxonomy, $slot_id ) ) {
			return false;
		}
		if ( Node_Type::is_under_type_catalog( $taxonomy, $slot_id ) ) {
			return false;
		}
		if ( self::slot_still_targeted( $taxonomy, $slot_id ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Q90 parked table band leftovers (Zeile/Kopf/Fuss and English aliases).
	 */
	private static function is_parked_table_band_slot( string $taxonomy, int $slot_id ): bool {
		return Attribute::is_parked_table_band_term( $taxonomy, $slot_id );
	}

	/**
	 * Ensure parked band edges carry Relation.name = Zeile|Kopf|Fuss (term name).
	 *
	 * @return array{named:int}
	 */
	public static function maybe_name_parked_band_edges( string $taxonomy ): array {
		$empty = array( 'named' => 0 );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $empty;
		}
		$flags = get_option( self::OPTION_PARKED_BAND_NAMES, array() );
		if ( ! is_array( $flags ) ) {
			$flags = array();
		}
		if ( ! empty( $flags[ $taxonomy ] ) ) {
			return $empty;
		}

		$named = self::name_parked_band_edges( $taxonomy );
		$flags[ $taxonomy ] = 1;
		update_option( self::OPTION_PARKED_BAND_NAMES, $flags, false );
		return array( 'named' => $named );
	}

	/**
	 * Idempotent write: empty/mismatched names on parked band edges → term name.
	 */
	public static function name_parked_band_edges( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $terms ) ) {
			return 0;
		}

		$named = 0;
		foreach ( $terms as $host_id ) {
			$host_id = (int) $host_id;
			if ( $host_id <= 0 ) {
				continue;
			}
			foreach ( Relation::list_outgoing( $taxonomy, $host_id ) as $edge ) {
				if ( ! is_array( $edge ) || ! Attribute::is_parked_table_band_edge( $taxonomy, $edge ) ) {
					continue;
				}
				$to_id   = (int) ( $edge['toId'] ?? 0 );
				$edge_id = sanitize_key( (string) ( $edge['id'] ?? '' ) );
				$term    = $to_id > 0 ? get_term( $to_id, $taxonomy ) : null;
				$want    = $term instanceof \WP_Term
					? Relation::normalize_edge_name( $term->name )
					: '';
				$have = Relation::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
				if ( '' === $edge_id || '' === $want || $have === $want ) {
					continue;
				}
				$result = Relation::update_name( $taxonomy, $host_id, $edge_id, $want );
				if ( ! is_wp_error( $result ) ) {
					++$named;
				}
			}
		}
		return $named;
	}
}
