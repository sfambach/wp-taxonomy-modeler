<?php
/**
 * Model schema version stamps (UR-S1 scaffold).
 *
 * Structure hosts store an integer schema version in term meta.
 * Model_Data instances carry `modelVersion` stamped on create/save.
 * Conflict = instance stamp ≠ current host schema version.
 *
 * Additive bump history (`_wtt_model_version_history`) records generations
 * for the admin stacked view. Full schema snapshots / mapping DSL are TODO.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema version helpers for structure hosts and instance conflict summaries.
 */
final class Model_Version {

	/** Term meta key on the structure host (int ≥ 1). */
	public const META_KEY = '_wtt_model_version';

	/**
	 * JSON list of bump events on the structure host.
	 *
	 * Each entry: `{ version:int, bumpedAt:string (UTC mysql), source:string }`.
	 */
	public const HISTORY_META_KEY = '_wtt_model_version_history';

	/** Default schema / stamp when meta or instance field is missing. */
	public const DEFAULT_VERSION = 1;

	/** Soft cap so history meta cannot grow without bound. */
	private const HISTORY_MAX_ENTRIES = 100;

	/**
	 * Current schema version for a structure host (ensures meta exists).
	 */
	public static function get( string $taxonomy, int $structure_id ): int {
		if ( ! Taxonomy::is_scaffold( $taxonomy ) || $structure_id <= 0 ) {
			return self::DEFAULT_VERSION;
		}

		$raw = get_term_meta( $structure_id, self::META_KEY, true );
		if ( '' === $raw || false === $raw || null === $raw ) {
			update_term_meta( $structure_id, self::META_KEY, self::DEFAULT_VERSION );
			self::ensure_history_seed( $structure_id, self::DEFAULT_VERSION );
			return self::DEFAULT_VERSION;
		}

		$version = (int) $raw;
		if ( $version < 1 ) {
			update_term_meta( $structure_id, self::META_KEY, self::DEFAULT_VERSION );
			self::ensure_history_seed( $structure_id, self::DEFAULT_VERSION );
			return self::DEFAULT_VERSION;
		}

		return $version;
	}

	/**
	 * Set schema version explicitly (clamped ≥ 1). Does not append history.
	 */
	public static function set( string $taxonomy, int $structure_id, int $version ): int {
		if ( ! Taxonomy::is_scaffold( $taxonomy ) || $structure_id <= 0 ) {
			return self::DEFAULT_VERSION;
		}
		$version = max( 1, $version );
		update_term_meta( $structure_id, self::META_KEY, $version );
		return $version;
	}

	/**
	 * Increment schema version by 1, append history, return the new value.
	 *
	 * @param string $source `structural` (attr edit), `manual` (admin bump), or other label.
	 */
	public static function bump( string $taxonomy, int $structure_id, string $source = 'structural' ): int {
		$current = self::get( $taxonomy, $structure_id );
		$new     = self::set( $taxonomy, $structure_id, $current + 1 );
		self::append_history(
			$structure_id,
			array(
				'version'  => $new,
				'bumpedAt' => self::now_utc_mysql(),
				'source'   => self::sanitize_source( $source ),
			)
		);
		return $new;
	}

	/**
	 * Stamp summary for one structure host (active / non-trashed instances).
	 *
	 * @return array{
	 *   schemaVersion:int,
	 *   instanceTotal:int,
	 *   countsByVersion:array<int,int>,
	 *   conflictCount:int
	 * }
	 */
	public static function summarize_host( string $taxonomy, int $structure_id ): array {
		$empty = array(
			'schemaVersion'   => self::DEFAULT_VERSION,
			'instanceTotal'   => 0,
			'countsByVersion' => array(),
			'conflictCount'   => 0,
		);
		if ( ! Taxonomy::is_scaffold( $taxonomy ) || $structure_id <= 0 ) {
			return $empty;
		}

		$schema    = self::get( $taxonomy, $structure_id );
		$instances = Model_Data::list( $taxonomy, $structure_id, false );
		$counts    = array();
		$conflicts = 0;

		foreach ( $instances as $row ) {
			$stamp = isset( $row['modelVersion'] ) ? (int) $row['modelVersion'] : self::DEFAULT_VERSION;
			if ( $stamp < 1 ) {
				$stamp = self::DEFAULT_VERSION;
			}
			if ( ! isset( $counts[ $stamp ] ) ) {
				$counts[ $stamp ] = 0;
			}
			++$counts[ $stamp ];
			if ( $stamp !== $schema ) {
				++$conflicts;
			}
		}
		ksort( $counts, SORT_NUMERIC );

		return array(
			'schemaVersion'   => $schema,
			'instanceTotal'   => count( $instances ),
			'countsByVersion' => $counts,
			'conflictCount'   => $conflicts,
		);
	}

	/**
	 * Count active instances whose modelVersion stamp ≠ host schema version.
	 */
	public static function conflict_count( string $taxonomy, int $structure_id ): int {
		return (int) ( self::summarize_host( $taxonomy, $structure_id )['conflictCount'] ?? 0 );
	}

