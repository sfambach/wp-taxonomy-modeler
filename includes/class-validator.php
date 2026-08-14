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
 *   Optional bounds (addable): int_min / int_max / double_min / double_max
 *   Optional length (addable on text/textarea): text_min_length / text_max_length
 *   Optional charset (addable on char/text/textarea): charset_range / charset_allowlist / charset_regex
 *     (params.value string — ranges `a-z,A-Z,0-9`, allowlist `a,b,c`, regex `[0-9a-z]`)
 *   Numeric bounds/length store one threshold in params.value (seed min=0 max=100).
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
		'int_min'       => array( 'int' ),
		'int_max'       => array( 'int' ),
		'number_shape'  => array( 'double' ),
		'double_min'    => array( 'double' ),
		'double_max'    => array( 'double' ),
		'bool_shape'    => array( 'bool' ), /* optional — no type default */
		'email_shape'   => array( 'email' ),
		'text_shape'       => array( 'text', 'textarea' ), /* optional — no type default */
		'text_min_length'  => array( 'text', 'textarea' ),
		'text_max_length'  => array( 'text', 'textarea' ),
		'char_shape'       => array( 'char' ),
		'charset_range'     => array( 'char', 'text', 'textarea' ),
		'charset_allowlist' => array( 'char', 'text', 'textarea' ),
		'charset_regex'     => array( 'char', 'text', 'textarea' ),
		'date_shape'    => array( 'date' ),
		'media_shape'   => array( 'media' ),
		'expression'    => array(), /* any type — instance needs expression */
	);

	/** Bound / length validators store one numeric threshold in params.value. */
	private const BOUND_IDS = array( 'int_min', 'int_max', 'double_min', 'double_max' );

	private const LENGTH_IDS = array( 'text_min_length', 'text_max_length' );

	/** Charset validators store a string spec in params.value. */
	private const CHARSET_IDS = array( 'charset_range', 'charset_allowlist', 'charset_regex' );

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
	 * @return array{id:string,errorText:string,expression?:string,params?:array{value:int|float},isDefault?:bool,fixes?:list<array<string,mixed>>}|null
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
		if ( self::is_param_threshold_id( $id ) ) {
			$bound = self::bound_value_from_row( $id, $row );
			if ( null !== $bound ) {
				$entry['params'] = array( 'value' => $bound );
			}
		}
		if ( self::is_charset_id( $id ) ) {
			$spec = self::string_param_from_row( $row );
			if ( null !== $spec && '' !== $spec ) {
				$entry['params'] = array( 'value' => $spec );
			}
		}
		$fixes = $row['fixes'] ?? null;
		if ( is_array( $fixes ) && $fixes ) {
			$entry['fixes'] = self::normalize_fixes( $fixes );
		}
		return $entry;
	}

	public static function is_bound_id( string $id ): bool {
		return in_array( strtolower( trim( $id ) ), self::BOUND_IDS, true );
	}

	public static function is_length_id( string $id ): bool {
		return in_array( strtolower( trim( $id ) ), self::LENGTH_IDS, true );
	}

	public static function is_charset_id( string $id ): bool {
		return in_array( strtolower( trim( $id ) ), self::CHARSET_IDS, true );
	}

	/** Bound or length — one numeric params.value threshold. */
	public static function is_param_threshold_id( string $id ): bool {
		return self::is_bound_id( $id ) || self::is_length_id( $id );
	}

	/** Any validator that stores a Bound-column params.value (numeric or string). */
	public static function is_param_value_id( string $id ): bool {
		return self::is_param_threshold_id( $id ) || self::is_charset_id( $id );
	}

	/**
	 * Default Bound-column seed when adding a param validator.
	 *
	 * @return int|float|string
	 */
	public static function default_param_value( string $id ) {
		$id = strtolower( trim( $id ) );
		if ( 'charset_range' === $id ) {
			return 'a-z';
		}
		if ( 'charset_allowlist' === $id ) {
			return 'a,b,c';
		}
		if ( 'charset_regex' === $id ) {
			return '[a-zA-Z0-9]';
		}
		return ( false !== strpos( $id, '_max' ) ) ? 100 : 0;
	}

	/**
	 * String params.value for charset validators.
	 *
	 * @param array<string, mixed> $row Raw entry.
	 */
	public static function string_param_from_row( array $row ): ?string {
		$params = ( isset( $row['params'] ) && is_array( $row['params'] ) ) ? $row['params'] : array();
		$raw    = null;
		if ( array_key_exists( 'value', $params ) ) {
			$raw = $params['value'];
		} elseif ( array_key_exists( 'value', $row ) ) {
			$raw = $row['value'];
		} elseif ( array_key_exists( 'pattern', $params ) ) {
			$raw = $params['pattern'];
		} elseif ( array_key_exists( 'pattern', $row ) ) {
			$raw = $row['pattern'];
		}
		if ( null === $raw ) {
			return null;
		}
		$s = trim( (string) $raw );
		return '' === $s ? null : $s;
	}

	/**
	 * Resolve bound threshold from entry / params (int_* → int, double_* → float).
	 *
	 * @param array<string, mixed> $row Raw entry.
	 * @return int|float|null
	 */
	public static function bound_value_from_row( string $id, array $row ) {
		$id     = strtolower( trim( $id ) );
		$params = ( isset( $row['params'] ) && is_array( $row['params'] ) ) ? $row['params'] : array();
		$raw    = null;
		if ( array_key_exists( 'value', $params ) ) {
			$raw = $params['value'];
		} elseif ( array_key_exists( 'value', $row ) ) {
			$raw = $row['value'];
		} elseif ( ( str_ends_with( $id, '_min' ) || str_ends_with( $id, '_min_length' ) ) && array_key_exists( 'min', $row ) ) {
			$raw = $row['min'];
		} elseif ( ( str_ends_with( $id, '_max' ) || str_ends_with( $id, '_max_length' ) ) && array_key_exists( 'max', $row ) ) {
			$raw = $row['max'];
		} elseif ( ( str_ends_with( $id, '_min' ) || str_ends_with( $id, '_min_length' ) ) && array_key_exists( 'min', $params ) ) {
			$raw = $params['min'];
		} elseif ( ( str_ends_with( $id, '_max' ) || str_ends_with( $id, '_max_length' ) ) && array_key_exists( 'max', $params ) ) {
			$raw = $params['max'];
		} elseif ( self::is_length_id( $id ) && array_key_exists( 'length', $params ) ) {
			$raw = $params['length'];
		}
		if ( null === $raw || '' === $raw || ( is_string( $raw ) && ! is_numeric( trim( $raw ) ) ) ) {
			return null;
		}
		/* Length + int bounds are whole numbers; double keeps float. */
		if ( str_starts_with( $id, 'double_' ) ) {
			return (float) $raw;
		}
		return (int) $raw;
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
			'int_min'          => __( 'Value is below the minimum.', 'wp-taxonomy-tree' ),
			'int_max'          => __( 'Value is above the maximum.', 'wp-taxonomy-tree' ),
			'number_shape'     => __( 'Enter a number.', 'wp-taxonomy-tree' ),
			'double_min'       => __( 'Value is below the minimum.', 'wp-taxonomy-tree' ),
			'double_max'       => __( 'Value is above the maximum.', 'wp-taxonomy-tree' ),
			'bool_shape'       => __( 'Enter a boolean value.', 'wp-taxonomy-tree' ),
			'email_shape'      => __( 'Enter a valid email address.', 'wp-taxonomy-tree' ),
			'text_shape'       => __( 'Enter text.', 'wp-taxonomy-tree' ),
			'text_min_length'  => __( 'Text is shorter than the minimum length.', 'wp-taxonomy-tree' ),
			'text_max_length'  => __( 'Text is longer than the maximum length.', 'wp-taxonomy-tree' ),
			'char_shape'       => __( 'Enter exactly one character.', 'wp-taxonomy-tree' ),
			'charset_range'     => __( 'Value contains characters outside the allowed range(s).', 'wp-taxonomy-tree' ),
			'charset_allowlist' => __( 'Value contains characters that are not in the allowlist.', 'wp-taxonomy-tree' ),
			'charset_regex'     => __( 'Value does not match the allowed pattern.', 'wp-taxonomy-tree' ),
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
			'int_min'          => __( 'Int min', 'wp-taxonomy-tree' ),
			'int_max'          => __( 'Int max', 'wp-taxonomy-tree' ),
			'number_shape'     => __( 'Number shape', 'wp-taxonomy-tree' ),
			'double_min'       => __( 'Double min', 'wp-taxonomy-tree' ),
			'double_max'       => __( 'Double max', 'wp-taxonomy-tree' ),
			'bool_shape'       => __( 'Boolean shape', 'wp-taxonomy-tree' ),
			'email_shape'      => __( 'Email shape', 'wp-taxonomy-tree' ),
			'text_shape'       => __( 'Text shape', 'wp-taxonomy-tree' ),
			'text_min_length'  => __( 'Text min length', 'wp-taxonomy-tree' ),
			'text_max_length'  => __( 'Text max length', 'wp-taxonomy-tree' ),
			'char_shape'       => __( 'Single character', 'wp-taxonomy-tree' ),
			'charset_range'     => __( 'Charset range', 'wp-taxonomy-tree' ),
			'charset_allowlist' => __( 'Charset allowlist', 'wp-taxonomy-tree' ),
			'charset_regex'     => __( 'Charset regex', 'wp-taxonomy-tree' ),
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
			case 'int_min':
			case 'int_max':
				$bound = self::bound_value_from_row( $id, $opts );
				if ( null === $bound ) {
					$ok = true; /* bound not configured yet */
					break;
				}
				if ( ! preg_match( '/^-?\d+$/', $s ) ) {
					$ok = false;
					break;
				}
				$n  = (int) $s;
				$ok = ( 'int_min' === $id ) ? ( $n >= (int) $bound ) : ( $n <= (int) $bound );
				break;
			case 'number_shape':
				$ok = (bool) preg_match( '/^-?\d+(\.\d+)?$/', $s ) && is_finite( (float) $s );
				break;
			case 'double_min':
			case 'double_max':
				$bound = self::bound_value_from_row( $id, $opts );
				if ( null === $bound ) {
					$ok = true;
					break;
				}
				if ( ! preg_match( '/^-?\d+(\.\d+)?$/', $s ) || ! is_finite( (float) $s ) ) {
					$ok = false;
					break;
				}
				$n  = (float) $s;
				$ok = ( 'double_min' === $id ) ? ( $n >= (float) $bound ) : ( $n <= (float) $bound );
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
			case 'text_min_length':
			case 'text_max_length':
				$bound = self::bound_value_from_row( $id, $opts );
				if ( null === $bound ) {
					$ok = true;
					break;
				}
				$len = self::string_length( $s );
				$ok  = ( 'text_min_length' === $id )
					? ( $len >= (int) $bound )
					: ( $len <= (int) $bound );
				break;
			case 'char_shape':
				$ok = self::is_single_character( $s );
				break;
			case 'charset_range':
			case 'charset_allowlist':
			case 'charset_regex':
				$spec = self::string_param_from_row( $opts );
				if ( null === $spec || '' === $spec ) {
					$ok = true;
					break;
				}
				if ( 'charset_range' === $id ) {
					$ok = self::value_matches_charset_range( $s, $spec );
				} elseif ( 'charset_allowlist' === $id ) {
					$ok = self::value_matches_charset_allowlist( $s, $spec );
				} else {
					$ok = self::value_matches_charset_regex( $s, $spec );
				}
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
	 * Unicode-aware string length (grapheme when available).
	 */
	public static function string_length( string $value ): int {
		if ( function_exists( 'grapheme_strlen' ) ) {
			$len = grapheme_strlen( $value );
			return false === $len ? 0 : (int) $len;
		}
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value, 'UTF-8' );
		}
		return strlen( $value );
	}

	/**
	 * Exactly one Unicode character (grapheme when intl available).
	 */
	public static function is_single_character( string $value ): bool {
		$value = (string) $value;
		if ( '' === $value ) {
			return false;
		}
		return 1 === self::string_length( $value );
	}

	/**
	 * Split into Unicode grapheme clusters (fallback: UTF-8 codepoints).
	 *
	 * @return list<string>
	 */
	public static function split_graphemes( string $value ): array {
		$value = (string) $value;
		if ( '' === $value ) {
			return array();
		}
		if ( function_exists( 'grapheme_strlen' ) && function_exists( 'grapheme_extract' ) ) {
			$out = array();
			$len = grapheme_strlen( $value );
			if ( false === $len ) {
				$len = 0;
			}
			$offset = 0;
			for ( $i = 0; $i < $len; $i++ ) {
				$g = grapheme_extract( $value, 1, GRAPHEME_EXTR_COUNT, $offset, $offset );
				if ( false === $g || '' === $g ) {
					break;
				}
				$out[] = $g;
			}
			return $out;
		}
		if ( function_exists( 'mb_str_split' ) ) {
			$parts = mb_str_split( $value, 1, 'UTF-8' );
			return is_array( $parts ) ? $parts : array();
		}
		$parts = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $parts ) ? $parts : array();
	}

	/**
	 * First Unicode code point of a grapheme/string, or null.
	 */
	public static function first_codepoint( string $value ): ?int {
		$value = (string) $value;
		if ( '' === $value ) {
			return null;
		}
		if ( function_exists( 'mb_ord' ) ) {
			$cp = mb_ord( $value, 'UTF-8' );
			return false === $cp ? null : (int) $cp;
		}
		$u = unpack( 'N', mb_convert_encoding( mb_substr( $value, 0, 1, 'UTF-8' ), 'UCS-4BE', 'UTF-8' ) );
		if ( ! is_array( $u ) || ! isset( $u[1] ) ) {
			return null;
		}
		return (int) $u[1];
	}

	/**
	 * Parse one range token: `a-z`, `0-9`, `U+0041-U+005A`, or a single grapheme.
	 *
	 * @return array{0:int,1:int}|null
	 */
	public static function parse_range_token( string $token ): ?array {
		$token = trim( $token );
		if ( '' === $token ) {
			return null;
		}
		if ( preg_match( '/^U\+([0-9A-Fa-f]{1,6})\s*-\s*U\+([0-9A-Fa-f]{1,6})$/', $token, $m ) ) {
			$lo = hexdec( $m[1] );
			$hi = hexdec( $m[2] );
			if ( $lo > $hi ) {
				$tmp = $lo;
				$lo  = $hi;
				$hi  = $tmp;
			}
			return array( $lo, $hi );
		}
		/* Single codepoint U+xxxx */
		if ( preg_match( '/^U\+([0-9A-Fa-f]{1,6})$/', $token, $m ) ) {
			$cp = hexdec( $m[1] );
			return array( $cp, $cp );
		}
		$dash = function_exists( 'mb_strpos' )
			? mb_strpos( $token, '-', 0, 'UTF-8' )
			: strpos( $token, '-' );
		if ( false !== $dash && $dash > 0 ) {
			$left  = function_exists( 'mb_substr' )
				? mb_substr( $token, 0, (int) $dash, 'UTF-8' )
				: substr( $token, 0, (int) $dash );
			$right = function_exists( 'mb_substr' )
				? mb_substr( $token, (int) $dash + 1, null, 'UTF-8' )
				: substr( $token, (int) $dash + 1 );
			$lo = self::first_codepoint( (string) $left );
			$hi = self::first_codepoint( (string) $right );
			if ( null === $lo || null === $hi ) {
				return null;
			}
			if ( $lo > $hi ) {
				$tmp = $lo;
				$lo  = $hi;
				$hi  = $tmp;
			}
			return array( $lo, $hi );
		}
		$cp = self::first_codepoint( $token );
		return null === $cp ? null : array( $cp, $cp );
	}

	/**
	 * @return list<array{0:int,1:int}>
	 */
	public static function parse_charset_ranges( string $spec ): array {
		$parts = preg_split( '/\s*,\s*/', trim( $spec ) );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$out = array();
		foreach ( $parts as $part ) {
			$range = self::parse_range_token( (string) $part );
			if ( null !== $range ) {
				$out[] = $range;
			}
		}
		return $out;
	}

	/**
	 * Every grapheme’s codepoint must fall in at least one range (`a-z,A-Z,0-9`).
	 */
	public static function value_matches_charset_range( string $value, string $spec ): bool {
		$ranges = self::parse_charset_ranges( $spec );
		if ( array() === $ranges ) {
			return false;
		}
		$chars = self::split_graphemes( $value );
		if ( array() === $chars ) {
			return false;
		}
		foreach ( $chars as $ch ) {
			$cp = self::first_codepoint( $ch );
			if ( null === $cp ) {
				return false;
			}
			$ok = false;
			foreach ( $ranges as $range ) {
				if ( $cp >= $range[0] && $cp <= $range[1] ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Comma-separated allowlist of graphemes (`a,b,c,ä`). Use `\,` for a literal comma.
	 */
	public static function value_matches_charset_allowlist( string $value, string $spec ): bool {
		$allowed = self::parse_charset_allowlist( $spec );
		if ( array() === $allowed ) {
			return false;
		}
		$map = array();
		foreach ( $allowed as $ch ) {
			$map[ $ch ] = true;
		}
		$chars = self::split_graphemes( $value );
		if ( array() === $chars ) {
			return false;
		}
		foreach ( $chars as $ch ) {
			if ( empty( $map[ $ch ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return list<string>
	 */
	public static function parse_charset_allowlist( string $spec ): array {
		$spec = (string) $spec;
		if ( '' === trim( $spec ) ) {
			return array();
		}
		/* Split on commas not preceded by backslash. */
		$parts = preg_split( '/(?<!\\\\),/', $spec );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$out = array();
		foreach ( $parts as $part ) {
			$part = str_replace( '\\,', ',', trim( (string) $part ) );
			if ( '' === $part ) {
				continue;
			}
			$gs = self::split_graphemes( $part );
			if ( 1 === count( $gs ) ) {
				$out[] = $gs[0];
			} elseif ( count( $gs ) > 1 ) {
				/* Multi-char token: allow each grapheme. */
				foreach ( $gs as $g ) {
					$out[] = $g;
				}
			}
		}
		return $out;
	}

	/**
	 * Full-string regex match. Pattern may omit delimiters; wraps with ^(?:…)$ when unanchored.
	 */
	public static function value_matches_charset_regex( string $value, string $pattern ): bool {
		$pattern = trim( $pattern );
		if ( '' === $pattern ) {
			return false;
		}
		/* Strip /…/ or #…# wrappers when present. */
		if ( preg_match( '/^(.)(.*)\1([imsxuADSUXJ]*)$/s', $pattern, $m ) && false === strpos( $m[1], '\\' ) ) {
			$body  = $m[2];
			$flags = $m[3];
		} else {
			$body  = $pattern;
			$flags = 'u';
		}
		if ( '' === $flags || false === strpos( $flags, 'u' ) ) {
			$flags .= 'u';
		}
		if ( ! str_starts_with( $body, '^' ) || ! str_ends_with( $body, '$' ) ) {
			$body = '^(?:' . $body . ')$';
		}
		$delim = "\x01";
		$re    = $delim . $body . $delim . $flags;
		$set   = preg_match( $re, $value );
		if ( false === $set ) {
			return false;
		}
		return 1 === $set;
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
