<?php
/**
 * Model schema version stamps (UR-S1 scaffold).
 *
 * Structure hosts store an integer schema version in term meta.
 * Model_Data instances carry `modelVersion` stamped on create/save.
 * Conflict = instance stamp ≠ current host schema version.
 *
 * This slice is shell + stamps only — no migrator / mapping DSL yet.
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

	/** Default schema / stamp when meta or instance field is missing. */
	public const DEFAULT_VERSION = 1;

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
			return self::DEFAULT_VERSION;
		}

		$version = (int) $raw;
		if ( $version < 1 ) {
			update_term_meta( $structure_id, self::META_KEY, self::DEFAULT_VERSION );
			return self::DEFAULT_VERSION;
		}

		return $version;
	}

	/**
	 * Set schema version explicitly (clamped ≥ 1).
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
	 * Increment schema version by 1 and return the new value.
	 */
	public static function bump( string $taxonomy, int $structure_id ): int {
		$current = self::get( $taxonomy, $structure_id );
		return self::set( $taxonomy, $structure_id, $current + 1 );
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
}
