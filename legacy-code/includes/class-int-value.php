<?php
/**
 * Integer value — normalize / convert / validators (1..n).
 *
 * Canonical storage: decimal digit string ("42", "-7").
 * Display formats: arabic (default), roman, binary, octal, hex.
 * JS SoT for preview: assets/js/wtt-int-value.js (keep format() in sync).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

final class Int_Value {

	public const DEFAULT_FORMAT = 'arabic';

	/** @var list<string> */
	public const FORMAT_IDS = array( 'arabic', 'roman', 'binary', 'octal', 'hex' );

	/**
	 * Classic Roman map (1..3999).
	 *
	 * @var list<array{0:int,1:string}>
	 */
	private const ROMAN_TABLE = array(
		array( 1000, 'M' ),
		array( 900, 'CM' ),
		array( 500, 'D' ),
		array( 400, 'CD' ),
		array( 100, 'C' ),
		array( 90, 'XC' ),
		array( 50, 'L' ),
		array( 40, 'XL' ),
		array( 10, 'X' ),
		array( 9, 'IX' ),
		array( 5, 'V' ),
		array( 4, 'IV' ),
		array( 1, 'I' ),
	);

	/**
	 * @param string $format_id Format id.
	 * @return string
	 */
	public static function normalize_format_id( string $format_id ): string {
		$id = strtolower( trim( $format_id ) );
		if ( in_array( $id, self::FORMAT_IDS, true ) ) {
			return $id;
		}
		return self::DEFAULT_FORMAT;
	}

	/**
	 * Live filter for arabic edit: optional leading minus + digits.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	public static function filter_live_arabic( string $raw ): string {
		$out = '';
		$len = strlen( $raw );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $raw[ $i ];
			if ( $ch >= '0' && $ch <= '9' ) {
				$out .= $ch;
				continue;
			}
			if ( '-' === $ch && '' === $out ) {
				$out .= $ch;
			}
		}
		return $out;
	}

	/**
	 * @param string $raw       Raw input.
	 * @param string $format_id Format id (unimplemented → arabic).
	 * @return string
	 */
	public static function filter_live( string $raw, string $format_id = self::DEFAULT_FORMAT ): string {
		self::normalize_format_id( $format_id );
		return self::filter_live_arabic( $raw );
	}

	/**
	 * @param string $value Value to test.
	 * @return bool
	 */
	public static function is_integer_shape( string $value ): bool {
		return (bool) preg_match( '/^-?\d+$/', $value );
	}

	/**
	 * @param string $filtered Filtered field text.
	 * @return string
	 */
	public static function canonicalize_arabic( string $filtered ): string {
		if ( '' === $filtered || '-' === $filtered ) {
			return $filtered;
		}
		$neg    = '-' === $filtered[0];
		$digits = $neg ? substr( $filtered, 1 ) : $filtered;
		$digits = (string) preg_replace( '/^0+(?=\d)/', '', $digits );
		if ( '' === $digits ) {
			$digits = '0';
		}
		if ( $neg && '0' === $digits ) {
			return '0';
		}
		return $neg ? '-' . $digits : $digits;
	}

	/**
	 * @param string $raw       Raw input.
	 * @param string $format_id Format id.
	 * @return string
	 */
	public static function normalize( string $raw, string $format_id = self::DEFAULT_FORMAT ): string {
		$filtered = self::filter_live( $raw, $format_id );
		if ( '' === $filtered || '-' === $filtered ) {
			return $filtered;
		}
		if ( self::is_integer_shape( $filtered ) ) {
			return self::canonicalize_arabic( $filtered );
		}
		return $filtered;
	}

	/**
	 * @param string $text      Field text.
	 * @param string $format_id Format id.
	 * @return string|null Canonical or null.
	 */
	public static function parse( string $text, string $format_id = self::DEFAULT_FORMAT ): ?string {
		$normalized = self::normalize( $text, $format_id );
		if ( ! self::is_integer_shape( $normalized ) ) {
			return null;
		}
		return self::canonicalize_arabic( $normalized );
	}

	/**
	 * Format canonical for display using the preferred converter id.
	 *
	 * @param string|null $canonical Canonical value.
	 * @param string      $format_id Format id (arabic|roman|binary|octal|hex).
	 * @return string
	 */
	public static function format( ?string $canonical, string $format_id = self::DEFAULT_FORMAT ): string {
		$id = self::normalize_format_id( $format_id );
		if ( null === $canonical || '' === $canonical ) {
			return '';
		}
		$s = trim( $canonical );
		if ( ! self::is_integer_shape( $s ) ) {
			return $s;
		}
		$arabic = self::canonicalize_arabic( $s );
		if ( 'arabic' === $id ) {
			return $arabic;
		}

		$neg    = str_starts_with( $arabic, '-' );
		$abs_str = $neg ? substr( $arabic, 1 ) : $arabic;
		if ( ! ctype_digit( $abs_str ) ) {
			return $arabic;
		}
		/* PHP int is enough for sample/preview magnitudes. */
		$abs_num = (int) $abs_str;

		$body = '';
		if ( 'roman' === $id ) {
			if ( $neg || $abs_num < 1 || $abs_num > 3999 ) {
				return $arabic;
			}
			$body = self::to_roman( $abs_num );
		} elseif ( 'binary' === $id ) {
			$body = decbin( $abs_num );
		} elseif ( 'octal' === $id ) {
			$body = decoct( $abs_num );
		} elseif ( 'hex' === $id ) {
			$body = strtoupper( dechex( $abs_num ) );
		} else {
			return $arabic;
		}

		return $neg ? '-' . $body : $body;
	}

	/**
	 * @param int $n Positive integer in classic Roman range.
	 * @return string
	 */
	private static function to_roman( int $n ): string {
		$out  = '';
		$rest = $n;
		foreach ( self::ROMAN_TABLE as $pair ) {
			$value = (int) $pair[0];
			$glyph = (string) $pair[1];
			while ( $rest >= $value ) {
				$out  .= $glyph;
				$rest -= $value;
			}
		}
		return $out;
	}

	/**
	 * @return string
	 */
	private static function invalid_message(): string {
		if ( function_exists( '__' ) ) {
			return __( 'Enter a whole number.', 'wp-taxonomy-tree' );
		}
		return 'Enter a whole number.';
	}

	/**
	 * @param string               $value Value.
	 * @param array{allow_empty?:bool} $opts Options.
	 * @return array{ok:bool,message?:string,failed_id?:string}
	 */
	public static function validate_all( string $value, array $opts = array() ): array {
		$allow_empty = ! isset( $opts['allow_empty'] ) || (bool) $opts['allow_empty'];
		if ( '' === $value ) {
			if ( $allow_empty ) {
				return array( 'ok' => true );
			}
			return array(
				'ok'        => false,
				'message'   => self::invalid_message(),
				'failed_id' => 'integer_shape',
			);
		}
		if ( ! self::is_integer_shape( $value ) ) {
			return array(
				'ok'        => false,
				'message'   => self::invalid_message(),
				'failed_id' => 'integer_shape',
			);
		}
		return array( 'ok' => true );
	}
}
