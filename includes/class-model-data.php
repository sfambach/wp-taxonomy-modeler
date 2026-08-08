<?php
/**
 * Instance data store for Fill Model Data (scaffold).
 *
 * Taxonomy terms define structures (hosts + attributes). This service stores
 * filled instances separately — attributes are not the instances.
 *
 * Persistence: one WP option keyed by taxonomy + structure term id.
 *
 * Identity rule (per host bag `taxonomy:structureId`):
 * - `seq` — running number, assigned on create as max(seq)+1 within the bag (never reused).
 * - `id` — opaque string key (`md_…`), stable primary key.
 * - `createdAt` — ISO-8601 UTC, set once on create.
 * - `version` — starts at 1; increments when an existing instance is saved with
 *   changed `values` (meaningful update). Identical re-saves keep the version.
 * - `modelVersion` — schema stamp from the structure host (`_wtt_model_version`);
 *   set on create/save to the current host version (UR-S1). Missing stamps
 *   backfill to 1 on load. Distinct from instance `version` (row revision).
 * - `modifiedAt` / `modifiedBy` — last save time (ISO-8601 UTC) and WP user id.
 *
 * Orphan values (UR-S1): on save, attribute keys no longer in the allowed
 * schema are retained under `values` so removing a field does not wipe data.
 * TODO: optional reserved `orphans` bag / mapping UI for renamed attrs.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for structure-bound instance rows (JSON option).
 *
 * OQ-B1 / Q97: instance data lives under the model object (structure bag).
 * Parent instances link children via `links[]` (besteht_aus | aggregation).
 * Composition delete soft-trashes father + linked children; aggregation leaves children.
 */
final class Model_Data {

	/** Option holding all instance bags. */
	public const OPTION_KEY = 'wtt_model_instances';

	/** Instance link = composition (dies with parent). */
	public const LINK_COMPOSITION = 'besteht_aus';

	/** Instance link = aggregation (children remain when parent trashed). */
	public const LINK_AGGREGATION = 'aggregation';

	/**
	 * Bag key for a structure host.
	 */
	public static function bag_key( string $taxonomy, int $structure_id ): string {
		return sanitize_key( $taxonomy ) . ':' . absint( $structure_id );
	}

	/**
	 * Normalize instance relation key (composition alias → besteht_aus).
	 */
	public static function normalize_link_relation( string $relation ): string {
		$key = strtolower( trim( $relation ) );
		if ( 'composition' === $key || Relation::TYPE_COMPOSITION === $key || Relation::TYPE_COMPOSITION_LEGACY === $key ) {
			return self::LINK_COMPOSITION;
		}
		if ( Relation::TYPE_AGGREGATION === $key || self::LINK_AGGREGATION === $key ) {
			return self::LINK_AGGREGATION;
		}
		return self::LINK_COMPOSITION;
	}

	/**
	 * Non-trashed (or all) instance counts keyed by structure id — one option read.
	 *
	 * @return array<int, int> structureId => count
	 */
	public static function counts_by_structure( string $taxonomy, bool $include_trashed = false ): array {
		if ( ! Taxonomy::is_scaffold( $taxonomy ) ) {
			return array();
		}

		$prefix = sanitize_key( $taxonomy ) . ':';
		$plen   = strlen( $prefix );
		$all    = self::load_all();
		$out    = array();

		foreach ( $all as $key => $bag ) {
			if ( ! is_string( $key ) || 0 !== strpos( $key, $prefix ) ) {
				continue;
			}
			$structure_id = absint( substr( $key, $plen ) );
			if ( $structure_id <= 0 || ! is_array( $bag ) ) {
				continue;
			}
			$changed = false;
			$rows    = self::normalize_bag( $bag, $changed );
			if ( ! $include_trashed ) {
				$rows = array_values(
					array_filter(
						$rows,
						static function ( array $row ): bool {
							return empty( $row['trashed'] );
						}
					)
				);
			}
			$out[ $structure_id ] = count( $rows );
		}

		return $out;
	}

	/**
	 * Structure host ids that have a non-empty attribute schema (Fill Model Data targets).
	 *
	 * @return array<int, true> structureId => true
	 */
	public static function structure_host_id_set( string $taxonomy ): array {
		$set = array();
		foreach ( self::list_structure_hosts( $taxonomy ) as $host ) {
			$id = (int) ( $host['id'] ?? 0 );
			if ( $id > 0 && (int) ( $host['attributeCount'] ?? 0 ) > 0 ) {
				$set[ $id ] = true;
			}
		}
		return $set;
	}

	/**
	 * List instances for a structure node (highest seq first).
	 *
	 * @param bool $include_trashed When false (default), soft-trashed rows are omitted.
	 * @return list<array<string, mixed>>
	 */
	public static function list( string $taxonomy, int $structure_id, bool $include_trashed = false ): array {
		if ( $structure_id <= 0 || ! Taxonomy::is_scaffold( $taxonomy ) ) {
			return array();
		}

		$all     = self::load_all();
		$key     = self::bag_key( $taxonomy, $structure_id );
		$bag     = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
		$changed = false;
		$out     = self::normalize_bag( $bag, $changed );

		if ( $changed ) {
			$all[ $key ] = self::rows_for_persist( $out );
			self::persist_all( $all );
		}

		if ( ! $include_trashed ) {
			$out = array_values(
				array_filter(
					$out,
					static function ( array $row ): bool {
						return empty( $row['trashed'] );
					}
				)
			);
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				$sa = (int) ( $a['seq'] ?? 0 );
				$sb = (int) ( $b['seq'] ?? 0 );
				if ( $sa !== $sb ) {
					return $sb <=> $sa;
				}
				return strcmp( (string) ( $b['modifiedAt'] ?? '' ), (string) ( $a['modifiedAt'] ?? '' ) );
			}
		);

