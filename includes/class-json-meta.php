<?php
/**
 * Safe JSON encode/decode for WordPress meta and option storage.
 *
 * WordPress `update_term_meta` / `update_post_meta` / `update_option` run
 * `wp_unslash` on values. Default `json_encode` emits `\u00e4` for "ä"; after
 * stripslashes that becomes the literal `u00e4` ("Währung" → "Wu00e4hrung").
 *
 * Always use {@see Json_Meta::encode()} (or {@see update_term_meta()}) when
 * persisting JSON strings through those APIs.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON helpers that preserve UTF-8 through WP slashing.
 */
final class Json_Meta {

	/** One-shot: repair stripped `\uXXXX` → `uXXXX` in Relation.name + term names. */
	public const OPTION_UNICODE_REPAIRED = 'wtt_json_meta_unicode_repaired_v1';

	/**
	 * Encode for meta/option write paths (UTF-8 + wp_slash).
	 *
	 * @param mixed $data Data to encode.
	 * @return string|false Slashing-safe JSON, or false on encode failure.
	 */
	public static function encode( $data ) {
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return false;
		}
		return wp_slash( $json );
	}

	/**
	 * Encode for script tags / HTTP bodies (no wp_slash).
	 *
	 * @param mixed $data Data to encode.
	 * @return string|false
	 */
	public static function encode_raw( $data ) {
		return wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param mixed $data Data to encode as JSON meta.
	 * @return int|bool Meta id / true / false (same as update_term_meta).
	 */
	public static function update_term_meta( int $term_id, string $meta_key, $data ) {
		$json = self::encode( $data );
		if ( false === $json ) {
			return false;
		}
		return update_term_meta( $term_id, $meta_key, $json );
	}

	/**
	 * @param mixed $data Data to encode as JSON meta.
	 * @return int|bool
	 */
	public static function update_post_meta( int $post_id, string $meta_key, $data ) {
		$json = self::encode( $data );
		if ( false === $json ) {
			return false;
		}
		return update_post_meta( $post_id, $meta_key, $json );
	}

	/**
	 * Whether the string looks like it contains stripped JSON `\uXXXX` escapes.
	 */
	public static function has_stripped_unicode_escapes( string $value ): bool {
		return 1 === preg_match( '/(?<!\\\\)u[0-9a-fA-F]{4}/', $value );
	}

	/**
	 * Repair literal `u00e4` (etc.) back to real UTF-8 characters.
	 *
	 * Only non-ASCII BMP code points are rewritten (what json_encode escapes).
	 */
	public static function repair_stripped_unicode_escapes( string $value ): string {
		if ( ! self::has_stripped_unicode_escapes( $value ) ) {
			return $value;
		}
		$repaired = preg_replace_callback(
			'/(?<!\\\\)u([0-9a-fA-F]{4})/',
			static function ( array $m ): string {
				$cp = hexdec( $m[1] );
				if ( $cp < 0x80 || $cp > 0xFFFF ) {
					return $m[0];
				}
				if ( function_exists( 'mb_chr' ) ) {
					$ch = mb_chr( $cp, 'UTF-8' );
					return is_string( $ch ) ? $ch : $m[0];
				}
				return html_entity_decode( '&#x' . $m[1] . ';', ENT_QUOTES, 'UTF-8' );
			},
			$value
		);
		return is_string( $repaired ) ? $repaired : $value;
	}

	/**
	 * Idempotent walk: fix Relation edge names + term names with stripped escapes.
	 *
	 * @return array{repaired:bool,edges:int,terms:int}
	 */
	public static function maybe_repair_taxonomy( string $taxonomy ): array {
		$empty = array(
			'repaired' => false,
			'edges'    => 0,
			'terms'    => 0,
		);
		if ( get_option( self::OPTION_UNICODE_REPAIRED ) ) {
			return $empty;
		}
		$result = self::repair_taxonomy( $taxonomy );
		update_option( self::OPTION_UNICODE_REPAIRED, 1, false );
		$result['repaired'] = true;
		return $result;
	}

	/**
	 * Force repair pass (also usable from CLI scripts).
	 *
	 * @return array{repaired:bool,edges:int,terms:int}
	 */
	public static function repair_taxonomy( string $taxonomy ): array {
		$edges_fixed = 0;
		$terms_fixed = 0;

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array(
				'repaired' => false,
				'edges'    => 0,
				'terms'    => 0,
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array(
				'repaired' => false,
				'edges'    => 0,
				'terms'    => 0,
			);
		}

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$term_id = (int) $term->term_id;

			if ( self::has_stripped_unicode_escapes( (string) $term->name ) ) {
				$fixed_name = self::repair_stripped_unicode_escapes( (string) $term->name );
				if ( $fixed_name !== $term->name ) {
					$updated = wp_update_term(
						$term_id,
						$taxonomy,
						array( 'name' => $fixed_name )
					);
					if ( ! is_wp_error( $updated ) ) {
						++$terms_fixed;
					}
				}
			}

			$raw = get_term_meta( $term_id, Relation::META_KEY, true );
			if ( ! is_string( $raw ) || '' === $raw ) {
				continue;
			}
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$dirty = false;
			foreach ( $decoded as $i => $edge ) {
				if ( ! is_array( $edge ) || ! isset( $edge['name'] ) ) {
					continue;
				}
				$name = (string) $edge['name'];
				if ( ! self::has_stripped_unicode_escapes( $name ) ) {
					continue;
				}
				$fixed = self::repair_stripped_unicode_escapes( $name );
				if ( $fixed !== $name ) {
					$decoded[ $i ]['name'] = $fixed;
					$dirty                 = true;
					++$edges_fixed;
				}
			}
			if ( $dirty ) {
				self::update_term_meta( $term_id, Relation::META_KEY, array_values( $decoded ) );
			}
		}

		return array(
			'repaired' => ( $edges_fixed + $terms_fixed ) > 0,
			'edges'    => $edges_fixed,
			'terms'    => $terms_fixed,
		);
	}
}
