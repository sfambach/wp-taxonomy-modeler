<?php
/**
 * Value converter registry (PHP side) — known ids + type applicability.
 *
 * JS SoT for canConvert / format lives in assets/js/wtt-converter.js (WTTConverter.Registry).
 * This class validates stored preferred-converter keys and lists known converters.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

final class Converter {

	/**
	 * Built-in converters (id → applies-to type keys).
	 *
	 * @var array<string, list<string>>
	 */
	private const BUILTIN = array(
		'arabic' => array( 'int' ),
		'roman'  => array( 'int' ),
		'binary' => array( 'int' ),
		'octal'  => array( 'int' ),
		'hex'    => array( 'int' ),
	);

	public const DEFAULT_INT = 'arabic';

	/**
	 * Normalize a preferred converter id (empty when invalid).
	 */
	public static function normalize_id( string $id ): string {
		$key = strtolower( trim( $id ) );
		if ( '' === $key ) {
			return '';
		}
		if ( isset( self::BUILTIN[ $key ] ) ) {
			return $key;
		}
		/* Forward-compatible custom ids. */
		if ( 1 === preg_match( '/^[a-z][a-z0-9_-]*$/', $key ) ) {
			return $key;
		}
		return '';
	}

	/**
	 * @return list<string>
	 */
	public static function known_ids(): array {
		return array_keys( self::BUILTIN );
	}

	/**
	 * Whether converter applies to a canonical type key (e.g. int).
	 */
	public static function applies_to_type( string $converter_id, string $type_key ): bool {
		$id  = self::normalize_id( $converter_id );
		$key = Node_Type::normalize_type_name( $type_key );
		if ( 'integer' === $key ) {
			$key = 'int';
		}
		if ( '' === $id || '' === $key ) {
			return false;
		}
		if ( ! isset( self::BUILTIN[ $id ] ) ) {
			/* Unknown custom id: allow until JS registry filters. */
			return true;
		}
		return in_array( $key, self::BUILTIN[ $id ], true );
	}

	/**
	 * Default preferred converter for a type key (empty when none).
	 */
	public static function default_for_type( string $type_key ): string {
		$key = Node_Type::normalize_type_name( $type_key );
		if ( 'integer' === $key ) {
			$key = 'int';
		}
		if ( 'int' === $key ) {
			return self::DEFAULT_INT;
		}
		return '';
	}

	/**
	 * @return list<array{id:string,label:string}>
	 */
	public static function list_for_type( string $type_key ): array {
		$key = Node_Type::normalize_type_name( $type_key );
		if ( 'integer' === $key ) {
			$key = 'int';
		}
		$out = array();
		foreach ( self::BUILTIN as $id => $types ) {
			if ( ! in_array( $key, $types, true ) ) {
				continue;
			}
			$out[] = array(
				'id'    => $id,
				'label' => self::label_for( $id ),
			);
		}
		return $out;
	}

	public static function label_for( string $id ): string {
		$id = self::normalize_id( $id );
		$map = array(
			'arabic' => __( 'Arabic (decimal)', 'wp-taxonomy-tree' ),
			'roman'  => __( 'Roman', 'wp-taxonomy-tree' ),
			'binary' => __( 'Binary', 'wp-taxonomy-tree' ),
			'octal'  => __( 'Octal', 'wp-taxonomy-tree' ),
			'hex'    => __( 'Hexadecimal', 'wp-taxonomy-tree' ),
		);
		return $map[ $id ] ?? $id;
	}
}
