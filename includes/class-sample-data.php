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
			/* Unix timestamp (UTC 2024-06-15 14:30:00). Mode on type chooses date vs datetime chrome. */
			'date'              => '1718461800',
			'time'              => '14:30',
			'datetime'          => '2024-06-15T14:30',
			'color'             => '#2271b1',
			/* Read-only host presentation — preview resolves context (form/symbol/…); DTO placeholder. */
			'node_presentation' => 'Node name',
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
			/* Prefix optional by domain — never sample-force. */
			'praefix'         => '',
			'prefix'          => '',
			/*
			 * Kuerzel / symbol: no hardcoded glyph — use node_presentation (context=symbol)
			 * from host presentation / short_description.
			 */
			'einheit'         => 'Ohm',
			'unit'            => 'Ohm',
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
			'datum'           => '1718461800',
			'date'            => '1718461800',
			'datetime'        => '1718461800',
			'zeitpunkt'       => '1718461800',
			'timestamp'       => '1718461800',
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
			'hausnummer'      => '1',
			'house number'    => '1',
			'housenumber'     => '1',
			'postleitzahl'    => $p['zip'],
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
			/* PCB / Platine posts (Retro Projekt tables). */
			'platine'         => 'ESP8266-RS232',
			'board'           => 'ESP8266-RS232',
			'pcb'             => 'ESP8266-RS232',
			'bestellt wo'     => 'JLCPCB',
			'bestellt bei'    => 'JLCPCB',
			'ordered from'    => 'JLCPCB',
			'gerberdatei'     => '{"source":"url","url":"https://example.com/esp8266-rs232-gerber.zip","mime":"application/zip","filename":"esp8266-rs232-gerber.zip"}',
			'gerber'          => '{"source":"url","url":"https://example.com/esp8266-rs232-gerber.zip","mime":"application/zip","filename":"esp8266-rs232-gerber.zip"}',
			'gerber vorhanden'=> 'true',
			'stuck'           => '20',
			'stück'           => '20',
			'qty'             => '20',
			'quantity boards' => '20',
			'preis'           => '12',
			'preis inclusive' => '12',
			'besonderheiten'  => 'Lead-free, black',
			'erfolgreich'     => 'true',
			'preis pro stück' => '7',
			'preisprostück'   => '7',
			'stückpreis'      => '7',
			'lötdauer'        => '20 Minuten',
			'lotdauer'        => '20 Minuten',
			'schwierigkeitsgrad' => 'Mittel',
			'schwierigkeitsfaktor' => 'Mittel',
			'funktion'        => 'Gut',
			'lohnt es sich'   => 'Ja — sinnvolle Ergänzung für das Set',
			'lohntessich'     => 'Ja — sinnvolle Ergänzung für das Set',
			'einschränkungen' => 'Verbraucht einen ISA-Slot.',
			'einschraenkungen'=> 'Verbraucht einen ISA-Slot.',
			'version'         => '1.3',
			'meine version'   => '1.3',
			'optionen'        => "Option A — Beschreibung\nOption B — Beschreibung",
			'protokoll'       => "30.08.2025 — Beitrag erstellt und Platine bestellt.\n09.09.2025 — Platinen eingetroffen.",
			'änderungsprotokoll' => "30.08.2025 — Beitrag erstellt und Platine bestellt.\n09.09.2025 — Platinen eingetroffen.",
			/* Bauteillisten Position (minimal BOM line — ESP8266-RS232 PCB). */
			'referenz'        => 'PCB',
			'designator'      => 'PCB',
			'wert'            => '',
			'menge'           => '1',
			'qty line'        => '1',
			'beschreibung'    => 'ESP8266-RS232 Leiterplatte',
			'description'     => 'ESP8266-RS232 Leiterplatte',
			'auf lager'       => 'true',
			'auflager'        => 'true',
			'in stock'        => 'true',
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

		/*
		 * node_presentation / display_node_name = live host term name — never
		 * "Name" → Herbert heuristics. Callers pass hostName from the schema host.
		 */
		if ( 'node_presentation' === $type_key || 'display_node_name' === $type_key ) {
			$host_name = '';
			if ( is_array( $attr ) ) {
				$host_name = trim(
					(string) (
						$attr['hostName']
						?? $attr['hostDisplayName']
						?? $attr['nodeName']
						?? $attr['schemaName']
						?? ''
					)
				);
			}
			if ( '' === $host_name ) {
				$map       = self::map();
				$host_name = (string) (
					$map['node_presentation']
					?? $map['display_node_name']
					?? 'Node name'
				);
			}
			return $host_name;
		}

		/* Platine.Name is a board title, not the Herbert persona. */
		$host = '';
		if ( is_array( $attr ) ) {
			$host = strtolower(
				trim(
					(string) ( $attr['definedOnName'] ?? $attr['hostName'] ?? '' )
				)
			);
		}
		if ( 'platine' === $host ) {
			foreach ( $name_hints as $hint ) {
				$h = strtolower( trim( (string) $hint ) );
				if ( in_array( $h, array( 'name', 'bezeichnung', 'titel', 'title' ), true ) ) {
					return 'ESP8266-RS232';
				}
			}
		}

		/* Lieferant.Name — fab / distributor, not persona. */
		if ( 'lieferant' === $host ) {
			foreach ( $name_hints as $hint ) {
				$h = strtolower( trim( (string) $hint ) );
				if ( in_array( $h, array( 'name', 'firma', 'lieferant' ), true ) ) {
					return 'JLCPCB';
				}
			}
		}

		/* Bauteilliste.Name */
		if ( 'bauteilliste' === $host ) {
			foreach ( $name_hints as $hint ) {
				$h = strtolower( trim( (string) $hint ) );
				if ( in_array( $h, array( 'name', 'bezeichnung', 'titel', 'title' ), true ) ) {
					return 'ESP8266-RS232 BOM';
				}
			}
		}

		/* Bauteillisten Position line — ESP8266-RS232 first BOM row (PCB). */
		$host_norm = preg_replace( '/\s+/', '', $host );
		if ( in_array( $host_norm, array( 'position', 'bauteillistenposition' ), true ) ) {
			foreach ( $name_hints as $hint ) {
				$h = strtolower( trim( (string) $hint ) );
				$map = array(
					'referenz'     => 'PCB',
					'menge'        => '1',
					'beschreibung' => 'ESP8266-RS232 Leiterplatte',
					'auf lager'    => 'true',
					'auflager'     => 'true',
				);
				if ( isset( $map[ $h ] ) ) {
					return $map[ $h ];
				}
			}
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
			$term_id = (int) $type_node_or_id;
			$term    = get_term( $term_id );
			if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
				$taxonomy = (string) $term->taxonomy;
				/* Q96: prefer builtin.* binding; leaf name = debt fallback. */
				$from_binding = Node_Type::registry_id_for_type_term( $taxonomy, $term_id );
				if ( '' !== $from_binding ) {
					return self::normalize_key( $from_binding );
				}
				return self::normalize_key( (string) $term->name );
			}
			return '';
		}

		if ( $type_node_or_id instanceof \WP_Term ) {
			$from_binding = Node_Type::registry_id_for_type_term(
				(string) $type_node_or_id->taxonomy,
				(int) $type_node_or_id->term_id
			);
			if ( '' !== $from_binding ) {
				return self::normalize_key( $from_binding );
			}
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
		if ( in_array( $key, array( 'datetime', 'date_time', 'timestamp' ), true ) ) {
			return 'date';
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