		return $out;
	}

	/**
	 * @param bool $include_trashed Include soft-trashed rows.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $taxonomy, int $structure_id, string $instance_id, bool $include_trashed = true ): ?array {
		$instance_id = sanitize_key( $instance_id );
		if ( '' === $instance_id ) {
			return null;
		}
		foreach ( self::list( $taxonomy, $structure_id, $include_trashed ) as $row ) {
			if ( (string) ( $row['id'] ?? '' ) === $instance_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Create or update an instance.
	 *
	 * @param array<string, mixed> $payload id?, values (attr id → string). Name is ignored.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function save( string $taxonomy, int $structure_id, array $payload ) {
		if ( ! Taxonomy::is_scaffold( $taxonomy ) || $structure_id <= 0 ) {
			return new \WP_Error( 'wtt_bad_structure', __( 'Invalid structure node.', 'wp-taxonomy-tree' ) );
		}

		$term = get_term( $structure_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
			return new \WP_Error( 'wtt_bad_structure', __( 'Structure node not found.', 'wp-taxonomy-tree' ) );
		}

		/*
		 * TODO(Q107 / UR-B6): Collect Mult=`1` / required empty attrs (e.g. Position.Wert)
		 * into envelope `{ ok, errors[], warnings[], fixes[] }`. Data-entry save must still
		 * persist drafts and keep the red ! badge; schema admin may block. Client already
		 * paints embed required-empty badge in WTTObjectRender.
		 */

		$allowed_attrs = self::allowed_attribute_ids( $taxonomy, $structure_id );
		$raw_values    = isset( $payload['values'] ) && is_array( $payload['values'] ) ? $payload['values'] : array();
		/* Related Mult many datasets live in links[] — never store inline blobs on the parent. */
		foreach ( array_keys( self::related_dataset_attr_ids( $taxonomy, $structure_id ) ) as $skip_id ) {
			unset( $allowed_attrs[ (int) $skip_id ] );
		}
		$values = self::sanitize_values( $raw_values, $allowed_attrs );

		$all     = self::load_all();
		$key     = self::bag_key( $taxonomy, $structure_id );
		$bag     = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
		$changed = false;
		$rows    = self::normalize_bag( $bag, $changed );

		$id = isset( $payload['id'] ) ? sanitize_key( (string) $payload['id'] ) : '';
		$now = gmdate( 'c' );
		$user_id = get_current_user_id();
		$user_name = self::user_display_name( $user_id );
		$model_version = Model_Version::get( $taxonomy, $structure_id );

		$existing_index = null;
		$existing       = null;
		if ( '' !== $id ) {
			foreach ( $rows as $i => $row ) {
				if ( (string) ( $row['id'] ?? '' ) === $id ) {
					$existing_index = $i;
					$existing       = $row;
					break;
				}
			}
		}

		if ( null === $existing ) {
			/* Q106: seed scalar default templates into empty slots on create. */
			$values = self::merge_scalar_defaults( $taxonomy, $structure_id, $values );
			$id     = '' !== $id ? $id : self::new_id();
			$seq    = self::next_seq( $rows );
			$row    = self::enrich_labels(
				array(
					'id'             => $id,
					'seq'            => $seq,
					'createdAt'      => $now,
					'version'        => 1,
					'modelVersion'   => $model_version,
					'modifiedAt'     => $now,
					'modifiedBy'     => $user_id,
					'modifiedByName' => $user_name,
					'values'         => $values,
					'links'          => array(),
					'trashed'        => false,
				)
			);
			$rows[]      = $row;
			$all[ $key ] = self::rows_for_persist( $rows );
			self::persist_all( $all );
			/* Related Mult nested default maps → create_linked children (when templates exist). */
			self::materialize_related_defaults( $taxonomy, $structure_id, $id );
			$fresh = self::get( $taxonomy, $structure_id, $id, true );
			return null !== $fresh ? $fresh : $row;
		} else {
			$prev_values = isset( $existing['values'] ) && is_array( $existing['values'] ) ? $existing['values'] : array();
			/* Keep keys removed from the schema so field delete does not wipe data (UR-S1). */
			$values      = self::merge_orphan_values( $values, $prev_values, $allowed_attrs );
			$version     = (int) ( $existing['version'] ?? 1 );
			if ( $version < 1 ) {
				$version = 1;
			}
			/* Instance revision bumps only when attribute values change. */
			if ( self::values_differ( $prev_values, $values ) ) {
				++$version;
			}
			$links = isset( $existing['links'] ) && is_array( $existing['links'] )
				? self::normalize_links( $existing['links'] )
				: array();
			$row = self::enrich_labels(
				array(
					'id'             => (string) $existing['id'],
					'seq'            => (int) ( $existing['seq'] ?? 0 ),
					'createdAt'      => (string) ( $existing['createdAt'] ?? $now ),
					'version'        => $version,
					'modelVersion'   => $model_version,
					'modifiedAt'     => $now,
					'modifiedBy'     => $user_id,
					'modifiedByName' => $user_name,
					'values'         => $values,
					'links'          => $links,
					'trashed'        => ! empty( $existing['trashed'] ),
				)
			);
			$rows[ $existing_index ] = $row;
		}

		$all[ $key ] = self::rows_for_persist( $rows );
		self::persist_all( $all );

		return $row;
	}

	/**
	 * Soft-delete an instance (OQ-B1). Composition-linked children are soft-trashed too;
	 * aggregation-linked children remain. Pass $purge=true to hard-delete (Empty Trash).
	 *
	 * @return true|\WP_Error
	 */
	public static function delete( string $taxonomy, int $structure_id, string $instance_id, bool $purge = false ) {
		$instance_id = sanitize_key( $instance_id );
		if ( '' === $instance_id ) {
			return new \WP_Error( 'wtt_bad_id', __( 'Invalid instance id.', 'wp-taxonomy-tree' ) );
		}

		$row = self::get( $taxonomy, $structure_id, $instance_id, true );
		if ( null === $row ) {
			return new \WP_Error( 'wtt_not_found', __( 'Instance not found.', 'wp-taxonomy-tree' ) );
		}

		if ( $purge ) {
			return self::purge_instance( $taxonomy, $structure_id, $instance_id );
		}

		$links = isset( $row['links'] ) && is_array( $row['links'] ) ? self::normalize_links( $row['links'] ) : array();
		foreach ( $links as $link ) {
			$rel = self::normalize_link_relation( (string) ( $link['relation'] ?? '' ) );
			if ( self::LINK_COMPOSITION !== $rel ) {
				continue;
			}
			$child_struct = (int) ( $link['structureId'] ?? 0 );
			$child_id     = sanitize_key( (string) ( $link['instanceId'] ?? '' ) );
			if ( $child_struct <= 0 || '' === $child_id ) {
				continue;
			}
			self::mark_trashed( $taxonomy, $child_struct, $child_id, true );
		}

		return self::mark_trashed( $taxonomy, $structure_id, $instance_id, true );
	}

	/**
	 * Restore a soft-trashed instance and composition-linked children.
	 *
	 * @return true|\WP_Error
	 */
	public static function restore( string $taxonomy, int $structure_id, string $instance_id ) {
		$instance_id = sanitize_key( $instance_id );
		$row         = self::get( $taxonomy, $structure_id, $instance_id, true );
		if ( null === $row ) {
			return new \WP_Error( 'wtt_not_found', __( 'Instance not found.', 'wp-taxonomy-tree' ) );
		}

		$links = isset( $row['links'] ) && is_array( $row['links'] ) ? self::normalize_links( $row['links'] ) : array();
		foreach ( $links as $link ) {
			$rel = self::normalize_link_relation( (string) ( $link['relation'] ?? '' ) );
			if ( self::LINK_COMPOSITION !== $rel ) {
				continue;
			}
			$child_struct = (int) ( $link['structureId'] ?? 0 );
			$child_id     = sanitize_key( (string) ( $link['instanceId'] ?? '' ) );
			if ( $child_struct <= 0 || '' === $child_id ) {
				continue;
			}
			self::mark_trashed( $taxonomy, $child_struct, $child_id, false );
		}

		return self::mark_trashed( $taxonomy, $structure_id, $instance_id, false );
	}

	/**
	 * Link a child instance to a parent (composition or aggregation). Idempotent.
	 *
	 * @return array<string, mixed>|\WP_Error Parent row after link.
	 */
	public static function link(
		string $taxonomy,
		int $parent_structure_id,
		string $parent_instance_id,
		int $child_structure_id,
		string $child_instance_id,
		string $relation = self::LINK_COMPOSITION
	) {
		$parent_instance_id = sanitize_key( $parent_instance_id );
		$child_instance_id  = sanitize_key( $child_instance_id );
		$relation           = self::normalize_link_relation( $relation );

		if ( $parent_structure_id <= 0 || $child_structure_id <= 0 || '' === $parent_instance_id || '' === $child_instance_id ) {
			return new \WP_Error( 'wtt_bad_link', __( 'Invalid instance link.', 'wp-taxonomy-tree' ) );
		}
		if ( null === self::get( $taxonomy, $parent_structure_id, $parent_instance_id, true ) ) {
			return new \WP_Error( 'wtt_not_found', __( 'Parent instance not found.', 'wp-taxonomy-tree' ) );
		}
		if ( null === self::get( $taxonomy, $child_structure_id, $child_instance_id, true ) ) {
			return new \WP_Error( 'wtt_not_found', __( 'Child instance not found.', 'wp-taxonomy-tree' ) );
		}

		$all     = self::load_all();
		$key     = self::bag_key( $taxonomy, $parent_structure_id );
		$changed = false;
		$rows    = self::normalize_bag(
			isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array(),
			$changed
		);

		$found = false;
		foreach ( $rows as $i => $row ) {
			if ( (string) ( $row['id'] ?? '' ) !== $parent_instance_id ) {
				continue;
			}
			$found = true;
			$links = isset( $row['links'] ) && is_array( $row['links'] ) ? self::normalize_links( $row['links'] ) : array();
			$dup   = false;
			foreach ( $links as $link ) {
				if (
					(int) ( $link['structureId'] ?? 0 ) === $child_structure_id
					&& (string) ( $link['instanceId'] ?? '' ) === $child_instance_id
				) {
					$dup = true;
					/* Upgrade / keep relation on the existing edge. */
					$link['relation'] = $relation;
					break;
				}
			}
			if ( ! $dup ) {
				$links[] = array(
					'relation'    => $relation,
					'structureId' => $child_structure_id,
					'instanceId'  => $child_instance_id,
				);
			} else {
				$fresh = array();
				foreach ( $links as $link ) {
					if (
						(int) ( $link['structureId'] ?? 0 ) === $child_structure_id
						&& (string) ( $link['instanceId'] ?? '' ) === $child_instance_id
					) {
						$link['relation'] = $relation;
					}
					$fresh[] = $link;
				}
				$links = $fresh;
			}
			$rows[ $i ]['links']      = $links;
			$rows[ $i ]['modifiedAt'] = gmdate( 'c' );
			$rows[ $i ]               = self::enrich_labels( $rows[ $i ] );
			$all[ $key ]              = self::rows_for_persist( $rows );
			self::persist_all( $all );
			return $rows[ $i ];
		}

		if ( ! $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Parent instance not found.', 'wp-taxonomy-tree' ) );
		}
		return new \WP_Error( 'wtt_link_failed', __( 'Could not link instances.', 'wp-taxonomy-tree' ) );
	}

	/**
	 * Related child instances for a parent (BOM lines via composition, etc.).
	 *
	 * @param string $relation_filter Empty = all; besteht_aus|aggregation to filter.
	 * @param int    $child_structure_id When > 0, only links to that structure (e.g. Position).
	 * @return list<array<string, mixed>> Each: link meta + child `instance` row + structureId.
	 */
	public static function list_related(
		string $taxonomy,
		int $parent_structure_id,
		string $parent_instance_id,
		string $relation_filter = '',
		int $child_structure_id = 0
	): array {
		$parent = self::get( $taxonomy, $parent_structure_id, $parent_instance_id, true );
		if ( null === $parent ) {
			return array();
		}
		$filter = '' !== $relation_filter ? self::normalize_link_relation( $relation_filter ) : '';
		$links  = isset( $parent['links'] ) && is_array( $parent['links'] )
			? self::normalize_links( $parent['links'] )
			: array();
		$out    = array();
		foreach ( $links as $link ) {
			$rel = self::normalize_link_relation( (string) ( $link['relation'] ?? '' ) );
			if ( '' !== $filter && $rel !== $filter ) {
				continue;
			}
			$sid = (int) ( $link['structureId'] ?? 0 );
			$iid = sanitize_key( (string) ( $link['instanceId'] ?? '' ) );
			if ( $sid <= 0 || '' === $iid ) {
				continue;
			}
			if ( $child_structure_id > 0 && $sid !== $child_structure_id ) {
				continue;
			}
			$child = self::get( $taxonomy, $sid, $iid, true );
			if ( null === $child || ! empty( $child['trashed'] ) ) {
				continue;
			}
			$out[] = array(
				'relation'    => $rel,
				'structureId' => $sid,
				'instanceId'  => $iid,
				'instance'    => $child,
			);
		}
		return $out;
	}

	/**
	 * Create a child Model_Data row and link it to a parent (BOM line, etc.).
	 *
	 * @param array<string, string> $values Child attribute values.
	 * @return array{parent:array<string,mixed>,child:array<string,mixed>,related:list<array<string,mixed>>}|\WP_Error
	 */
	public static function create_linked(
		string $taxonomy,
		int $parent_structure_id,
		string $parent_instance_id,
		int $child_structure_id,
		string $relation = self::LINK_COMPOSITION,
		array $values = array()
	) {
		$parent_instance_id = sanitize_key( $parent_instance_id );
		$relation           = self::normalize_link_relation( $relation );
		if ( $parent_structure_id <= 0 || $child_structure_id <= 0 || '' === $parent_instance_id ) {
			return new \WP_Error( 'wtt_bad_link', __( 'Invalid instance link.', 'wp-taxonomy-tree' ) );
		}
		if ( null === self::get( $taxonomy, $parent_structure_id, $parent_instance_id, true ) ) {
			return new \WP_Error( 'wtt_not_found', __( 'Parent instance not found.', 'wp-taxonomy-tree' ) );
		}

		$child = self::save(
			$taxonomy,
			$child_structure_id,
			array(
				'values' => $values,
			)
		);
		if ( is_wp_error( $child ) ) {
			return $child;
		}
		$child_id = sanitize_key( (string) ( $child['id'] ?? '' ) );
		$parent   = self::link(
			$taxonomy,
			$parent_structure_id,
			$parent_instance_id,
			$child_structure_id,
			$child_id,
			$relation
		);
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}

		return array(
			'parent'  => $parent,
			'child'   => $child,
			'related' => self::list_related(
				$taxonomy,
				$parent_structure_id,
				$parent_instance_id,
				$relation,
				$child_structure_id
			),
		);
	}

	/**
	 * Mult many + structured type (has attributes) → related Model_Data rows via links[],
	 * not an inline blob on the parent attribute slot (Q97 / BOM lines).
	 *
	 * @param array<string, mixed> $row Attribute row from Attribute::list.
	 */
	public static function is_related_dataset_attr( string $taxonomy, array $row ): bool {
		$mult = (string) ( $row['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY );
		if ( ! Attribute::multiplicity_allows_many( $mult ) && empty( $row['allowsMany'] ) ) {
			return false;
		}
		$type_id = (int) ( $row['typeId'] ?? 0 );
		if ( $type_id <= 0 || ! Attribute::type_has_attributes( $taxonomy, $type_id ) ) {
			return false;
		}
		$binding = Attribute::normalize_binding( (string) ( $row['binding'] ?? Attribute::DEFAULT_BINDING ) );
		return Attribute::is_attribute_binding( $binding );
	}

	/**
	 * Soft-trash or untrash one instance row (no link cascade).
	 *
	 * @return true|\WP_Error
	 */
	private static function mark_trashed( string $taxonomy, int $structure_id, string $instance_id, bool $trashed ) {
		$all     = self::load_all();
		$key     = self::bag_key( $taxonomy, $structure_id );
		$changed = false;
		$rows    = self::normalize_bag(
			isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array(),
			$changed
		);
		$found   = false;
		foreach ( $rows as $i => $row ) {
			if ( (string) ( $row['id'] ?? '' ) !== $instance_id ) {
				continue;
			}
			$found                   = true;
			$rows[ $i ]['trashed']   = $trashed;
			$rows[ $i ]['modifiedAt'] = gmdate( 'c' );
			$rows[ $i ]              = self::enrich_labels( $rows[ $i ] );
			break;
		}
		if ( ! $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Instance not found.', 'wp-taxonomy-tree' ) );
		}
		$all[ $key ] = self::rows_for_persist( $rows );
		self::persist_all( $all );
		return true;
	}

	/**
	 * Permanently remove an instance row (and composition children if still linked).
	 *
	 * @return true|\WP_Error
	 */
	private static function purge_instance( string $taxonomy, int $structure_id, string $instance_id ) {
		$row = self::get( $taxonomy, $structure_id, $instance_id, true );
		if ( null === $row ) {
			return new \WP_Error( 'wtt_not_found', __( 'Instance not found.', 'wp-taxonomy-tree' ) );
		}
		$links = isset( $row['links'] ) && is_array( $row['links'] ) ? self::normalize_links( $row['links'] ) : array();
		foreach ( $links as $link ) {
			$rel = self::normalize_link_relation( (string) ( $link['relation'] ?? '' ) );
			if ( self::LINK_COMPOSITION !== $rel ) {
				continue;
			}
			$child_struct = (int) ( $link['structureId'] ?? 0 );
			$child_id     = sanitize_key( (string) ( $link['instanceId'] ?? '' ) );
			if ( $child_struct > 0 && '' !== $child_id ) {
				self::hard_remove_row( $taxonomy, $child_struct, $child_id );
			}
		}
		return self::hard_remove_row( $taxonomy, $structure_id, $instance_id );
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function hard_remove_row( string $taxonomy, int $structure_id, string $instance_id ) {
		$all = self::load_all();
		$key = self::bag_key( $taxonomy, $structure_id );
		if ( ! isset( $all[ $key ] ) || ! is_array( $all[ $key ] ) ) {
			return new \WP_Error( 'wtt_not_found', __( 'Instance not found.', 'wp-taxonomy-tree' ) );
		}

		$next  = array();
		$found = false;
		foreach ( $all[ $key ] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$eid = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
			if ( $eid === $instance_id ) {
				$found = true;
				continue;
			}
			$next[] = $row;
		}

		if ( ! $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Instance not found.', 'wp-taxonomy-tree' ) );
		}

		if ( array() === $next ) {
			unset( $all[ $key ] );
		} else {
			$changed     = false;
			$all[ $key ] = self::rows_for_persist( self::normalize_bag( $next, $changed ) );
		}
		self::persist_all( $all );

		return true;
	}

	/**
	 * @param list<mixed> $links Raw links.
	 * @return list<array{relation:string,structureId:int,instanceId:string}>
	 */
	private static function normalize_links( array $links ): array {
		$out = array();
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$sid = (int) ( $link['structureId'] ?? 0 );
			$iid = sanitize_key( (string) ( $link['instanceId'] ?? '' ) );
			if ( $sid <= 0 || '' === $iid ) {
				continue;
			}
			$out[] = array(
				'relation'    => self::normalize_link_relation( (string) ( $link['relation'] ?? self::LINK_COMPOSITION ) ),
				'structureId' => $sid,
				'instanceId'  => $iid,
			);
		}
		return $out;
	}

	/**
	 * Drop all instance bags for one taxonomy (e.g. after Case_Data::wipe_all_terms).
	 *
	 * @return int Number of bags removed.
	 */
	public static function clear_taxonomy( string $taxonomy ): int {
		$prefix = sanitize_key( $taxonomy ) . ':';
		$all    = self::load_all();
		$removed = 0;
		foreach ( array_keys( $all ) as $key ) {
			if ( 0 === strpos( (string) $key, $prefix ) ) {
				unset( $all[ $key ] );
				++$removed;
			}
		}
		if ( $removed > 0 ) {
			self::persist_all( $all );
		}
		return $removed;
	}

	/**
	 * Fill empty attribute slots from default templates (Q106) then Sample_Data.
	 *
	 * Mult-many scalars seed the full default list (JSON array store when >1);
	 * Mult-1 seeds at most one value. Related Mult datasets are skipped here
	 * (materialized via links on create).
	 *
	 * @param array<string, string> $values Current values (attr id → string).
	 * @return array<string, string>
	 */
	public static function apply_samples( string $taxonomy, int $structure_id, array $values ): array {
		$out = array();
		foreach ( $values as $attr_id => $val ) {
			$out[ (string) $attr_id ] = is_scalar( $val ) || null === $val ? (string) $val : '';
		}

		foreach ( Attribute::list( $taxonomy, $structure_id ) as $row ) {
			if ( ! empty( $row['hidden'] ) ) {
				continue;
			}
			if ( self::is_related_dataset_attr( $taxonomy, $row ) ) {
				continue;
			}
			$attr_id = (string) (int) ( $row['id'] ?? 0 );
			if ( '' === $attr_id || '0' === $attr_id ) {
				continue;
			}
			$current = isset( $out[ $attr_id ] ) ? trim( (string) $out[ $attr_id ] ) : '';
			if ( '' !== $current ) {
				continue;
			}
			/* Schema default templates win over invented samples (Q106). */
			$seed = self::scalar_default_store_value( $row );
			if ( '' !== $seed ) {
				$out[ $attr_id ] = $seed;
				continue;
			}
			$type_key = (string) ( $row['typeKey'] ?? '' );
			$sample   = Sample_Data::for_type( '' !== $type_key ? $type_key : (int) ( $row['typeId'] ?? 0 ) );
			if ( '' !== $sample ) {
				$out[ $attr_id ] = $sample;
			}
		}

		return $out;
	}

	/**
	 * Encode scalar default template(s) for one attribute into a store string (Q106).
	 *
	 * @param array<string, mixed> $row Attribute row from Attribute::list.
	 */
	public static function scalar_default_store_value( array $row ): string {
		$fixed = isset( $row['fixedValues'] ) && is_array( $row['fixedValues'] ) ? $row['fixedValues'] : array();
		$scalars = array();
		foreach ( $fixed as $v ) {
			if ( is_array( $v ) ) {
				continue;
			}
			$s = trim( (string) $v );
			if ( '' !== $s ) {
				$scalars[] = $s;
			}
		}
		if ( array() === $scalars ) {
			return '';
		}
		$mult        = (string) ( $row['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY );
		$allows_many = ! empty( $row['allowsMany'] ) || Attribute::multiplicity_allows_many( $mult );
		if ( ! $allows_many ) {
			return $scalars[0];
		}
		return Object_Render::encode_store_values( $scalars );
	}

	/**
	 * Fill empty scalar slots from schema default templates (create / open-new seed).
	 *
	 * @param array<string, string> $values Current values.
	 * @return array<string, string>
	 */
	public static function merge_scalar_defaults( string $taxonomy, int $structure_id, array $values ): array {
		$out = array();
		foreach ( $values as $attr_id => $val ) {
			$out[ (string) $attr_id ] = is_scalar( $val ) || null === $val ? (string) $val : '';
		}
		foreach ( Attribute::list( $taxonomy, $structure_id ) as $row ) {
			if ( ! empty( $row['hidden'] ) || self::is_related_dataset_attr( $taxonomy, $row ) ) {
				continue;
			}
			$attr_id = (string) (int) ( $row['id'] ?? 0 );
			if ( '' === $attr_id || '0' === $attr_id ) {
				continue;
			}
			$current = isset( $out[ $attr_id ] ) ? trim( (string) $out[ $attr_id ] ) : '';
			if ( '' !== $current ) {
				continue;
			}
			$seed = self::scalar_default_store_value( $row );
			if ( '' !== $seed ) {
				$out[ $attr_id ] = $seed;
			}
		}
		return $out;
	}

	/**
	 * Materialize related-Mult nested default maps as linked children (Q106).
	 *
	 * No-op when templates are absent (admin UI for editing default rows is TODO).
	 * Idempotent only in the sense of create-time call — does not re-run on update.
	 */
	private static function materialize_related_defaults(
		string $taxonomy,
		int $parent_structure_id,
		string $parent_instance_id
	): void {
		$parent_instance_id = sanitize_key( $parent_instance_id );
		if ( '' === $parent_instance_id || $parent_structure_id <= 0 ) {
			return;
		}
		foreach ( Attribute::list( $taxonomy, $parent_structure_id ) as $row ) {
			if ( ! empty( $row['hidden'] ) || ! self::is_related_dataset_attr( $taxonomy, $row ) ) {
				continue;
			}
			$type_id = (int) ( $row['typeId'] ?? 0 );
			if ( $type_id <= 0 ) {
				continue;
			}
			$templates = isset( $row['fixedValues'] ) && is_array( $row['fixedValues'] ) ? $row['fixedValues'] : array();
			$relation  = Attribute::normalize_binding( (string) ( $row['binding'] ?? Attribute::DEFAULT_BINDING ) );
			foreach ( $templates as $tpl ) {
				if ( ! is_array( $tpl ) ) {
					continue;
				}
				$child_values = array();
				foreach ( $tpl as $k => $v ) {
					if ( is_array( $v ) ) {
						continue;
					}
					$aid = absint( $k );
					if ( $aid <= 0 ) {
						continue;
					}
					$child_values[ (string) $aid ] = (string) $v;
				}
				$linked = self::create_linked(
					$taxonomy,
					$parent_structure_id,
					$parent_instance_id,
					$type_id,
					$relation,
					$child_values
				);
				if ( is_wp_error( $linked ) ) {
					continue;
				}
			}
		}
	}

	/**
	 * Structure DTO for the admin form (host + fillable attributes).
	 *
	 * Related Mult many structure attrs (e.g. Bauteilliste → Position) carry
	 * `isRelatedDataset` + `typeProperties` for a linked-line table UX.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function structure_dto( string $taxonomy, int $structure_id ): ?array {
		$view = Object_Render::get_view( $taxonomy, $structure_id );
		if ( null === $view ) {
			return null;
		}

		$fields = array();
		foreach ( Attribute::list( $taxonomy, $structure_id ) as $row ) {
			if ( ! empty( $row['hidden'] ) ) {
				continue;
			}
			$attr_id = (int) ( $row['id'] ?? 0 );
			if ( $attr_id <= 0 ) {
				continue;
			}
			$fixed = array();
			if ( isset( $row['fixedValues'] ) && is_array( $row['fixedValues'] ) ) {
				foreach ( $row['fixedValues'] as $v ) {
					/* Q106: scalars as strings; related Mult defaults as nested maps. */
					if ( is_array( $v ) ) {
						$fixed[] = $v;
					} else {
						$fixed[] = (string) $v;
					}
				}
			}
			$mult        = (string) ( $row['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY );
			$binding     = Attribute::normalize_binding( (string) ( $row['binding'] ?? Attribute::DEFAULT_BINDING ) );
			$type_id     = (int) ( $row['typeId'] ?? 0 );
			$allows_many = ! empty( $row['allowsMany'] ) || Attribute::multiplicity_allows_many( $mult );
			$is_related  = self::is_related_dataset_attr( $taxonomy, $row );
			$type_props  = array();
			if ( $is_related && $type_id > 0 ) {
				foreach ( Attribute::list( $taxonomy, $type_id ) as $child_row ) {
					if ( ! is_array( $child_row ) || ! empty( $child_row['hidden'] ) ) {
						continue;
					}
					$cid = (int) ( $child_row['id'] ?? 0 );
					if ( $cid <= 0 ) {
						continue;
					}
					$child_type_id = (int) ( $child_row['typeId'] ?? 0 );
					$child_mult    = (string) ( $child_row['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY );
					/* Include preferred/fixedOptions so UR-B6 Wert→Bauteil embed chrome works in line tables. */
					$type_props[] = array(
						'id'                  => $cid,
						'name'                => (string) ( $child_row['name'] ?? '' ),
						'typeId'              => $child_type_id,
						'typeName'            => (string) ( $child_row['typeName'] ?? '' ),
						'typeKey'             => (string) ( $child_row['typeKey'] ?? '' ),
						'multiplicity'        => $child_mult,
						'allowsMany'          => ! empty( $child_row['allowsMany'] )
							|| Attribute::multiplicity_allows_many( $child_mult ),
						'allowsEmpty'         => Attribute::multiplicity_allows_empty( $child_mult ),
						'readonly'            => ! empty( $child_row['readonly'] ),
						'fixedMode'           => (string) ( $child_row['fixedMode'] ?? '' ),
						'fixedRootId'         => (int) ( $child_row['fixedRootId'] ?? 0 ) > 0
							? (int) $child_row['fixedRootId']
							: $child_type_id,
						'fixedOptions'        => isset( $child_row['fixedOptions'] ) && is_array( $child_row['fixedOptions'] )
							? array_values( $child_row['fixedOptions'] )
							: array(),
						'choiceDepth'         => Attribute::choice_depth_from_options(
							isset( $child_row['fixedOptions'] ) && is_array( $child_row['fixedOptions'] )
								? $child_row['fixedOptions']
								: array()
						),
						'typePreferredRender' => (string) ( $child_row['typePreferredRender'] ?? '' ),
						'preferredRender'     => (string) ( $child_row['preferredRender'] ?? $child_row['typePreferredRender'] ?? '' ),
						'typeProperties'      => array(),
					);
				}
			}
			$fields[] = array(
				'id'               => $attr_id,
				'name'             => (string) ( $row['name'] ?? '' ),
				'typeId'           => $type_id,
				'typeName'         => (string) ( $row['typeName'] ?? '' ),
				'typeKey'          => (string) ( $row['typeKey'] ?? '' ),
				'multiplicity'     => $mult,
				'allowsMany'       => $allows_many,
				'binding'          => $binding,
				'isRelatedDataset' => $is_related,
				'typeProperties'   => $type_props,
				'inherited'        => ! empty( $row['inherited'] ),
				'readonly'         => ! empty( $row['readonly'] ),
				'fixedValues'      => $fixed,
				'fixedLabel'       => (string) ( $row['fixedLabel'] ?? '' ),
			);
		}

		$view['fields']         = $fields;
		$view['attributeCount'] = count( $fields );
		return $view;
	}

	/**
	 * Pickable structure hosts (prefer nodes that already have attributes).
	 *
	 * @return list<array{id:int,name:string,path:string,taxonomy:string,attributeCount:int}>
	 */
	public static function list_structure_hosts( string $taxonomy = '' ): array {
		$nodes = Object_Render::list_pickable_nodes( $taxonomy );
		$out   = array();
		foreach ( $nodes as $node ) {
			$tax = (string) ( $node['taxonomy'] ?? '' );
			$id  = (int) ( $node['id'] ?? 0 );
			if ( '' === $tax || $id <= 0 ) {
				continue;
			}
			$count = 0;
			foreach ( Attribute::list( $tax, $id ) as $row ) {
				if ( empty( $row['hidden'] ) ) {
					++$count;
				}
			}
			$out[] = array(
				'id'             => $id,
				'name'           => (string) ( $node['name'] ?? '' ),
				'path'           => (string) ( $node['path'] ?? '' ),
				'taxonomy'       => $tax,
				'attributeCount' => $count,
			);
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				/* Hosts with attributes first, then path. */
				$ac = (int) ( $a['attributeCount'] ?? 0 );
				$bc = (int) ( $b['attributeCount'] ?? 0 );
				if ( $ac > 0 && 0 === $bc ) {
					return -1;
				}
				if ( $bc > 0 && 0 === $ac ) {
					return 1;
				}
				$tax = strcasecmp( (string) ( $a['taxonomy'] ?? '' ), (string) ( $b['taxonomy'] ?? '' ) );
				if ( 0 !== $tax ) {
					return $tax;
				}
				return strcasecmp( (string) ( $a['path'] ?? '' ), (string) ( $b['path'] ?? '' ) );
			}
		);

		return $out;
	}

	/**
	 * TreeChooser payload for Fill Model Data Structure node.
	 *
	 * rootId = chooser_root; focusId = model (caller-owned).
	 * Nodes with attributes are selectable; folders remain visible.
	 *
	 * @return array{roots:list<array<string,mixed>>,rootId:int,focusId:int}
	 */
	public static function structure_chooser_tree( string $taxonomy ): array {
		if ( ! Taxonomy::is_scaffold( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
			return array(
				'roots'   => array(),
				'rootId'  => 0,
				'focusId' => 0,
			);
		}

		Catalog_Bindings::ensure( $taxonomy );
		$root_id  = Catalog_Bindings::resolve( $taxonomy, Catalog_Bindings::KEY_CHOOSER_ROOT );
		$focus_id = Catalog_Bindings::resolve( $taxonomy, Catalog_Bindings::KEY_MODEL );

		$attr_counts = array();
		foreach ( self::list_structure_hosts( $taxonomy ) as $host ) {
			$hid = (int) ( $host['id'] ?? 0 );
			if ( $hid > 0 ) {
				$attr_counts[ $hid ] = (int) ( $host['attributeCount'] ?? 0 );
			}
		}

		$full  = Tree_Model::get_tree( $taxonomy );
		$roots = self::picker_roots_under( $full, $root_id );
		$roots = self::slim_picker_nodes( $roots, $attr_counts );

		return array(
			'roots'   => $roots,
			'rootId'  => $root_id,
			'focusId' => $focus_id,
		);
	}

	/**
	 * @param list<array<string,mixed>> $nodes
	 * @return list<array<string,mixed>>
	 */
	private static function picker_roots_under( array $nodes, int $root_id ): array {
		if ( $root_id <= 0 ) {
			return $nodes;
		}
		$found = self::find_node_in_tree_nodes( $nodes, $root_id );
		if ( null === $found ) {
			return $nodes;
		}
		return array( $found );
	}

	/**
	 * @param list<array<string,mixed>> $nodes
	 * @return array<string,mixed>|null
	 */
	private static function find_node_in_tree_nodes( array $nodes, int $id ) {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( (int) ( $node['id'] ?? 0 ) === $id ) {
				return $node;
			}
			$kids = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
			$hit  = self::find_node_in_tree_nodes( $kids, $id );
			if ( null !== $hit ) {
				return $hit;
			}
		}
		return null;
	}

	/**
	 * @param list<array<string,mixed>> $nodes
	 * @param array<int,int>            $attr_counts
	 * @return list<array<string,mixed>>
	 */
	private static function slim_picker_nodes( array $nodes, array $attr_counts ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$id    = (int) ( $node['id'] ?? 0 );
			$count = isset( $attr_counts[ $id ] ) ? (int) $attr_counts[ $id ] : 0;
			$kids  = isset( $node['children'] ) && is_array( $node['children'] )
				? self::slim_picker_nodes( $node['children'], $attr_counts )
				: array();
			$out[] = array(
				'id'             => $id,
				'name'           => (string) ( $node['name'] ?? '' ),
				'parent'         => (int) ( $node['parent'] ?? 0 ),
				'isAbstract'     => ! empty( $node['isAbstract'] ),
				'attributeCount' => $count,
				'selectable'     => $count > 0,
				'children'       => $kids,
				'hasChildren'    => count( $kids ) > 0,
			);
		}
		return $out;
	}

	/**
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function load_all(): array {
		$raw = get_option( self::OPTION_KEY, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @param array<string, list<array<string, mixed>>> $all Full store.
	 */
	private static function persist_all( array $all ): void {
		update_option( self::OPTION_KEY, $all, false );
	}

	private static function new_id(): string {
		return 'md_' . strtolower( wp_generate_password( 12, false, false ) );
	}

	/**
	 * Next running number within one host bag.
	 *
	 * @param list<array<string, mixed>> $rows Normalized rows.
	 */
	private static function next_seq( array $rows ): int {
		$max = 0;
		foreach ( $rows as $row ) {
			$seq = (int) ( $row['seq'] ?? 0 );
			if ( $seq > $max ) {
				$max = $seq;
			}
		}
		return $max + 1;
	}

	/**
	 * Normalize a bag and backfill missing identity metadata.
	 *
	 * @param list<mixed> $bag Raw rows.
	 * @param bool        $changed Set true when backfill mutated data.
	 * @return list<array<string, mixed>>
	 */
	private static function normalize_bag( array $bag, bool &$changed ): array {
		$changed = false;
		$raw     = array();
		foreach ( $bag as $row ) {
			$normalized = self::normalize_row_raw( $row, $changed );
			if ( null !== $normalized ) {
				$raw[] = $normalized;
			}
		}

		/*
		 * Backfill missing seq: keep any positive existing numbers, assign the
		 * next free integers to rows without seq (oldest createdAt first).
		 */
		$needs_seq = false;
		foreach ( $raw as $row ) {
			if ( (int) ( $row['seq'] ?? 0 ) <= 0 ) {
				$needs_seq = true;
				break;
			}
		}
		if ( $needs_seq ) {
			$changed = true;
			usort(
				$raw,
				static function ( array $a, array $b ): int {
					return strcmp( (string) ( $a['createdAt'] ?? '' ), (string) ( $b['createdAt'] ?? '' ) );
				}
			);
			$used = array();
			foreach ( $raw as $row ) {
				$s = (int) ( $row['seq'] ?? 0 );
				if ( $s > 0 ) {
					$used[ $s ] = true;
				}
			}
			$next = 1;
			foreach ( $raw as $i => $row ) {
				$s = (int) ( $row['seq'] ?? 0 );
				if ( $s > 0 ) {
					continue;
				}
				while ( isset( $used[ $next ] ) ) {
					++$next;
				}
				$raw[ $i ]['seq'] = $next;
				$used[ $next ]    = true;
				++$next;
			}
		}

		$out = array();
		foreach ( $raw as $row ) {
			$out[] = self::enrich_labels( $row );
		}
		return $out;
	}

	/**
	 * Persist shape without computed label fields.
	 *
	 * @param list<array<string, mixed>> $rows Display rows.
	 * @return list<array<string, mixed>>
	 */
	private static function rows_for_persist( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'             => (string) ( $row['id'] ?? '' ),
				'seq'            => (int) ( $row['seq'] ?? 0 ),
				'createdAt'      => (string) ( $row['createdAt'] ?? '' ),
				'version'        => max( 1, (int) ( $row['version'] ?? 1 ) ),
				'modelVersion'   => max( 1, (int) ( $row['modelVersion'] ?? Model_Version::DEFAULT_VERSION ) ),
				'modifiedAt'     => (string) ( $row['modifiedAt'] ?? '' ),
				'modifiedBy'     => (int) ( $row['modifiedBy'] ?? 0 ),
				'modifiedByName' => (string) ( $row['modifiedByName'] ?? '' ),
				'values'         => isset( $row['values'] ) && is_array( $row['values'] ) ? $row['values'] : array(),
				'links'          => isset( $row['links'] ) && is_array( $row['links'] )
					? self::normalize_links( $row['links'] )
					: array(),
				'trashed'        => ! empty( $row['trashed'] ),
			);
		}
		return array_values( $out );
	}

	/**
	 * @param mixed $row Raw row.
	 * @param bool  $changed Set when defaults were filled.
	 * @return array<string, mixed>|null
	 */
	private static function normalize_row_raw( $row, bool &$changed ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$id = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
		if ( '' === $id ) {
			return null;
		}

		$values = array();
		if ( isset( $row['values'] ) && is_array( $row['values'] ) ) {
			foreach ( $row['values'] as $k => $v ) {
				$values[ (string) absint( $k ) ] = is_scalar( $v ) ? (string) $v : '';
			}
		}

		$legacy_updated = isset( $row['updatedAt'] ) ? sanitize_text_field( (string) $row['updatedAt'] ) : '';
		$now            = gmdate( 'c' );

		$created_at = isset( $row['createdAt'] ) ? sanitize_text_field( (string) $row['createdAt'] ) : '';
		if ( '' === $created_at ) {
			$created_at = '' !== $legacy_updated ? $legacy_updated : $now;
			$changed    = true;
		}

		$modified_at = isset( $row['modifiedAt'] ) ? sanitize_text_field( (string) $row['modifiedAt'] ) : '';
		if ( '' === $modified_at ) {
			$modified_at = '' !== $legacy_updated ? $legacy_updated : $created_at;
			$changed     = true;
		}

		$version = isset( $row['version'] ) ? (int) $row['version'] : 0;
		if ( $version < 1 ) {
			$version = 1;
			$changed = true;
		}

		/*
		 * Schema stamp: missing → 1 (not "current host") so bumps surface as conflicts.
		 */
		$model_version = isset( $row['modelVersion'] ) ? (int) $row['modelVersion'] : 0;
		if ( $model_version < 1 ) {
			$model_version = Model_Version::DEFAULT_VERSION;
			$changed       = true;
		}

		$seq = isset( $row['seq'] ) ? (int) $row['seq'] : 0;
		if ( $seq <= 0 && ! array_key_exists( 'seq', $row ) ) {
			$changed = true;
		}

		$modified_by = isset( $row['modifiedBy'] ) ? absint( $row['modifiedBy'] ) : 0;
		$by_name     = isset( $row['modifiedByName'] ) ? sanitize_text_field( (string) $row['modifiedByName'] ) : '';
		if ( '' === $by_name && $modified_by > 0 ) {
			$by_name = self::user_display_name( $modified_by );
		}

		return array(
			'id'             => $id,
			'seq'            => $seq,
			'createdAt'      => $created_at,
			'version'        => $version,
			'modelVersion'   => $model_version,
			'modifiedAt'     => $modified_at,
			'modifiedBy'     => $modified_by,
			'modifiedByName' => $by_name,
			'values'         => $values,
			'links'          => isset( $row['links'] ) && is_array( $row['links'] )
				? self::normalize_links( $row['links'] )
				: array(),
			'trashed'        => ! empty( $row['trashed'] ),
		);
	}

	/**
	 * Add localized date labels for admin UI.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function enrich_labels( array $row ): array {
		$row['createdAtLabel']  = self::format_datetime_label( (string) ( $row['createdAt'] ?? '' ) );
		$row['modifiedAtLabel'] = self::format_datetime_label( (string) ( $row['modifiedAt'] ?? '' ) );
		if ( '' === (string) ( $row['modifiedByName'] ?? '' ) && (int) ( $row['modifiedBy'] ?? 0 ) > 0 ) {
			$row['modifiedByName'] = self::user_display_name( (int) $row['modifiedBy'] );
		}
		return $row;
	}

	private static function format_datetime_label( string $iso ): string {
		if ( '' === $iso ) {
			return '';
		}
		$ts = strtotime( $iso );
		if ( false === $ts ) {
			return $iso;
		}
		return (string) wp_date(
			trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			$ts
		);
	}

	private static function user_display_name( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return '#' . $user_id;
		}
		$name = trim( (string) $user->display_name );
		if ( '' !== $name ) {
			return $name;
		}
		return (string) $user->user_login;
	}

	/**
	 * @param array<string, string> $a Values.
	 * @param array<string, string> $b Values.
	 */
	private static function values_differ( array $a, array $b ): bool {
		$keys = array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) );
		foreach ( $keys as $key ) {
			$av = isset( $a[ $key ] ) ? (string) $a[ $key ] : '';
			$bv = isset( $b[ $key ] ) ? (string) $b[ $key ] : '';
			if ( $av !== $bv ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<int, true>
	 */
	private static function allowed_attribute_ids( string $taxonomy, int $structure_id ): array {
		$allowed = array();
		foreach ( Attribute::list( $taxonomy, $structure_id ) as $row ) {
			if ( ! empty( $row['hidden'] ) ) {
				continue;
			}
			$aid = (int) ( $row['id'] ?? 0 );
			if ( $aid > 0 ) {
				$allowed[ $aid ] = true;
			}
		}
		return $allowed;
	}

	/**
	 * Attribute ids that must not store instance values on the host (related links instead).
	 *
	 * @return array<int, true>
	 */
	private static function related_dataset_attr_ids( string $taxonomy, int $structure_id ): array {
		$out = array();
		foreach ( Attribute::list( $taxonomy, $structure_id ) as $row ) {
			if ( ! self::is_related_dataset_attr( $taxonomy, $row ) ) {
				continue;
			}
			$aid = (int) ( $row['id'] ?? 0 );
			if ( $aid > 0 ) {
				$out[ $aid ] = true;
			}
		}
		return $out;
	}

	/**
	 * @param array<mixed, mixed> $raw Raw values map.
	 * @param array<int, true>    $allowed Allowed attribute ids.
	 * @return array<string, string>
	 */
	private static function sanitize_values( array $raw, array $allowed ): array {
		$out = array();
		foreach ( $raw as $key => $value ) {
			$attr_id = absint( $key );
			if ( $attr_id <= 0 || ! isset( $allowed[ $attr_id ] ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$encoded = wp_json_encode( $value );
				$out[ (string) $attr_id ] = false === $encoded ? '' : $encoded;
				continue;
			}
			$out[ (string) $attr_id ] = sanitize_textarea_field( (string) $value );
		}
		return $out;
	}

	/**
	 * Re-attach previous value keys that are no longer in the allowed schema.
	 *
	 * UR-S1: keep orphan attr data under `values` on save (remove-field must not wipe).
	 * TODO: migrate to a dedicated `orphans` bag + mapping UI when schema renames land.
	 *
	 * @param array<string, string> $sanitized Fresh allowed values.
	 * @param array<string, mixed>  $previous  Prior stored values.
	 * @param array<int, true>      $allowed   Current allowed attribute ids.
	 * @return array<string, string>
	 */
	private static function merge_orphan_values( array $sanitized, array $previous, array $allowed ): array {
		foreach ( $previous as $key => $value ) {
			$attr_id = absint( $key );
			if ( $attr_id <= 0 || isset( $allowed[ $attr_id ] ) ) {
				continue;
			}
			$store_key = (string) $attr_id;
			if ( array_key_exists( $store_key, $sanitized ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$encoded = wp_json_encode( $value );
				$sanitized[ $store_key ] = false === $encoded ? '' : $encoded;
				continue;
			}
			$sanitized[ $store_key ] = sanitize_textarea_field( (string) $value );
		}
		return $sanitized;
	}
}
