<?php
/**
 * Attribute host rules (Bindings → Rules → Fixes).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema rules over attribute rows on a host.
 *
 * Envelope: `{ ok, blocking, errors[], warnings[], fixes[] }`.
 */
final class Attribute_Validator {

	public const RULE_READONLY_NEEDS_DEFAULT = 'readonly_needs_default';

	public const FIX_CLEAR_READONLY = 'clear_readonly';

	public const FIX_SET_DEFAULT = 'set_default';

	/** Q105: own Background-only (Hide) requires Mult 0..1. */
	public const RULE_BACKGROUND_ONLY_NEEDS_MULT = 'background_only_needs_mult';

	public const FIX_SET_MULT_01 = 'set_mult_01';

	public const FIX_CLEAR_HIDE = 'clear_hide';

	/**
	 * Validate effective attributes on a host.
	 *
	 * @return array{
	 *   ok:bool,
	 *   blocking:bool,
	 *   errors:list<string>,
	 *   warnings:list<string>,
	 *   fixes:list<array<string,mixed>>
	 * }
	 */
	public static function validate( string $taxonomy, int $host_id ): array {
		$attrs = Attribute::list( $taxonomy, $host_id );
		return self::validate_rows( $attrs );
	}

	/**
	 * @param list<array<string,mixed>> $attrs Effective attribute rows.
	 * @return array{
	 *   ok:bool,
	 *   blocking:bool,
	 *   errors:list<string>,
	 *   warnings:list<string>,
	 *   fixes:list<array<string,mixed>>
	 * }
	 */
	public static function validate_rows( array $attrs ): array {
		$errors   = array();
		$fixes    = array();
		$blocking = false;

		foreach ( $attrs as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$attr_id   = Attribute::normalize_attr_id( $row['id'] ?? '' );
			$attr_name = (string) ( $row['name'] ?? '' );
			if ( '' === $attr_id ) {
				continue;
			}
			if ( '' === $attr_name ) {
				$attr_name = '#' . $attr_id;
			}

			if ( self::row_readonly_without_default( $row ) ) {
				$blocking = true;
				$errors[] = sprintf(
					/* translators: %s: attribute name */
					__( '“%s” is read-only but has no default value.', 'wp-taxonomy-tree' ),
					$attr_name
				);

				$fixes[] = array(
					'rule'     => self::RULE_READONLY_NEEDS_DEFAULT,
					'action'   => self::FIX_CLEAR_READONLY,
					'attrId'   => $attr_id,
					'attrName' => $attr_name,
				);
				$fixes[] = array(
					'rule'     => self::RULE_READONLY_NEEDS_DEFAULT,
					'action'   => self::FIX_SET_DEFAULT,
					'attrId'   => $attr_id,
					'attrName' => $attr_name,
					'needsUi'  => true,
				);
			}

			if ( self::row_background_only_bad_mult( $row ) ) {
				$blocking = true;
				$errors[] = sprintf(
					/* translators: %s: attribute name */
					__( '“%s” is Background-only (Hide) but multiplicity is not 0..1 or 1.', 'wp-taxonomy-tree' ),
					$attr_name
				);

				$fixes[] = array(
					'rule'     => self::RULE_BACKGROUND_ONLY_NEEDS_MULT,
					'action'   => self::FIX_SET_MULT_01,
					'attrId'   => $attr_id,
					'attrName' => $attr_name,
				);
				$fixes[] = array(
					'rule'     => self::RULE_BACKGROUND_ONLY_NEEDS_MULT,
					'action'   => self::FIX_CLEAR_HIDE,
					'attrId'   => $attr_id,
					'attrName' => $attr_name,
				);
			}
		}

		return array(
			'ok'       => array() === $errors,
			'blocking' => $blocking,
			'errors'   => $errors,
			'warnings' => array(),
			'fixes'    => $fixes,
		);
	}

