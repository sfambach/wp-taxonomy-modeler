<?php
/**
 * Node presentation store (Q117) — texts + locale-invariant icon.
 *
 * Table: {prefix}wtt_node_presentation (term_id, context, locale, value).
 * Text contexts are locale-aware; context `icon` uses locale `*`.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD + resolve for node presentation values.
 */
final class Node_Presentation {

	public const TABLE_SUFFIX = 'wtt_node_presentation';

	public const LOCALE_INVARIANT = '*';

	public const OPTION_DB_VERSION = 'wtt_node_presentation_db_version';

	public const DB_VERSION = 1;

	public const OPTION_MIGRATED = 'wtt_node_presentation_migrated';

	/** @var list<string> */
	public const TEXT_CONTEXTS = array( 'form', 'table', 'select', 'symbol', 'help' );

	public const CONTEXT_ICON = 'icon';

	/**
	 * Recommended contexts for “incomplete” filter (icon optional).
	 *
	 * @return list<string>
	 */
	public static function recommended_text_contexts(): array {
		return array( 'form', 'table', 'select' );
	}

	/**
	 * @return list<string>
	 */
	public static function all_contexts(): array {
		return array_merge( self::TEXT_CONTEXTS, array( self::CONTEXT_ICON ) );
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	public static function register(): void {
		add_action( 'plugins_loaded', array( self::class, 'maybe_install' ), 5 );
	}

	public static function maybe_install(): void {
		$ver = (int) get_option( self::OPTION_DB_VERSION, 0 );
		if ( $ver >= self::DB_VERSION ) {
			return;
		}
		self::install_table();
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	public static function install_table(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			term_id bigint(20) unsigned NOT NULL,
			context varchar(32) NOT NULL,
			locale varchar(20) NOT NULL DEFAULT '',
			value longtext NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY term_context_locale (term_id, context, locale),
			KEY context_locale (context, locale),
			KEY term_id (term_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function is_valid_context( string $context ): bool {
		return in_array( $context, self::all_contexts(), true );
	}

	public static function normalize_locale( string $context, string $locale ): string {
		if ( self::CONTEXT_ICON === $context ) {
			return self::LOCALE_INVARIANT;
		}
		$locale = str_replace( '-', '_', trim( $locale ) );
		if ( '' === $locale ) {
			return self::site_locale();
		}
		return $locale;
	}

	public static function site_locale(): string {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$locale = str_replace( '-', '_', (string) $locale );
		return '' !== $locale ? $locale : 'en_US';
	}

	/**
	 * Resolve presentation value with fallbacks.
	 */
	public static function get( int $term_id, string $context, string $locale = '' ): string {
		if ( $term_id <= 0 || ! self::is_valid_context( $context ) ) {
			return '';
		}

		$locale = self::normalize_locale( $context, $locale );
		$raw    = self::get_raw( $term_id, $context, $locale );
		if ( '' !== $raw ) {
			return $raw;
		}

		if ( self::CONTEXT_ICON !== $context ) {
			$site = self::site_locale();
			if ( $locale !== $site ) {
				$raw = self::get_raw( $term_id, $context, $site );
				if ( '' !== $raw ) {
					return $raw;
				}
			}
		}

		return self::legacy_fallback( $term_id, $context );
	}

	/**
	 * Direct table read (no fallbacks).
	 */
	public static function get_raw( int $term_id, string $context, string $locale ): string {
		global $wpdb;
		self::maybe_install();
		$locale = self::normalize_locale( $context, $locale );
		$table  = self::table_name();
		$value  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT value FROM {$table} WHERE term_id = %d AND context = %s AND locale = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$term_id,
				$context,
				$locale
			)
		);
		return is_string( $value ) ? $value : '';
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set( int $term_id, string $context, string $locale, string $value ) {
		global $wpdb;
		if ( $term_id <= 0 || ! self::is_valid_context( $context ) ) {
			return new \WP_Error( 'wtt_presentation_bad_args', __( 'Invalid presentation arguments.', 'wp-taxonomy-tree' ) );
		}
		self::maybe_install();
		$locale = self::normalize_locale( $context, $locale );
		if ( self::CONTEXT_ICON === $context ) {
			$value = Tree_Icons::normalize_key( $value );
		} elseif ( in_array( $context, array( 'help', 'form' ), true ) ) {
			$value = sanitize_textarea_field( $value );
		} else {
			$value = sanitize_text_field( $value );
		}

		$table = self::table_name();
		if ( '' === $value ) {
			$wpdb->delete(
				$table,
				array(
					'term_id' => $term_id,
					'context' => $context,
					'locale'  => $locale,
				),
				array( '%d', '%s', '%s' )
			);
			if ( self::CONTEXT_ICON === $context ) {
				delete_term_meta( $term_id, Tree_Icons::META_KEY );
			}
			return true;
		}

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE term_id = %d AND context = %s AND locale = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$term_id,
				$context,
				$locale
			)
		);

		if ( $existing_id ) {
			$wpdb->update(
				$table,
				array( 'value' => $value ),
				array( 'id' => (int) $existing_id ),
				array( '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'term_id' => $term_id,
					'context' => $context,
					'locale'  => $locale,
					'value'   => $value,
				),
				array( '%d', '%s', '%s', '%s' )
			);
		}

		if ( self::CONTEXT_ICON === $context ) {
			update_term_meta( $term_id, Tree_Icons::META_KEY, $value );
		}

		/*
		 * Basiseinheit unit leaves: Identity shortDescription mirrors symbol
		 * so Compact / Sample fallbacks never paint a stale SI compound (kg).
		 */
		if ( 'symbol' === $context && '' !== $value ) {
			self::sync_short_description_from_symbol( $term_id, $value );
		}

		return true;
	}

	/**
	 * Align `_wtt_short_description` with Presentation.symbol for unit leaves.
	 *
	 * Glyph SoT is Presentation.symbol (seed may still use shortDescription).
	 * Never invent "kg" here — mass units stay "g"; prefix Kilo composes "kg" in Unit paint.
	 *
	 * @param string $symbol Optional explicit symbol; empty → read stored Presentation.symbol.
	 */
	public static function sync_short_description_from_symbol( int $term_id, string $symbol = '' ): void {
		if ( $term_id <= 0 || ! class_exists( Tree_Model::class ) ) {
			return;
		}
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}
		$taxonomy = (string) $term->taxonomy;
		if ( ! class_exists( Taxonomy::class ) || ! Taxonomy::is_scaffold( $taxonomy ) ) {
			return;
		}
		if (
			class_exists( Node_Type::class )
			&& ! Node_Type::is_basiseinheit_unit_node( $taxonomy, $term_id )
		) {
			return;
		}

		$symbol = trim( $symbol );
		if ( '' === $symbol ) {
			$symbol = trim( self::get( $term_id, 'symbol', self::site_locale() ) );
		}
		if ( '' === $symbol ) {
			return;
		}

		$current = Tree_Model::get_short_description( $term_id );
		if ( $current === $symbol ) {
			return;
		}
		Tree_Model::set_short_description( $taxonomy, $term_id, $symbol );
	}

