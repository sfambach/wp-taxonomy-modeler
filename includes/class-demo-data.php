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
											array(
												'name'        => 'display_node_name',
												'description' => 'Read-only: shows the host Node.name (no user input).',
											),
											array( 'name' => 'node_ref', 'description' => 'Free jump to any Node (no ref_scope).' ),
										),
									),
									array(
										'name'     => 'Complex',
										'children' => array(
											array( 'name' => 'quantity', 'description' => 'Groesse: value + optional prefix + base unit (not a measurement act).' ),
											array( 'name' => 'subtree', 'description' => 'Pick under a catalog root via Relation ref_scope.' ),
											self::abmessung_type_node(),
											array(
												'name'        => 'Collection',
												'description' => 'Super-kind list/table/enum/set. Parameter Projektname (type text) lives on Collection instances - not a tree child (Q61/Q64).',
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
														'description' => 'Collection with n columns; rows open. Extra settings: has footer (Fusszeile). BOM/Rezept -> table.',
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
																			array( 'name' => '1206' ),
																			array( 'name' => 'axial' ),
																		),
																	),
																),
															),
														),
													),
													array(
														'name'        => 'set',
														'description' => 'Collection of named members; schema = child nodes (each typed). Used by Bauteil groups.',
													),
												),
											),
										),
									),
								),
							),
							array(
								'name'        => 'Praefixe',
								'description' => 'SI prefixes. multiplikator = scale vs the unit’s prefix root (Q51). Same factors for Meter and mass — mass differs via unit prefix_root_to_si (g→kg).',
								'children'    => array(
									array( 'name' => 'p', 'short_description' => 'Pico', 'description' => 'SI prefix pico (10⁻¹²).', 'multiplikator' => 1.0e-12 ),
									array( 'name' => 'n', 'short_description' => 'Nano', 'description' => 'SI prefix nano (10⁻⁹).', 'multiplikator' => 1.0e-9 ),
									array( 'name' => 'u', 'short_description' => 'Micro', 'description' => 'SI prefix micro (10⁻⁶); proto: µ.', 'multiplikator' => 1.0e-6 ),
									array( 'name' => 'm', 'short_description' => 'Milli', 'description' => 'SI prefix milli (10⁻³); with Meter symbol → mm (Millimeter).', 'multiplikator' => 1.0e-3 ),
									array( 'name' => 'c', 'short_description' => 'Centi', 'description' => 'SI prefix centi (10⁻²).', 'multiplikator' => 1.0e-2 ),
									array( 'name' => 'k', 'short_description' => 'Kilo', 'description' => 'SI prefix kilo (10³).', 'multiplikator' => 1.0e3 ),
									array( 'name' => 'Mega', 'short_description' => 'Mega', 'description' => 'SI prefix mega (10⁶); node name Mega avoids slug clash with milli m.', 'multiplikator' => 1.0e6 ),
								),
							),
							array(
								'name'        => 'Basiseinheit',
								'description' => 'Each unit is a set: Typ (numeric type) + optional Praefix + Kuerzel. Display = Praefix+Kuerzel. to_si = Typ × multiplikator × prefix_root_to_si. Mass: SI base = kg, prefix root = g.',
								'children'    => array(
									self::basiseinheit_unit_node( 'Meter', 'm', 'double', array( 'u', 'm', 'c', 'k' ), 'Length; m+m → mm.' ),
									self::basiseinheit_unit_node( 'Liter', 'l', 'double', array( 'm', 'c', 'k' ), 'Volume.' ),
									self::basiseinheit_unit_node(
										'Kilogramm',
										'g',
										'double',
										array( 'm', 'k', 'Mega' ),
										'SI base unit is kilogram; prefixes attach to gram (mg/kg/Mg). Kuerzel=g; prefix_root_to_si=1e-3.',
										1.0e-3
									),
									self::basiseinheit_unit_node( 'Sekunde', 's', 'double', array( 'p', 'n', 'u', 'm' ), 'Time.' ),
									self::basiseinheit_unit_node( 'Kelvin', 'K', 'double', array(), 'Thermodynamic temperature; no prefixes.' ),
									self::basiseinheit_unit_node( 'Celsius', '°C', 'double', array(), 'Celsius temperature; no SI prefixes.' ),
									self::basiseinheit_unit_node( 'Ampere', 'A', 'double', array( 'p', 'n', 'u', 'm', 'k', 'Mega' ), 'Electric current.' ),
									self::basiseinheit_unit_node( 'Ohm', 'Ω', 'double', array( 'p', 'n', 'u', 'm', 'k', 'Mega' ), 'Resistance; k+Ω → kΩ.' ),
									self::basiseinheit_unit_node( 'Farad', 'F', 'double', array( 'p', 'n', 'u', 'm' ), 'Capacitance; no k/Mega.' ),
									self::basiseinheit_unit_node( 'Watt', 'W', 'double', array( 'm', 'k', 'Mega', 'u' ), 'Power.' ),
									self::basiseinheit_unit_node( 'Volt', 'V', 'double', array( 'm', 'k', 'Mega', 'u' ), 'Voltage.' ),
									self::basiseinheit_unit_node( 'Stück', 'Stk', 'int', array(), 'Count / Menge (BOM); no prefixes.' ),
								),
							),
							array(
								'name'        => 'Bauformen',
								'description' => 'Package / footprint catalog (Typen branch). SMD sizes carry Abmessung L/B/H in mm.',
								'children'    => array(
									array(
										'name'        => 'Durchloch Axial',
										'type_name'   => 'display_node_name',
										'description' => 'Zum Beispiel Widerstaende, Anschluesse rechts und links vom Koerper. Auch alte Kondensatoren haben oft dieses Format.',
									),
									array(
										'name'        => 'Durchloch Radial',
										'type_name'   => 'display_node_name',
										'description' => 'Zum Beispiel bei Kondensatoren beide Anschlussbeine auf einer Seite.',
									),
									self::smd_package_node( 'SMD 0201', 'Imperial 0201 (metric 0603).', 0.6, 0.3, 0.23 ),
									self::smd_package_node( 'SMD 0402', 'Kleinste Bauform 0402.', 1.0, 0.5, 0.35 ),
									self::smd_package_node( 'SMD 0603', 'Imperial 0603 (metric 1608).', 1.6, 0.8, 0.45 ),
									self::smd_package_node( 'SMD 0805', 'Imperial 0805 (metric 2012).', 2.0, 1.25, 0.5 ),
									self::smd_package_node( 'SMD 1206', 'Imperial 1206 (metric 3216).', 3.2, 1.6, 0.55 ),
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
								'type_name'   => 'table',
								'has_footer'  => true,
								'description' => 'Composition table (type table) with Fusszeile. Columns as child Nodes in scaffold.',
								'children'    => array(
									array( 'name' => 'Bezeichnung', 'type_name' => 'text', 'required' => true, 'description' => 'Column -> text' ),
									array( 'name' => 'Anzahl', 'type_name' => 'int', 'required' => true, 'description' => 'Column -> int' ),
									array( 'name' => 'Aktiv', 'type_name' => 'bool', 'required' => false, 'description' => 'Column -> bool' ),
									array( 'name' => 'Code', 'type_name' => 'char', 'required' => false, 'description' => 'Column -> char' ),
									array( 'name' => 'Faktor', 'type_name' => 'double', 'required' => false, 'description' => 'Column -> double' ),
								),
							),
							array(
								'name'        => 'BOM',
								'type_name'   => 'table',
								'has_footer'  => true,
								'description' => 'Structure node BOM (type table, has Fusszeile). Parameters later: Bauteil Wahl, Reference, Wert, Footprint, Menge, Beschreibung. Instance Projektname on WP page.',
							),
						),
					),
					array(
						'name'        => 'Bauteile',
						'description' => 'Catalog (not Composition). BOM Bauteil Wahl uses subtree + ref_scope -> this root.',
						'children'    => array(
							array(
								'name'        => 'Widerstand',
								'type_name'   => 'set',
								'description' => 'Part group (type set): Wert + Praefix + Einheit Ohm as child nodes define the set.',
								'children'    => array(
									array(
										'name'        => 'Wert',
										'type_name'   => 'double',
										'required'    => true,
										'description' => 'Set member: numeric value (double). Required.',
									),
									array(
										'name'        => 'Praefix',
										'type_name'   => 'Praefixe',
										'required'    => false,
										'description' => 'Set member: SI prefix catalog (Praefixe root). Optional.',
									),
									array(
										'name'             => 'Einheit',
										'type_name'        => 'Basiseinheit',
										'fixed_node_name'  => 'Ohm',
										'required'         => true,
										'description'      => 'Set member: type Basiseinheit, fixed value Ohm.',
									),
								),
							),
							array(
								'name'        => 'Kondensator',
								'type_name'   => 'set',
								'description' => 'Part group (type set): Wert + Praefix + Einheit Farad as child nodes define the set.',
								'children'    => array(
									array(
										'name'        => 'Wert',
										'type_name'   => 'double',
										'required'    => true,
										'description' => 'Set member: numeric value (double). Required.',
									),
									array(
										'name'                  => 'Praefix',
										'type_name'             => 'Praefixe',
										'required'              => false,
										'disabled_branch_names' => array(),
										'description'           => 'SI prefixes filtered by Einheit Farad allowlist (Q51).',
									),
									array(
										'name'             => 'Einheit',
										'type_name'        => 'Basiseinheit',
										'fixed_node_name'  => 'Farad',
										'required'         => true,
										'description'      => 'Set member: type Basiseinheit, fixed value Farad.',
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Basiseinheit catalog unit as set: Typ + optional Praefix + Kuerzel (fixed symbol).
	 *
	 * @param array<int, string> $allowed_prefix_names Empty = L1 no prefixes (omit Praefix child).
	 * @param float              $prefix_root_to_si    Factor prefix-root → SI base (Kilogramm: 1e-3).
	 * @return array<string, mixed>
	 */
	private static function basiseinheit_unit_node(
		string $name,
		string $symbol,
		string $numeric_type,
		array $allowed_prefix_names,
		string $description = '',
		float $prefix_root_to_si = 1.0
	): array {
		$has_prefix = ! empty( $allowed_prefix_names );
		$children   = array(
			array(
				'name'        => 'Typ',
				'type_name'   => $numeric_type,
				'required'    => true,
				'description' => 'Numeric magnitude field; data type = ' . $numeric_type . '.',
			),
		);

		if ( $has_prefix ) {
			$children[] = array(
				'name'        => 'Praefix',
				'type_name'   => 'Praefixe',
				'required'    => false,
				'short_description' => 'SI-Präfix',
				'description' => 'Optional SI prefix from Praefixe (e.g. catalog “m” = Milli). Not fixed in the unit schema. Display: Praefix + Kuerzel → mm.',
			);
		}

		$children[] = array(
			'name'          => 'Kuerzel',
			'type_name'     => 'text',
			'required'      => true,
			'fixed_literal' => $symbol,
			'short_description' => 'Einheitensymbol',
			'description'   => 'Fixed unit symbol / prefix-root (e.g. Meter → m). Not the Praefix catalog node “m” (Milli). With Praefix → mm / kΩ / mg.',
		);

		$node = array(
			'name'                 => $name,
			'type_name'            => 'set',
			'allowed_prefix_names' => $allowed_prefix_names,
			'description'          => '' !== $description
				? $description
				: ( 'Unit set: Typ + ' . ( $has_prefix ? 'Praefix + ' : '' ) . 'Kuerzel=' . $symbol ),
			'children'             => $children,
		);

		if ( 1.0 !== $prefix_root_to_si ) {
			$node['prefix_root_to_si'] = $prefix_root_to_si;
		}

		return $node;
	}

	/**
	 * Complex type: Abmessung = set of L/B/H, each typed as Basiseinheit unit Meter
	 * (quantity trinity Typ + Praefix + Kuerzel). In tables the set is ONE column.
	 *
	 * @return array<string, mixed>
	 */
	private static function abmessung_type_node(): array {
		return array(
			'name'        => 'Abmessung',
			'type_name'   => 'set',
			'description' => 'Package dimensions L/B/H. Each edge is a Meter quantity (Typ + Praefix + m). Table: one Abmessung column containing L/B/H.',
			'children'    => self::abmessung_member_nodes( null, null, null ),
		);
	}

	/**
	 * L/B/H typed as Meter. Pass floats to also fix magnitude literals (SMD instances).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function abmessung_member_nodes( ?float $l, ?float $b, ?float $h ): array {
		$l_node = array(
			'name'        => 'L',
			'type_name'   => 'Meter',
			'required'    => true,
			'short_description' => 'Länge',
			'description' => 'Length as Meter quantity (Typ + Praefix + m).',
		);
		$b_node = array(
			'name'        => 'B',
			'type_name'   => 'Meter',
			'required'    => true,
			'short_description' => 'Breite',
			'description' => 'Width (Breite) as Meter quantity.',
		);
		$h_node = array(
			'name'        => 'H',
			'type_name'   => 'Meter',
			'required'    => true,
			'short_description' => 'Höhe',
			'description' => 'Height (Hoehe) as Meter quantity.',
		);
		if ( null !== $l ) {
			$l_node['fixed_literal'] = self::format_mm( $l );
			$l_node['description']   = 'Length magnitude (mm via milli + Meter).';
		}
		if ( null !== $b ) {
			$b_node['fixed_literal'] = self::format_mm( $b );
			$b_node['description']   = 'Width magnitude (mm via milli + Meter).';
		}
		if ( null !== $h ) {
			$h_node['fixed_literal'] = self::format_mm( $h );
			$h_node['description']   = 'Height magnitude (mm via milli + Meter).';
		}

		return array( $l_node, $b_node, $h_node );
	}

	/**
	 * SMD package catalog entry with Abmessung instance (L/B/H as Meter quantities).
	 *
	 * @return array<string, mixed>
	 */
	private static function smd_package_node( string $name, string $description, float $l, float $b, float $h ): array {
		return array(
			'name'        => $name,
			'type_name'   => 'display_node_name',
			'description' => $description,
			'children'    => array(
				array(
					'name'        => 'Abmessung',
					'type_name'   => 'Abmessung',
					'description' => 'Fixed package body size: L/B/H as Meter quantities (mm via milli).',
					'children'    => self::abmessung_member_nodes( $l, $b, $h ),
				),
			),
		);
	}

	private static function format_mm( float $value ): string {
		$formatted = rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' );
		return '' !== $formatted ? $formatted : '0';
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
		$blueprint = self::blueprint();
		// First pass creates the tree; second pass reapplies types/fixed values that
		// reference later siblings (e.g. Abmessung → Basiseinheit Meter, Praefix m).
		self::install_nodes( $taxonomy, $blueprint, 0, $created, $existing );
		$re_created  = 0;
		$re_existing = 0;
		self::install_nodes( $taxonomy, $blueprint, 0, $re_created, $re_existing );
		self::migrate_basiseinheit_wert_to_typ( $taxonomy );
		self::migrate_abmessung_t_to_h( $taxonomy );
		self::ensure_prefix_multiplikators( $taxonomy );
		self::ensure_short_descriptions( $taxonomy );

		return array(
			'created'  => $created,
			'existing' => $existing,
			'taxonomy' => $taxonomy,
		);
	}

	/**
	 * Basiseinheit units use member "Typ" (not legacy "Wert"). Rename in place when needed.
	 *
	 * @return int Number of terms renamed or removed.
	 */
	public static function migrate_basiseinheit_wert_to_typ( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$typen = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => 'Typen',
				'hide_empty' => false,
				'number'     => 1,
			)
		);
		if ( ! is_array( $typen ) || empty( $typen ) || ! ( $typen[0] instanceof \WP_Term ) ) {
			return 0;
		}

		$base_root = 0;
		$base_kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => (int) $typen[0]->term_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_array( $base_kids ) ) {
			foreach ( $base_kids as $kid ) {
				if ( $kid instanceof \WP_Term && 'Basiseinheit' === $kid->name ) {
					$base_root = (int) $kid->term_id;
					break;
				}
			}
		}
		if ( $base_root <= 0 ) {
			return 0;
		}

		$units = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $base_root,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $units ) ) {
			return 0;
		}

		$changed = 0;
		foreach ( $units as $unit ) {
			if ( ! $unit instanceof \WP_Term ) {
				continue;
			}
			if ( ! Node_Type::is_basiseinheit_unit_node( $taxonomy, (int) $unit->term_id ) ) {
				continue;
			}

			$members = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => (int) $unit->term_id,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			if ( ! is_array( $members ) ) {
				continue;
			}

			$wert_id = 0;
			$typ_id  = 0;
			foreach ( $members as $member ) {
				if ( ! $member instanceof \WP_Term ) {
					continue;
				}
				if ( 'Wert' === $member->name ) {
					$wert_id = (int) $member->term_id;
				}
				if ( 'Typ' === $member->name ) {
					$typ_id = (int) $member->term_id;
				}
			}

			if ( $wert_id <= 0 ) {
				continue;
			}

			if ( $typ_id > 0 ) {
				$result = wp_delete_term( $wert_id, $taxonomy );
				if ( ! is_wp_error( $result ) ) {
					++$changed;
				}
				continue;
			}

			$result = wp_update_term(
				$wert_id,
				$taxonomy,
				array(
					'name' => 'Typ',
				)
			);
			if ( ! is_wp_error( $result ) ) {
				++$changed;
			}
		}

		return $changed;
	}

	/**
	 * Abmessung edges: rename legacy "T" → "H" (height) under every Abmessung parent.
	 *
	 * @return int Number of terms renamed or removed.
	 */
	public static function migrate_abmessung_t_to_h( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$parents = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => 'Abmessung',
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $parents ) ) {
			return 0;
		}

		$changed = 0;
		foreach ( $parents as $parent ) {
			if ( ! $parent instanceof \WP_Term ) {
				continue;
			}
			$children = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => (int) $parent->term_id,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			if ( ! is_array( $children ) ) {
				continue;
			}

			$t_id = 0;
			$h_id = 0;
			foreach ( $children as $child ) {
				if ( ! $child instanceof \WP_Term ) {
					continue;
				}
				if ( 'T' === $child->name ) {
					$t_id = (int) $child->term_id;
				}
				if ( 'H' === $child->name ) {
					$h_id = (int) $child->term_id;
				}
			}

			if ( $t_id <= 0 ) {
				continue;
			}

			if ( $h_id > 0 ) {
				$result = wp_delete_term( $t_id, $taxonomy );
				if ( ! is_wp_error( $result ) ) {
					++$changed;
				}
				continue;
			}

			$result = wp_update_term(
				$t_id,
				$taxonomy,
				array(
					'name' => 'H',
				)
			);
			if ( ! is_wp_error( $result ) ) {
				++$changed;
			}
		}

		return $changed;
	}

	/**
	 * Ensure SI prefix catalog nodes have multiplikator meta (idempotent).
	 */
	public static function ensure_prefix_multiplikators( string $taxonomy ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$factors = array(
			'p'    => 1.0e-12,
			'n'    => 1.0e-9,
			'u'    => 1.0e-6,
			'm'    => 1.0e-3,
			'c'    => 1.0e-2,
			'k'    => 1.0e3,
			'Mega' => 1.0e6,
		);

		$roots = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => 'Praefixe',
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $roots ) ) {
			return;
		}

		foreach ( $roots as $root ) {
			if ( ! $root instanceof \WP_Term ) {
				continue;
			}
			$children = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => (int) $root->term_id,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			if ( ! is_array( $children ) ) {
				continue;
			}
			foreach ( $children as $child ) {
				if ( ! $child instanceof \WP_Term ) {
					continue;
				}
				if ( ! array_key_exists( $child->name, $factors ) ) {
					continue;
				}
				$current = Node_Type::get_multiplikator( (int) $child->term_id );
				if ( null !== $current && $current > 0.0 ) {
					continue;
				}
				Node_Type::set_multiplikator( (int) $child->term_id, $factors[ $child->name ] );
			}
		}
	}

	/**
	 * Fill short_description for known demo nodes when empty (idempotent).
	 *
	 * @return int Number of terms updated.
	 */
	public static function ensure_short_descriptions( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$map = array(
			'L'    => 'Länge',
			'B'    => 'Breite',
			'H'    => 'Höhe',
			'p'    => 'Pico',
			'n'    => 'Nano',
			'u'    => 'Micro',
			'm'    => 'Milli',
			'c'    => 'Centi',
			'k'    => 'Kilo',
			'Mega' => 'Mega',
		);

		$updated = 0;
		foreach ( $map as $name => $short ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => $name,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$term_id = (int) $term->term_id;
				if ( '' !== Tree_Model::get_short_description( $term_id ) ) {
					continue;
				}
				// Only Abmessung edges L/B/H and Praefixe catalog — avoid unrelated same-named terms.
				$parent = $term->parent ? get_term( (int) $term->parent, $taxonomy ) : null;
				$parent_name = $parent instanceof \WP_Term ? $parent->name : '';
				if ( in_array( $name, array( 'L', 'B', 'H' ), true ) && 'Abmessung' !== $parent_name ) {
					continue;
				}
				if ( in_array( $name, array( 'p', 'n', 'u', 'm', 'c', 'k', 'Mega' ), true ) && 'Praefixe' !== $parent_name ) {
					continue;
				}
				Tree_Model::set_short_description( $taxonomy, $term_id, $short );
				++$updated;
			}
		}

		return $updated;
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
		foreach ( $nodes as $index => $node ) {
			$name = isset( $node['name'] ) ? (string) $node['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			$description = isset( $node['description'] ) ? (string) $node['description'] : '';
			$type_name   = isset( $node['type_name'] ) ? (string) $node['type_name'] : '';
			$term_id     = self::ensure_term( $taxonomy, $name, $parent_id, $description, $created, $existing );
			if ( $term_id <= 0 ) {
				continue;
			}

			Tree_Model::set_position( $term_id, (int) $index );

			if ( array_key_exists( 'short_description', $node ) ) {
				Tree_Model::set_short_description( $taxonomy, $term_id, (string) $node['short_description'] );
			}

			if ( '' !== $type_name ) {
				$type_id = Node_Type::find_type_by_name( $taxonomy, $term_id, $type_name );
				if ( $type_id > 0 ) {
					Node_Type::set_type_id( $taxonomy, $term_id, $type_id );
				}
			}

			if ( array_key_exists( 'required', $node ) ) {
				Node_Type::set_required( $taxonomy, $term_id, (bool) $node['required'] );
			}

			if ( array_key_exists( 'has_footer', $node ) ) {
				Node_Type::set_has_footer( $taxonomy, $term_id, (bool) $node['has_footer'] );
			}

			if ( array_key_exists( 'fixed_literal', $node ) ) {
				$literal = (string) $node['fixed_literal'];
				Node_Type::set_fixed_value( $taxonomy, $term_id, true, $literal, 0 );
			}

			$fixed_name = isset( $node['fixed_node_name'] ) ? (string) $node['fixed_node_name'] : '';
			if ( '' !== $fixed_name ) {
				$fixed_id = Node_Type::find_fixed_by_name( $taxonomy, $term_id, $fixed_name );
				if ( $fixed_id <= 0 ) {
					// Fallback: catalog roots / assignable types (e.g. Basiseinheit → Meter).
					$fixed_id = Node_Type::find_type_by_name( $taxonomy, $term_id, $fixed_name );
				}
				if ( $fixed_id > 0 ) {
					Node_Type::set_fixed_value( $taxonomy, $term_id, true, '', $fixed_id );
				}
			}

			if ( array_key_exists( 'disabled_branch_names', $node ) && is_array( $node['disabled_branch_names'] ) ) {
				$type_id = Node_Type::get_type_id( $term_id );
				if ( $type_id > 0 ) {
					$disabled_ids = array();
					foreach ( $node['disabled_branch_names'] as $disabled_name ) {
						$disabled_id = Node_Type::find_direct_child_by_name(
							$taxonomy,
							$type_id,
							(string) $disabled_name
						);
						if ( $disabled_id > 0 ) {
							$disabled_ids[] = $disabled_id;
						}
					}
					Node_Type::set_disabled_branch_ids( $taxonomy, $term_id, $disabled_ids );
				}
			}

			if ( array_key_exists( 'allowed_prefix_names', $node ) && is_array( $node['allowed_prefix_names'] ) ) {
				$allowed_ids = array();
				foreach ( $node['allowed_prefix_names'] as $prefix_name ) {
					$prefix_id = Node_Type::find_fixed_by_name( $taxonomy, $term_id, (string) $prefix_name );
					if ( $prefix_id > 0 ) {
						$allowed_ids[] = $prefix_id;
					}
				}
				if ( Node_Type::is_basiseinheit_unit_node( $taxonomy, $term_id ) ) {
					Node_Type::set_allowed_prefix_ids( $taxonomy, $term_id, $allowed_ids );
				}
			}

			if ( array_key_exists( 'multiplikator', $node ) && is_numeric( $node['multiplikator'] ) ) {
				Node_Type::set_multiplikator( $term_id, (float) $node['multiplikator'] );
			}

			if ( array_key_exists( 'prefix_root_to_si', $node ) && is_numeric( $node['prefix_root_to_si'] ) ) {
				Node_Type::set_prefix_root_to_si( $term_id, (float) $node['prefix_root_to_si'] );
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
