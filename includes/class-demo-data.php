<?php
/**
 * Demo / test taxonomy tree seeder.
 *
 * Mirrors prototypes/tree-split seedTemplateCore + seedBomTestData (v33)
 * and docs/plans/data-structure.md (BOM Testprojekt). Relations / Parameters
 * are noted in term descriptions until the domain model is implemented.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs the BOM Testprojekt hierarchy (idempotent).
 */
final class Demo_Data {

	public const ROOT_NAME = 'BOM Testprojekt';

	/**
	 * Older stub roots from early scaffolds - removed on reset.
	 *
	 * @var array<int, string>
	 */
	private const LEGACY_ROOT_NAMES = array(
		'Passive Components',
		'Semiconductors',
	);

	/**
	 * Full BOM Testprojekt outline from planning + tree-split prototype.
	 *
	 * @return array<int, array{name:string, description?:string, children?:array<int, array<string, mixed>>}>
	 */
	public static function blueprint(): array {
		return array(
			array(
				'name'        => self::ROOT_NAME,
				'description' => 'Editable demo Project (proto Demo). Pure Template is Datentypen+Praefixe+SI units only; this copy adds Bauart, electronics units, Compositionen, Bauteile. See docs/plans/data-structure.md and prototypes/tree-split.',
				'children'    => array(
					array(
						'name'        => 'Typen',
						'description' => 'Type branch (Q26): Datentypen, Praefixe, Basiseinheit only.',
						'children'    => array(
							array(
								'name'        => 'Datentypen',
								'description' => 'Simple (scalars + node_ref) and Complex (quantity, subtree, Collection).',
								'children'    => array(
									array(
										'name'     => 'Simple',
										'children' => array(
											array( 'name' => 'int', 'description' => 'Whole number.' ),
											array( 'name' => 'double', 'description' => 'Floating point.' ),
											array( 'name' => 'text', 'description' => 'Single-line text.' ),
											array( 'name' => 'textarea', 'description' => 'Multi-line text.' ),
											array( 'name' => 'char', 'description' => 'Single character.' ),
											array( 'name' => 'bool', 'description' => 'Boolean.' ),
											array( 'name' => 'node_ref', 'description' => 'Free jump to any Node (no ref_scope).' ),
										),
									),
									array(
										'name'     => 'Complex',
										'children' => array(
											array( 'name' => 'quantity', 'description' => 'Groesse: value + optional prefix + base unit (not a measurement act).' ),
											array( 'name' => 'subtree', 'description' => 'Pick under a catalog root via Relation ref_scope.' ),
											array(
												'name'        => 'Collection',
												'description' => 'Super-kind list/table/enum. Parameter Projektname (type text) lives on this Node - not a tree child (Q61/Q64).',
												'children'    => array(
													array(
														'name'        => 'list',
														'description' => 'Collection with exactly one column; rows open.',
														'children'    => array(
															array(
																'name'        => 'RefDes',
																'description' => 'BOM open list for board references (R1, R2, ...).',
																'children'    => array(
																	array( 'name' => 'Element', 'description' => 'Single list column - has_type text (proto).' ),
																),
															),
														),
													),
													array(
														'name'        => 'table',
														'description' => 'Collection with n columns; rows open. BOM has_type -> table.',
													),
													array(
														'name'        => 'enum',
														'description' => 'Like list + closed options under the column.',
														'children'    => array(
															array(
																'name'        => 'Bauart',
																'description' => 'Concrete enum for footprints (BOM Testprojekt, not pure Template).',
																'children'    => array(
																	array(
																		'name'     => 'Option',
																		'description' => 'Single enum column - has_type text (proto).',
																		'children' => array(
																			array( 'name' => '0201' ),
																			array( 'name' => '0402' ),
																			array( 'name' => '0603' ),
																			array( 'name' => '0805' ),
																			array( 'name' => 'axial' ),
																		),
																	),
																),
															),
														),
													),
												),
											),
										),
									),
								),
							),
							array(
								'name'        => 'Praefixe',
								'description' => 'SI prefixes; multiplikator -> int on edge (Q51).',
								'children'    => array(
									array( 'name' => 'p', 'description' => 'Pico 1e-12' ),
									array( 'name' => 'n', 'description' => 'Nano 1e-9' ),
									array( 'name' => 'u', 'description' => 'Micro 1e-6 (proto: µ)' ),
									array( 'name' => 'm', 'description' => 'Milli 1e-3' ),
									array( 'name' => 'c', 'description' => 'Centi 1e-2' ),
									array( 'name' => 'k', 'description' => 'Kilo 1e3' ),
									array( 'name' => 'Mega', 'description' => 'Mega 1e6 (proto node name M; WP slug cannot collide with milli m)' ),
								),
							),
							array(
								'name'        => 'Basiseinheit',
								'description' => 'SI units in Template + Ohm/Farad/Watt/Volt in BOM Testprojekt.',
								'children'    => array(
									array( 'name' => 'Meter' ),
									array( 'name' => 'Liter' ),
									array( 'name' => 'Kilogramm' ),
									array( 'name' => 'Sekunde' ),
									array( 'name' => 'Kelvin' ),
									array( 'name' => 'Ampere' ),
									array( 'name' => 'Ohm', 'description' => 'Electronics (BOM); allows_prefix m/k/M/u/n/p.' ),
									array( 'name' => 'Farad', 'description' => 'Electronics (BOM); allows_prefix p/n/u/m.' ),
									array( 'name' => 'Watt', 'description' => 'Electronics (BOM).' ),
									array( 'name' => 'Volt', 'description' => 'Electronics (BOM).' ),
								),
							),
						),
					),
					array(
						'name'        => 'Compositionen',
						'description' => 'Zusammenstellungen (Composition definitions). BOM columns are Parameters (Q64), not child Nodes.',
						'children'    => array(
							array(
								'name'        => 'Rezept - Backzutaten',
								'description' => 'Legacy simples Composition demo (columns as child Nodes in proto Phase 1).',
								'children'    => array(
									array( 'name' => 'Bezeichnung', 'description' => 'Column -> text' ),
									array( 'name' => 'Anzahl', 'description' => 'Column -> int' ),
									array( 'name' => 'Aktiv', 'description' => 'Column -> bool' ),
									array( 'name' => 'Code', 'description' => 'Column -> char' ),
									array( 'name' => 'Faktor', 'description' => 'Column -> double' ),
								),
							),
							array(
								'name'        => 'BOM',
								'description' => 'Structure node BOM (has_type table). Parameters: Bauteil Wahl (subtree->Bauteile), Reference (RefDes), Wert (quantity), Footprint (Bauart), Menge (int Stuck), Beschreibung (textarea). Instance Projektname e.g. Platine XY on WP page.',
							),
						),
					),
					array(
						'name'        => 'Bauteile',
						'description' => 'Catalog (not Composition). BOM Bauteil Wahl uses subtree + ref_scope -> this root.',
						'children'    => array(
							array(
								'name'        => 'Widerstand',
								'description' => 'Part group: Wert(double) + Praefix + Einheit Ohm (proto Parameters - scaffold children).',
								'children'    => array(
									array( 'name' => 'Wert', 'description' => 'Parameter name; type double (proto).' ),
									array( 'name' => 'Praefix', 'description' => 'Parameter name; type Praefixe root (proto).' ),
									array( 'name' => 'Einheit', 'description' => 'Parameter name; type Ohm fixed (proto).' ),
								),
							),
							array(
								'name'        => 'Kondensator',
								'description' => 'Part group: Wert(double) + Praefix + Einheit Farad.',
								'children'    => array(
									array( 'name' => 'Wert', 'description' => 'Parameter name; type double (proto).' ),
									array( 'name' => 'Praefix', 'description' => 'Parameter name; type Praefixe root (proto).' ),
									array( 'name' => 'Einheit', 'description' => 'Parameter name; type Farad fixed (proto).' ),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Ensure demo terms exist under a hierarchical taxonomy.
	 *
	 * @return array{created:int,existing:int,taxonomy:string}|\WP_Error
	 */
	public static function install( string $taxonomy = 'category' ) {
		if ( ! Tree_Model::is_hierarchical_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Not a hierarchical taxonomy.', 'wp-taxonomy-tree' ) );
		}

		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			return new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) );
		}

		$created  = 0;
		$existing = 0;
		self::install_nodes( $taxonomy, self::blueprint(), 0, $created, $existing );
		Node_Meta::seed_demo_bindings( $taxonomy );

		return array(
			'created'  => $created,
			'existing' => $existing,
			'taxonomy' => $taxonomy,
		);
	}

	/**
	 * Delete known demo roots (and descendants), then reinstall the blueprint.
	 *
	 * @return array{deleted:int,created:int,existing:int,taxonomy:string}|\WP_Error
	 */
	public static function reset( string $taxonomy = 'category' ) {
		if ( ! Tree_Model::is_hierarchical_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Not a hierarchical taxonomy.', 'wp-taxonomy-tree' ) );
		}

		$can_edit   = ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( Capabilities::edit_terms( $taxonomy ) );
		$can_delete = ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( Capabilities::delete_terms( $taxonomy ) );
		if ( ! $can_edit || ! $can_delete ) {
			return new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) );
		}

		$deleted = 0;
		$roots   = array_merge( array( self::ROOT_NAME ), self::LEGACY_ROOT_NAMES );
		foreach ( $roots as $root_name ) {
			$removed = self::delete_root_by_name( $taxonomy, $root_name );
			if ( is_wp_error( $removed ) ) {
				return $removed;
			}
			$deleted += $removed;
		}

		$install = self::install( $taxonomy );
		if ( is_wp_error( $install ) ) {
			return $install;
		}

		return array(
			'deleted'  => $deleted,
			'created'  => $install['created'],
			'existing' => $install['existing'],
			'taxonomy' => $taxonomy,
		);
	}

	/**
	 * @return int|\WP_Error Number of terms deleted.
	 */
	private static function delete_root_by_name( string $taxonomy, string $name ) {
		$found = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => $name,
				'parent'     => 0,
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $found ) || empty( $found ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $found as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$count = self::delete_term_cascade( $taxonomy, (int) $term->term_id );
			if ( is_wp_error( $count ) ) {
				return $count;
			}
			$deleted += $count;
		}

		return $deleted;
	}

	/**
	 * @return int|\WP_Error
	 */
	private static function delete_term_cascade( string $taxonomy, int $term_id ) {
		$deleted  = 0;
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
				$nested = self::delete_term_cascade( $taxonomy, (int) $child->term_id );
				if ( is_wp_error( $nested ) ) {
					return $nested;
				}
				$deleted += $nested;
			}
		}

		$result = wp_delete_term( $term_id, $taxonomy );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result || 0 === $result ) {
			return new \WP_Error( 'wtt_delete_failed', __( 'Could not delete demo term.', 'wp-taxonomy-tree' ) );
		}