	/**
	 * All stored rows for one term (any locale).
	 *
	 * @return list<array{context:string,locale:string,value:string}>
	 */
	public static function list_for_term( int $term_id ): array {
		global $wpdb;
		if ( $term_id <= 0 ) {
			return array();
		}
		self::maybe_install();
		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT context, locale, value FROM {$table} WHERE term_id = %d ORDER BY context ASC, locale ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$term_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'context' => (string) ( $row['context'] ?? '' ),
				'locale'  => (string) ( $row['locale'] ?? '' ),
				'value'   => (string) ( $row['value'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Map context => value for site locale (+ icon).
	 *
	 * @return array<string, string>
	 */
	public static function map_for_term_ui( int $term_id, string $locale = '' ): array {
		$locale = '' !== $locale ? $locale : self::site_locale();
		$map    = array();
		foreach ( self::TEXT_CONTEXTS as $ctx ) {
			$map[ $ctx ] = self::get( $term_id, $ctx, $locale );
		}
		$map[ self::CONTEXT_ICON ] = self::get( $term_id, self::CONTEXT_ICON, self::LOCALE_INVARIANT );
		return $map;
	}

	/**
	 * Presentation map for render/preview: fill empty slots from effective type
	 * (and shortDescription for symbol/table). Does not write the store.
	 *
	 * @return array<string, string>
	 */
	public static function map_for_term_resolved( string $taxonomy, int $term_id, string $locale = '' ): array {
		$map = self::map_for_term_ui( $term_id, $locale );
		if ( $term_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return $map;
		}

		$type_id = 0;
		if ( class_exists( Node_Type::class ) ) {
			$type_id = (int) Node_Type::get_effective_type_id( $taxonomy, $term_id );
		}
		if ( $type_id > 0 && $type_id !== $term_id ) {
			$type_map = self::map_for_term_ui( $type_id, $locale );
			foreach ( $map as $ctx => $val ) {
				if ( '' !== (string) $val ) {
					continue;
				}
				$from_type = isset( $type_map[ $ctx ] ) ? trim( (string) $type_map[ $ctx ] ) : '';
				if ( '' !== $from_type ) {
					$map[ $ctx ] = $from_type;
				}
			}
		}

		foreach ( array( 'symbol', 'table' ) as $ctx ) {
			if ( '' !== trim( (string) ( $map[ $ctx ] ?? '' ) ) ) {
				continue;
			}
			$short = '';
			if ( class_exists( Tree_Model::class ) ) {
				$short = trim( Tree_Model::get_short_description( $term_id ) );
				if ( '' === $short && $type_id > 0 ) {
					$short = trim( Tree_Model::get_short_description( $type_id ) );
				}
			}
			if ( '' !== $short ) {
				$map[ $ctx ] = $short;
			}
		}

		return $map;
	}

	/**
	 * Whether recommended text contexts are all non-empty for locale.
	 */
	public static function is_complete( int $term_id, string $locale = '' ): bool {
		$locale = '' !== $locale ? $locale : self::site_locale();
		foreach ( self::recommended_text_contexts() as $ctx ) {
			if ( '' === self::get( $term_id, $ctx, $locale ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Fill empty presentation slots from legacy term fields (idempotent).
	 * Never overwrites an existing presentation row.
	 *
	 * Mapping (when target empty):
	 * - form   ← term name
	 * - select ← term name (list-friendly, e.g. Milli)
	 * - symbol ← short_description when compact (e.g. m, µ, Ω)
	 * - table  ← short_description if set, else name
	 * - help   ← term description
	 * - icon   ← `_wtt_icon`
	 *
	 * @return int Rows written.
	 */
	public static function fill_taxonomy_from_legacy( string $taxonomy ): int {
		self::maybe_install();
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'all',
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return 0;
		}

		$locale  = self::site_locale();
		$written = 0;
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$written += self::fill_term_from_legacy( (int) $term->term_id, $term, $locale );
		}

		update_option( self::OPTION_MIGRATED . '_' . $taxonomy, '1', false );
		return $written;
	}

	/**
	 * @param \WP_Term|null $term Optional preloaded term.
	 * @return int Rows written.
	 */
	public static function fill_term_from_legacy( int $term_id, $term = null, string $locale = '' ): int {
		if ( $term_id <= 0 ) {
			return 0;
		}
		if ( ! $term instanceof \WP_Term ) {
			$loaded = get_term( $term_id );
			$term   = ( $loaded instanceof \WP_Term && ! is_wp_error( $loaded ) ) ? $loaded : null;
		}
		if ( ! $term instanceof \WP_Term ) {
			return 0;
		}

		$locale = '' !== $locale ? $locale : self::site_locale();
		$name   = trim( (string) $term->name );
		$desc   = trim( (string) $term->description );
		$short  = trim( Tree_Model::get_short_description( $term_id ) );
		$icon   = Tree_Icons::get( $term_id );
		$written = 0;

		if ( '' !== $name && '' === self::get_raw( $term_id, 'form', $locale ) ) {
			self::set( $term_id, 'form', $locale, $name );
			++$written;
		}
		if ( '' !== $name && '' === self::get_raw( $term_id, 'select', $locale ) ) {
			self::set( $term_id, 'select', $locale, $name );
			++$written;
		}

		if ( '' !== $short ) {
			if ( '' === self::get_raw( $term_id, 'symbol', $locale ) && self::looks_like_symbol( $short ) ) {
				self::set( $term_id, 'symbol', $locale, $short );
				++$written;
			}
			if ( '' === self::get_raw( $term_id, 'table', $locale ) ) {
				self::set( $term_id, 'table', $locale, $short );
				++$written;
			}
			/* Long short_description (MPN-style): also seed select/form when name is thin. */
			if ( ! self::looks_like_symbol( $short ) && strlen( $short ) > strlen( $name ) ) {
				if ( '' === self::get_raw( $term_id, 'select', $locale ) ) {
					self::set( $term_id, 'select', $locale, $short );
					++$written;
				}
			}
		} elseif ( '' !== $name && '' === self::get_raw( $term_id, 'table', $locale ) ) {
			self::set( $term_id, 'table', $locale, $name );
			++$written;
		}

		if ( '' !== $desc && '' === self::get_raw( $term_id, 'help', $locale ) ) {
			self::set( $term_id, 'help', $locale, $desc );
			++$written;
		}

		if ( '' !== $icon && '' === self::get_raw( $term_id, self::CONTEXT_ICON, self::LOCALE_INVARIANT ) ) {
			self::set( $term_id, self::CONTEXT_ICON, self::LOCALE_INVARIANT, $icon );
			++$written;
		}

		return $written;
	}

	/**
	 * Compact symbols / Kürzel (m, µ, Ω, L, …) vs long display shorts.
	 */
	public static function looks_like_symbol( string $text ): bool {
		$text = trim( $text );
		if ( '' === $text ) {
			return false;
		}
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $len <= 6 ) {
			return true;
		}
		return false;
	}

	/**
	 * @deprecated Use fill_taxonomy_from_legacy.
	 */
	public static function migrate_taxonomy( string $taxonomy ): int {
		return self::fill_taxonomy_from_legacy( $taxonomy );
	}

	/**
	 * Fill empty presentation slots from legacy once per taxonomy (safe).
	 * Never re-scans on every admin load — that re-seeded cleared slots from
	 * old term name/description after the user emptied presentation fields.
	 */
	public static function maybe_migrate_taxonomy( string $taxonomy ): void {
		$flag = self::OPTION_MIGRATED . '_' . sanitize_key( $taxonomy );
		if ( '1' === (string) get_option( $flag, '' ) ) {
			return;
		}
		self::fill_taxonomy_from_legacy( $taxonomy );
	}

	private static function legacy_fallback( int $term_id, string $context ): string {
		if ( self::CONTEXT_ICON === $context ) {
			return Tree_Icons::get( $term_id );
		}
		$short = Tree_Model::get_short_description( $term_id );
		$term  = get_term( $term_id );
		$name  = ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) ? trim( (string) $term->name ) : '';
		$desc  = ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) ? trim( (string) $term->description ) : '';

		if ( 'symbol' === $context && '' !== $short && self::looks_like_symbol( $short ) ) {
			return $short;
		}
		if ( 'table' === $context ) {
			return '' !== $short ? $short : $name;
		}
		if ( 'select' === $context || 'form' === $context ) {
			return '' !== $name ? $name : $short;
		}
		if ( 'help' === $context ) {
			return $desc;
		}
		return '';
	}
}
