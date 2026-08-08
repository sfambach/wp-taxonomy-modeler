<?php
/**
 * Optional tree-node icons (Dashicons).
 *
 * Model:
 * - Settings = allowlist of icon keys (from a curated catalog).
 * - Term meta `_wtt_icon` = optional per-node choice (empty = none).
 * - On create: standard icon by term name first; else copy parent icon when set.
 *   Later parent edits do not cascade. No live father-walk at render time.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catalog + term icon helpers for the admin taxonomy tree.
 */
final class Tree_Icons {

	public const OPTION_ENABLED = 'wtt_tree_icon_keys';

	public const META_KEY = '_wtt_icon';

	/**
	 * Icon keys drawn as CSS (not Dashicons glyphs).
	 * Empty — former example keys `circle` / `dot` were removed (use Dashicon `marker`).
	 *
	 * @return list<string>
	 */
	public static function css_icon_keys(): array {
		return array();
	}

	public static function is_css_icon( string $key ): bool {
		return in_array( self::normalize_key( $key ), self::css_icon_keys(), true );
	}

	/**
	 * Curated icons usable as tree icons (Dashicons key without prefix).
	 *
	 * @return array<string, string> key => English label
	 */
	public static function catalog(): array {
		return array(
			'marker'           => __( 'Marker', 'wp-taxonomy-tree' ),
			'category'         => __( 'Category / folder', 'wp-taxonomy-tree' ),
			'networking'       => __( 'Network / tree', 'wp-taxonomy-tree' ),
			'admin-site'       => __( 'Site / root', 'wp-taxonomy-tree' ),
			'admin-generic'    => __( 'Generic', 'wp-taxonomy-tree' ),
			'admin-page'       => __( 'Page / document', 'wp-taxonomy-tree' ),
			'admin-settings'   => __( 'Settings', 'wp-taxonomy-tree' ),
			'database'         => __( 'Database / data', 'wp-taxonomy-tree' ),
			'editor-code'      => __( 'Code / type', 'wp-taxonomy-tree' ),
			'editor-table'     => __( 'Table', 'wp-taxonomy-tree' ),
			'list-view'        => __( 'List', 'wp-taxonomy-tree' ),
			'editor-ul'        => __( 'Bullet list', 'wp-taxonomy-tree' ),
			'tag'              => __( 'Tag / kind', 'wp-taxonomy-tree' ),
			'products'         => __( 'Product / part', 'wp-taxonomy-tree' ),
			'portfolio'        => __( 'Portfolio / model', 'wp-taxonomy-tree' ),
			'groups'           => __( 'People / contact', 'wp-taxonomy-tree' ),
			'email'            => __( 'Email', 'wp-taxonomy-tree' ),
			'phone'            => __( 'Phone', 'wp-taxonomy-tree' ),
			'location'         => __( 'Location', 'wp-taxonomy-tree' ),
			'media-default'    => __( 'Media', 'wp-taxonomy-tree' ),
			'format-image'     => __( 'Image', 'wp-taxonomy-tree' ),
			'yes'              => __( 'Yes / bool', 'wp-taxonomy-tree' ),
			'no'               => __( 'No', 'wp-taxonomy-tree' ),
			'calculator'       => __( 'Number / calc', 'wp-taxonomy-tree' ),
			'text'             => __( 'Text', 'wp-taxonomy-tree' ),
			'calendar'         => __( 'Date', 'wp-taxonomy-tree' ),
			'chart-bar'        => __( 'Chart', 'wp-taxonomy-tree' ),
			'book'             => __( 'Book / definition', 'wp-taxonomy-tree' ),
			'hammer'           => __( 'Build / implementation', 'wp-taxonomy-tree' ),
			'trash'            => __( 'Trash', 'wp-taxonomy-tree' ),
			'hidden'           => __( 'Hidden', 'wp-taxonomy-tree' ),
			'warning'          => __( 'Warning', 'wp-taxonomy-tree' ),
			'info'             => __( 'Info', 'wp-taxonomy-tree' ),
			'star-filled'      => __( 'Star', 'wp-taxonomy-tree' ),
			'carrot'           => __( 'Pointer', 'wp-taxonomy-tree' ),
			'layout'           => __( 'Layout', 'wp-taxonomy-tree' ),
			'block-default'    => __( 'Block', 'wp-taxonomy-tree' ),
		);
	}

