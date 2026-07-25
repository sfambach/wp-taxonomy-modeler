<?php
/**
 * Node planning attributes stored as term meta (scaffold).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Term-meta overlay for planning Node fields until custom storage exists.
 */
final class Node_Meta {

	public const META_HAS_TYPE   = '_wtt_has_type';
	public const META_REF_SCOPE  = '_wtt_ref_scope';
	public const META_TEMPLATE   = '_wtt_template';
	public const META_REQUIRED   = '_wtt_required';
	public const META_FOOTER_OP  = '_wtt_footer_op';
	public const META_PARAMETERS = '_wtt_parameters';
	public const META_EDGES      = '_wtt_edges';

	public const PROJECT_ROOT = 'BOM Testprojekt';

	/**
	 * @return array<string, mixed>
	 */
	public static function get_for_term( string $taxonomy, int $term_id ): array {
		$has_type  = (int) get_term_meta( $term_id, self::META_HAS_TYPE, true );
		$ref_scope = (int) get_term_meta( $term_id, self::META_REF_SCOPE, true );
		$template  = (string) get_term_meta( $term_id, self::META_TEMPLATE, true ) === '1';
		$required  = (string) get_term_meta( $term_id, self::META_REQUIRED, true ) === '1';
		$footer_op = (string) get_term_meta( $term_id, self::META_FOOTER_OP, true );
		if ( '' === $footer_op ) {
			$footer_op = 'none';
		}

		return array(
			'hasType'      => $has_type > 0 ? $has_type : null,
			'hasTypeName'  => self::term_name( $taxonomy, $has_type ),
			'hasTypePath'  => self::term_path( $taxonomy, $has_type ),
			'refScope'     => $ref_scope > 0 ? $ref_scope : null,
			'refScopeName' => self::term_name( $taxonomy, $ref_scope ),
			'template'     => $template,
			'required'     => $required,
			'footerOp'     => $footer_op,
			'parameters'   => self::get_parameters( $term_id ),
			'edges'        => self::get_edges( $term_id ),
			'typeOptions'  => self::type_picker_options( $taxonomy, $term_id ),
			'scopeOptions' => self::ref_scope_options( $taxonomy, $term_id ),
			'slotLike'     => self::is_slot_like( $taxonomy, $term_id ),
		);
	}