	/**
	 * Hosts worth listing: attribute count > 0 and/or existing Model_Data rows.
	 *
	 * @return list<array{
	 *   id:int,
	 *   name:string,
	 *   path:string,
	 *   taxonomy:string,
	 *   attributeCount:int,
	 *   schemaVersion:int,
	 *   instanceTotal:int,
	 *   countsByVersion:array<int,int>,
	 *   conflictCount:int
	 * }>
	 */
	public static function list_host_summaries( string $taxonomy = '' ): array {
		$hosts = Model_Data::list_structure_hosts( $taxonomy );
		$out   = array();

		foreach ( $hosts as $host ) {
			$tax = (string) ( $host['taxonomy'] ?? '' );
			$id  = (int) ( $host['id'] ?? 0 );
			if ( '' === $tax || $id <= 0 || ! Taxonomy::is_scaffold( $tax ) ) {
				continue;
			}

			$attr_count = (int) ( $host['attributeCount'] ?? 0 );
			$summary    = self::summarize_host( $tax, $id );
			if ( $attr_count <= 0 && (int) ( $summary['instanceTotal'] ?? 0 ) <= 0 ) {
				continue;
			}

			$out[] = array(
				'id'              => $id,
				'name'            => (string) ( $host['name'] ?? '' ),
				'path'            => (string) ( $host['path'] ?? '' ),
				'taxonomy'        => $tax,
				'attributeCount'  => $attr_count,
				'schemaVersion'   => (int) ( $summary['schemaVersion'] ?? self::DEFAULT_VERSION ),
				'instanceTotal'   => (int) ( $summary['instanceTotal'] ?? 0 ),
				'countsByVersion' => (array) ( $summary['countsByVersion'] ?? array() ),
				'conflictCount'   => (int) ( $summary['conflictCount'] ?? 0 ),
			);
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				$ca = (int) ( $a['conflictCount'] ?? 0 );
				$cb = (int) ( $b['conflictCount'] ?? 0 );
				if ( $ca !== $cb ) {
					return $cb <=> $ca;
				}
				return strcasecmp( (string) ( $a['path'] ?? '' ), (string) ( $b['path'] ?? '' ) );
			}
		);

