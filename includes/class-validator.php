<?php
/**
 * Value validator registry (PHP) — defaults, normalize configs, type applicability.
 *
 * JS SoT for validate / expression eval: assets/js/wtt-validator.js (WTTValidator.Registry).
 * Node meta `_wtt_validators`: 0..n entries { id, errorText, expression?, fixes? }.
 *
 * Simple type defaults (empty meta → ensure_validators):
 *   int → integer_shape, double → number_shape, email → email_shape,
 *   char → char_shape, date → date_shape, media → media_shape.
 *   text / textarea / bool → none (optional builtins remain addable).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

final class Validator {

	public const META_KEY = '_wtt_validators';

	/**
	 * Built-in validator id → applies-to type keys (empty list = all types).
	 *
	 * @var array<string, list<string>>
	 */
	private const BUILTIN = array(
		'integer_shape' => array( 'int' ),
		'number_shape'  => array( 'double' ),
		'bool_shape'    => array( 'bool' ), /* optional — no type default */
		'email_shape'   => array( 'email' ),
		'text_shape'    => array( 'text', 'textarea' ), /* optional — no type default */
		'char_shape'    => array( 'char' ),
		'date_shape'    => array( 'date' ),
		'media_shape'   => array( 'media' ),
		'expression'    => array(), /* any type — instance needs expression */
	);

	/**
	 * Default builtin id per type key (absent = no default).
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_BY_TYPE = array(
		'int'    => 'integer_shape',
		'double' => 'number_shape',
		'email'  => 'email_shape',
		'char'   => 'char_shape',
		'date'   => 'date_shape',
		'media'  => 'media_shape',
	);

	/**
	 * Normalize a stored validators list.
	 *
	 * @param mixed $raw Raw meta / JSON.
	 * @return list<array{id:string,errorText:string,expression?:string,isDefault?:bool,fixes?:list<array<string,mixed>>}>
	 */
	public static function normalize_list( $raw ): array {
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$norm = self::normalize_entry( $row );
			if ( null !== $norm ) {
				$out[] = $norm;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $row Raw entry.
	 * @return array{id:string,errorText:string,expression?:string,isDefault?:bool,fixes?:list<array<string,mixed>>}|null
	 */
	public static function normalize_entry( array $row ): ?array {
		$id = strtolower( trim( (string) ( $row['id'] ?? '' ) ) );
		if ( '' === $id || ! self::is_known_id( $id ) ) {
			return null;
		}
		$error = trim( (string) ( $row['errorText'] ?? $row['error_text'] ?? '' ) );
		if ( '' === $error ) {
			$error = self::default_error_text( $id );
		}
		$entry = array(
			'id'        => $id,
			'errorText' => $error,
		);
		if ( ! empty( $row['isDefault'] ) || ! empty( $row['is_default'] ) ) {
			$entry['isDefault'] = true;
		}
		if ( 'expression' === $id ) {
			$expr = trim( (string) ( $row['expression'] ?? '' ) );
			if ( '' === $expr ) {
				return null;
			}
			$entry['expression'] = $expr;
		}
		$fixes = $row['fixes'] ?? null;
		if ( is_array( $fixes ) && $fixes ) {
			$entry['fixes'] = self::normalize_fixes( $fixes );
		}
		return $entry;
	}

	/**
	 * @param list<mixed> $fixes Raw fixes.
	 * @return list<array{action:string,label:string}>
	 */
	public static function normalize_fixes( array $fixes ): array {
		$out = array();
		foreach ( $fixes as $fix ) {
			if ( ! is_array( $fix ) ) {
				continue;
			}
			$action = sanitize_key( (string) ( $fix['action'] ?? 'hint' ) );
			$label  = trim( (string) ( $fix['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$out[] = array(
				'action' => '' !== $action ? $action : 'hint',
				'label'  => $label,
			);
		}
		return $out;
	}

	public static function is_known_id( string $id ): bool {
		$id = strtolower( trim( $id ) );
		return isset( self::BUILTIN[ $id ] );
	}

	/**
	 * Whether builtin applies to type key (expression applies to all).
	 */
	public static function applies_to_type( string $validator_id, string $type_key ): bool {
		$id  = strtolower( trim( $validator_id ) );
		$key = self::normalize_type_key( $type_key );
		if ( '' === $id || ! isset( self::BUILTIN[ $id ] ) ) {
			return false;
		}
		$types = self::BUILTIN[ $id ];
		if ( array() === $types ) {
			return true;
		}
		return in_array( $key, $types, true );
	}

	public static function default_id_for_type( string $type_key ): string {
		$key = self::normalize_type_key( $type_key );
		return self::DEFAULT_BY_TYPE[ $key ] ?? '';
	}

	/**
	 * Default validator list for a type (0..1 default entry).
	 *
	 * @return list<array{id:string,errorText:string,isDefault:bool,fixes:list}>
	 */
	public static function default_list_for_type( string $type_key ): array {
		$id = self::default_id_for_type( $type_key );
		if ( '' === $id ) {
			return array();
		}
		return array(
			array(
				'id'        => $id,
				'errorText' => self::default_error_text( $id ),
				'isDefault' => true,
				'fixes'     => array(),
			),
		);
	}

	public static function default_error_text( string $validator_id ): string {
		$id  = strtolower( trim( $validator_id ) );
		$map = array(
			'integer_shape' => __( 'Enter a whole number.', 'wp-taxonomy-tree' ),
			'number_shape'  => __( 'Enter a number.', 'wp-taxonomy-tree' ),
			'bool_shape'    => __( 'Enter a boolean value.', 'wp-taxonomy-tree' ),
			'email_shape'   => __( 'Enter a valid email address.', 'wp-taxonomy-tree' ),
			'text_shape'    => __( 'Enter text.', 'wp-taxonomy-tree' ),
			'char_shape'    => __( 'Enter exactly one character.', 'wp-taxonomy-tree' ),
			'date_shape'    => __( 'Enter a valid date.', 'wp-taxonomy-tree' ),
			'media_shape'   => __( 'Enter a media attachment, URL, or media reference.', 'wp-taxonomy-tree' ),
			'expression'    => __( 'Value does not satisfy the expression.', 'wp-taxonomy-tree' ),
		);
		return $map[ $id ] ?? __( 'Invalid value.', 'wp-taxonomy-tree' );
	}

	public static function label_for( string $validator_id ): string {
		$id  = strtolower( trim( $validator_id ) );
		$map = array(
			'integer_shape' => __( 'Integer shape', 'wp-taxonomy-tree' ),
			'number_shape'  => __( 'Number shape', 'wp-taxonomy-tree' ),
			'bool_shape'    => __( 'Boolean shape', 'wp-taxonomy-tree' ),
			'email_shape'   => __( 'Email shape', 'wp-taxonomy-tree' ),
			'text_shape'    => __( 'Text shape', 'wp-taxonomy-tree' ),
			'char_shape'    => __( 'Single character', 'wp-taxonomy-tree' ),
			'date_shape'    => __( 'Date shape', 'wp-taxonomy-tree' ),
			'media_shape'   => __( 'Media shape', 'wp-taxonomy-tree' ),
			'expression'    => __( 'Expression', 'wp-taxonomy-tree' ),
		);
		return $map[ $id ] ?? $id;
	}

	/**
	 * Builtins compatible with a type (for Add picker). Includes expression.
	 *
	 * @return list<array{id:string,label:string}>
	 */
	public static function list_compatible_ids( string $type_key ): array {
		$key = self::normalize_type_key( $type_key );
		$out = array();
		foreach ( self::BUILTIN as $id => $types ) {
			if ( array() !== $types && ! in_array( $key, $types, true ) ) {
				continue;
			}
			$out[] = array(
				'id'    => $id,
				'label' => self::label_for( $id ),
			);
		}
		return $out;
	}

	/**
	 * Merge stored list with type default (ensure default present once).
	 *
	 * @param list<array<string,mixed>> $stored Stored entries.
	 * @param string                    $type_key Type key.
	 * @return list<array{id:string,errorText:string,expression?:string,isDefault?:bool,fixes?:list}>
	 */
	public static function effective_list( array $stored, string $type_key ): array {
		$stored   = self::normalize_list( $stored );
		$defaults = self::default_list_for_type( $type_key );
		if ( array() === $defaults ) {
			return $stored;
		}
		$default_id  = $defaults[0]['id'];
		$has_default = false;
		foreach ( $stored as $row ) {
			if ( ( $row['id'] ?? '' ) === $default_id && ! empty( $row['isDefault'] ) ) {
				$has_default = true;
				break;
			}
			if ( ( $row['id'] ?? '' ) === $default_id && 'expression' !== $default_id ) {
				$has_default = true;
				break;
			}
		}
		if ( $has_default ) {
			return $stored;
		}
		return array_merge( $defaults, $stored );
	}

	/**
	 * Server-side shape check for one builtin (mirrors JS Registry.validate).
	 * Message-only — no auto-fixes.
	 *
	 * @param array{allowEmpty?:bool,expression?:string} $opts Options.
	 * @return array{ok:bool,message?:string,failedId?:string,fixes?:list,warnings?:list}
	 */
	public static function validate_value( string $validator_id, $value, array $opts = array() ): array {
		$id          = strtolower( trim( $validator_id ) );
		$allow_empty = ! array_key_exists( 'allowEmpty', $opts ) || (bool) $opts['allowEmpty'];
		$msg         = self::default_error_text( $id );
		$s           = null === $value ? '' : ( is_string( $value ) ? $value : wp_json_encode( $value ) );
		if ( false === $s ) {
			$s = '';
		}
		$s = is_string( $value ) || is_numeric( $value ) ? trim( (string) $value ) : trim( (string) $s );

		if ( '' === $s ) {
			if ( $allow_empty ) {
				return array(
					'ok'       => true,
					'warnings' => array(),
				);
			}
			return array(
				'ok'       => false,
				'message'  => $msg,
				'failedId' => $id,
				'fixes'    => array(),
				'warnings' => array(),
			);
		}

		$ok = false;
		switch ( $id ) {
			case 'integer_shape':
				$ok = (bool) preg_match( '/^-?\d+$/', $s );
				break;
			case 'number_shape':
				$ok = (bool) preg_match( '/^-?\d+(\.\d+)?$/', $s ) && is_finite( (float) $s );
				break;
			case 'bool_shape':
				$ok = in_array( strtolower( $s ), array( '0', '1', 'true', 'false', 'yes', 'no' ), true );
				break;
			case 'email_shape':
				$ok = Node_Type::is_valid_email_value( $s );
				break;
			case 'text_shape':
				$ok = true;
				break;
			case 'char_shape':
				$ok = self::is_single_character( $s );
				break;
			case 'date_shape':
				$ok = self::is_flexible_date_value( $s );
				break;
			case 'media_shape':
				$ok = self::is_media_ref_value( $value );
				break;
			case 'expression':
				/* Expression eval is JS SoT; PHP reports fail without expression context. */
				$ok = false;
				break;
			default:
				$ok = false;
		}

		if ( $ok ) {
			return array(
				'ok'       => true,
				'warnings' => array(),
			);
		}
		return array(
			'ok'       => false,
			'message'  => $msg,
			'failedId' => $id,
			'fixes'    => array(),
			/* Q107 envelope slot — shape validators emit errors only today. */
			'warnings' => array(),
		);
	}

	/**
	 * Exactly one Unicode character (grapheme when intl available).
	 */
	public static function is_single_character( string $value ): bool {
		$value = (string) $value;
		if ( '' === $value ) {
			return false;
		}
		if ( function_exists( 'grapheme_strlen' ) ) {
			return 1 === grapheme_strlen( $value );
		}
		if ( function_exists( 'mb_strlen' ) ) {
			return 1 === mb_strlen( $value, 'UTF-8' );
		}
		return 1 === strlen( $value );
	}

	/**
	 * Flexible date acceptance (shape validator — not store SoT).
	 *
	 * Accepts:
	 * - 4-digit year only (1000–9999), e.g. `2026`
	 * - Unix timestamp decimal (5+ digits — not confused with year-only)
	 * - Compact `Ymd` (8 digits)
	 * - `Y-m-d` / `Y-m-d H:i[:s]`, `Y/m/d`, `Y.m.d`, `d.m.Y`, `d/m/Y`
	 * - Other strings that strtotime can parse into a real calendar date
	 *
	 * Rejects alphabetic garbage and impossible calendar dates.
	 */
	public static function is_flexible_date_value( string $raw ): bool {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return false;
		}

		/* Year-only. */
		if ( preg_match( '/^\d{4}$/', $raw ) ) {
			$y = (int) $raw;
			return $y >= 1000 && $y <= 9999;
		}

		/* Compact Ymd. */
		if ( preg_match( '/^\d{8}$/', $raw ) ) {
			$dt = \DateTimeImmutable::createFromFormat( '!Ymd', $raw );
			return $dt instanceof \DateTimeImmutable && $dt->format( 'Ymd' ) === $raw;
		}

		/* Unix timestamp (avoid 1–4 digit ints — those are not years here except handled above). */
		if ( preg_match( '/^-?\d{5,}$/', $raw ) ) {
			return true;
		}

		$tz      = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$formats = array(
			'Y-m-d H:i:s',
			'Y-m-d H:i',
			'Y-m-d',
			'Y/m/d',
			'Y.m.d',
			'd.m.Y',
			'd/m/Y',
			'd.m.Y H:i',
			'd/m/Y H:i',
		);
		foreach ( $formats as $fmt ) {
			$dt = \DateTimeImmutable::createFromFormat( $fmt, $raw, $tz );
			if ( ! $dt instanceof \DateTimeImmutable ) {
				continue;
			}
			$errors = \DateTimeImmutable::getLastErrors();
			if ( is_array( $errors ) && ( ( $errors['warning_count'] ?? 0 ) > 0 || ( $errors['error_count'] ?? 0 ) > 0 ) ) {
				continue;
			}
			return true;
		}

		/* Must look date-like before strtotime (rejects pure words). */
		if ( ! preg_match( '/\d/', $raw ) || ! preg_match( '/[^\d]/', $raw ) ) {
			return false;
		}
		$ts = strtotime( $raw );
		return false !== $ts;
	}

	/**
	 * MediaRef-like value: attachment id, URL, or JSON {attachment_id|url|filename}.
	 *
	 * @param mixed $value Raw store / UI value.
	 */
	public static function is_media_ref_value( $value ): bool {
		if ( null === $value || '' === $value ) {
			return false;
		}
		if ( is_array( $value ) ) {
			$att  = (int) ( $value['attachment_id'] ?? 0 );
			$url  = trim( (string) ( $value['url'] ?? '' ) );
			$file = trim( (string) ( $value['filename'] ?? '' ) );
			if ( $att > 0 ) {
				return true;
			}
			if ( '' !== $file ) {
				return true;
			}
			return self::looks_like_media_url( $url );
		}
		$s = trim( (string) $value );
		if ( preg_match( '/^\d+$/', $s ) ) {
			return (int) $s > 0;
		}
		if ( '{' === $s[0] ) {
			$decoded = json_decode( $s, true );
			if ( is_array( $decoded ) ) {
				return self::is_media_ref_value( $decoded );
			}
			return false;
		}
		return self::looks_like_media_url( $s );
	}

	private static function looks_like_media_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url ) {
			return false;
		}
		if ( preg_match( '#^(https?:)?//#i', $url ) ) {
			return true;
		}
		if ( str_starts_with( $url, '/' ) ) {
			return true;
		}
		if ( function_exists( 'wp_http_validate_url' ) && false !== wp_http_validate_url( $url ) ) {
			return true;
		}
		return (bool) filter_var( $url, FILTER_VALIDATE_URL );
	}

	private static function normalize_type_key( string $type_key ): string {
		$key = Node_Type::normalize_type_name( $type_key );
		if ( 'integer' === $key ) {
			return 'int';
		}
		if ( 'boolean' === $key ) {
			return 'bool';
		}
		if ( 'float' === $key || 'number' === $key ) {
			return 'double';
		}
		if ( 'string' === $key ) {
			return 'text';
		}
		return $key;
	}
}
