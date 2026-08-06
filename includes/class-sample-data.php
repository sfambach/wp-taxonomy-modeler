<?php
/**
 * Central sample / preview values for simple data types and attributes.
 *
 * Samples are name-aware first (attribute label heuristics), then type fallback.
 * Registry maps only — not methods on nodes. Pass an attribute / type identity
 * → get a realistic default for preview/fixtures.
 *
 * Mirrors assets/js/wtt-sample-data.js.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attribute-name → sample, then type-key → sample registry.
 */
final class Sample_Data {

	/**
	 * Shared persona so name + email stay consistent across fields on one host.
	 *
	 * @return array<string, string>
	 */
	public static function persona(): array {
		return array(
			'first_name' => 'Herbert',
			'last_name'  => 'Müller',
			'full_name'  => 'Herbert Müller',
			'email'      => 'herbert@home.de',
			'phone'      => '+49 30 12345678',
			'mobile'     => '+49 170 1234567',
			'company'    => 'Muster GmbH',
			'city'       => 'Berlin',
			'zip'        => '10115',
			'street'     => 'Musterstraße 1',
			'title'      => 'Herr',
			'country'    => 'Deutschland',
			'website'    => 'https://www.muster.de',
			'note'       => 'Sample note',
		);
	}

	/**
	 * Simple catalog leaf keys (Definition/Data Types/Simple).
	 *
	 * @return array<string, string>
	 */
	public static function map(): array {
		$p = self::persona();

		return array(
			'int'               => '42',
			'double'            => '10.5',
			'text'              => 'Sample',
			/* Type email → always a fake address (persona-consistent). */
			'email'             => $p['email'],
			'textarea'          => "Sample text\nSecond line",
			'char'              => 'A',
			'bool'              => 'true',
			/* Read-only host name — preview uses the live node name; placeholder for DTOs. */
			'display_node_name' => 'Node name',
			/* MediaRef JSON (attachment-like) for SSR / fixtures; admin prefers WTTMediaRender. */
			'media'             => '{"source":"url","url":"https://example.com/sample.png","mime":"image/png","filename":"beispiel.png"}',
			/* Usage magnitude only (P1: Basiseinheit units are schema, not fillable instances). */
			'quantity'          => '10.5',
		);
	}

	/**
	 * Normalized attribute-name hints → sample strings (DE + EN).
	 *
	 * @return array<string, string>
	 */
	public static function name_map(): array {
		$p = self::persona();

		return array(
			'name'            => $p['first_name'],
			'bezeichnung'     => $p['first_name'],
			'fullname'       => $p['full_name'],
			'full name'       => $p['full_name'],
			'vorname'         => $p['first_name'],
			'first name'      => $p['first_name'],
			'firstname'       => $p['first_name'],
			'given name'      => $p['first_name'],
			'nachname'        => $p['last_name'],
			'last name'       => $p['last_name'],
			'lastname'        => $p['last_name'],
			'surname'         => $p['last_name'],
			'family name'     => $p['last_name'],
			'email'           => $p['email'],
			'e mail'          => $p['email'],
			'mail'            => $p['email'],
			'telefon'         => $p['phone'],
			'phone'           => $p['phone'],
			'tel'             => $p['phone'],
			'handy'           => $p['mobile'],
			'mobile'          => $p['mobile'],
			'firma'           => $p['company'],
			'company'         => $p['company'],
			'unternehmen'     => $p['company'],
			'organization'    => $p['company'],
			'organisation'    => $p['company'],
			'stadt'           => $p['city'],
			'city'            => $p['city'],
			'ort'             => $p['city'],
			'plz'             => $p['zip'],
			'zip'             => $p['zip'],
			'zipcode'         => $p['zip'],
			'zip code'        => $p['zip'],
			'postal code'    => $p['zip'],
			'postcode'        => $p['zip'],
			'strasse'         => $p['street'],
			'street'          => $p['street'],
			'adresse'         => $p['street'],
			'address'         => $p['street'],
			'titel'           => $p['title'],
			'title'           => $p['title'],
			'anrede'          => $p['title'],
			'land'            => $p['country'],
			'country'         => $p['country'],
			'website'         => $p['website'],
			'web'             => $p['website'],
			'url'             => $p['website'],
			'homepage'        => $p['website'],
			'bemerkung'       => $p['note'],
			'notiz'           => $p['note'],
			'note'            => $p['note'],
			'notes'           => $p['note'],
			'comment'         => $p['note'],
			'kommentar'       => $p['note'],
		);
	}