		return $out;
	}

	/**
	 * One host for the detail view, or null when unknown / not scaffold.
	 *
	 * @return array{
	 *   id:int,
	 *   name:string,
	 *   path:string,
	 *   taxonomy:string,
	 *   attributeCount:int,
	 *   schemaVersion:int,
	 *   instanceTotal:int,
	 *   countsByVersion:array<int,int>,
	 *   conflictCount:int,
	 *   versions:list<array{
	 *     version:int,
	 *     bumpedAt:string,
	 *     source:string,
	 *     isCurrent:bool,
	 *     instanceCount:int,
	 *     isConflict:bool,
	 *     knownDate:bool
	 *   }>
	 * }|null
	 */
	public static function get_host_detail( string $taxonomy, int $structure_id ): ?array {
		if ( ! Taxonomy::is_scaffold( $taxonomy ) || $structure_id <= 0 ) {
			return null;
		}

		$term = get_term( $structure_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
			return null;
		}

		$summary    = self::summarize_host( $taxonomy, $structure_id );
		$attr_count = 0;
		$path       = '';
		foreach ( Model_Data::list_structure_hosts( $taxonomy ) as $host ) {
			if ( (int) ( $host['id'] ?? 0 ) !== $structure_id ) {
				continue;
			}
			$path       = (string) ( $host['path'] ?? '' );
			$attr_count = (int) ( $host['attributeCount'] ?? 0 );
			break;
		}
		if ( $attr_count <= 0 && class_exists( Attribute::class ) ) {
			$attr_count = count( Attribute::list_own( $taxonomy, $structure_id ) );
		}

		return array(
			'id'              => $structure_id,
			'name'            => (string) $term->name,
			'path'            => $path,
			'taxonomy'        => $taxonomy,
			'attributeCount'  => $attr_count,
			'schemaVersion'   => (int) ( $summary['schemaVersion'] ?? self::DEFAULT_VERSION ),
			'instanceTotal'   => (int) ( $summary['instanceTotal'] ?? 0 ),
			'countsByVersion' => (array) ( $summary['countsByVersion'] ?? array() ),
			'conflictCount'   => (int) ( $summary['conflictCount'] ?? 0 ),
			'versions'        => self::list_version_stack( $taxonomy, $structure_id, $summary ),
		);
	}

	/**
	 * Stacked generations for one host (newest version number first).
	 *
	 * Merges persisted bump history with current schema + instance stamps so
	 * older installs still show a useful stack before the first logged bump.
	 *
	 * @param array{
	 *   schemaVersion?:int,
	 *   countsByVersion?:array<int,int>
	 * }|null $summary
	 * @return list<array{
	 *   version:int,
	 *   bumpedAt:string,
	 *   source:string,
	 *   isCurrent:bool,
	 *   instanceCount:int,
	 *   isConflict:bool,
	 *   knownDate:bool
	 * }>
	 */
	public static function list_version_stack( string $taxonomy, int $structure_id, ?array $summary = null ): array {
		if ( ! Taxonomy::is_scaffold( $taxonomy ) || $structure_id <= 0 ) {
			return array();
		}

		$summary = null !== $summary ? $summary : self::summarize_host( $taxonomy, $structure_id );
		$schema  = max( 1, (int) ( $summary['schemaVersion'] ?? self::get( $taxonomy, $structure_id ) ) );
		$counts  = (array) ( $summary['countsByVersion'] ?? array() );

		$by_version = array();
		foreach ( self::read_history_raw( $structure_id ) as $entry ) {
			$ver = (int) ( $entry['version'] ?? 0 );
			if ( $ver < 1 ) {
				continue;
			}
			$by_version[ $ver ] = array(
				'version'  => $ver,
				'bumpedAt' => (string) ( $entry['bumpedAt'] ?? '' ),
				'source'   => self::sanitize_source( (string) ( $entry['source'] ?? 'unknown' ) ),
			);
		}

		if ( ! isset( $by_version[ $schema ] ) ) {
			$by_version[ $schema ] = array(
				'version'  => $schema,
				'bumpedAt' => '',
				'source'   => 1 === $schema ? 'seed' : 'backfill',
			);
		}

		foreach ( $counts as $ver => $_count ) {
			$ver = (int) $ver;
			if ( $ver < 1 || isset( $by_version[ $ver ] ) ) {
				continue;
			}
			$by_version[ $ver ] = array(
				'version'  => $ver,
				'bumpedAt' => '',
				'source'   => 'instance_stamp',
			);
		}

		krsort( $by_version, SORT_NUMERIC );

		$stack = array();
		foreach ( $by_version as $ver => $meta ) {
			$instance_count = (int) ( $counts[ $ver ] ?? 0 );
			$bumped_at      = (string) ( $meta['bumpedAt'] ?? '' );
			$stack[]        = array(
				'version'       => (int) $ver,
				'bumpedAt'      => $bumped_at,
				'source'        => (string) ( $meta['source'] ?? 'unknown' ),
				'isCurrent'     => ( (int) $ver === $schema ),
				'instanceCount' => $instance_count,
				'isConflict'    => ( (int) $ver !== $schema && $instance_count > 0 ),
				'knownDate'     => ( '' !== $bumped_at ),
			);
		}

		return $stack;
	}

	/**
	 * Persist a bump / seed history row (newest last in storage; UI sorts desc).
	 *
	 * @param array{version:int,bumpedAt?:string,source?:string} $entry
	 */
	private static function append_history( int $structure_id, array $entry ): void {
		if ( $structure_id <= 0 ) {
			return;
		}

		$version = (int) ( $entry['version'] ?? 0 );
		if ( $version < 1 ) {
			return;
		}

		$rows   = self::read_history_raw( $structure_id );
		$filtered = array();
		foreach ( $rows as $row ) {
			if ( (int) ( $row['version'] ?? 0 ) !== $version ) {
				$filtered[] = $row;
			}
		}
		$filtered[] = array(
			'version'  => $version,
			'bumpedAt' => (string) ( $entry['bumpedAt'] ?? self::now_utc_mysql() ),
			'source'   => self::sanitize_source( (string) ( $entry['source'] ?? 'structural' ) ),
		);

		if ( count( $filtered ) > self::HISTORY_MAX_ENTRIES ) {
			$filtered = array_slice( $filtered, -1 * self::HISTORY_MAX_ENTRIES );
		}

		Json_Meta::update_term_meta( $structure_id, self::HISTORY_META_KEY, array_values( $filtered ) );
	}

	/**
	 * Ensure version 1 (or current) has a seed history row when meta was first created.
	 */
	private static function ensure_history_seed( int $structure_id, int $version ): void {
		$rows = self::read_history_raw( $structure_id );
		if ( array() !== $rows ) {
			return;
		}
		self::append_history(
			$structure_id,
			array(
				'version'  => max( 1, $version ),
				'bumpedAt' => self::now_utc_mysql(),
				'source'   => 'seed',
			)
		);
	}

	/**
	 * @return list<array{version:int,bumpedAt:string,source:string}>
	 */
	private static function read_history_raw( int $structure_id ): array {
		$raw = get_term_meta( $structure_id, self::HISTORY_META_KEY, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$out = array();
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$ver = (int) ( $row['version'] ?? 0 );
			if ( $ver < 1 ) {
				continue;
			}
			$out[] = array(
				'version'  => $ver,
				'bumpedAt' => (string) ( $row['bumpedAt'] ?? '' ),
				'source'   => self::sanitize_source( (string) ( $row['source'] ?? 'unknown' ) ),
			);
		}

		return $out;
	}

	private static function sanitize_source( string $source ): string {
		$source = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $source ) ?? '' );
		if ( '' === $source ) {
			return 'unknown';
		}
		return substr( $source, 0, 32 );
	}

	private static function now_utc_mysql(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
