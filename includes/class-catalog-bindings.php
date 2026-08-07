<?php
/**
 * Stable catalog branch bindings (rename-safe term ids per taxonomy).
 *
 * Template trees seed Data Types / Simple / Complex. Users may rename those
 * nodes; install-specific term ids still differ across sites. Resolve by
 * option binding, not display name.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option shape per taxonomy:
 * {
 *   chooser_root: shared catalog/tree branch root (e.g. Fallstudie) — not chooser-only,
 *   chooser_focus: default chooser focus/expand when caller passes none (e.g. Data Types),
 *   model: Model folder (Object View / Model table default selection),
 *   data_types, simple, complex: legacy aliases / helpers
 * }
 */
final class Catalog_Bindings {

	public const OPTION = 'wtt_catalog_bindings';

	/**
	 * Shared catalog/tree branch root (e.g. Fallstudie).
	 * Used by type chooser, Object View tree, pickable nodes — not chooser-only.
	 * Option key remains `chooser_root` (legacy name).
	 */
	public const KEY_CHOOSER_ROOT = 'chooser_root';

	/**
	 * Default chooser focus/expand node when the caller does not pass a focus
	 * (e.g. Data Types for the attribute type picker).
	 */
	public const KEY_CHOOSER_FOCUS = 'chooser_focus';

	/** Model folder — default selection for Object View / model bind. */
	public const KEY_MODEL = 'model';

	public const KEY_DATA_TYPES = 'data_types';

	public const KEY_SIMPLE = 'simple';

	public const KEY_COMPLEX = 'complex';

	/**
	 * @return list<string>
	 */
	public static function keys(): array {
		return array(
			self::KEY_CHOOSER_ROOT,
			self::KEY_CHOOSER_FOCUS,
			self::KEY_MODEL,
			self::KEY_DATA_TYPES,
			self::KEY_SIMPLE,
			self::KEY_COMPLEX,
		);
	}

	/**
	 * Human-readable labels for Settings (key → label).
	 *
	 * @return array<string, string>
	 */
	public static function key_labels(): array {
		return array(
			self::KEY_CHOOSER_ROOT  => __( 'Root (shared tree branch)', 'wp-taxonomy-tree' ),
			self::KEY_CHOOSER_FOCUS => __( 'Chooser default focus (fallback only)', 'wp-taxonomy-tree' ),
			self::KEY_MODEL         => __( 'Model node (Object View / Model table)', 'wp-taxonomy-tree' ),
			self::KEY_DATA_TYPES    => __( 'Data Types (legacy alias)', 'wp-taxonomy-tree' ),
			self::KEY_SIMPLE        => __( 'Simple (helper)', 'wp-taxonomy-tree' ),
			self::KEY_COMPLEX       => __( 'Complex (helper)', 'wp-taxonomy-tree' ),
		);
	}

	/**
	 * Short help under each Settings binding label.
	 *
	 * @return array<string, string>
	 */
	public static function key_helps(): array {
		return array(
			self::KEY_CHOOSER_ROOT  => __( 'Subtree shown in shared tree choosers (e.g. Fallstudie).', 'wp-taxonomy-tree' ),
			self::KEY_CHOOSER_FOCUS => __( 'Used only when a picker passes no focus of its own (e.g. attribute type chooser → Data Types). Not the Object View / Model table default — that is Model.', 'wp-taxonomy-tree' ),
			self::KEY_MODEL         => __( 'Explicit default selection and focus for Object View and Model table. Wins over chooser default focus.', 'wp-taxonomy-tree' ),
			self::KEY_DATA_TYPES    => __( 'Legacy helper; migrates into chooser default focus when that key is empty.', 'wp-taxonomy-tree' ),
			self::KEY_SIMPLE        => __( 'Helper binding for scaffold tooling.', 'wp-taxonomy-tree' ),
			self::KEY_COMPLEX       => __( 'Helper binding for scaffold tooling.', 'wp-taxonomy-tree' ),
		);
	}

	/**
	 * Migrate legacy maps: data_types → chooser_focus when focus empty.
	 * Does not invent chooser_root from data_types (that would wrongly shrink the picker).
	 *
	 * @param array<string, mixed> $map
	 * @return array<string, int>
	 */
	private static function migrate_map( array $map ): array {
		$clean = array();
		foreach ( self::keys() as $key ) {
			$id = isset( $map[ $key ] ) ? absint( $map[ $key ] ) : 0;
			if ( $id > 0 ) {
				$clean[ $key ] = $id;
			}
		}
		$focus = isset( $clean[ self::KEY_CHOOSER_FOCUS ] ) ? $clean[ self::KEY_CHOOSER_FOCUS ] : 0;
		$legacy = isset( $clean[ self::KEY_DATA_TYPES ] ) ? $clean[ self::KEY_DATA_TYPES ] : 0;
		if ( $focus <= 0 && $legacy > 0 ) {
			$clean[ self::KEY_CHOOSER_FOCUS ] = $legacy;
		}
		return $clean;
	}