	/**
	 * Default enabled keys (subset of catalog) for a fresh install.
	 *
	 * @return list<string>
	 */
	public static function default_enabled_keys(): array {
		return array(
			'marker',
			'category',
			'networking',
			'admin-site',
			'admin-generic',
			'admin-page',
			'admin-settings',
			'database',
			'editor-code',
			'editor-table',
			'list-view',
			'tag',
			'products',
			'portfolio',
			'groups',
			'email',
			'media-default',
			'format-image',
			'yes',
			'calculator',
			'text',
			'calendar',
			'book',
			'hammer',
			'trash',
			'hidden',
			'warning',
			'info',
			'star-filled',
			'layout',
		);
	}

	/**
	 * Enabled icon keys from Settings (always intersected with catalog).
	 *
	 * @return list<string>
	 */
	public static function enabled_keys(): array {
		$raw = get_option( self::OPTION_ENABLED, null );
		if ( null === $raw || false === $raw || '' === $raw ) {
			return self::default_enabled_keys();
		}
		return self::sanitize_enabled_keys( $raw );
	}

	/**
	 * @param mixed $raw Option value (array or JSON string).
	 * @return list<string>
	 */
	public static function sanitize_enabled_keys( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return self::default_enabled_keys();
		}
		$catalog = self::catalog();
		$out     = array();
		foreach ( $raw as $key ) {
			$key = self::normalize_key( (string) $key );
			if ( '' === $key || ! isset( $catalog[ $key ] ) ) {
				continue;
			}
			if ( ! in_array( $key, $out, true ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	public static function normalize_key( string $key ): string {
		$key = strtolower( trim( $key ) );
		$key = preg_replace( '/^dashicons-/', '', $key ) ?? $key;
		$key = preg_replace( '/[^a-z0-9\-]/', '', $key ) ?? '';
		return $key;
	}

	public static function is_allowed( string $key ): bool {
		$key = self::normalize_key( $key );
		return '' !== $key && in_array( $key, self::enabled_keys(), true );
	}

	/**
	 * Whether a key exists in the curated catalog (regardless of Settings allowlist).
	 */
	public static function is_catalog_key( string $key ): bool {
		$key = self::normalize_key( $key );
		return '' !== $key && isset( self::catalog()[ $key ] );
	}

	/**
	 * Options for pickers: enabled keys with labels + dashicons class.
	 *
	 * @return list<array{key:string,label:string,class:string}>
	 */
	public static function picker_options(): array {
		$catalog = self::catalog();
		$out     = array();
		foreach ( self::enabled_keys() as $key ) {
			$out[] = array(
				'key'   => $key,
				'label' => isset( $catalog[ $key ] ) ? (string) $catalog[ $key ] : $key,
				'class' => 'dashicons dashicons-' . $key,
			);
		}
		return $out;
	}

	/**
	 * Ensure a catalog key is in the Settings allowlist (for seeded standards).
	 */
	public static function ensure_key_enabled( string $key ): void {
		$key = self::normalize_key( $key );
		if ( '' === $key || ! isset( self::catalog()[ $key ] ) ) {
			return;
		}
		$raw = get_option( self::OPTION_ENABLED, null );
		if ( null === $raw || false === $raw || '' === $raw ) {
			/* Still on defaults — marker and other standards already included. */
			return;
		}
		$keys = self::sanitize_enabled_keys( $raw );
		if ( in_array( $key, $keys, true ) ) {
			return;
		}
		$keys[] = $key;
		update_option( self::OPTION_ENABLED, $keys, false );
	}

	/**
	 * Set icon when the node has no icon meta yet, or when stored key left the catalog
	 * (e.g. retired CSS examples `circle` / `dot`). Does not overwrite a valid catalog choice.
	 *
	 * @return bool True when an icon was written.
	 */
	public static function seed_if_empty( string $taxonomy, int $term_id, string $key ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		$existing = self::normalize_key( (string) get_term_meta( $term_id, self::META_KEY, true ) );
		if ( '' !== $existing && self::is_catalog_key( $existing ) ) {
			return false;
		}
		return self::apply_standard( $taxonomy, $term_id, $key );
	}

	/**
	 * Force-apply a catalog standard icon (enable allowlist key, then set).
	 * Used for implanted Case_Data Simple catalog terms — not for arbitrary user nodes.
	 *
	 * @return bool True when an icon was written (or already matched).
	 */
	public static function apply_standard( string $taxonomy, int $term_id, string $key ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		$key = self::normalize_key( $key );
		if ( '' === $key || ! self::is_catalog_key( $key ) ) {
			return false;
		}
		$existing = self::normalize_key( (string) get_term_meta( $term_id, self::META_KEY, true ) );
		if ( $existing === $key && self::is_allowed( $key ) ) {
			return true;
		}
		self::ensure_key_enabled( $key );
		$result = self::set( $taxonomy, $term_id, $key );
		return ! is_wp_error( $result );
	}

	public static function get( int $term_id ): string {
		if ( $term_id <= 0 ) {
			return '';
		}
		$key = self::normalize_key( (string) get_term_meta( $term_id, self::META_KEY, true ) );
		return self::is_allowed( $key ) ? $key : '';
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set( string $taxonomy, int $term_id, string $key ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		$key = self::normalize_key( $key );
		if ( '' === $key ) {
			delete_term_meta( $term_id, self::META_KEY );
			return true;
		}
		if ( ! self::is_allowed( $key ) ) {
			return new \WP_Error( 'wtt_bad_icon', __( 'Icon is not in the allowed catalog.', 'wp-taxonomy-tree' ) );
		}
		update_term_meta( $term_id, self::META_KEY, $key );
		return true;
	}

	/**
	 * Default icon key for a known node name (case-insensitive), or empty.
	 *
	 * Only returns keys that exist in catalog(). Small YAGNI map for Simple
	 * scalars and obvious catalog matches — not a full type→icon registry.
	 */
	public static function standard_for_name( string $name ): string {
		$norm = strtolower( trim( $name ) );
		if ( '' === $norm ) {
			return '';
		}

		$map = array(
			'simple'            => 'marker',
			'int'               => 'calculator',
			'integer'           => 'calculator',
			'double'            => 'calculator',
			'float'             => 'calculator',
			'number'            => 'calculator',
			'bool'              => 'yes',
			'boolean'           => 'yes',
			'text'              => 'text',
			'string'            => 'text',
			'textarea'          => 'admin-page',
			'char'              => 'editor-code',
			'date'              => 'calendar',
			'email'             => 'email',
			'media'             => 'media-default',
			'display_node_name' => 'tag',
		);

		if ( ! isset( $map[ $norm ] ) ) {
			return '';
		}

		$key = self::normalize_key( $map[ $norm ] );
		return isset( self::catalog()[ $key ] ) ? $key : '';
	}

	/**
	 * Assign icon on node create: standard-by-name first, else parent copy.
	 * Skips when the child already has a valid catalog icon. No later cascade.
	 */
	public static function apply_on_create( string $taxonomy, int $term_id, int $parent_id ): void {
		if ( $term_id <= 0 ) {
			return;
		}
		$existing = self::normalize_key( (string) get_term_meta( $term_id, self::META_KEY, true ) );
		if ( '' !== $existing && self::is_catalog_key( $existing ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$standard = self::standard_for_name( (string) $term->name );
		if ( '' !== $standard ) {
			self::ensure_key_enabled( $standard );
			if ( self::is_allowed( $standard ) ) {
				self::set( $taxonomy, $term_id, $standard );
				return;
			}
		}

		self::copy_from_parent( $taxonomy, $term_id, $parent_id );
	}

	/**
	 * Copy parent icon onto a new child (create-time helper; no later cascade).
	 */
	public static function copy_from_parent( string $taxonomy, int $term_id, int $parent_id ): void {
		if ( $term_id <= 0 || $parent_id <= 0 ) {
			return;
		}
		$parent_icon = self::get( $parent_id );
		if ( '' === $parent_icon ) {
			return;
		}
		self::set( $taxonomy, $term_id, $parent_icon );
	}
}
