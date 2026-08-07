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
 * - `modifiedAt` / `modifiedBy` — last save time (ISO-8601 UTC) and WP user id.
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
 */
final class Model_Data {

	/** Option holding all instance bags. */
	public const OPTION_KEY = 'wtt_model_instances';

	/**
	 * Bag key for a structure host.
	 */
	public static function bag_key( string $taxonomy, int $structure_id ): string {
		return sanitize_key( $taxonomy ) . ':' . absint( $structure_id );
	}

	/**
	 * List instances for a structure node (highest seq first).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function list( string $taxonomy, int $structure_id ): array {
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
	 * @return array<string, mixed>|null
	 */
	public static function get( string $taxonomy, int $structure_id, string $instance_id ): ?array {
		$instance_id = sanitize_key( $instance_id );
		if ( '' === $instance_id ) {
			return null;
		}
		foreach ( self::list( $taxonomy, $structure_id ) as $row ) {
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

		$allowed_attrs = self::allowed_attribute_ids( $taxonomy, $structure_id );
		$raw_values    = isset( $payload['values'] ) && is_array( $payload['values'] ) ? $payload['values'] : array();
		$values        = self::sanitize_values( $raw_values, $allowed_attrs );

		$all     = self::load_all();
		$key     = self::bag_key( $taxonomy, $structure_id );
		$bag     = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
		$changed = false;
		$rows    = self::normalize_bag( $bag, $changed );

		$id = isset( $payload['id'] ) ? sanitize_key( (string) $payload['id'] ) : '';
		$now = gmdate( 'c' );
		$user_id = get_current_user_id();
		$user_name = self::user_display_name( $user_id );

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
			$id  = '' !== $id ? $id : self::new_id();
			$seq = self::next_seq( $rows );
			$row = self::enrich_labels(
				array(
					'id'             => $id,
					'seq'            => $seq,
					'createdAt'      => $now,
					'version'        => 1,
					'modifiedAt'     => $now,
					'modifiedBy'     => $user_id,
					'modifiedByName' => $user_name,
					'values'         => $values,
				)
			);
			$rows[] = $row;
		} else {
			$prev_values = isset( $existing['values'] ) && is_array( $existing['values'] ) ? $existing['values'] : array();
			$version     = (int) ( $existing['version'] ?? 1 );
			if ( $version < 1 ) {
				$version = 1;
			}
			/* Version bumps only when attribute values change. */
			if ( self::values_differ( $prev_values, $values ) ) {
				++$version;
			}
			$row = self::enrich_labels(
				array(
					'id'             => (string) $existing['id'],
					'seq'            => (int) ( $existing['seq'] ?? 0 ),
					'createdAt'      => (string) ( $existing['createdAt'] ?? $now ),
					'version'        => $version,
					'modifiedAt'     => $now,
					'modifiedBy'     => $user_id,
					'modifiedByName' => $user_name,
					'values'         => $values,
				)
			);
			$rows[ $existing_index ] = $row;
		}

		$all[ $key ] = self::rows_for_persist( $rows );
		self::persist_all( $all );

		return $row;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function delete( string $taxonomy, int $structure_id, string $instance_id ) {
		$instance_id = sanitize_key( $instance_id );
		if ( '' === $instance_id ) {
			return new \WP_Error( 'wtt_bad_id', __( 'Invalid instance id.', 'wp-taxonomy-tree' ) );
		}

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
			$all[ $key ] = self::rows_for_persist( $next );
		}
		self::persist_all( $all );

		return true;
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
	 * Fill empty attribute slots from Sample_Data (type → sample map).
	 *
	 * @param array<string, string> $values Current values (attr id → string).
	 * @return array<string, string>
	 */
	public static function apply_samples( string $taxonomy, int $structure_id, array $values ): array {
		$out = array();
		foreach ( $values as $attr_id => $val ) {
			$out[ (string) $attr_id ] = (string) $val;
		}

		foreach ( Attribute::list( $taxonomy, $structure_id ) as $row ) {
			if ( ! empty( $row['hidden'] ) ) {
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
			/* Fixed Festwerte stay as defined — do not invent samples over them. */
			if ( ! empty( $row['fixedValues'] ) && is_array( $row['fixedValues'] ) && array() !== $row['fixedValues'] ) {
				$first = $row['fixedValues'][0] ?? '';
				$out[ $attr_id ] = (string) $first;
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
	 * Structure DTO for the admin form (host + fillable attributes).
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
					$fixed[] = (string) $v;
				}
			}
			$fields[] = array(
				'id'           => $attr_id,
				'name'         => (string) ( $row['name'] ?? '' ),
				'typeId'       => (int) ( $row['typeId'] ?? 0 ),
				'typeName'     => (string) ( $row['typeName'] ?? '' ),
				'typeKey'      => (string) ( $row['typeKey'] ?? '' ),
				'multiplicity' => (string) ( $row['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY ),
				'inherited'    => ! empty( $row['inherited'] ),
				'readonly'     => ! empty( $row['readonly'] ),
				'fixedValues'  => $fixed,
				'fixedLabel'   => (string) ( $row['fixedLabel'] ?? '' ),
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
				'modifiedAt'     => (string) ( $row['modifiedAt'] ?? '' ),
				'modifiedBy'     => (int) ( $row['modifiedBy'] ?? 0 ),
				'modifiedByName' => (string) ( $row['modifiedByName'] ?? '' ),
				'values'         => isset( $row['values'] ) && is_array( $row['values'] ) ? $row['values'] : array(),
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
			'modifiedAt'     => $modified_at,
			'modifiedBy'     => $modified_by,
			'modifiedByName' => $by_name,
			'values'         => $values,
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
}