	/**
	 * @return array<string, array<string, int>>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out      = array();
		$persist  = false;
		foreach ( $raw as $taxonomy => $map ) {
			if ( ! is_string( $taxonomy ) || ! Taxonomy::is_scaffold( $taxonomy ) || ! is_array( $map ) ) {
				continue;
			}
			$before = array();
			foreach ( self::keys() as $key ) {
				$id = isset( $map[ $key ] ) ? absint( $map[ $key ] ) : 0;
				if ( $id > 0 ) {
					$before[ $key ] = $id;
				}
			}
			$clean = self::migrate_map( $map );
			if ( $clean !== $before ) {
				$persist = true;
			}
			if ( $clean ) {
				$out[ $taxonomy ] = $clean;
			}
		}
		if ( $persist ) {
			update_option( self::OPTION, $out, false );
		}
		return $out;
	}

	/**
	 * @return array<string, int>
	 */
	public static function for_taxonomy( string $taxonomy ): array {
		if ( ! Taxonomy::is_scaffold( $taxonomy ) ) {
			return array();
		}
		$all = self::all();
		return isset( $all[ $taxonomy ] ) && is_array( $all[ $taxonomy ] ) ? $all[ $taxonomy ] : array();
	}

	public static function get( string $taxonomy, string $key ): int {
		$map = self::for_taxonomy( $taxonomy );
		return isset( $map[ $key ] ) ? absint( $map[ $key ] ) : 0;
	}

	/**
	 * Bound id only if the term still exists in that taxonomy.
	 */
	public static function resolve( string $taxonomy, string $key ): int {
		$id = self::get( $taxonomy, $key );
		if ( $id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}
		$term = get_term( $id, $taxonomy );
		return $term instanceof \WP_Term ? $id : 0;
	}

	/**
	 * @param array<string, int> $bindings Key → term id.
	 */
	public static function set_for_taxonomy( string $taxonomy, array $bindings ): void {
		if ( ! Taxonomy::is_scaffold( $taxonomy ) ) {
			return;
		}
		$all   = self::all();
		$clean = self::migrate_map( $bindings );
		if ( $clean ) {
			$all[ $taxonomy ] = $clean;
		} else {
			unset( $all[ $taxonomy ] );
		}
		update_option( self::OPTION, $all, false );
	}

	public static function set( string $taxonomy, string $key, int $term_id ): void {
		if ( ! in_array( $key, self::keys(), true ) ) {
			return;
		}
		$map         = self::for_taxonomy( $taxonomy );
		$map[ $key ] = absint( $term_id );
		self::set_for_taxonomy( $taxonomy, $map );
	}

	/**
	 * Payload for admin JS (current taxonomy).
	 *
	 * @return array<string, int>
	 */
	public static function for_client( string $taxonomy ): array {
		$focus = self::resolve( $taxonomy, self::KEY_CHOOSER_FOCUS );
		if ( $focus <= 0 ) {
			$focus = self::resolve( $taxonomy, self::KEY_DATA_TYPES );
		}
		return array(
			self::KEY_CHOOSER_ROOT  => self::resolve( $taxonomy, self::KEY_CHOOSER_ROOT ),
			self::KEY_CHOOSER_FOCUS => $focus,
			self::KEY_MODEL         => self::resolve( $taxonomy, self::KEY_MODEL ),
			self::KEY_DATA_TYPES    => self::resolve( $taxonomy, self::KEY_DATA_TYPES ),
			self::KEY_SIMPLE        => self::resolve( $taxonomy, self::KEY_SIMPLE ),
			self::KEY_COMPLEX       => self::resolve( $taxonomy, self::KEY_COMPLEX ),
		);
	}

