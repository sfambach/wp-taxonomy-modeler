<?php
/**
 * Plugin taxonomy registration (domain tree, not post categories).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and identifies the scaffold domain taxonomies.
 */
final class Taxonomy {

	/** Domain tree — not bound to posts; separate from blog `category`. */
	public const TREE = 'wtt_tree';

	/** Case-study tree — slim Definition / Implementation sample (parallel to BOM). */
	public const FS = 'wtt_fs';

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_taxonomies' ), 5 );
	}

	public static function register_taxonomies(): void {
		self::register_one(
			self::TREE,
			array(
				'name'          => __( 'Taxonomy Tree', 'wp-taxonomy-tree' ),
				'singular_name' => __( 'Tree node', 'wp-taxonomy-tree' ),
				'menu_name'     => __( 'Taxonomy Tree', 'wp-taxonomy-tree' ),
			)
		);

		self::register_one(
			self::FS,
			array(
				'name'          => __( 'Fallstudie', 'wp-taxonomy-tree' ),
				'singular_name' => __( 'Case node', 'wp-taxonomy-tree' ),
				'menu_name'     => __( 'Fallstudie', 'wp-taxonomy-tree' ),
			)
		);
	}

	/**
	 * @param array{name:string,singular_name:string,menu_name:string} $labels
	 */
	private static function register_one( string $slug, array $labels ): void {
		if ( taxonomy_exists( $slug ) ) {
			return;
		}

		register_taxonomy(
			$slug,
			array(),
			array(
				'labels'             => $labels,
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_tagcloud'       => false,
				'show_in_rest'       => false,
				'hierarchical'       => true,
				'rewrite'            => false,
				'query_var'          => false,
				'capabilities'       => array(
					'manage_terms' => 'manage_categories',
					'edit_terms'   => 'manage_categories',
					'delete_terms' => 'manage_categories',
					'assign_terms' => 'edit_posts',
				),
			)
		);
	}

	public static function default_slug(): string {
		return self::TREE;
	}

	public static function is_scaffold( string $taxonomy ): bool {
		return self::TREE === $taxonomy || self::FS === $taxonomy;
	}

	/**
	 * Scaffold taxonomy slugs (domain trees only).
	 *
	 * @return list<string>
	 */
	public static function scaffold_slugs(): array {
		return array( self::TREE, self::FS );
	}

	/**
	 * Resolve which scaffold taxonomy owns a term id.
	 */
	public static function taxonomy_for_term( int $term_id ): string {
		if ( $term_id <= 0 ) {
			return '';
		}
		foreach ( self::scaffold_slugs() as $slug ) {
			if ( ! taxonomy_exists( $slug ) ) {
				continue;
			}
			$term = get_term( $term_id, $slug );
			if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
				return $slug;
			}
		}
		return '';
	}

	public static function is_case_study( string $taxonomy ): bool {
		return self::FS === $taxonomy;
	}

	/**
	 * Taxonomies offered in the scaffold tree UI (excludes blog category).
	 *
	 * @return array<int, array{slug:string,label:string}>
	 */
	public static function scaffold_taxonomies(): array {
		self::register_taxonomies();

		$out = array();
		foreach ( array( self::TREE, self::FS ) as $slug ) {
			if ( ! taxonomy_exists( $slug ) ) {
				continue;
			}
			$tax   = get_taxonomy( $slug );
			$label = $tax instanceof \WP_Taxonomy
				? (string) $tax->labels->name
				: $slug;
			$out[] = array(
				'slug'  => $slug,
				'label' => $label,
			);
		}

		return $out;
	}
}