	/**
	 * @param array<string, mixed> $data Patch fields.
	 * @return true|\WP_Error
	 */
	public static function update( string $taxonomy, int $term_id, array $data ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		if ( array_key_exists( 'name', $data ) ) {
			$name = trim( (string) $data['name'] );
			if ( '' === $name ) {
				return new \WP_Error( 'wtt_empty_name', __( 'Name is required.', 'wp-taxonomy-tree' ) );
			}
			$updated = wp_update_term(
				$term_id,
				$taxonomy,
				array(
					'name' => $name,
					'description' => array_key_exists( 'description', $data )
						? (string) $data['description']
						: $term->description,
				)
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		} elseif ( array_key_exists( 'description', $data ) ) {
			$updated = wp_update_term(
				$term_id,
				$taxonomy,
				array( 'description' => (string) $data['description'] )
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		if ( array_key_exists( 'template', $data ) ) {
			update_term_meta( $term_id, self::META_TEMPLATE, ! empty( $data['template'] ) ? '1' : '0' );
		}
		if ( array_key_exists( 'required', $data ) ) {
			update_term_meta( $term_id, self::META_REQUIRED, ! empty( $data['required'] ) ? '1' : '0' );
		}
		if ( array_key_exists( 'footerOp', $data ) ) {
			$op = sanitize_key( (string) $data['footerOp'] );
			$allowed = array( 'none', 'label', 'sum', 'avg', 'min', 'max', 'count' );
			if ( ! in_array( $op, $allowed, true ) ) {
				$op = 'none';
			}
			update_term_meta( $term_id, self::META_FOOTER_OP, $op );
		}

		if ( array_key_exists( 'hasType', $data ) ) {
			$type_id = absint( $data['hasType'] );
			if ( $type_id > 0 ) {
				if ( ! self::is_under_typen( $taxonomy, $type_id, $term_id ) ) {
					return new \WP_Error( 'wtt_bad_type', __( 'Type must be under Typen (Q26).', 'wp-taxonomy-tree' ) );
				}
				update_term_meta( $term_id, self::META_HAS_TYPE, (string) $type_id );
				self::upsert_edge( $term_id, 'has_type', $type_id, null );
			} else {
				delete_term_meta( $term_id, self::META_HAS_TYPE );
				self::remove_edges_by_label( $term_id, 'has_type' );
			}
		}

		if ( array_key_exists( 'refScope', $data ) ) {
			$scope_id = absint( $data['refScope'] );
			if ( $scope_id > 0 ) {
				update_term_meta( $term_id, self::META_REF_SCOPE, (string) $scope_id );
				self::upsert_edge( $term_id, 'ref_scope', $scope_id, null );
			} else {
				delete_term_meta( $term_id, self::META_REF_SCOPE );
				self::remove_edges_by_label( $term_id, 'ref_scope' );
			}
		}

		if ( array_key_exists( 'parameters', $data ) && is_array( $data['parameters'] ) ) {
			$clean = array();
			foreach ( $data['parameters'] as $param ) {
				if ( ! is_array( $param ) ) {
					continue;
				}
				$pname = isset( $param['name'] ) ? sanitize_text_field( (string) $param['name'] ) : '';
				$ptype = isset( $param['type'] ) ? absint( $param['type'] ) : 0;
				if ( '' === $pname || $ptype <= 0 ) {
					continue;
				}
				if ( ! self::is_under_typen( $taxonomy, $ptype, $term_id ) ) {
					continue;
				}
				$clean[] = array(
					'id'       => isset( $param['id'] ) ? sanitize_key( (string) $param['id'] ) : uniqid( 'p_', false ),
					'name'     => $pname,
					'type'     => $ptype,
					'required' => ! empty( $param['required'] ),
					'footerOp' => isset( $param['footerOp'] ) ? sanitize_key( (string) $param['footerOp'] ) : 'none',
					'refScope' => isset( $param['refScope'] ) ? absint( $param['refScope'] ) : null,
				);
			}
			update_term_meta( $term_id, self::META_PARAMETERS, wp_json_encode( $clean ) );
		}

		return true;
	}

	/**
	 * Seed known has_type bindings after demo install (idempotent).
	 */
	public static function seed_demo_bindings( string $taxonomy ): void {
		$map = array(
			// path relative under BOM Testprojekt as name chain
			array( array( 'Compositionen', 'Rezept - Backzutaten', 'Bezeichnung' ), array( 'Typen', 'Datentypen', 'Simple', 'text' ), null ),
			array( array( 'Compositionen', 'Rezept - Backzutaten', 'Anzahl' ), array( 'Typen', 'Datentypen', 'Simple', 'int' ), null ),
			array( array( 'Compositionen', 'Rezept - Backzutaten', 'Aktiv' ), array( 'Typen', 'Datentypen', 'Simple', 'bool' ), null ),
			array( array( 'Compositionen', 'Rezept - Backzutaten', 'Code' ), array( 'Typen', 'Datentypen', 'Simple', 'char' ), null ),
			array( array( 'Compositionen', 'Rezept - Backzutaten', 'Faktor' ), array( 'Typen', 'Datentypen', 'Simple', 'double' ), null ),
			array( array( 'Typen', 'Datentypen', 'Complex', 'Collection', 'list', 'RefDes', 'Element' ), array( 'Typen', 'Datentypen', 'Simple', 'text' ), null ),
			array( array( 'Typen', 'Datentypen', 'Complex', 'Collection', 'enum', 'Bauart', 'Option' ), array( 'Typen', 'Datentypen', 'Simple', 'text' ), null ),
			array( array( 'Bauteile', 'Widerstand', 'Wert' ), array( 'Typen', 'Datentypen', 'Simple', 'double' ), null ),
			array( array( 'Bauteile', 'Widerstand', 'Praefix' ), array( 'Typen', 'Praefixe' ), null ),
			array( array( 'Bauteile', 'Widerstand', 'Einheit' ), array( 'Typen', 'Basiseinheit', 'Ohm' ), null ),
			array( array( 'Bauteile', 'Kondensator', 'Wert' ), array( 'Typen', 'Datentypen', 'Simple', 'double' ), null ),
			array( array( 'Bauteile', 'Kondensator', 'Praefix' ), array( 'Typen', 'Praefixe' ), null ),
			array( array( 'Bauteile', 'Kondensator', 'Einheit' ), array( 'Typen', 'Basiseinheit', 'Farad' ), null ),
			array( array( 'Compositionen', 'BOM' ), array( 'Typen', 'Datentypen', 'Complex', 'Collection', 'table' ), null ),
		);

		$project = self::find_named_root( $taxonomy, self::PROJECT_ROOT );
		if ( ! $project ) {
			return;
		}

		foreach ( $map as $row ) {
			$slot = self::find_by_path( $taxonomy, $project, $row[0] );
			$type = self::find_by_path( $taxonomy, $project, $row[1] );
			if ( ! $slot || ! $type ) {
				continue;
			}
			update_term_meta( $slot, self::META_HAS_TYPE, (string) $type );
			self::upsert_edge( $slot, 'has_type', $type, null );
			update_term_meta( $slot, self::META_REQUIRED, '0' );
		}

		// BOM parameters (Q64) - not tree children.
		$bom = self::find_by_path( $taxonomy, $project, array( 'Compositionen', 'BOM' ) );
		if ( $bom ) {
			$t_subtree = self::find_by_path( $taxonomy, $project, array( 'Typen', 'Datentypen', 'Complex', 'subtree' ) );
			$t_qty     = self::find_by_path( $taxonomy, $project, array( 'Typen', 'Datentypen', 'Complex', 'quantity' ) );
			$t_int     = self::find_by_path( $taxonomy, $project, array( 'Typen', 'Datentypen', 'Simple', 'int' ) );
			$t_ta      = self::find_by_path( $taxonomy, $project, array( 'Typen', 'Datentypen', 'Simple', 'textarea' ) );
			$refdes    = self::find_by_path( $taxonomy, $project, array( 'Typen', 'Datentypen', 'Complex', 'Collection', 'list', 'RefDes' ) );
			$bauart    = self::find_by_path( $taxonomy, $project, array( 'Typen', 'Datentypen', 'Complex', 'Collection', 'enum', 'Bauart' ) );
			$bauteile  = self::find_by_path( $taxonomy, $project, array( 'Bauteile' ) );

			$params = array();
			if ( $t_subtree ) {
				$params[] = array(
					'id'       => 'p_bauteil',
					'name'     => 'Bauteil Wahl',
					'type'     => $t_subtree,
					'required' => true,
					'footerOp' => 'count',
					'refScope' => $bauteile ?: null,
				);
			}
			if ( $refdes ) {
				$params[] = array(
					'id'       => 'p_ref',
					'name'     => 'Reference',
					'type'     => $refdes,
					'required' => true,
					'footerOp' => 'none',
					'refScope' => null,
				);
			}
			if ( $t_qty ) {
				$params[] = array(
					'id'       => 'p_wert',
					'name'     => 'Wert',
					'type'     => $t_qty,
					'required' => true,
					'footerOp' => 'none',
					'refScope' => null,
				);
			}
			if ( $bauart ) {
				$params[] = array(
					'id'       => 'p_fp',
					'name'     => 'Footprint',
					'type'     => $bauart,
					'required' => false,
					'footerOp' => 'none',
					'refScope' => null,
				);
			}
			if ( $t_int ) {
				$params[] = array(
					'id'       => 'p_menge',
					'name'     => 'Menge',
					'type'     => $t_int,
					'required' => true,
					'footerOp' => 'sum',
					'refScope' => null,
				);
			}
			if ( $t_ta ) {
				$params[] = array(
					'id'       => 'p_desc',
					'name'     => 'Beschreibung',
					'type'     => $t_ta,
					'required' => false,
					'footerOp' => 'none',
					'refScope' => null,
				);
			}
			update_term_meta( $bom, self::META_PARAMETERS, wp_json_encode( $params ) );
		}

		// Collection.Projektname parameter
		$collection = self::find_by_path( $taxonomy, $project, array( 'Typen', 'Datentypen', 'Complex', 'Collection' ) );
		$t_text     = self::find_by_path( $taxonomy, $project, array( 'Typen', 'Datentypen', 'Simple', 'text' ) );
		if ( $collection && $t_text ) {
			update_term_meta(
				$collection,
				self::META_PARAMETERS,
				wp_json_encode(
					array(
						array(
							'id'       => 'p_projektname',
							'name'     => 'Projektname',
							'type'     => $t_text,
							'required' => true,
							'footerOp' => 'none',
							'refScope' => null,
						),
					)
				)
			);
		}

		// Prefix multiplikator edges (value on edge).
		$factors = array(
			'p'    => 1e-12,
			'n'    => 1e-9,
			'u'    => 1e-6,
			'm'    => 1e-3,
			'c'    => 1e-2,
			'k'    => 1e3,
			'Mega' => 1e6,
		);
		$t_int = self::find_by_path( $taxonomy, $project, array( 'Typen', 'Datentypen', 'Simple', 'int' ) );
		foreach ( $factors as $name => $factor ) {
			$pref = self::find_by_path( $taxonomy, $project, array( 'Typen', 'Praefixe', $name ) );
			if ( $pref && $t_int ) {
				self::upsert_edge( $pref, 'multiplikator', $t_int, $factor );
			}
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_parameters( int $term_id ): array {
		$raw = get_term_meta( $term_id, self::META_PARAMETERS, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_edges( int $term_id ): array {
		$raw = get_term_meta( $term_id, self::META_EDGES, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param mixed $value Edge prop value.
	 */
	public static function upsert_edge( int $from_id, string $label, int $to_id, $value ): void {
		$edges = self::get_edges( $from_id );
		$found = false;
		foreach ( $edges as &$edge ) {
			if ( ! is_array( $edge ) ) {
				continue;
			}
			if ( ( $edge['label'] ?? '' ) === $label ) {
				$edge['to'] = $to_id;
				if ( null !== $value ) {
					$edge['value'] = $value;
				} else {
					unset( $edge['value'] );
				}
				$found = true;
				break;
			}
		}
		unset( $edge );
		if ( ! $found ) {
			$edge = array(
				'label' => $label,
				'to'    => $to_id,
			);
			if ( null !== $value ) {
				$edge['value'] = $value;
			}
			$edges[] = $edge;
		}
		update_term_meta( $from_id, self::META_EDGES, wp_json_encode( array_values( $edges ) ) );
	}

	public static function remove_edges_by_label( int $from_id, string $label ): void {
		$edges = array_values(
			array_filter(
				self::get_edges( $from_id ),
				static function ( $edge ) use ( $label ) {
					return is_array( $edge ) && ( $edge['label'] ?? '' ) !== $label;
				}
			)
		);
		update_term_meta( $from_id, self::META_EDGES, wp_json_encode( $edges ) );
	}

	/**
	 * @return array<int, array{id:int,label:string,path:string}>
	 */
	public static function type_picker_options( string $taxonomy, int $context_term_id ): array {
		$project = self::project_root_of( $taxonomy, $context_term_id );
		if ( ! $project ) {
			$project = self::find_named_root( $taxonomy, self::PROJECT_ROOT );
		}
		if ( ! $project ) {
			return array();
		}
		$typen = self::find_child_named( $taxonomy, $project, 'Typen' );
		if ( ! $typen ) {
			return array();
		}

		$options = array();
		self::collect_descendants( $taxonomy, $typen, $options, $typen );
		usort(
			$options,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['path'], $b['path'] );
			}
		);
		return $options;
	}

	/**
	 * @return array<int, array{id:int,label:string,path:string}>
	 */
	public static function ref_scope_options( string $taxonomy, int $context_term_id ): array {
		$project = self::project_root_of( $taxonomy, $context_term_id );
		if ( ! $project ) {
			$project = self::find_named_root( $taxonomy, self::PROJECT_ROOT );
		}
		if ( ! $project ) {
			return array();
		}

		$options = array();
		$top     = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $project,
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $top ) ) {
			return array();
		}
		foreach ( $top as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$options[] = array(
				'id'    => (int) $term->term_id,
				'label' => $term->name,
				'path'  => self::PROJECT_ROOT . ' / ' . $term->name,
			);
			if ( 'Bauteile' === $term->name ) {
				$kids = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'parent'     => (int) $term->term_id,
						'hide_empty' => false,
					)
				);
				if ( is_array( $kids ) ) {
					foreach ( $kids as $kid ) {
						if ( ! $kid instanceof \WP_Term ) {
							continue;
						}
						$options[] = array(
							'id'    => (int) $kid->term_id,
							'label' => $kid->name,
							'path'  => self::PROJECT_ROOT . ' / Bauteile / ' . $kid->name,
						);
					}
				}
			}
		}
		return $options;
	}

	public static function is_slot_like( string $taxonomy, int $term_id ): bool {
		if ( (int) get_term_meta( $term_id, self::META_HAS_TYPE, true ) > 0 ) {
			return true;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || ! $term->parent ) {
			return false;
		}
		$parent = get_term( (int) $term->parent, $taxonomy );
		if ( ! $parent instanceof \WP_Term ) {
			return false;
		}
		$parent_type = (int) get_term_meta( (int) $parent->term_id, self::META_HAS_TYPE, true );
		if ( $parent_type > 0 ) {
			$pt = get_term( $parent_type, $taxonomy );
			if ( $pt instanceof \WP_Term && in_array( $pt->name, array( 'table', 'list', 'enum' ), true ) ) {
				return true;
			}
		}
		if ( in_array( $parent->name, array( 'list', 'table', 'enum', 'Collection', 'Widerstand', 'Kondensator', 'Rezept - Backzutaten' ), true ) ) {
			return true;
		}
		$gp = $parent->parent ? get_term( (int) $parent->parent, $taxonomy ) : null;
		if ( $gp instanceof \WP_Term && 'Bauteile' === $gp->name ) {
			return true;
		}
		return false;
	}

	private static function is_under_typen( string $taxonomy, int $type_id, int $context_term_id ): bool {
		$project = self::project_root_of( $taxonomy, $context_term_id );
		if ( ! $project ) {
			$project = self::find_named_root( $taxonomy, self::PROJECT_ROOT );
		}
		if ( ! $project ) {
			return false;
		}
		$typen = self::find_child_named( $taxonomy, $project, 'Typen' );
		if ( ! $typen ) {
			return false;
		}
		return self::is_ancestor( $taxonomy, $typen, $type_id ) || $typen === $type_id;
	}

	private static function is_ancestor( string $taxonomy, int $ancestor_id, int $term_id ): bool {
		$current = get_term( $term_id, $taxonomy );
		$guard   = 0;
		while ( $current instanceof \WP_Term && $current->parent && $guard < 50 ) {
			if ( (int) $current->parent === $ancestor_id ) {
				return true;
			}
			$current = get_term( (int) $current->parent, $taxonomy );
			++$guard;
		}
		return false;
	}

	private static function project_root_of( string $taxonomy, int $term_id ): ?int {
		$current = get_term( $term_id, $taxonomy );
		$guard   = 0;
		while ( $current instanceof \WP_Term && $guard < 50 ) {
			if ( self::PROJECT_ROOT === $current->name && 0 === (int) $current->parent ) {
				return (int) $current->term_id;
			}
			if ( ! $current->parent ) {
				break;
			}
			$current = get_term( (int) $current->parent, $taxonomy );
			++$guard;
		}
		return null;
	}

	private static function find_named_root( string $taxonomy, string $name ): ?int {
		$found = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => $name,
				'parent'     => 0,
				'hide_empty' => false,
				'number'     => 1,
			)
		);
		if ( is_array( $found ) && isset( $found[0] ) && $found[0] instanceof \WP_Term ) {
			return (int) $found[0]->term_id;
		}
		return null;
	}

	private static function find_child_named( string $taxonomy, int $parent_id, string $name ): ?int {
		$found = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => $name,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'number'     => 1,
			)
		);
		if ( is_array( $found ) && isset( $found[0] ) && $found[0] instanceof \WP_Term ) {
			return (int) $found[0]->term_id;
		}
		return null;
	}

	/**
	 * @param array<int, string> $path Names under $root_id.
	 */
	private static function find_by_path( string $taxonomy, int $root_id, array $path ): ?int {
		$current = $root_id;
		foreach ( $path as $name ) {
			$next = self::find_child_named( $taxonomy, $current, $name );
			if ( ! $next ) {
				return null;
			}
			$current = $next;
		}
		return $current;
	}

	/**
	 * @param array<int, array{id:int,label:string,path:string}> $options Collected options.
	 */
	private static function collect_descendants( string $taxonomy, int $parent_id, array &$options, int $typen_root ): void {
		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $kids ) ) {
			return;
		}
		foreach ( $kids as $kid ) {
			if ( ! $kid instanceof \WP_Term ) {
				continue;
			}
			$id = (int) $kid->term_id;
			$options[] = array(
				'id'    => $id,
				'label' => $kid->name,
				'path'  => self::term_path( $taxonomy, $id ),
			);
			self::collect_descendants( $taxonomy, $id, $options, $typen_root );
		}
	}

	private static function term_name( string $taxonomy, int $term_id ): string {
		if ( $term_id <= 0 ) {
			return '';
		}
		$term = get_term( $term_id, $taxonomy );
		return $term instanceof \WP_Term ? $term->name : '';
	}

	private static function term_path( string $taxonomy, int $term_id ): string {
		if ( $term_id <= 0 ) {
			return '';
		}
		$parts   = array();
		$current = get_term( $term_id, $taxonomy );
		$guard   = 0;
		while ( $current instanceof \WP_Term && $guard < 50 ) {
			array_unshift( $parts, $current->name );
			if ( ! $current->parent ) {
				break;
			}
			$current = get_term( (int) $current->parent, $taxonomy );
			++$guard;
		}
		return implode( ' / ', $parts );
	}
}