	/**
	 * Sample for an attribute/member: name heuristics, then type fallback.
	 *
	 * @param int|string|\WP_Term|array<string,mixed>|null $attr Attribute node-like, or name string.
	 * @param int|string|\WP_Term|array<string,mixed>|null $type_fallback Optional type when $attr is a bare name.
	 */
	public static function for_attribute( $attr, $type_fallback = null ): string {
		$name_hints = self::collect_name_hints( $attr );
		$type_key   = self::resolve_type_key_from_attr( $attr, $type_fallback );

		/* Type email → always persona fake address. */
		if ( 'email' === $type_key ) {
			return self::persona()['email'];
		}

		foreach ( $name_hints as $hint ) {
			$mapped = self::sample_for_name_hint( $hint );
			if ( '' !== $mapped ) {
				return $mapped;
			}
		}

		if ( '' !== $type_key ) {
			return self::for_type( $type_key );
		}

		return '';
	}

	/**
	 * Resolve a type identity to a sample string (empty when unknown / parked).
	 *
	 * Optional $context may carry attribute name / shortDescription for name-aware fill:
	 * `for_type( 'text', array( 'name' => 'Vorname' ) )`.
	 *
	 * @param int|string|\WP_Term|array<string,mixed>|null $type_node_or_id Type term id, key, WP_Term, or node-like array.
	 * @param array<string,mixed>|null                     $context         Optional name / shortDescription.
	 */
	public static function for_type( $type_node_or_id, ?array $context = null ): string {
		if ( null !== $context && ( ! empty( $context['name'] ) || ! empty( $context['shortDescription'] ) || ! empty( $context['short_description'] ) ) ) {
			$attr = is_array( $type_node_or_id )
				? array_merge( $type_node_or_id, $context )
				: array_merge(
					array(
						'typeKey' => is_string( $type_node_or_id ) || is_int( $type_node_or_id ) ? $type_node_or_id : '',
					),
					$context
				);
			return self::for_attribute( $attr );
		}

		$key = self::resolve_type_key( $type_node_or_id );
		if ( '' === $key ) {
			return '';
		}

		$map = self::map();
		if ( isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}

		/*
		 * Parked Complex kinds (Q90): no product sample factories.
		 * Intentionally empty — do not revive enum/list/table samples here.
		 */
		return '';
	}

	/**
	 * Normalize type key from id / term / name / node-like payload.
	 *
	 * @param int|string|\WP_Term|array<string,mixed>|null $type_node_or_id Type identity.
	 */
	public static function resolve_type_key( $type_node_or_id ): string {
		if ( null === $type_node_or_id || false === $type_node_or_id ) {
			return '';
		}

		if ( is_int( $type_node_or_id ) || ( is_string( $type_node_or_id ) && ctype_digit( $type_node_or_id ) ) ) {
			$term = get_term( (int) $type_node_or_id );
			if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
				return self::normalize_key( (string) $term->name );
			}
			return '';
		}

		if ( $type_node_or_id instanceof \WP_Term ) {
			return self::normalize_key( (string) $type_node_or_id->name );
		}

		if ( is_string( $type_node_or_id ) ) {
			return self::normalize_key( $type_node_or_id );
		}

		if ( is_array( $type_node_or_id ) ) {
			foreach ( array( 'typeKey', 'typeName', 'typeLabel' ) as $field ) {
				if ( ! empty( $type_node_or_id[ $field ] ) && is_scalar( $type_node_or_id[ $field ] ) ) {
					$key = self::normalize_key( (string) $type_node_or_id[ $field ] );
					if ( '' !== $key ) {
						return $key;
					}
				}
			}
			if ( isset( $type_node_or_id['type'] ) && is_array( $type_node_or_id['type'] ) && ! empty( $type_node_or_id['type']['name'] ) ) {
				return self::normalize_key( (string) $type_node_or_id['type']['name'] );
			}
			/*
			 * Do not fall back to attribute display name as a type key —
			 * that belongs to for_attribute() name heuristics.
			 */
		}