	/**
	 * Whether a decorated attribute row violates readonly→default.
	 *
	 * Computed attributes are always RO and supply their own value — excluded.
	 *
	 * @param array<string,mixed> $row
	 */
	public static function row_readonly_without_default( array $row ): bool {
		if ( empty( $row['readonly'] ) ) {
			return false;
		}
		if ( ! empty( $row['computed'] ) || ( isset( $row['compute'] ) && is_array( $row['compute'] ) && ! empty( $row['compute']['op'] ) ) ) {
			return false;
		}
		/*
		 * node_presentation (alias display_node_name) shows a live host field —
		 * Festwert is unused (Node_Type rejects fixed). Treat as satisfied.
		 */
		$type_key = strtolower( trim( (string) ( $row['typeKey'] ?? $row['typeName'] ?? '' ) ) );
		if (
			'node_presentation' === $type_key
			|| 'display_node_name' === $type_key
			|| false !== strpos( $type_key, 'node_presentation' )
			|| false !== strpos( $type_key, 'display_node_name' )
		) {
			return false;
		}
		return ! self::row_has_default_value( $row );
	}

	/**
	 * Q105: own Background-only (Hide) with Mult that is not single-valued.
	 *
	 * Inherited hide is cover-up only (no Mult gate).
	 *
	 * @param array<string,mixed> $row
	 */
	public static function row_background_only_bad_mult( array $row ): bool {
		if ( ! empty( $row['inherited'] ) ) {
			return false;
		}
		if ( empty( $row['hidden'] ) ) {
			return false;
		}
		$mult = Relation::normalize_multiplicity(
			(string) ( $row['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY )
		);
		return ! Attribute::multiplicity_allows_background_only( $mult );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function row_has_default_value( array $row ): bool {
		$values = $row['fixedValues'] ?? null;
		if ( ! is_array( $values ) || array() === $values ) {
			return false;
		}
		foreach ( $values as $v ) {
			if ( is_array( $v ) ) {
				if ( array() !== $v ) {
					return true;
				}
				continue;
			}
			if ( is_int( $v ) || is_float( $v ) ) {
				return true;
			}
			if ( is_string( $v ) && '' !== trim( $v ) ) {
				return true;
			}
			if ( is_bool( $v ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Apply a fix for an attribute rule.
	 *
	 * `set_default` is UI-only (needsUi) — open the Default value dialog client-side.
	 *
	 * @param string $attr_id Relation edge id (Q123) or legacy numeric slot id.
	 * @return array{validation:array}|\WP_Error
	 */
	public static function apply_fix( string $taxonomy, int $host_id, string $attr_id, string $action ) {
		$action  = strtolower( sanitize_key( $action ) );
		$attr_id = Attribute::normalize_attr_id( $attr_id );
		if ( '' === $attr_id ) {
			return new \WP_Error( 'wtt_bad_attribute', __( 'Attribute not found.', 'wp-taxonomy-tree' ) );
		}

		if ( self::FIX_SET_DEFAULT === $action ) {
			return new \WP_Error(
				'wtt_fix_needs_ui',
				__( 'Set a default value in the Default dialog.', 'wp-taxonomy-tree' )
			);
		}

		if ( self::FIX_CLEAR_READONLY === $action ) {
			$result = Attribute::set_readonly( $taxonomy, $host_id, $attr_id, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array(
				'validation' => self::validate( $taxonomy, $host_id ),
			);
		}

		if ( self::FIX_CLEAR_HIDE === $action ) {
			$result = Attribute::set_hidden( $taxonomy, $host_id, $attr_id, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array(
				'validation' => self::validate( $taxonomy, $host_id ),
			);
		}

		if ( self::FIX_SET_MULT_01 === $action ) {
			$result = Attribute::set_multiplicity(
				$taxonomy,
				$host_id,
				$attr_id,
				Attribute::BACKGROUND_ONLY_MULTIPLICITY
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array(
				'validation' => self::validate( $taxonomy, $host_id ),
			);
		}

		return new \WP_Error( 'wtt_bad_fix', __( 'Unknown attribute fix action.', 'wp-taxonomy-tree' ) );
	}
}