		return $deleted + 1;
	}

	/**
	 * @param array<int, array<string, mixed>> $nodes Nodes to ensure.
	 */
	private static function install_nodes( string $taxonomy, array $nodes, int $parent_id, int &$created, int &$existing ): void {
		foreach ( $nodes as $node ) {
			$name = isset( $node['name'] ) ? (string) $node['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			$description = isset( $node['description'] ) ? (string) $node['description'] : '';
			$term_id     = self::ensure_term( $taxonomy, $name, $parent_id, $description, $created, $existing );
			if ( $term_id <= 0 ) {
				continue;
			}

			$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
			if ( ! empty( $children ) ) {
				self::install_nodes( $taxonomy, $children, $term_id, $created, $existing );
			}
		}
	}

	private static function ensure_term(
		string $taxonomy,
		string $name,
		int $parent_id,
		string $description,
		int &$created,
		int &$existing
	): int {
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
			++$existing;
			$term_id = (int) $found[0]->term_id;
			if ( '' !== $description && $found[0]->description !== $description ) {
				wp_update_term(
					$term_id,
					$taxonomy,
					array( 'description' => $description )
				);
			}
			return $term_id;
		}

		$result = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'parent'      => max( 0, $parent_id ),
				'description' => $description,
			)
		);

		if ( is_wp_error( $result ) ) {
			if ( 'term_exists' === $result->get_error_code() ) {
				$term_id = (int) $result->get_error_data();
				if ( $term_id > 0 ) {
					++$existing;
					return $term_id;
				}
			}
			return 0;
		}

		++$created;
		return (int) $result['term_id'];
	}
}