	/**
	 * Terms eligible as catalog binding targets (slots / trash excluded).
	 *
	 * @return list<array{id:int,path:string}>
	 */
	public static function list_candidate_terms( string $taxonomy ): array {
		if ( ! Taxonomy::is_scaffold( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$term_id = (int) $term->term_id;
			if ( Trash::is_trashed( $term_id ) || Trash::is_trash_node( $term_id ) ) {
				continue;
			}
			if ( Attribute::is_slot( $term_id ) ) {
				continue;
			}
			$out[] = array(
				'id'   => $term_id,
				'path' => Composition::term_path( $taxonomy, $term_id ),
			);
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['path'], $b['path'] );
			}
		);
		return $out;
	}

	/**
	 * Sanitize Settings form payload for option {@see self::OPTION}.
	 *
	 * Expected shape: { taxonomy: { key: term_id, … }, … }
	 * Keys present in the payload replace existing values (0 = clear).
	 * Keys omitted from a submitted taxonomy map keep their previous id.
	 *
	 * @param mixed $value Raw option value.
	 * @return array<string, array<string, int>>
	 */
	public static function sanitize_option( $value ): array {
		$existing = self::all();
		if ( ! is_array( $value ) ) {
			return $existing;
		}

		$out = $existing;
		foreach ( $value as $taxonomy => $map ) {
			if ( ! is_string( $taxonomy ) || ! Taxonomy::is_scaffold( $taxonomy ) || ! is_array( $map ) ) {
				continue;
			}
			$prev  = isset( $existing[ $taxonomy ] ) && is_array( $existing[ $taxonomy ] )
				? $existing[ $taxonomy ]
				: array();
			$clean = $prev;
			foreach ( self::keys() as $key ) {
				if ( ! array_key_exists( $key, $map ) ) {
					continue;
				}
				$id = absint( $map[ $key ] );
				if ( $id <= 0 ) {
					unset( $clean[ $key ] );
					continue;
				}
				if ( ! taxonomy_exists( $taxonomy ) ) {
					unset( $clean[ $key ] );
					continue;
				}
				$term = get_term( $id, $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					unset( $clean[ $key ] );
					continue;
				}
				if ( Attribute::is_slot( $id ) || Trash::is_trashed( $id ) || Trash::is_trash_node( $id ) ) {
					unset( $clean[ $key ] );
					continue;
				}
				$clean[ $key ] = $id;
			}
			$clean = self::migrate_map( $clean );
			if ( $clean ) {
				$out[ $taxonomy ] = $clean;
			} else {
				unset( $out[ $taxonomy ] );
			}
		}

		return $out;
	}

	/**
	 * Bind Fallstudie chooser root + Data Types focus (+ Simple/Complex helpers).
	 *
	 * @return array<string, int>
	 */
	public static function ensure_case_study( string $taxonomy = Taxonomy::FS ): array {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$root = self::resolve( $taxonomy, self::KEY_CHOOSER_ROOT );
		if ( $root <= 0 ) {
			$root = self::find_term_by_paths(
				$taxonomy,
				array(
					array( 'Fallstudie' ),
				)
			);
		}

		$data_types = self::resolve( $taxonomy, self::KEY_DATA_TYPES );
		if ( $data_types <= 0 ) {
			$data_types = self::find_term_by_paths(
				$taxonomy,
				array(
					array( 'Fallstudie', 'Definition', 'Data Types' ),
					array( 'Fallstudie', 'Definition', 'Datentypen' ),
				)
			);
		}

		$focus = self::resolve( $taxonomy, self::KEY_CHOOSER_FOCUS );
		if ( $focus <= 0 ) {
			$focus = $data_types;
		}

		$simple = self::resolve( $taxonomy, self::KEY_SIMPLE );
		if ( $simple <= 0 ) {
			$simple = self::find_term_by_paths(
				$taxonomy,
				array(
					array( 'Fallstudie', 'Definition', 'Data Types', 'Simple' ),
					array( 'Fallstudie', 'Definition', 'Datentypen', 'Simple' ),
				)
			);
			if ( $simple <= 0 && $data_types > 0 ) {
				$simple = self::find_child_named( $taxonomy, $data_types, 'Simple' );
			}
		}

		$complex = self::resolve( $taxonomy, self::KEY_COMPLEX );
		if ( $complex <= 0 ) {
			$complex = self::find_term_by_paths(
				$taxonomy,
				array(
					array( 'Fallstudie', 'Definition', 'Data Types', 'Complex' ),
					array( 'Fallstudie', 'Definition', 'Datentypen', 'Complex' ),
				)
			);
			if ( $complex <= 0 && $data_types > 0 ) {
				$complex = self::find_child_named( $taxonomy, $data_types, 'Complex' );
			}
		}

		$model = self::resolve( $taxonomy, self::KEY_MODEL );
		if ( $model <= 0 ) {
			$model = self::find_term_by_paths(
				$taxonomy,
				array(
					array( 'Fallstudie', 'Model' ),
				)
			);
			if ( $model <= 0 && $root > 0 ) {
				$model = self::find_child_named( $taxonomy, $root, 'Model' );
			}
		}

		$bindings = array(
			self::KEY_CHOOSER_ROOT  => $root,
			self::KEY_CHOOSER_FOCUS => $focus,
			self::KEY_MODEL         => $model,
			self::KEY_DATA_TYPES    => $data_types,
			self::KEY_SIMPLE        => $simple,
			self::KEY_COMPLEX       => $complex,
		);
		self::set_for_taxonomy( $taxonomy, $bindings );
		return array_filter( $bindings );
	}

	/**
	 * Bind BOM chooser root + Datentypen focus (+ Simple/Complex helpers).
	 *
	 * @return array<string, int>
	 */
	public static function ensure_bom( string $taxonomy = Taxonomy::TREE ): array {
		if ( Taxonomy::TREE !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$root = self::resolve( $taxonomy, self::KEY_CHOOSER_ROOT );
		if ( $root <= 0 ) {
			$root = self::find_term_by_paths(
				$taxonomy,
				array(
					array( 'BOM Testprojekt' ),
				)
			);
		}

		$data_types = self::resolve( $taxonomy, self::KEY_DATA_TYPES );
		if ( $data_types <= 0 ) {
			$data_types = self::find_term_by_paths(
				$taxonomy,
				array(
					array( 'BOM Testprojekt', 'Typen', 'Datentypen' ),
				)
			);
		}

		$focus = self::resolve( $taxonomy, self::KEY_CHOOSER_FOCUS );
		if ( $focus <= 0 ) {
			$focus = $data_types;
		}

		$simple = self::resolve( $taxonomy, self::KEY_SIMPLE );
		if ( $simple <= 0 ) {
			$simple = self::find_term_by_paths(
				$taxonomy,
				array(
					array( 'BOM Testprojekt', 'Typen', 'Datentypen', 'Simple' ),
				)
			);
			if ( $simple <= 0 && $data_types > 0 ) {
				$simple = self::find_child_named( $taxonomy, $data_types, 'Simple' );
			}
		}

		$complex = self::resolve( $taxonomy, self::KEY_COMPLEX );
		if ( $complex <= 0 ) {
			$complex = self::find_term_by_paths(
				$taxonomy,
				array(
					array( 'BOM Testprojekt', 'Typen', 'Datentypen', 'Complex' ),
				)
			);
			if ( $complex <= 0 && $data_types > 0 ) {
				$complex = self::find_child_named( $taxonomy, $data_types, 'Complex' );
			}
		}

		$bindings = array(
			self::KEY_CHOOSER_ROOT  => $root,
			self::KEY_CHOOSER_FOCUS => $focus,
			self::KEY_DATA_TYPES    => $data_types,
			self::KEY_SIMPLE        => $simple,
			self::KEY_COMPLEX       => $complex,
		);
		self::set_for_taxonomy( $taxonomy, $bindings );
		return array_filter( $bindings );
	}

	/**
	 * Refresh bindings for a managed taxonomy (path bootstrap if unbound).
	 *
	 * @return array<string, int>
	 */
	public static function ensure( string $taxonomy ): array {
		if ( Taxonomy::FS === $taxonomy ) {
			return self::ensure_case_study( $taxonomy );
		}
		if ( Taxonomy::TREE === $taxonomy ) {
			return self::ensure_bom( $taxonomy );
		}
		return array();
	}

	/**
	 * @param list<list<string>> $paths
	 */
	private static function find_term_by_paths( string $taxonomy, array $paths ): int {
		foreach ( $paths as $path ) {
			$id = self::find_term_by_path( $taxonomy, $path );
			if ( $id > 0 ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * @param list<string> $path
	 */
	private static function find_term_by_path( string $taxonomy, array $path ): int {
		$parent = 0;
		$id     = 0;
		foreach ( $path as $name ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $parent,
					'name'       => $name,
					'hide_empty' => false,
					'number'     => 1,
				)
			);
			if ( ! is_array( $terms ) || empty( $terms ) || ! $terms[0] instanceof \WP_Term ) {
				return 0;
			}
			$id     = (int) $terms[0]->term_id;
			$parent = $id;
		}
		return $id;
	}

	private static function find_child_named( string $taxonomy, int $parent_id, string $name ): int {
		if ( $parent_id <= 0 || '' === $name ) {
			return 0;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'name'       => $name,
				'hide_empty' => false,
				'number'     => 1,
			)
		);
		if ( ! is_array( $terms ) || empty( $terms ) || ! $terms[0] instanceof \WP_Term ) {
			return 0;
		}
		return (int) $terms[0]->term_id;
	}
}