		return '';
	}

	/**
	 * @param string $key Raw type name / path segment.
	 */
	private static function normalize_key( string $key ): string {
		$key = strtolower( trim( $key ) );
		if ( '' === $key ) {
			return '';
		}
		/* Path-style labels ("… / text") → last segment. */
		if ( false !== strpos( $key, '/' ) ) {
			$parts = array_map( 'trim', explode( '/', $key ) );
			$key   = strtolower( (string) end( $parts ) );
		}
		if ( 'integer' === $key ) {
			return 'int';
		}
		/* Unicode dashes → ASCII before slug folding. */
		$key = preg_replace( '/[\x{2010}-\x{2015}\x{2212}\x{FE58}\x{FE63}\x{FF0D}]/u', '-', $key ) ?? $key;
		$key = str_replace( '-', '_', $key );
		if ( 'e_mail' === $key || 'mail' === $key ) {
			return 'email';
		}
		if ( 'boolean' === $key ) {
			return 'bool';
		}
		if ( 'string' === $key || 'varchar' === $key ) {
			return 'text';
		}
		if ( 'float' === $key || 'number' === $key ) {
			return 'double';
		}
		return $key;
	}

	/**
	 * Fold attribute labels for heuristic lookup (case-insensitive, umlauts, punctuation).
	 */
	private static function normalize_name_hint( string $raw ): string {
		$hint = strtolower( trim( $raw ) );
		if ( '' === $hint ) {
			return '';
		}
		$hint = strtr(
			$hint,
			array(
				'ä' => 'ae',
				'ö' => 'oe',
				'ü' => 'ue',
				'ß' => 'ss',
			)
		);
		$hint = preg_replace( '/[\x{2010}-\x{2015}\x{2212}\x{FE58}\x{FE63}\x{FF0D}]/u', '-', $hint ) ?? $hint;
		$hint = preg_replace( '/[_\-\.]+/u', ' ', $hint ) ?? $hint;
		$hint = preg_replace( '/\s+/u', ' ', $hint ) ?? $hint;
		return trim( $hint );
	}

	/**
	 * @param int|string|\WP_Term|array<string,mixed>|null $attr Attribute identity.
	 * @return list<string>
	 */
	private static function collect_name_hints( $attr ): array {
		$hints = array();
		if ( null === $attr || false === $attr ) {
			return $hints;
		}
		if ( is_string( $attr ) && ! ctype_digit( $attr ) ) {
			$hints[] = $attr;
			return $hints;
		}
		if ( $attr instanceof \WP_Term ) {
			$hints[] = (string) $attr->name;
			return $hints;
		}
		if ( ! is_array( $attr ) ) {
			return $hints;
		}
		foreach ( array( 'name', 'displayName', 'display_name', 'label', 'shortDescription', 'short_description' ) as $field ) {
			if ( ! empty( $attr[ $field ] ) && is_scalar( $attr[ $field ] ) ) {
				$hints[] = (string) $attr[ $field ];
			}
		}
		return $hints;
	}

	/**
	 * @param int|string|\WP_Term|array<string,mixed>|null $attr
	 * @param int|string|\WP_Term|array<string,mixed>|null $type_fallback
	 */
	private static function resolve_type_key_from_attr( $attr, $type_fallback ): string {
		if ( is_array( $attr ) ) {
			$key = self::resolve_type_key( $attr );
			if ( '' !== $key ) {
				return $key;
			}
		}
		if ( null !== $type_fallback ) {
			return self::resolve_type_key( $type_fallback );
		}
		return '';
	}

	private static function sample_for_name_hint( string $raw ): string {
		$hint = self::normalize_name_hint( $raw );
		if ( '' === $hint ) {
			return '';
		}
		$map = self::name_map();
		if ( isset( $map[ $hint ] ) ) {
			return $map[ $hint ];
		}
		/* Compact form: "first name" → also try "firstname". */
		$compact = str_replace( ' ', '', $hint );
		if ( $compact !== $hint && isset( $map[ $compact ] ) ) {
			return $map[ $compact ];
		}
		return '';
	}
}
