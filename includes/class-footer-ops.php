<?php
/**
 * Table Fuss aggregate operations (Q57).
 *
 * Shared catalog for footer cell ops. avg = Durchschnitt / Mittelwert
 * (arithmetic mean) — one operation, not two.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FooterAggOp definitions for table Fuss bands.
 */
final class Footer_Ops {

	public const NONE  = 'none';
	public const TEXT  = 'text';
	public const SUM   = 'sum';
	public const AVG   = 'avg';
	public const MIN   = 'min';
	public const MAX   = 'max';
	public const COUNT = 'count';

	/**
	 * Folder under Definition for aggregate catalog nodes.
	 */
	public const CATALOG_FOLDER = 'Aggregate';

	/**
	 * @return array<string, array{key:string,numeric:bool,symbol:string,label:string}>
	 */
	public static function catalog(): array {
		return array(
			self::NONE  => array(
				'key'     => self::NONE,
				'numeric' => false,
				'symbol'  => '—',
				'label'   => __( 'None', 'wp-taxonomy-tree' ),
			),
			self::TEXT  => array(
				'key'     => self::TEXT,
				'numeric' => false,
				'symbol'  => '—',
				'label'   => __( 'Text', 'wp-taxonomy-tree' ),
			),
			self::SUM   => array(
				'key'     => self::SUM,
				'numeric' => true,
				'symbol'  => 'Σ',
				'label'   => __( 'Sum', 'wp-taxonomy-tree' ),
			),
			self::AVG   => array(
				'key'     => self::AVG,
				'numeric' => true,
				'symbol'  => 'Ø',
				'label'   => __( 'Average', 'wp-taxonomy-tree' ),
			),
			self::MIN   => array(
				'key'     => self::MIN,
				'numeric' => true,
				'symbol'  => 'min',
				'label'   => __( 'Minimum', 'wp-taxonomy-tree' ),
			),
			self::MAX   => array(
				'key'     => self::MAX,
				'numeric' => true,
				'symbol'  => 'max',
				'label'   => __( 'Maximum', 'wp-taxonomy-tree' ),
			),
			self::COUNT => array(
				'key'     => self::COUNT,
				'numeric' => false,
				'symbol'  => 'n',
				'label'   => __( 'Count', 'wp-taxonomy-tree' ),
			),
		);
	}

	/**
	 * Resolve catalog term id for an op key (0 if missing).
	 */
	public static function catalog_term_id( string $taxonomy, string $op_key ): int {
		$op_key = strtolower( sanitize_key( $op_key ) );
		if ( '' === $op_key || ! isset( self::catalog()[ $op_key ] ) ) {
			return 0;
		}
		$path_opts = array(
			array( Case_Data::ROOT_NAME, 'Definition', self::CATALOG_FOLDER, $op_key ),
			array( 'Definition', self::CATALOG_FOLDER, $op_key ),
		);
		foreach ( $path_opts as $path ) {
			$id = Case_Data::find_term_by_path( $taxonomy, $path );
			if ( $id > 0 ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * Picker rows for a column type (includes catalog term ids when seeded).
	 *
	 * @return list<array{key:string,id:int,numeric:bool,symbol:string,label:string}>
	 */
	public static function picker_options( string $taxonomy, string $type_key ): array {
		$out = array();
		foreach ( self::for_type( $type_key ) as $def ) {
			$out[] = array(
				'key'     => $def['key'],
				'id'      => self::catalog_term_id( $taxonomy, $def['key'] ),
				'numeric' => $def['numeric'],
				'symbol'  => $def['symbol'],
				'label'   => $def['label'],
			);
		}
		return $out;
	}

	public static function is_numeric_type( string $type_key ): bool {
		$key = strtolower( trim( $type_key ) );
		return in_array( $key, array( 'int', 'integer', 'double' ), true );
	}

	/**
	 * Normalize aliases (summe, durchschnitt, mittelwert, label, …).
	 *
	 * @return array{key:string,numeric:bool,symbol:string,label:string}
	 */
	public static function normalize( string $op, string $type_key = '' ): array {
		$key = strtolower( trim( $op ) );
		$map = array(
			'label'         => self::TEXT,
			'average'       => self::AVG,
			'mean'          => self::AVG,
			'mittelwert'    => self::AVG,
			'durchschnitt'  => self::AVG,
			'summe'         => self::SUM,
		);
		if ( isset( $map[ $key ] ) ) {
			$key = $map[ $key ];
		}
		$catalog = self::catalog();
		if ( ! isset( $catalog[ $key ] ) ) {
			$key = self::is_numeric_type( $type_key ) ? self::SUM : self::TEXT;
		}
		$def = $catalog[ $key ];
		if ( $def['numeric'] && ! self::is_numeric_type( $type_key ) && self::COUNT !== $key ) {
			return $catalog[ self::TEXT ];
		}
		return $def;
	}

	/**
	 * Ops allowed for a column type (UI picker).
	 *
	 * @return list<array{key:string,numeric:bool,symbol:string,label:string}>
	 */
	public static function for_type( string $type_key ): array {
		$numeric = self::is_numeric_type( $type_key );
		$out     = array();
		foreach ( self::catalog() as $def ) {
			if ( $def['numeric'] && ! $numeric ) {
				continue;
			}
			$out[] = $def;
		}
		return $out;
	}

	/**
	 * Evaluate an aggregate op over a flat list of numbers (Q57 / computed attrs).
	 *
	 * @param list<float|int|string> $values Numeric contributions.
	 * @return string|null Display string, or null when empty / unknown op.
	 */
	public static function evaluate( string $op, array $values ): ?string {
		$op = strtolower( sanitize_key( $op ) );
		if ( self::COUNT === $op ) {
			return (string) count( $values );
		}
		$nums = array();
		foreach ( $values as $v ) {
			if ( is_numeric( $v ) ) {
				$nums[] = (float) $v;
			}
		}
		if ( array() === $nums ) {
			return null;
		}
		switch ( $op ) {
			case self::SUM:
				return self::format_number( array_sum( $nums ) );
			case self::AVG:
				return self::format_number( array_sum( $nums ) / count( $nums ) );
			case self::MIN:
				return self::format_number( min( $nums ) );
			case self::MAX:
				return self::format_number( max( $nums ) );
			default:
				return null;
		}
	}

	/**
	 * Compact display for computed / footer samples.
	 */
	private static function format_number( float $n ): string {
		if ( abs( $n - round( $n ) ) < 0.0000001 ) {
			return (string) (int) round( $n );
		}
		return rtrim( rtrim( number_format( $n, 6, '.', '' ), '0' ), '.' );
	}
}
