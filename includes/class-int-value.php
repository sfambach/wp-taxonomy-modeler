<?php
/**
 * Integer value — normalize / convert / validators (1..n).
 *
 * Canonical storage: decimal digit string ("42", "-7").
 * Default display/edit format: arabic. Other format ids reserved for later slices.
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
	 * @param string|null $canonical Canonical value.
	 * @param string      $format_id Format id (non-arabic reserved → arabic).
	 * @return string
	 */
	public static function format( ?string $canonical, string $format_id = self::DEFAULT_FORMAT ): string {
		self::normalize_format_id( $format_id );
		if ( null === $canonical || '' === $canonical ) {
			return '';
		}
		$s = trim( $canonical );
		if ( ! self::is_integer_shape( $s ) ) {
			return $s;
		}
		return self::canonicalize_arabic( $s );
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
