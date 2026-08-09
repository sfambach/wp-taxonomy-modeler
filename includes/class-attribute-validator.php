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
			if ( ! self::row_readonly_without_default( $row ) ) {
				continue;
			}

			$attr_id   = (int) ( $row['id'] ?? 0 );
			$attr_name = (string) ( $row['name'] ?? '' );
			if ( '' === $attr_name ) {
				$attr_name = '#' . (string) $attr_id;
			}

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
		return ! self::row_has_default_value( $row );
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
	 * @return array{validation:array}|\WP_Error
	 */
	public static function apply_fix( string $taxonomy, int $host_id, int $attr_id, string $action ) {
		$action = strtolower( sanitize_key( $action ) );

		if ( self::FIX_SET_DEFAULT === $action ) {
			return new \WP_Error(
				'wtt_fix_needs_ui',
				__( 'Set a default value in the Default dialog.', 'wp-taxonomy-tree' )
			);
		}

		if ( self::FIX_CLEAR_READONLY !== $action ) {
			return new \WP_Error( 'wtt_bad_fix', __( 'Unknown attribute fix action.', 'wp-taxonomy-tree' ) );
		}

		$result = Attribute::set_readonly( $taxonomy, $host_id, $attr_id, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'validation' => self::validate( $taxonomy, $host_id ),
		);
	}
}
