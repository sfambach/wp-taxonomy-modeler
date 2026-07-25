<?php
/**
 * Taxonomy tree model over WordPress terms.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads hierarchical taxonomies as nested arrays for the admin UI.
 */
final class Tree_Model {

	/**
	 * List hierarchical public/admin taxonomies.
	 *
	 * @return array<int, array{slug:string,label:string}>
	 */
	public static function hierarchical_taxonomies(): array {
		$objects = get_taxonomies(
			array(
				'hierarchical' => true,
				'show_ui'      => true,
			),
			'objects'
		);

		$list = array();
		foreach ( $objects as $tax ) {
			if ( ! $tax instanceof \WP_Taxonomy ) {
				continue;
			}
			$list[] = array(
				'slug'  => $tax->name,
				'label' => (string) $tax->labels->name,
			);
		}

		usort(
			$list,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return $list;
	}

	public static function is_hierarchical_taxonomy( string $taxonomy ): bool {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		$tax = get_taxonomy( $taxonomy );
		return $tax instanceof \WP_Taxonomy && (bool) $tax->hierarchical;
	}

	/**
	 * Build nested tree for a taxonomy.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_tree( string $taxonomy ): array {
		if ( ! self::is_hierarchical_taxonomy( $taxonomy ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$by_parent = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$parent = (int) $term->parent;
			if ( ! isset( $by_parent[ $parent ] ) ) {
				$by_parent[ $parent ] = array();
			}
			$by_parent[ $parent ][] = $term;
		}

		return self::nest( $by_parent, 0 );
	}

	/**
	 * @param array<int, array<int, \WP_Term>> $by_parent Terms grouped by parent.
	 * @return array<int, array<string, mixed>>
	 */
	private static function nest( array $by_parent, int $parent_id ): array {
		if ( ! isset( $by_parent[ $parent_id ] ) ) {
			return array();
		}

		$nodes = array();
		foreach ( $by_parent[ $parent_id ] as $term ) {
			$children = self::nest( $by_parent, (int) $term->term_id );
			$nodes[]  = array(
				'id'          => (int) $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => (int) $term->parent,
				'count'       => (int) $term->count,
				'children'    => $children,
				'hasChildren' => count( $children ) > 0,
			);
		}

		return $nodes;
	}

	/**
	 * Serialize a single term for the side panel.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_node( string $taxonomy, int $term_id ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		$parent_name = '';
		if ( $term->parent ) {
			$parent = get_term( (int) $term->parent, $taxonomy );
			if ( $parent instanceof \WP_Term ) {
				$parent_name = $parent->name;
			}
		}

		return array(
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
			'parentName'  => $parent_name,
			'count'       => (int) $term->count,
			'hasChildren' => self::term_has_children( $taxonomy, (int) $term->term_id ),
		);
	}

	public static function term_has_children( string $taxonomy, int $term_id ): bool {
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 1,
			)
		);

		return is_array( $children ) && count( $children ) > 0;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_term( string $taxonomy, string $name, int $parent = 0 ) {
		$name = trim( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'wtt_empty_name', __( 'Name is required.', 'wp-taxonomy-tree' ) );
		}

		if ( $parent > 0 ) {
			$parent_term = get_term( $parent, $taxonomy );
			if ( ! $parent_term instanceof \WP_Term ) {
				return new \WP_Error( 'wtt_bad_parent', __( 'Parent term not found.', 'wp-taxonomy-tree' ) );
			}
		}

		$result = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'parent' => max( 0, $parent ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::get_node( $taxonomy, (int) $result['term_id'] );
	}

	/**
	 * Delete a term. Mode: leaf | promote | cascade.
	 *
	 * @return true|\WP_Error
	 */
	public static function delete_term( string $taxonomy, int $term_id, string $mode = 'leaf' ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		$has_children = self::term_has_children( $taxonomy, $term_id );

		if ( $has_children && 'leaf' === $mode ) {
			return new \WP_Error(
				'wtt_has_children',
				__( 'Term has children. Choose promote or cascade.', 'wp-taxonomy-tree' )
			);
		}

		if ( $has_children && 'promote' === $mode ) {
			$children = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $term_id,
					'hide_empty' => false,
				)
			);
			if ( is_array( $children ) ) {
				foreach ( $children as $child ) {
					if ( ! $child instanceof \WP_Term ) {
						continue;
					}
					$updated = wp_update_term(
						(int) $child->term_id,
						$taxonomy,
						array( 'parent' => (int) $term->parent )
					);
					if ( is_wp_error( $updated ) ) {
						return $updated;
					}
				}
			}
		}

		if ( $has_children && 'cascade' === $mode ) {
			$deleted = self::delete_descendants( $taxonomy, $term_id );
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
		}

		$result = wp_delete_term( $term_id, $taxonomy );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result || 0 === $result ) {
			return new \WP_Error( 'wtt_delete_failed', __( 'Could not delete term.', 'wp-taxonomy-tree' ) );
		}

		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function delete_descendants( string $taxonomy, int $term_id ) {
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $children ) ) {
			return true;
		}

		foreach ( $children as $child ) {
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			$nested = self::delete_descendants( $taxonomy, (int) $child->term_id );
			if ( is_wp_error( $nested ) ) {
				return $nested;
			}
			$result = wp_delete_term( (int) $child->term_id, $taxonomy );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}
}
