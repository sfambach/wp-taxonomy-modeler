<?php
/**
 * Demo / test taxonomy tree seeder.
 *
 * Mirrors prototypes/tree-split seedTemplateCore + seedBomTestData (v33)
 * and docs/plans/data-structure.md (BOM Testprojekt). Relations / property
 * slots (typed children) are noted in term descriptions until the domain
 * model is implemented.
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
						'description' => 'Type branch (Q26/Q77): is_datatype + is_abstract; children inherit. Selectable types are non-abstract leaves.',
						'is_datatype' => true,
						'is_abstract' => true,
						'children'    => array(
							array(
								'name'        => 'Datentypen',
								'description' => 'Simple (scalars + node_ref) and Complex (quantity, node_embed, Collection).',
								'is_datatype' => true,
								'is_abstract' => true,
								'children'    => array(
									array(
										'name'        => 'Knoten',
										'description' => 'General assignable node type (project roots and ordinary nodes).',
									),
									array(
										'name'     => 'Simple',
										'children' => array(
											array( 'name' => 'int', 'description' => 'Whole number.' ),
											array( 'name' => 'double', 'description' => 'Floating point.' ),
											array( 'name' => 'text', 'description' => 'Single-line text.' ),
											array( 'name' => 'email', 'description' => 'Email address (validated input).' ),
											array( 'name' => 'textarea', 'description' => 'Multi-line text.' ),
											array( 'name' => 'char', 'description' => 'Single character.' ),
											array( 'name' => 'bool', 'description' => 'Boolean.' ),
											array(
												'name'        => 'date',
												'description' => 'Calendar date or date+time (mode on type). Store: Unix timestamp.',
												'date_mode'   => 'date',
											),
											array(
												'name'        => 'display_node_name',
												'description' => 'Read-only: shows the host Node.name (no user input).',
											),
											array(
												'name'               => 'media',
												'description'        => 'WP Media Library and/or URL (Q65). MIME-based display.',
												'media_allow_upload' => true,
												'media_allow_url'    => false,
											),
										),
									),
									array(
										'name'     => 'Complex',
										'children' => array(
											array(
												'name'              => 'quantity',
												'description'       => 'Größe: value + optional prefix + base unit (not a measurement act; not BOM Menge). Alias: measure.',
												'short_description' => 'Größe',
												'aliases'           => array( 'measure', 'Größe', 'Groesse' ),
											),
											array(
												'name'        => 'subnode',
												'description' => 'Direct child (Unterknoten) of the host node. Type catalog entry for table props (Kopf/Zeile/Fuss): binding must be a direct child.',
											),
											array(
												'name'        => 'node_pick',
												'description' => 'Shared parent (Q73): ref_scope + allowed catalog children. Assign leaf types node_embed / node_ref to slots.',
												'children'    => array(
													array( 'name' => 'node_embed', 'description' => 'Pick under catalog root (ref_scope); options = allowed direct children (default all); embed that node’s fields after pick (ex-subtree).' ),
													array( 'name' => 'node_ref', 'description' => 'Pick under catalog root (ref_scope); options = allowed children + descendants (default all descendants); stores id only.' ),
												),
											),
											self::abmessung_type_node(),
											array(
												'name'        => 'Collection',
												'description' => 'Super-kind list/table/enum/set. Composition Name (string, slot_scope composition) lives under Compositionen domain folder — not under this type node (Q61/Q70/Q54).',
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
													/* Q85: table datatype paused — re-add under Collection when composition-first tables return. */
													array(
														'name'        => 'enum',
														'description' => 'Like list + closed options under the column.',
														'children'    => array(
															self::bauart_enum_node(),
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
									array( 'name' => 'pico', 'aliases' => array( 'p' ), 'short_description' => 'p', 'description' => 'SI prefix pico (10^-12).', 'multiplikator' => 1.0e-12 ),
									array( 'name' => 'nano', 'aliases' => array( 'n' ), 'short_description' => 'n', 'description' => 'SI prefix nano (10^-9).', 'multiplikator' => 1.0e-9 ),
									array( 'name' => 'Micro', 'aliases' => array( 'u', 'micro' ), 'short_description' => 'u', 'description' => 'SI prefix micro (10^-6).', 'multiplikator' => 1.0e-6 ),
									array( 'name' => 'Milli', 'aliases' => array( 'm', 'milli' ), 'short_description' => 'm', 'description' => 'SI prefix milli (10^-3); with Meter symbol -> mm.', 'multiplikator' => 1.0e-3 ),
									array( 'name' => 'Centi', 'aliases' => array( 'c', 'centi' ), 'short_description' => 'c', 'description' => 'SI prefix centi (10^-2).', 'multiplikator' => 1.0e-2 ),
									array( 'name' => 'Kilo', 'aliases' => array( 'k', 'kilo' ), 'short_description' => 'k', 'description' => 'SI prefix kilo (10^3).', 'multiplikator' => 1.0e3 ),
									array( 'name' => 'Mega', 'short_description' => 'Mega', 'description' => 'SI prefix mega (10^6); name Mega avoids slug clash with milli.', 'multiplikator' => 1.0e6 ),
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
									self::basiseinheit_unit_node( 'Henry', 'H', 'double', array( 'p', 'n', 'u', 'm' ), 'Inductance; DigiKey Inductors / Coils / Chokes.' ),
									self::basiseinheit_unit_node( 'Hertz', 'Hz', 'double', array( 'k', 'Mega' ), 'Frequency; crystals / oscillators.' ),
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
						'description' => 'Zusammenstellungen (Composition definitions). BOM columns are typed property children (Q64 superseded), not a separate Parameter class.',
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
									array( 'name' => 'Datei', 'type_name' => 'media', 'required' => false, 'description' => 'Column -> media (WP library / optional URL).' ),
								),
							),
							array(
								'name'        => 'BOM',
								'type_name'   => 'table',
								'has_footer'  => true,
								'description' => 'Structure node BOM (type table, has Fusszeile). Columns = row-scoped property children (Q70). Instance Name inherited from Compositionen via hierarchy (Q54/Q61).',
								'children'    => array(
									array(
										'name'           => 'Bauteil Wahl',
										'type_name'      => 'node_embed',
										'required'       => true,
										'ref_scope_path' => array( 'BOM Testprojekt', 'Bauteile' ),
										'description'    => 'Part pick: node_embed + ref_scope → Bauteile (children selectable; fields embedded).',
									),
									array(
										'name'        => 'Reference',
										'type_name'   => 'text',
										'required'    => true,
										'description' => 'Board references (e.g. R1,R2).',
									),
									array(
										'name'        => 'Wert',
										'type_name'   => 'text',
										'required'    => false,
										'description' => 'Value / rating display.',
									),
									array(
										'name'        => 'Footprint',
										'type_name'   => 'text',
										'required'    => false,
										'description' => 'Footprint / Bauart (scaffold: text; later enum).',
									),
									array(
										'name'        => 'Menge',
										'type_name'   => 'int',
										'required'    => true,
										'description' => 'Quantity (Stück).',
									),
									array(
										'name'        => 'Beschreibung',
										'type_name'   => 'text',
										'required'    => false,
										'description' => 'Notes.',
									),
								),
							),
						),
					),
					self::bauteilarten_catalog_node(),
					self::bauteile_implementation_node(),
					self::lieferanten_catalog_node(),
					array(
						'name'        => 'Relationstypen',
						'description' => 'RelationType catalog (Q35/Q54/Q74). Seed: child_of, has_type, ref_scope (system/synthetic) + composition (additive).',
						'is_abstract' => true,
						'children'    => array(
							array(
								'name'        => 'child_of',
								'description' => 'Hierarchy Kind von (system). Multiplicity always 1. Not creatable via Add relation — use Reparent.',
							),
							array(
								'name'        => 'has_type',
								'description' => 'Data-type binding. Managed via Relations UI; persists as type_id (not a stored edge).',
							),
							array(
								'name'        => 'ref_scope',
								'description' => 'Catalog root for node_embed / node_ref (system). Derived from ref_scope setting.',
							),
							array(
								'name'        => 'composition',
								'description' => 'Domain composition / besteht aus (Q75 set members later).',
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

	/**
	 * Term meta: catalog example leaf under a Bauteil kind (not a set member).
	 */
	public const META_CATALOG_EXAMPLE = '_wtt_catalog_example';

	/**
	 * Lieferanten catalog: set schema (Url, Suchstring, Bewertung) + distributor records as leaves.
	 * Not an enum — each supplier is a dataset (Q64-style typed record).
	 *
	 * @return array<string, mixed>
	 */
	public static function lieferanten_catalog_node(): array {
		return array(
			'name'            => 'Lieferanten',
			'type_name'       => 'set',
			'type_inheriting' => true,
			'description'     => 'Supplier / vendor catalog (electronics DigiKey, grocery Rewe, …). Each record: Url, optional Suchstring, optional Bewertung. Part/ingredient Lieferant slot = node_ref → this root.',
			'children'        => array(
				self::slot( 'Url', 'text', true, 'Homepage or catalog base URL.' ),
				self::slot( 'Suchstring', 'text', false, 'Optional search hint / query template for the supplier site.' ),
				self::slot( 'Bewertung', 'double', false, 'Optional rating (e.g. 1–5); filled when reviewing suppliers.' ),
			),
		);
	}

	/**
	 * Distributor sample names formerly mis-seeded under datatype `enum`.
	 *
	 * @return list<string>
	 */
	public static function distributor_sample_names(): array {
		return array(
			'DigiKey',
			'Mouser',
			'Conrad',
			'Reichelt',
			'Farnell',
			'RS',
			'TME',
			'Arrow',
			'Avnet',
			'LCSC',
			'Rewe',
		);
	}

	/**
	 * Remove DigiKey/Mouser/… wrongly nested under datatype `enum` (keep Bauart etc.).
	 * Does not create or move them elsewhere.
	 */
	public static function strip_distributor_samples_under_enum( string $taxonomy ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$names = self::distributor_sample_names();
		$enums = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => 'enum',
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $enums ) ) {
			return;
		}
		foreach ( $enums as $enum ) {
			if ( ! $enum instanceof \WP_Term ) {
				continue;
			}
			$kids = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => (int) $enum->term_id,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			if ( ! is_array( $kids ) ) {
				continue;
			}
			foreach ( $kids as $kid ) {
				if ( ! $kid instanceof \WP_Term || ! in_array( $kid->name, $names, true ) ) {
					continue;
				}
				$kid_id = (int) $kid->term_id;
				Node_Type::set_deletable( $kid_id, true );
				Tree_Model::delete_term( $taxonomy, $kid_id, 'cascade' );
			}
		}
	}

	/**
	 * Sample distributor records under Lieferanten — intentionally not auto-seeded.
	 * Users add Lieferanten leaves manually; do not recreate DigiKey/… anywhere.
	 *
	 * @param array<int, string> $lieferanten_path Path ending in Lieferanten.
	 */
	public static function ensure_lieferanten_records( string $taxonomy, array $lieferanten_path = array() ): void {
		unset( $taxonomy, $lieferanten_path );
		/* No sample distributors (DigiKey, Mouser, …). */
	}

	/**
	 * Map Lieferanten slot names → term ids.
	 *
	 * @return array<string, int>
	 */
	private static function lieferanten_slot_ids( string $taxonomy, int $root ): array {
		$out = array();
		foreach ( Node_Type::get_set_members( $taxonomy, $root ) as $member ) {
			$name = (string) ( $member['name'] ?? '' );
			$id   = (int) ( $member['id'] ?? 0 );
			if ( '' !== $name && $id > 0 && ! self::is_catalog_example( $id ) ) {
				$out[ $name ] = $id;
			}
		}
		return $out;
	}

	/**
	 * Wire Bauteil kind slots named Lieferant → node_ref + ref_scope Lieferanten.
	 * Kinds live under Bauteilarten (Q83); path is the kinds parent.
	 *
	 * @param array<int, string> $bauteilarten_path Path to Bauteilarten (kinds root).
	 * @param array<int, string> $lieferanten_path  Path to Lieferanten.
	 */
	public static function ensure_lieferant_slot_ref_scopes(
		string $taxonomy,
		array $bauteilarten_path,
		array $lieferanten_path
	): void {
		$arten = self::find_term_by_path( $taxonomy, $bauteilarten_path );
		$scope = self::find_term_by_path( $taxonomy, $lieferanten_path );
		if ( $arten <= 0 || $scope <= 0 ) {
			return;
		}

		$ref_type = Node_Type::find_type_by_name( $taxonomy, $arten, 'node_ref' );
		$kinds    = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $arten,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $kinds ) ) {
			return;
		}

		foreach ( $kinds as $kind ) {
			if ( ! $kind instanceof \WP_Term ) {
				continue;
			}
			$slots = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => (int) $kind->term_id,
					'name'       => 'Lieferant',
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			if ( ! is_array( $slots ) ) {
				continue;
			}
			foreach ( $slots as $slot ) {
				if ( ! $slot instanceof \WP_Term ) {
					continue;
				}
				$sid = (int) $slot->term_id;
				if ( $ref_type > 0 ) {
					Node_Type::set_type_id( $taxonomy, $sid, $ref_type );
				}
				Node_Type::set_ref_scope_id( $taxonomy, $sid, $scope );
			}
		}
	}

	/**
	 * Install / refresh Lieferanten catalog + wire Bauteilarten.*.Lieferant ref_scope (Q83).
	 *
	 * @param array<int, string> $parent_path         Parent of Lieferanten (project root or Implementation).
	 * @param array<int, string> $bauteilarten_path   Path to Bauteilarten (kinds with Lieferant slots).
	 */
	public static function ensure_lieferanten_catalog(
		string $taxonomy,
		array $parent_path,
		array $bauteilarten_path
	): void {
		$parent = self::find_term_by_path( $taxonomy, $parent_path );
		if ( $parent <= 0 ) {
			return;
		}
		$created  = 0;
		$existing = 0;
		self::install_node_tree(
			$taxonomy,
			array( self::lieferanten_catalog_node() ),
			$parent,
			$created,
			$existing
		);

		$lieferanten_path = array_merge( $parent_path, array( 'Lieferanten' ) );
		self::ensure_type_inheritance( $taxonomy, $lieferanten_path );
		self::ensure_set_composition_members( $taxonomy );
		self::ensure_lieferanten_records( $taxonomy, $lieferanten_path );
		self::strip_distributor_samples_under_enum( $taxonomy );
		self::ensure_lieferant_slot_ref_scopes( $taxonomy, $bauteilarten_path, $lieferanten_path );
	}

	/**
	 * @deprecated Use lieferanten_catalog_node() — Lieferant is a record set, not an enum.
	 *
	 * @return array<string, mixed>
	 */
	public static function lieferant_enum_node(): array {
		return self::lieferanten_catalog_node();
	}

	/**
	 * DigiKey / Mouser / Conrad-style kinds (schema only — Q83). Used under Bauteilarten.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function bauteile_kind_nodes(): array {
		return array(
			self::bauteil_kind(
				'Widerstand',
				'Resistors (DigiKey/Mouser). Primary value in Ohm.',
				array_merge(
					self::quantity_member_slots( 'Ohm', 'Resistance value.' ),
					array(
						self::slot( 'Bauform', 'text', false, 'Package / footprint (e.g. 0603, 0805, axial).' ),
						self::slot( 'Toleranz', 'text', false, 'Tolerance (e.g. 1%, 5%).' ),
						self::slot( 'Nennleistung', 'text', false, 'Power rating (e.g. 0.1 W, 0.125 W).' ),
						self::slot( 'Hersteller', 'text', false, 'Manufacturer / OEM — not the distributor.' ),
						self::slot( 'Lieferant', 'node_ref', false, 'Distributor record (node_ref → Lieferanten: Url, Suchstring, Bewertung).' ),
						self::slot( 'Bestellnummer', 'text', false, 'MPN / order code.' ),
						self::slot( 'Datenblatt', 'media', false, 'Datasheet / image (Q65).' ),
					)
				)
			),
			self::bauteil_kind(
				'Kondensator',
				'Capacitors. Primary value in Farad; voltage rating common filter param.',
				array_merge(
					self::quantity_member_slots( 'Farad', 'Capacitance value.' ),
					array(
						self::slot( 'Nennspannung', 'text', false, 'Voltage rating (e.g. 16 V, 50 V).' ),
						self::slot( 'Dielektrikum', 'text', false, 'Dielectric (X7R, C0G, electrolytic, film, …).' ),
						self::slot( 'Bauform', 'text', false, 'Package (0603, radial, …).' ),
						self::slot( 'Hersteller', 'text', false, 'Manufacturer / OEM — not the distributor.' ),
						self::slot( 'Lieferant', 'node_ref', false, 'Distributor record (node_ref → Lieferanten: Url, Suchstring, Bewertung).' ),
						self::slot( 'Bestellnummer', 'text', false ),
						self::slot( 'Datenblatt', 'media', false ),
					)
				)
			),
			self::bauteil_kind(
				'Spule',
				'Inductors, Coils, Chokes (DigiKey). Primary value in Henry.',
				array_merge(
					self::quantity_member_slots( 'Henry', 'Inductance value.' ),
					array(
						self::slot( 'Nennstrom', 'text', false, 'Rated current (e.g. 200 mA).' ),
						self::slot( 'Bauform', 'text', false, 'Package / shielded vs unshielded.' ),
						self::slot( 'Hersteller', 'text', false, 'Manufacturer / OEM — not the distributor.' ),
						self::slot( 'Lieferant', 'node_ref', false, 'Distributor record (node_ref → Lieferanten: Url, Suchstring, Bewertung).' ),
						self::slot( 'Bestellnummer', 'text', false ),
						self::slot( 'Datenblatt', 'media', false ),
					)
				)
			),
			self::bauteil_kind(
				'Diode',
				'Discrete diodes (rectifier, Schottky, Zener, …).',
				array(
					self::slot( 'Diodentyp', 'text', true, 'e.g. Schottky, Zener, rectifier.' ),
					self::slot( 'U_r', 'text', false, 'Reverse / Zener voltage.' ),
					self::slot( 'I_f', 'text', false, 'Forward current.' ),
					self::slot( 'Bauform', 'text', false, 'e.g. SOD-123, DO-214AC.' ),
					self::slot( 'Hersteller', 'text', false, 'Manufacturer / OEM — not the distributor.' ),
					self::slot( 'Lieferant', 'node_ref', false, 'Distributor record (node_ref → Lieferanten: Url, Suchstring, Bewertung).' ),
					self::slot( 'Bestellnummer', 'text', false ),
					self::slot( 'Datenblatt', 'media', false ),
				)
			),
			self::bauteil_kind(
				'Transistor',
				'Discrete transistors (BJT, MOSFET, …).',
				array(
					self::slot( 'Transistortyp', 'text', true, 'e.g. N-MOSFET, NPN BJT.' ),
					self::slot( 'U_max', 'text', false, 'Vds / Vceo rating.' ),
					self::slot( 'I_max', 'text', false, 'Id / Ic rating.' ),
					self::slot( 'Bauform', 'text', false, 'e.g. SOT-23, TO-220.' ),
					self::slot( 'Hersteller', 'text', false, 'Manufacturer / OEM — not the distributor.' ),
					self::slot( 'Lieferant', 'node_ref', false, 'Distributor record (node_ref → Lieferanten: Url, Suchstring, Bewertung).' ),
					self::slot( 'Bestellnummer', 'text', false ),
					self::slot( 'Datenblatt', 'media', false ),
				)
			),
			self::bauteil_kind(
				'LED',
				'Optoelectronics — LEDs (Conrad/Mouser Opto).',
				array(
					self::slot( 'Farbe', 'text', true, 'e.g. red, green, white, IR.' ),
					self::slot( 'U_f', 'text', false, 'Forward voltage.' ),
					self::slot( 'I_f', 'text', false, 'Forward current.' ),
					self::slot( 'Bauform', 'text', false, 'e.g. 0603, 5 mm THT.' ),
					self::slot( 'Hersteller', 'text', false, 'Manufacturer / OEM — not the distributor.' ),
					self::slot( 'Lieferant', 'node_ref', false, 'Distributor record (node_ref → Lieferanten: Url, Suchstring, Bewertung).' ),
					self::slot( 'Bestellnummer', 'text', false ),
					self::slot( 'Datenblatt', 'media', false ),
				)
			),
			self::bauteil_kind(
				'IC',
				'Integrated circuits / microcontrollers / interface chips.',
				array(
					self::slot( 'Funktion', 'text', true, 'e.g. MCU, USB-UART, LDO, OpAmp.' ),
					self::slot( 'Gehaeuse', 'text', false, 'Package (SOP-8, QFN, …).' ),
					self::slot( 'Versorgung', 'text', false, 'Supply voltage range.' ),
					self::slot( 'Hersteller', 'text', false, 'Manufacturer / OEM — not the distributor.' ),
					self::slot( 'Lieferant', 'node_ref', false, 'Distributor record (node_ref → Lieferanten: Url, Suchstring, Bewertung).' ),
					self::slot( 'Bestellnummer', 'text', false ),
					self::slot( 'Datenblatt', 'media', false ),
				)
			),
			self::bauteil_kind(
				'Relais',
				'Relays (DigiKey Relays category).',
				array(
					self::slot( 'Spulenspannung', 'text', true, 'Coil voltage (e.g. 5 V, 12 V).' ),
					self::slot( 'Kontakt', 'text', false, 'Contact form / rating (e.g. SPDT 2 A).' ),
					self::slot( 'Bauform', 'text', false ),
					self::slot( 'Hersteller', 'text', false, 'Manufacturer / OEM — not the distributor.' ),
					self::slot( 'Lieferant', 'node_ref', false, 'Distributor record (node_ref → Lieferanten: Url, Suchstring, Bewertung).' ),
					self::slot( 'Bestellnummer', 'text', false ),
					self::slot( 'Datenblatt', 'media', false ),
				)
			),
			self::bauteil_kind(
				'Steckverbinder',
				'Connectors / Interconnects (DigiKey Connectors).',
				array(
					self::slot( 'Steckertyp', 'text', true, 'e.g. USB-A, Pin header, JST.' ),
					self::slot( 'Polzahl', 'int', false, 'Number of positions / pins.' ),
					self::slot( 'Bauform', 'text', false, 'Mounting / gender.' ),
					self::slot( 'Hersteller', 'text', false, 'Manufacturer / OEM — not the distributor.' ),
					self::slot( 'Lieferant', 'node_ref', false, 'Distributor record (node_ref → Lieferanten: Url, Suchstring, Bewertung).' ),
					self::slot( 'Bestellnummer', 'text', false ),
					self::slot( 'Datenblatt', 'media', false ),
				)
			),
			self::bauteil_kind(
				'Schalter',
				'Switches (electromechanical).',
				array(
					self::slot( 'Schaltertyp', 'text', true, 'e.g. tactile, slide, toggle.' ),
					self::slot( 'Pole', 'text', false, 'Poles / throws (SPST, SPDT, …).' ),
					self::slot( 'Bauform', 'text', false ),
					self::slot( 'Hersteller', 'text', false, 'Manufacturer / OEM — not the distributor.' ),
					self::slot( 'Lieferant', 'node_ref', false, 'Distributor record (node_ref → Lieferanten: Url, Suchstring, Bewertung).' ),
					self::slot( 'Bestellnummer', 'text', false ),
					self::slot( 'Datenblatt', 'media', false ),
				)
			),
			self::bauteil_kind(
				'Quarz',
				'Crystals / oscillators (frequency control).',
				array_merge(
					self::quantity_member_slots( 'Hertz', 'Nominal frequency.' ),
					array(
						self::slot( 'Lastkapazitaet', 'text', false, 'Load capacitance (e.g. 18 pF).' ),
						self::slot( 'Bauform', 'text', false, 'e.g. 3225, HC-49.' ),
						self::slot( 'Hersteller', 'text', false, 'Manufacturer / OEM — not the distributor.' ),
						self::slot( 'Lieferant', 'node_ref', false, 'Distributor record (node_ref → Lieferanten: Url, Suchstring, Bewertung).' ),
						self::slot( 'Bestellnummer', 'text', false ),
						self::slot( 'Datenblatt', 'media', false ),
					)
				)
			),
			self::bauteil_kind(
				'Sicherung',
				'Fuses / circuit protection.',
				array(
					self::slot( 'Nennstrom', 'text', true, 'Rated current (e.g. 1 A).' ),
					self::slot( 'Charakteristik', 'text', false, 'e.g. fast-blow, slow-blow PTC.' ),
					self::slot( 'Nennspannung', 'text', false, 'Voltage rating.' ),
					self::slot( 'Bauform', 'text', false, 'e.g. 1206, 5×20 mm.' ),
					self::slot( 'Hersteller', 'text', false, 'Manufacturer / OEM — not the distributor.' ),
					self::slot( 'Lieferant', 'node_ref', false, 'Distributor record (node_ref → Lieferanten: Url, Suchstring, Bewertung).' ),
					self::slot( 'Bestellnummer', 'text', false ),
					self::slot( 'Datenblatt', 'media', false ),
				)
			),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $slots Property children (set members).
	 * @return array<string, mixed>
	 */
	private static function bauteil_kind( string $name, string $description, array $slots ): array {
		return array(
			'name'        => $name,
			'type_name'   => 'set',
			'is_datatype' => true,
			'description' => $description,
			'children'    => $slots,
		);
	}

	/**
	 * Category + schema only (Definition). No MPN leaves here — those are Implementation/Bauteile (Q83).
	 *
	 * @return array<string, mixed>
	 */
	public static function bauteilarten_catalog_node(): array {
		return array(
			'name'        => 'Bauteilarten',
			'is_datatype' => true,
			'is_abstract' => true,
			'description' => 'Part category schemas (Q83). Kinds hold slots; MPN records live under Implementation/Bauteile with type → kind.',
			'children'    => self::bauteile_kind_nodes(),
		);
	}

	/**
	 * Part master list (Implementation). Records are catalog leaves typed to Bauteilarten.
	 *
	 * @return array<string, mixed>
	 */
	public static function bauteile_implementation_node(): array {
		return array(
			'name'        => 'Bauteile',
			'description' => 'Part master data (MPNs). Category/schema = Bauteilarten; each record has type_id → kind (Q83). BOM Bauteil Wahl = node_embed → this root.',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function slot( string $name, string $type_name, bool $required, string $description = '' ): array {
		$node = array(
			'name'      => $name,
			'type_name' => $type_name,
			'required'  => $required,
		);
		if ( '' !== $description ) {
			$node['description'] = $description;
		}
		return $node;
	}

	/**
	 * Primary quantity triad (Wert + Praefix + fixed Einheit) — same pattern as unit sets.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function quantity_member_slots( string $unit_name, string $wert_description = '' ): array {
		return array(
			array(
				'name'        => 'Wert',
				'type_name'   => 'double',
				'required'    => true,
				'description' => '' !== $wert_description ? $wert_description : 'Numeric magnitude.',
			),
			array(
				'name'        => 'Praefix',
				'type_name'   => 'Praefixe',
				'required'    => false,
				'description' => 'SI prefix (optional).',
			),
			array(
				'name'            => 'Einheit',
				'type_name'       => 'Basiseinheit',
				'fixed_node_name' => $unit_name,
				'required'        => true,
				'description'     => 'Fixed base unit: ' . $unit_name . '.',
			),
		);
	}

	/**
	 * Example MPNs under Implementation/Bauteile, typed to Definition/Bauteilarten kinds (Q83).
	 *
	 * @param array<int, string> $bauteile_path    Path ending in Bauteile (records root).
	 * @param array<int, string> $bauteilarten_path Path ending in Bauteilarten (schemas).
	 */
	public static function ensure_bauteil_examples(
		string $taxonomy,
		array $bauteile_path = array(),
		array $bauteilarten_path = array()
	): void {
		if ( empty( $bauteile_path ) ) {
			$bauteile_path = array( self::ROOT_NAME, 'Bauteile' );
		}
		if ( empty( $bauteilarten_path ) ) {
			$bauteilarten_path = array( self::ROOT_NAME, 'Bauteilarten' );
		}
		$bauteile = self::find_term_by_path( $taxonomy, $bauteile_path );
		$arten    = self::find_term_by_path( $taxonomy, $bauteilarten_path );
		if ( $bauteile <= 0 || $arten <= 0 ) {
			return;
		}

		$examples = self::bauteil_example_seed();

		$created  = 0;
		$existing = 0;
		$position = 100;
		foreach ( $examples as $kind_name => $leaves ) {
			$kind_id = self::find_term_by_path(
				$taxonomy,
				array_merge( $bauteilarten_path, array( $kind_name ) )
			);
			if ( $kind_id <= 0 ) {
				continue;
			}
			foreach ( $leaves as $leaf ) {
				$term_id = self::ensure_term(
					$taxonomy,
					(string) $leaf['name'],
					$bauteile,
					isset( $leaf['description'] ) ? (string) $leaf['description'] : '',
					$created,
					$existing
				);
				if ( $term_id <= 0 ) {
					continue;
				}
				/* Reparent if still under a legacy kind folder. */
				$term = get_term( $term_id, $taxonomy );
				if ( $term instanceof \WP_Term && (int) $term->parent !== $bauteile ) {
					wp_update_term(
						$term_id,
						$taxonomy,
						array(
							'parent' => $bauteile,
						)
					);
				}
				Tree_Model::set_position( $term_id, $position );
				++$position;
				if ( ! empty( $leaf['short_description'] ) ) {
					Tree_Model::set_short_description(
						$taxonomy,
						$term_id,
						(string) $leaf['short_description']
					);
				}
				update_term_meta( $term_id, self::META_CATALOG_EXAMPLE, '1' );
				Node_Type::set_type_id( $taxonomy, $term_id, $kind_id );
			}
		}

		self::migrate_legacy_bauteile_kind_folders( $taxonomy, $bauteile, $arten );
	}

	/**
	 * Move leftover kind folders under Bauteile → records to Bauteile root; drop empty kind shells.
	 */
	public static function migrate_legacy_bauteile_kind_folders(
		string $taxonomy,
		int $bauteile_id,
		int $bauteilarten_id
	): void {
		if ( $bauteile_id <= 0 || $bauteilarten_id <= 0 ) {
			return;
		}

		$kind_names = array();
		$arten_kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $bauteilarten_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		foreach ( (array) $arten_kids as $k ) {
			if ( $k instanceof \WP_Term ) {
				$kind_names[ $k->name ] = (int) $k->term_id;
			}
		}

		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $bauteile_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		foreach ( (array) $kids as $kid ) {
			if ( ! $kid instanceof \WP_Term ) {
				continue;
			}
			$kid_id = (int) $kid->term_id;
			if ( self::is_catalog_example( $kid_id ) ) {
				continue;
			}
			if ( ! isset( $kind_names[ $kid->name ] ) ) {
				continue;
			}
			$kind_type_id = $kind_names[ $kid->name ];
			$grand        = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $kid_id,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			foreach ( (array) $grand as $g ) {
				if ( ! $g instanceof \WP_Term ) {
					continue;
				}
				$gid = (int) $g->term_id;
				if ( self::is_catalog_example( $gid ) ) {
					wp_update_term(
						$gid,
						$taxonomy,
						array(
							'parent' => $bauteile_id,
						)
					);
					Node_Type::set_type_id( $taxonomy, $gid, $kind_type_id );
					continue;
				}
				/* Slot duplicates under legacy kind — remove. */
				wp_delete_term( $gid, $taxonomy );
			}
			wp_delete_term( $kid_id, $taxonomy );
		}
	}

	/**
	 * Install Bauteilarten + Bauteile split and seed examples (Q83).
	 *
	 * @param array<int, string> $arten_parent_path Parent of Bauteilarten (Definition or project root).
	 * @param array<int, string> $bauteile_parent_path Parent of Bauteile (Implementation or project root).
	 */
	public static function ensure_bauteile_split(
		string $taxonomy,
		array $arten_parent_path,
		array $bauteile_parent_path
	): void {
		$arten_parent = self::find_term_by_path( $taxonomy, $arten_parent_path );
		$bau_parent   = self::find_term_by_path( $taxonomy, $bauteile_parent_path );
		if ( $arten_parent <= 0 || $bau_parent <= 0 ) {
			return;
		}

		$created  = 0;
		$existing = 0;
		self::install_node_tree(
			$taxonomy,
			array( self::bauteilarten_catalog_node() ),
			$arten_parent,
			$created,
			$existing
		);
		self::install_node_tree(
			$taxonomy,
			array( self::bauteile_implementation_node() ),
			$bau_parent,
			$created,
			$existing
		);

		$arten_path = array_merge( $arten_parent_path, array( 'Bauteilarten' ) );
		$bau_path   = array_merge( $bauteile_parent_path, array( 'Bauteile' ) );
		self::ensure_set_composition_members( $taxonomy );
		self::ensure_bauteil_examples( $taxonomy, $bau_path, $arten_path );
	}

	/**
	 * @return array<string, list<array{name:string,short_description?:string,description?:string}>>
	 */
	private static function bauteil_example_seed(): array {
		return array(
			'Widerstand'     => array(
				array(
					'name'              => 'RC0603FR-071K0L',
					'short_description' => '1 kΩ 0603 1%',
					'description'       => 'Yageo thick-film chip resistor 1 kΩ, 0603, 1%, ~0.1 W (Mouser/DigiKey Resistors).',
				),
				array(
					'name'              => 'ERJ-6ENF1002V',
					'short_description' => '10 kΩ 0805 1%',
					'description'       => 'Panasonic ERA/ERJ series 10 kΩ, 0805, 1%.',
				),
			),
			'Kondensator'    => array(
				array(
					'name'              => 'CL10B104KB8NNNC',
					'short_description' => '100 nF 0603 X7R',
					'description'       => 'Samsung MLCC 100 nF, 0603, X7R, 50 V (typical DigiKey Capacitors).',
				),
				array(
					'name'              => 'GRM21BR61A106KE19L',
					'short_description' => '10 µF 0805 X5R',
					'description'       => 'Murata GRM 10 µF, 0805, X5R, 10 V.',
				),
			),
			'Spule'          => array(
				array(
					'name'              => 'LQH3NPN100MM0L',
					'short_description' => '10 µH 1212',
					'description'       => 'Murata power inductor ~10 µH (Inductors / Coils / Chokes).',
				),
				array(
					'name'              => 'MLZ2012N100LT000',
					'short_description' => '10 µH 0805',
					'description'       => 'TDK multilayer inductor 10 µH, 0805.',
				),
			),
			'Diode'          => array(
				array(
					'name'              => '1N4148WS',
					'short_description' => 'Switching SOD-323',
					'description'       => 'Small-signal switching diode, SOD-323 (Discrete semiconductors).',
				),
				array(
					'name'              => 'BAT54S',
					'short_description' => 'Dual Schottky SOT-23',
					'description'       => 'Dual Schottky barrier diode, SOT-23.',
				),
			),
			'Transistor'     => array(
				array(
					'name'              => '2N7002',
					'short_description' => 'N-MOSFET SOT-23',
					'description'       => 'N-channel MOSFET 60 V, SOT-23 (common DigiKey Discrete).',
				),
				array(
					'name'              => 'BC847B',
					'short_description' => 'NPN BJT SOT-23',
					'description'       => 'NPN general-purpose BJT, SOT-23.',
				),
			),
			'LED'            => array(
				array(
					'name'              => 'LTST-C190CKT',
					'short_description' => 'Red 0603',
					'description'       => 'Lite-On red chip LED 0603 (Opto / LED).',
				),
				array(
					'name'              => 'LG R971',
					'short_description' => 'Green 0603',
					'description'       => 'OSRAM/other green 0603 LED (illustrative Conrad/Mouser Opto).',
				),
			),
			'IC'             => array(
				array(
					'name'              => 'ATtiny412-SSN',
					'short_description' => 'MCU SOP-8',
					'description'       => 'Microchip tinyAVR MCU, SOIC-8 (Integrated Circuits).',
				),
				array(
					'name'              => 'CH340N',
					'short_description' => 'USB-UART SOP-8',
					'description'       => 'WCH USB-UART bridge, SOP-8.',
				),
			),
			'Relais'         => array(
				array(
					'name'              => 'G5V-1-DC5',
					'short_description' => '5 V SPDT signal',
					'description'       => 'Omron signal relay 5 V coil, SPDT (DigiKey Relays).',
				),
				array(
					'name'              => 'IM03GR',
					'short_description' => '5 V SMD relay',
					'description'       => 'TE Connectivity IM series 5 V SMD relay.',
				),
			),
			'Steckverbinder' => array(
				array(
					'name'              => 'USB-A-THT',
					'short_description' => 'USB-A receptacle',
					'description'       => 'USB Type-A receptacle through-hole (Connectors / Interconnects).',
				),
				array(
					'name'              => 'PinHeader-1x40',
					'short_description' => '2.54 mm 1×40',
					'description'       => '2.54 mm pitch male pin header 1×40.',
				),
			),
			'Schalter'       => array(
				array(
					'name'              => 'B3F-1000',
					'short_description' => 'Tactile 6×6',
					'description'       => 'Omron tactile switch 6×6 mm (electromechanical).',
				),
				array(
					'name'              => 'SS12D00G3',
					'short_description' => 'Slide SPDT',
					'description'       => 'Slide switch SPDT (illustrative).',
				),
			),
			'Quarz'          => array(
				array(
					'name'              => 'ABS07-32.768KHZ-T',
					'short_description' => '32.768 kHz',
					'description'       => 'Abracon 32.768 kHz tuning-fork crystal.',
				),
				array(
					'name'              => 'FA-238 16.0000MB-C',
					'short_description' => '16 MHz',
					'description'       => 'Epson FA-238 16 MHz crystal (frequency control).',
				),
			),
			'Sicherung'      => array(
				array(
					'name'              => '0468001.NR',
					'short_description' => '1 A 1206',
					'description'       => 'Littelfuse 1206 chip fuse 1 A (circuit protection).',
				),
				array(
					'name'              => '5x20-T1A',
					'short_description' => '1 A slow-blow',
					'description'       => '5×20 mm glass fuse 1 A time-lag.',
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
	public static function install( string $taxonomy = Taxonomy::TREE ) {
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
		self::ensure_bom_bauteil_ref_scope( $taxonomy );
		self::migrate_subtree_type_to_node_embed( $taxonomy );
		self::ensure_node_pick_type_group( $taxonomy );
		self::ensure_datatype_flags( $taxonomy );
		Catalog_Bindings::ensure( $taxonomy );
		self::ensure_relation_types( $taxonomy );
		self::ensure_knoten_datatype( $taxonomy );
		self::ensure_root_typed_knoten( $taxonomy );
		self::ensure_set_composition_members( $taxonomy );
		self::ensure_bauteil_examples(
			$taxonomy,
			array( self::ROOT_NAME, 'Bauteile' ),
			array( self::ROOT_NAME, 'Bauteilarten' )
		);
		self::ensure_lieferanten_catalog(
			$taxonomy,
			array( self::ROOT_NAME ),
			array( self::ROOT_NAME, 'Bauteilarten' )
		);
		self::ensure_subnode_type( $taxonomy );
		Node_Type::ensure_table_type_props( $taxonomy );
		self::ensure_bauart_enum( $taxonomy );
		self::ensure_deletable_flags( $taxonomy );
		Trash::ensure_trash_node( $taxonomy );

		return array(
			'created'  => $created,
			'existing' => $existing,
			'taxonomy' => $taxonomy,
		);
	}

	/**
	 * Concrete footprint enum under catalog `enum` (Q52).
	 *
	 * Shape: enum → Bauart → Option ─[has_type]→ text → closed options.
	 *
	 * @return array<string, mixed>
	 */
	public static function bauart_enum_node(): array {
		return array(
			'name'        => 'Bauart',
			'description' => 'Concrete enum for footprints / package styles (0201, 0603, axial, …).',
			'is_datatype' => true,
			'deletable'   => false,
			'children'    => array(
				array(
					'name'        => 'Option',
					'description' => 'Single enum column - has_type text (proto).',
					'type_name'   => 'text',
					'children'    => array(
						array( 'name' => '0201' ),
						array( 'name' => '0402' ),
						array( 'name' => '0603' ),
						array( 'name' => '0805' ),
						array( 'name' => '1206' ),
						array( 'name' => 'axial' ),
					),
				),
			),
		);
	}

	/**
	 * Ensure Bauart concrete enum exists under the catalog `enum` node (idempotent).
	 * Untrashes when soft-deleted. Does not remove sibling concrete enums (e.g. Dioden Typen).
	 *
	 * @return int Bauart term id, or 0 on failure.
	 */
	public static function ensure_bauart_enum( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$enum_id = self::find_enum_catalog_id( $taxonomy );
		if ( $enum_id <= 0 ) {
			return 0;
		}

		Trash::restore_subtree( $taxonomy, $enum_id );

		$created  = 0;
		$existing = 0;
		self::install_node_tree(
			$taxonomy,
			array( self::bauart_enum_node() ),
			$enum_id,
			$created,
			$existing
		);

		$bauart_id = self::find_direct_child_named( $taxonomy, $enum_id, 'Bauart' );
		if ( $bauart_id <= 0 ) {
			return 0;
		}

		Trash::restore_subtree( $taxonomy, $bauart_id );
		Node_Type::set_is_datatype( $taxonomy, $bauart_id, true );
		Node_Type::set_is_abstract( $taxonomy, $bauart_id, false );
		Node_Type::set_deletable( $bauart_id, false );

		$option_id = 0;
		foreach ( Node_Type::enum_option_column_names() as $column_name ) {
			$option_id = self::find_direct_child_named( $taxonomy, $bauart_id, $column_name );
			if ( $option_id > 0 ) {
				break;
			}
		}
		if ( $option_id > 0 ) {
			$text_id = Node_Type::find_type_by_name( $taxonomy, $option_id, 'text' );
			if ( $text_id > 0 && Node_Type::get_type_id( $option_id ) !== $text_id ) {
				Node_Type::set_type_id( $taxonomy, $option_id, $text_id );
			}
		}

		return $bauart_id;
	}

	/**
	 * Resolve the catalog `enum` datatype leaf (Complex/Collection child).
	 */
	public static function find_enum_catalog_id( string $taxonomy ): int {
		$paths = array(
			array( 'Fallstudie', 'Definition', 'Data Types', 'Complex', 'enum' ),
			array( 'Fallstudie', 'Definition', 'Datentypen', 'Complex', 'enum' ),
			array( 'Fallstudie', 'Definition', 'Complex', 'enum' ),
			array( 'BOM Testprojekt', 'Typen', 'Datentypen', 'Complex', 'Collection', 'enum' ),
			array( 'BOM Testprojekt', 'Typen', 'Datentypen', 'Complex', 'enum' ),
			array( 'BOM Testprojekt', 'Typen', 'Complex', 'Collection', 'enum' ),
		);
		foreach ( $paths as $path ) {
			$id = self::find_term_by_path( $taxonomy, $path );
			if ( $id > 0 ) {
				return $id;
			}
		}

		$enums = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => 'enum',
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $enums ) ) {
			return 0;
		}
		$fallback = 0;
		foreach ( $enums as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$id = (int) $term->term_id;
			if ( Node_Type::is_datatype( $taxonomy, $id ) ) {
				return $id;
			}
			if ( 0 === $fallback ) {
				$fallback = $id;
			}
		}

		return $fallback;
	}

	/**
	 * Direct child term id by name (0 if missing).
	 */
	public static function find_direct_child_named( string $taxonomy, int $parent_id, string $name ): int {
		if ( $parent_id <= 0 || '' === $name ) {
			return 0;
		}
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
		return 0;
	}

	/**
	 * Q74/Q35: Relationstypen Ast — system types + composition.
	 */
	public static function ensure_relation_types( string $taxonomy ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$root = self::find_term_by_path( $taxonomy, array( self::ROOT_NAME ) );
		if ( $root <= 0 ) {
			return;
		}

		$created  = 0;
		$existing = 0;
		$folder   = self::ensure_term(
			$taxonomy,
			Relation::ROOT_NAME,
			$root,
			'RelationType catalog (Q35/Q54/Q74).',
			$created,
			$existing
		);
		if ( $folder <= 0 ) {
			return;
		}

		$seeds = array(
			'child_of'     => 'Hierarchy Kind von (system). Multiplicity always 1 (exactly one parent). Not creatable via Add relation — use Reparent.',
			'has_type'     => 'Data-type binding (has_type). Managed via Relations UI; persists as type_id (Q74).',
			'ref_scope'    => 'Catalog root for node_embed / node_ref (system). Derived from ref_scope setting.',
			'besteht_aus'  => 'Composition / besteht aus — dies with the object (Q75/Q85; attribute Bindung).',
			'aggregation'  => 'Aggregation — member lives on when the host object no longer exists (attribute Bindung).',
		);
		foreach ( $seeds as $name => $description ) {
			$id = self::ensure_term(
				$taxonomy,
				$name,
				$folder,
				$description,
				$created,
				$existing
			);
			if ( $id > 0 ) {
				Node_Type::set_deletable( $id, false );
			}
		}
		self::migrate_composition_relation_type_name( $taxonomy, $folder );
		Relation::migrate_drop_erbt_von( $taxonomy );
		Relation::repair_child_of_multiplicity( $taxonomy );
		Node_Type::set_is_abstract( $taxonomy, $folder, true );
		Node_Type::set_is_datatype( $taxonomy, $folder, false );
		Node_Type::set_deletable( $folder, false );
	}

	/**
	 * General datatype "Knoten" under Datentypen (sibling of Simple/Complex).
	 */
	public static function ensure_knoten_datatype( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$existing = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Typen', 'Datentypen', 'Knoten' )
		);
		if ( $existing > 0 ) {
			Node_Type::set_is_datatype( $taxonomy, $existing, true );
			Node_Type::set_is_abstract( $taxonomy, $existing, false );
			Node_Type::set_deletable( $existing, false );
			return $existing;
		}

		$parent = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Typen', 'Datentypen' )
		);
		if ( $parent <= 0 ) {
			return 0;
		}

		$created  = 0;
		$existing_n = 0;
		$id       = self::ensure_term(
			$taxonomy,
			'Knoten',
			$parent,
			'General assignable node type (project roots and ordinary nodes).',
			$created,
			$existing_n
		);
		if ( $id <= 0 ) {
			return 0;
		}
		Node_Type::set_is_datatype( $taxonomy, $id, true );
		Node_Type::set_is_abstract( $taxonomy, $id, false );
		Node_Type::set_deletable( $id, false );
		return $id;
	}

	/**
	 * Project root typed as Knoten (idempotent).
	 */
	public static function ensure_root_typed_knoten( string $taxonomy ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$root = self::find_term_by_path( $taxonomy, array( self::ROOT_NAME ) );
		$knoten = self::ensure_knoten_datatype( $taxonomy );
		if ( $root <= 0 || $knoten <= 0 ) {
			return;
		}
		Node_Type::set_type_id( $taxonomy, $root, $knoten );
	}

	/**
	 * @see Relation::migrate_composition_type_name()
	 */
	public static function migrate_composition_relation_type_name( string $taxonomy, int $folder_id = 0 ): void {
		unset( $folder_id );
		Relation::migrate_composition_type_name( $taxonomy );
	}

	/**
	 * Lock seeded catalog datatypes + Relationstypen.
	 */
	public static function ensure_deletable_flags( string $taxonomy ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$extra = array();
		$folder = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, Relation::ROOT_NAME )
		);
		if ( $folder > 0 ) {
			$extra[] = $folder;
			foreach ( array( 'child_of', 'has_type', 'ref_scope', 'besteht_aus', 'composition', 'aggregation' ) as $name ) {
				$id = Relation::find_type_id_by_name( $taxonomy, $name );
				if ( $id > 0 ) {
					$extra[] = $id;
				}
			}
		}

		Node_Type::lock_seeded_catalog_deletable( $taxonomy, $extra );
	}

	/**
	 * Q75: for each set-typed node, ensure outgoing composition Relations for
	 * hierarchy children that are property slots (not catalog example leaves).
	 * Adds missing edges; does not remove existing ones.
	 */
	public static function ensure_set_composition_members( string $taxonomy ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$type_id = Relation::find_type_id_by_name( $taxonomy, Relation::TYPE_COMPOSITION );
		if ( $type_id <= 0 ) {
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$set_id = (int) $term->term_id;
			if ( ! Node_Type::is_set_typed( $taxonomy, $set_id ) ) {
				continue;
			}

			$existing     = Relation::list_outgoing_by_type_key( $taxonomy, $set_id, Relation::TYPE_COMPOSITION );
			$existing_tos = array();
			foreach ( $existing as $edge ) {
				$to = isset( $edge['toId'] ) ? (int) $edge['toId'] : ( isset( $edge['to'] ) ? (int) $edge['to'] : 0 );
				if ( $to > 0 ) {
					$existing_tos[ $to ] = true;
				}
			}

			$children = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $set_id,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			if ( ! is_array( $children ) || empty( $children ) ) {
				continue;
			}

			$sorted = array();
			foreach ( $children as $child ) {
				if ( $child instanceof \WP_Term ) {
					$sorted[] = $child;
				}
			}
			usort(
				$sorted,
				static function ( \WP_Term $a, \WP_Term $b ): int {
					$pa = Tree_Model::get_position( (int) $a->term_id );
					$pb = Tree_Model::get_position( (int) $b->term_id );
					if ( $pa !== $pb ) {
						return $pa <=> $pb;
					}
					return strcasecmp( $a->name, $b->name );
				}
			);

			foreach ( $sorted as $child ) {
				$cid = (int) $child->term_id;
				if ( self::is_catalog_example( $cid ) ) {
					continue;
				}
				if ( isset( $existing_tos[ $cid ] ) ) {
					continue;
				}
				Relation::add( $taxonomy, $set_id, $type_id, $cid );
			}
		}
	}

	/**
	 * Whether a term is a catalog example MPN leaf (not a set property slot).
	 */
	public static function is_catalog_example( int $term_id ): bool {
		return '1' === (string) get_term_meta( $term_id, self::META_CATALOG_EXAMPLE, true );
	}

	/**
	 * Q76: Bauteile = set + inheriting so catalog children get effective type set
	 * until they Override. Clears redundant own type_id on non-overriding children
	 * that already matched the inherited set (optional cleanup — keep if override).
	 *
	 * @param array<int, string> $bauteile_path Path to Bauteile root.
	 */
	public static function ensure_type_inheritance( string $taxonomy, array $bauteile_path = array() ): void {
		if ( empty( $bauteile_path ) ) {
			$bauteile_path = array( self::ROOT_NAME, 'Bauteile' );
		}
		$bauteile = self::find_term_by_path( $taxonomy, $bauteile_path );
		if ( $bauteile <= 0 ) {
			return;
		}

		$set_id = Node_Type::find_type_by_name( $taxonomy, $bauteile, 'set' );
		if ( $set_id > 0 && Node_Type::get_type_id( $bauteile ) <= 0 ) {
			Node_Type::set_type_id( $taxonomy, $bauteile, $set_id );
		}
		Node_Type::set_type_inheriting( $taxonomy, $bauteile, true );

		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $bauteile,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $children ) ) {
			return;
		}
		foreach ( $children as $child ) {
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			$cid = (int) $child->term_id;
			if ( Node_Type::is_type_override( $cid ) ) {
				continue;
			}
			/* Prefer live inherit: drop duplicate own set type_id on children. */
			if ( $set_id > 0 && Node_Type::get_type_id( $cid ) === $set_id ) {
				delete_term_meta( $cid, Node_Type::META_KEY );
			}
		}
	}

	/**
	 * Q77: Typen = is_datatype (inherited). is_abstract is local-only (folders mark abstract explicitly).
	 * Set members under units / Abmessung are not datatype catalog nodes.
	 */
	public static function ensure_datatype_flags( string $taxonomy ): void {
		$typen = self::find_term_by_path( $taxonomy, array( 'BOM Testprojekt', 'Typen' ) );
		if ( $typen <= 0 ) {
			$all = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => 'Typen',
					'hide_empty' => false,
					'number'     => 1,
				)
			);
			if ( is_array( $all ) && isset( $all[0] ) && $all[0] instanceof \WP_Term ) {
				$typen = (int) $all[0]->term_id;
			}
		}
		if ( $typen <= 0 ) {
			return;
		}

		Node_Type::set_is_datatype( $taxonomy, $typen, true );
		Node_Type::set_is_abstract( $taxonomy, $typen, true );

		$abstract_names = array(
			'Datentypen'   => true,
			'Simple'       => true,
			'Complex'      => true,
			'Collection'   => true,
			'node_pick'    => true,
			'Praefixe'     => true,
			'Basiseinheit' => true,
			'Bauformen'    => true,
		);

		$descendants = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'child_of'   => $typen,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $descendants ) ) {
			return;
		}

		foreach ( $descendants as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$id     = (int) $term->term_id;
			$parent = (int) $term->parent;

			/* Slot children of set-typed catalog nodes (units, Abmessung, …) are not types. */
			if (
				$parent > 0
				&& ( Node_Type::is_basiseinheit_unit_node( $taxonomy, $parent ) || Node_Type::is_set_typed( $taxonomy, $parent ) )
			) {
				Node_Type::set_is_datatype( $taxonomy, $id, false );
				Node_Type::set_is_abstract( $taxonomy, $id, null );
				continue;
			}

			Node_Type::set_is_datatype( $taxonomy, $id, null );

			if ( isset( $abstract_names[ $term->name ] ) ) {
				Node_Type::set_is_abstract( $taxonomy, $id, true );
			} else {
				Node_Type::set_is_abstract( $taxonomy, $id, false );
			}
		}
	}

	/**
	 * Q73: Ensure Complex/node_pick/{node_embed,node_ref}.
	 */
	public static function ensure_node_pick_type_group( string $taxonomy ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$complex = self::find_term_by_path(
			$taxonomy,
			array( 'BOM Testprojekt', 'Typen', 'Datentypen', 'Complex' )
		);
		if ( $complex <= 0 ) {
			return;
		}

		$pick = 0;
		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $complex,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_array( $kids ) ) {
			foreach ( $kids as $kid ) {
				if ( $kid instanceof \WP_Term && 'node_pick' === $kid->name ) {
					$pick = (int) $kid->term_id;
					break;
				}
			}
		}
		if ( $pick <= 0 ) {
			$created = wp_insert_term(
				'node_pick',
				$taxonomy,
				array(
					'parent'      => $complex,
					'description' => 'Shared parent (Q73): ref_scope + allowed catalog children.',
					'slug'        => 'node-pick',
				)
			);
			if ( is_wp_error( $created ) || empty( $created['term_id'] ) ) {
				return;
			}
			$pick = (int) $created['term_id'];
		}

		$move_names = array( 'node_embed', 'node_ref', 'subtree' );
		$sources    = array( $complex );
		$simple     = self::find_term_by_path(
			$taxonomy,
			array( 'BOM Testprojekt', 'Typen', 'Datentypen', 'Simple' )
		);
		if ( $simple > 0 ) {
			$sources[] = $simple;
		}

		foreach ( $sources as $parent_id ) {
			$siblings = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $parent_id,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			if ( ! is_array( $siblings ) ) {
				continue;
			}
			foreach ( $siblings as $sib ) {
				if ( ! $sib instanceof \WP_Term ) {
					continue;
				}
				if ( ! in_array( $sib->name, $move_names, true ) ) {
					continue;
				}
				if ( (int) $sib->parent === $pick ) {
					continue;
				}
				$name = 'subtree' === $sib->name ? 'node_embed' : $sib->name;
				$existing = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'name'       => $name,
						'parent'     => $pick,
						'hide_empty' => false,
						'number'     => 1,
					)
				);
				if ( is_array( $existing ) && ! empty( $existing ) && $existing[0] instanceof \WP_Term ) {
					self::repoint_type_id( $taxonomy, (int) $sib->term_id, (int) $existing[0]->term_id );
					wp_delete_term( (int) $sib->term_id, $taxonomy );
					continue;
				}
				wp_update_term(
					(int) $sib->term_id,
					$taxonomy,
					array(
						'parent' => $pick,
						'name'   => $name,
						'slug'   => sanitize_title( $name ),
					)
				);
			}
		}

		foreach ( array( 'node_embed', 'node_ref' ) as $leaf ) {
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => $leaf,
					'parent'     => $pick,
					'hide_empty' => false,
					'number'     => 1,
				)
			);
			if ( is_array( $found ) && ! empty( $found ) ) {
				continue;
			}
			wp_insert_term(
				$leaf,
				$taxonomy,
				array(
					'parent' => $pick,
					'slug'   => sanitize_title( $leaf ),
				)
			);
		}
	}

	/**
	 * Rename legacy Complex type `subtree` → `node_embed` when present (idempotent).
	 * If both names exist under the same parent, re-point typed terms to node_embed and remove subtree.
	 */
	public static function migrate_subtree_type_to_node_embed( string $taxonomy ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$matches = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => 'subtree',
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $matches ) ) {
			return;
		}
		foreach ( $matches as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$subtree_id = (int) $term->term_id;
			$embed      = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => 'node_embed',
					'parent'     => (int) $term->parent,
					'hide_empty' => false,
					'number'     => 1,
				)
			);
			if ( is_array( $embed ) && ! empty( $embed ) && $embed[0] instanceof \WP_Term ) {
				$embed_id = (int) $embed[0]->term_id;
				self::repoint_type_id( $taxonomy, $subtree_id, $embed_id );
				wp_delete_term( $subtree_id, $taxonomy );
				continue;
			}
			wp_update_term(
				$subtree_id,
				$taxonomy,
				array(
					'name' => 'node_embed',
					'slug' => 'node-embed',
				)
			);
		}
	}

	/**
	 * Move all terms typed as $from_type_id to $to_type_id.
	 */
	private static function repoint_type_id( string $taxonomy, int $from_type_id, int $to_type_id ): void {
		if ( $from_type_id <= 0 || $to_type_id <= 0 || $from_type_id === $to_type_id ) {
			return;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $terms ) ) {
			return;
		}
		foreach ( $terms as $term_id ) {
			$term_id = (int) $term_id;
			if ( Node_Type::get_type_id( $term_id ) === $from_type_id ) {
				Node_Type::set_type_id( $taxonomy, $term_id, $to_type_id );
			}
		}
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
	 * Ensure Typen/Praefixe + Typen/Basiseinheit catalogs exist (idempotent).
	 * Use after Q85 clear_except_datatypes (keeps Datentypen only) without reinstalling BOM / Bauteile.
	 *
	 * @return array{created:int,existing:int}
	 */
	public static function ensure_praefixe_and_basiseinheit( string $taxonomy = Taxonomy::TREE ): array {
		$out = array(
			'created'  => 0,
			'existing' => 0,
		);
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $out;
		}

		$typen = self::find_term_by_path( $taxonomy, array( self::ROOT_NAME, 'Typen' ) );
		if ( $typen <= 0 ) {
			return $out;
		}

		$blueprint = self::blueprint();
		$root      = isset( $blueprint[0] ) && is_array( $blueprint[0] ) ? $blueprint[0] : null;
		if ( null === $root ) {
			return $out;
		}

		$typen_node = null;
		foreach ( (array) ( $root['children'] ?? array() ) as $child ) {
			if ( is_array( $child ) && 'Typen' === ( $child['name'] ?? '' ) ) {
				$typen_node = $child;
				break;
			}
		}
		if ( null === $typen_node ) {
			return $out;
		}

		$slices = array();
		foreach ( (array) ( $typen_node['children'] ?? array() ) as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$name = (string) ( $child['name'] ?? '' );
			if ( 'Praefixe' === $name || 'Basiseinheit' === $name ) {
				$slices[] = $child;
			}
		}
		if ( empty( $slices ) ) {
			return $out;
		}

		/* Two passes: unit members type_name Praefixe / fixed symbols need catalogs present. */
		self::install_nodes( $taxonomy, $slices, $typen, $out['created'], $out['existing'] );
		$re_created  = 0;
		$re_existing = 0;
		self::install_nodes( $taxonomy, $slices, $typen, $re_created, $re_existing );
		foreach ( array( 'Praefixe', 'Basiseinheit' ) as $catalog_name ) {
			$catalog_id = self::find_term_by_path(
				$taxonomy,
				array( self::ROOT_NAME, 'Typen', $catalog_name )
			);
			if ( $catalog_id > 0 ) {
				Trash::restore_subtree( $taxonomy, $catalog_id );
			}
		}
		self::migrate_basiseinheit_wert_to_typ( $taxonomy );
		self::ensure_prefix_multiplikators( $taxonomy );
		self::ensure_short_descriptions( $taxonomy );
		self::ensure_datatype_flags( $taxonomy );
		self::ensure_set_composition_members( $taxonomy );

		return $out;
	}

	/**
	 * Ensure SI prefix catalog nodes have multiplikator meta (idempotent).
	 */
	public static function ensure_prefix_multiplikators( string $taxonomy ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$factors = array(
			'p'     => 1.0e-12,
			'pico'  => 1.0e-12,
			'n'     => 1.0e-9,
			'nano'  => 1.0e-9,
			'u'     => 1.0e-6,
			'Micro' => 1.0e-6,
			'micro' => 1.0e-6,
			'm'     => 1.0e-3,
			'Milli' => 1.0e-3,
			'milli' => 1.0e-3,
			'c'     => 1.0e-2,
			'Centi' => 1.0e-2,
			'centi' => 1.0e-2,
			'k'     => 1.0e3,
			'Kilo'  => 1.0e3,
			'kilo'  => 1.0e3,
			'Mega'  => 1.0e6,
		);

		$roots = array();
		foreach ( array( 'Praefixe', 'Präfixe' ) as $root_name ) {
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => $root_name,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			if ( ! is_array( $found ) ) {
				continue;
			}
			foreach ( $found as $root ) {
				if ( $root instanceof \WP_Term ) {
					$roots[ (int) $root->term_id ] = $root;
				}
			}
		}
		if ( empty( $roots ) ) {
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
			'L'     => 'Länge',
			'B'     => 'Breite',
			'H'     => 'Höhe',
			'p'     => 'Pico',
			'n'     => 'Nano',
			'u'     => 'Micro',
			'm'     => 'Milli',
			'c'     => 'Centi',
			'k'     => 'Kilo',
			'Mega'  => 'Mega',
			'pico'  => 'p',
			'nano'  => 'n',
			'Micro' => 'u',
			'Milli' => 'm',
			'Centi' => 'c',
			'Kilo'  => 'k',
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
				if ( in_array( $name, array( 'p', 'n', 'u', 'm', 'c', 'k', 'Mega', 'pico', 'nano', 'Micro', 'Milli', 'Centi', 'Kilo' ), true ) && 'Praefixe' !== $parent_name && 'Präfixe' !== $parent_name ) {
					continue;
				}
				Tree_Model::set_short_description( $taxonomy, $term_id, $short );
				++$updated;
			}
		}

		return $updated;
	}

	/**
	 * Ensure Complex → subnode datatype exists (direct-child binding type for table props).
	 *
	 * @return int Term id, or 0.
	 */
	public static function ensure_subnode_type( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$created  = 0;
		$existing = 0;
		$complex  = self::find_term_by_path( $taxonomy, array( 'BOM Testprojekt', 'Typen', 'Datentypen', 'Complex' ) );
		if ( $complex <= 0 ) {
			return 0;
		}

		return self::ensure_term(
			$taxonomy,
			'subnode',
			$complex,
			'Direct child (Unterknoten) of the host node. Type catalog entry for table props (Kopf/Zeile/Fuss): binding must be a direct child.',
			$created,
			$existing
		);
	}

	/**
	 * Ensure Simple → email type exists (idempotent).
	 *
	 * @return int Email type term id, or 0 on failure.
	 */
	public static function ensure_email_type( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$created  = 0;
		$existing = 0;
		$simple   = self::find_term_by_path( $taxonomy, array( 'BOM Testprojekt', 'Typen', 'Datentypen', 'Simple' ) );
		if ( $simple <= 0 ) {
			return 0;
		}

		$email = self::ensure_term(
			$taxonomy,
			'email',
			$simple,
			'Email address (validated input).',
			$created,
			$existing
		);
		if ( $email > 0 ) {
			Node_Type::set_is_datatype( $taxonomy, $email, true );
			Node_Type::set_deletable( $email, false );
		}

		return $email > 0 ? $email : 0;
	}

	/**
	 * Ensure Simple → date type exists (idempotent). Default mode: date (calendar day).
	 *
	 * @return int Date type term id, or 0 on failure.
	 */
	public static function ensure_date_type( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$created  = 0;
		$existing = 0;
		$simple   = self::find_term_by_path( $taxonomy, array( 'BOM Testprojekt', 'Typen', 'Datentypen', 'Simple' ) );
		if ( $simple <= 0 ) {
			return 0;
		}

		$date = self::ensure_term(
			$taxonomy,
			'date',
			$simple,
			'Calendar date or date+time (mode on type). Store: Unix timestamp.',
			$created,
			$existing
		);
		if ( $date > 0 ) {
			Node_Type::set_is_datatype( $taxonomy, $date, true );
			Node_Type::set_deletable( $date, false );
			if ( ! metadata_exists( 'term', $date, Node_Type::META_KEY_DATE_MODE ) ) {
				Node_Type::set_date_mode( $taxonomy, $date, 'date' );
			}
		}

		return $date > 0 ? $date : 0;
	}

	/**
	 * Ensure Simple → media type exists (idempotent). Also adds demo slots if missing.
	 *
	 * @return int Media type term id, or 0 on failure.
	 */
	public static function ensure_media_type( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$created  = 0;
		$existing = 0;
		$simple   = self::find_term_by_path( $taxonomy, array( 'BOM Testprojekt', 'Typen', 'Datentypen', 'Simple' ) );
		if ( $simple <= 0 ) {
			return 0;
		}

		$media = self::ensure_term(
			$taxonomy,
			'media',
			$simple,
			'WP Media Library and/or URL (Q65). MIME-based display.',
			$created,
			$existing
		);
		if ( $media > 0 ) {
			Node_Type::set_media_type_config( $taxonomy, $media, true, false );
		}

		$rezept = self::find_term_by_path(
			$taxonomy,
			array( 'BOM Testprojekt', 'Compositionen', 'Rezept - Backzutaten' )
		);
		if ( $rezept > 0 && $media > 0 ) {
			$datei = self::ensure_term(
				$taxonomy,
				'Datei',
				$rezept,
				'Column -> media (WP library / optional URL).',
				$created,
				$existing
			);
			if ( $datei > 0 ) {
				Node_Type::set_type_id( $taxonomy, $datei, $media );
			}
		}

		$widerstand = self::find_term_by_path(
			$taxonomy,
			array( 'BOM Testprojekt', 'Bauteile', 'Widerstand' )
		);
		if ( $widerstand > 0 && $media > 0 ) {
			$datenblatt = self::ensure_term(
				$taxonomy,
				'Datenblatt',
				$widerstand,
				'Datasheet / image via media type (Q65).',
				$created,
				$existing
			);
			if ( $datenblatt > 0 ) {
				Node_Type::set_type_id( $taxonomy, $datenblatt, $media );
			}
		}

		return $media > 0 ? $media : 0;
	}

	/**
	 * Ensure BOM table has blueprint property-slot columns (idempotent).
	 *
	 * @return int BOM term id, or 0.
	 */
	public static function ensure_bom_columns( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$bom = self::find_term_by_path(
			$taxonomy,
			array( 'BOM Testprojekt', 'Compositionen', 'BOM' )
		);
		if ( $bom <= 0 ) {
			return 0;
		}

		$created  = 0;
		$existing = 0;
		$slots    = array(
			array( 'Bauteil Wahl', 'node_embed', 'Part pick: node_embed + ref_scope → Bauteile.', true ),
			array( 'Reference', 'text', 'Board references (e.g. R1,R2).', true ),
			array( 'Wert', 'text', 'Value / rating display.', false ),
			array( 'Footprint', 'text', 'Footprint / Bauart (scaffold: text; later enum).', false ),
			array( 'Menge', 'int', 'Quantity (Stück).', true ),
			array( 'Beschreibung', 'text', 'Notes.', false ),
		);

		foreach ( $slots as $index => $slot ) {
			$term_id = self::ensure_term(
				$taxonomy,
				$slot[0],
				$bom,
				$slot[2],
				$created,
				$existing
			);
			if ( $term_id <= 0 ) {
				continue;
			}
			Tree_Model::set_position( $term_id, $index );
			$type_id = Node_Type::find_type_by_name( $taxonomy, $term_id, $slot[1] );
			if ( $type_id > 0 ) {
				Node_Type::set_type_id( $taxonomy, $term_id, $type_id );
			}
			Node_Type::set_required( $taxonomy, $term_id, ! empty( $slot[3] ) );
		}

		self::ensure_bom_bauteil_ref_scope( $taxonomy );

		return $bom;
	}

	/**
	 * Wire BOM column Bauteil Wahl → node_embed ref_scope Bauteile (idempotent).
	 */
	public static function ensure_bom_bauteil_ref_scope( string $taxonomy ): void {
		$wahl = self::find_term_by_path(
			$taxonomy,
			array( 'BOM Testprojekt', 'Compositionen', 'BOM', 'Bauteil Wahl' )
		);
		$bauteile = self::find_term_by_path(
			$taxonomy,
			array( 'BOM Testprojekt', 'Bauteile' )
		);
		if ( $wahl <= 0 || $bauteile <= 0 ) {
			return;
		}
		$embed = Node_Type::find_type_by_name( $taxonomy, $wahl, 'node_embed' );
		if ( $embed > 0 ) {
			Node_Type::set_type_id( $taxonomy, $wahl, $embed );
		}
		Node_Type::set_ref_scope_id( $taxonomy, $wahl, $bauteile );
	}

	/**
	 * @param array<int, string> $path Root-to-leaf names.
	 */
	public static function find_term_by_path( string $taxonomy, array $path ): int {
		$parent = 0;
		foreach ( $path as $name ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => $name,
					'parent'     => $parent,
					'hide_empty' => false,
					'number'     => 1,
				)
			);
			if ( ! is_array( $terms ) || empty( $terms ) || ! $terms[0] instanceof \WP_Term ) {
				return 0;
			}
			$parent = (int) $terms[0]->term_id;
		}

		return $parent;
	}

	/**
	 * Delete known demo roots (and descendants), then reinstall the blueprint.
	 *
	 * @return array{deleted:int,created:int,existing:int,taxonomy:string}|\WP_Error
	 */
	public static function reset( string $taxonomy = Taxonomy::TREE ) {
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
	 * Q85 restart: delete everything except Datentypen (+ ancestors) and Relationstypen (system).
	 * Does not reinstall demo BOM / Bauteile / Compositionen.
	 *
	 * @return array{taxonomy:string,kept:list<int>,deleted:int}|\WP_Error
	 */
	public static function clear_except_datatypes( string $taxonomy = Taxonomy::TREE ) {
		if ( ! Tree_Model::is_hierarchical_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Not a hierarchical taxonomy.', 'wp-taxonomy-tree' ) );
		}

		$can_delete = ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'WTT_ALLOW_DEMO_MUTATIONS' ) && WTT_ALLOW_DEMO_MUTATIONS )
			|| current_user_can( Capabilities::delete_terms( $taxonomy ) );
		if ( ! $can_delete ) {
			return new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) );
		}

		$keep = self::datatype_keep_term_ids( $taxonomy );
		if ( empty( $keep ) ) {
			return new \WP_Error(
				'wtt_no_datatypes',
				__( 'No Datentypen branch found to keep.', 'wp-taxonomy-tree' )
			);
		}

		$all = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $all ) ) {
			return new \WP_Error( 'wtt_list_failed', __( 'Could not list terms.', 'wp-taxonomy-tree' ) );
		}

		/* Deepest first so children go before parents. */
		$by_depth = array();
		foreach ( $all as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$tid = (int) $term->term_id;
			if ( isset( $keep[ $tid ] ) ) {
				continue;
			}
			$depth = 0;
			$cur   = $tid;
			while ( $cur > 0 && $depth < 64 ) {
				$t = get_term( $cur, $taxonomy );
				if ( ! $t instanceof \WP_Term ) {
					break;
				}
				$cur = (int) $t->parent;
				++$depth;
			}
			$by_depth[] = array(
				'id'    => $tid,
				'depth' => $depth,
			);
		}
		usort(
			$by_depth,
			static function ( array $a, array $b ): int {
				return $b['depth'] <=> $a['depth'];
			}
		);

		$deleted = 0;
		foreach ( $by_depth as $row ) {
			$id = (int) $row['id'];
			if ( $id <= 0 || isset( $keep[ $id ] ) ) {
				continue;
			}
			/* May already be gone with a parent cascade — skip quietly. */
			$term = get_term( $id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			Node_Type::set_deletable( $id, true );
			$result = wp_delete_term( $id, $taxonomy );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( false !== $result && 0 !== $result ) {
				++$deleted;
			}
		}

		return array(
			'taxonomy' => $taxonomy,
			'kept'     => array_map( 'intval', array_keys( $keep ) ),
			'deleted'  => $deleted,
		);
	}

	/**
	 * Term ids to preserve: project root, Typen/Definition folders, Datentypen (or Simple+Complex), Relationstypen.
	 *
	 * @return array<int, true>
	 */
	private static function datatype_keep_term_ids( string $taxonomy ): array {
		$keep = array();

		$self = static function ( int $id ) use ( &$keep, &$self, $taxonomy ): void {
			if ( $id <= 0 || isset( $keep[ $id ] ) ) {
				return;
			}
			$keep[ $id ] = true;
			$children    = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $id,
					'hide_empty' => false,
					'number'     => 0,
					'fields'     => 'ids',
				)
			);
			if ( ! is_array( $children ) ) {
				return;
			}
			foreach ( $children as $cid ) {
				$self( (int) $cid );
			}
		};

		$ancestors = static function ( int $id ) use ( &$keep, $taxonomy ): void {
			$term = get_term( $id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				return;
			}
			$cur   = (int) $term->parent;
			$guard = 0;
			while ( $cur > 0 && $guard++ < 64 ) {
				$keep[ $cur ] = true;
				$t            = get_term( $cur, $taxonomy );
				if ( ! $t instanceof \WP_Term ) {
					break;
				}
				$cur = (int) $t->parent;
			}
		};

		/* BOM Testprojekt: Typen / Datentypen / … */
		$datentypen = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Typen', 'Datentypen' )
		);
		if ( $datentypen > 0 ) {
			$self( $datentypen );
			$ancestors( $datentypen );
		}

		/* Fallstudie: Definition / Data Types / Simple + Complex (datatype catalogs). */
		foreach ( array( 'Simple', 'Complex' ) as $leaf ) {
			foreach (
				array(
					array( 'Fallstudie', 'Definition', 'Data Types', $leaf ),
					array( 'Fallstudie', 'Definition', 'Datentypen', $leaf ),
					array( 'Fallstudie', 'Definition', $leaf ),
				) as $path
			) {
				$id = self::find_term_by_path( $taxonomy, $path );
				if ( $id > 0 ) {
					$self( $id );
					$ancestors( $id );
					break;
				}
			}
		}

		/* System Relationstypen (child_of, composition, …) — required infrastructure. */
		$rel_roots = array(
			array( self::ROOT_NAME, Relation::ROOT_NAME ),
			array( 'Fallstudie', Relation::ROOT_NAME ),
		);
		foreach ( $rel_roots as $path ) {
			$folder = self::find_term_by_path( $taxonomy, $path );
			if ( $folder > 0 ) {
				$self( $folder );
				$ancestors( $folder );
			}
		}

		return $keep;
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
	 * Public wrapper so Case_Data can install Bauteile / shared subtrees with full meta.
	 *
	 * @param array<int, array<string, mixed>> $nodes Nodes to ensure.
	 */
	public static function install_node_tree( string $taxonomy, array $nodes, int $parent_id, int &$created, int &$existing ): void {
		self::install_nodes( $taxonomy, $nodes, $parent_id, $created, $existing );
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
			$aliases     = array();
			if ( isset( $node['aliases'] ) && is_array( $node['aliases'] ) ) {
				foreach ( $node['aliases'] as $alias ) {
					$alias = is_string( $alias ) ? trim( $alias ) : '';
					if ( '' !== $alias && $alias !== $name ) {
						$aliases[] = $alias;
					}
				}
			}
			$term_id = self::ensure_term( $taxonomy, $name, $parent_id, $description, $created, $existing, $aliases );
			if ( $term_id <= 0 ) {
				continue;
			}

			Tree_Model::set_position( $term_id, (int) $index );

			if ( array_key_exists( 'short_description', $node ) ) {
				Tree_Model::set_short_description( $taxonomy, $term_id, (string) $node['short_description'] );
			}

			if ( array_key_exists( 'media_allow_upload', $node ) || array_key_exists( 'media_allow_url', $node ) ) {
				$allow_upload = array_key_exists( 'media_allow_upload', $node ) ? (bool) $node['media_allow_upload'] : true;
				$allow_url    = array_key_exists( 'media_allow_url', $node ) ? (bool) $node['media_allow_url'] : false;
				Node_Type::set_media_type_config( $taxonomy, $term_id, $allow_upload, $allow_url );
			}

			if ( array_key_exists( 'date_mode', $node ) ) {
				Node_Type::set_date_mode( $taxonomy, $term_id, (string) $node['date_mode'] );
			}

			if ( '' !== $type_name ) {
				$type_id = Node_Type::find_type_by_name( $taxonomy, $term_id, $type_name );
				if ( $type_id > 0 ) {
					Node_Type::set_type_id( $taxonomy, $term_id, $type_id );
				}
			}

			if ( array_key_exists( 'type_inheriting', $node ) ) {
				Node_Type::set_type_inheriting( $taxonomy, $term_id, (bool) $node['type_inheriting'] );
			}
			if ( array_key_exists( 'type_override', $node ) ) {
				Node_Type::set_type_override( $taxonomy, $term_id, (bool) $node['type_override'] );
			}

			if ( array_key_exists( 'required', $node ) ) {
				Node_Type::set_required( $taxonomy, $term_id, (bool) $node['required'] );
			}

			if ( array_key_exists( 'is_datatype', $node ) ) {
				Node_Type::set_is_datatype( $taxonomy, $term_id, (bool) $node['is_datatype'] );
			}
			if ( array_key_exists( 'is_abstract', $node ) ) {
				Node_Type::set_is_abstract( $taxonomy, $term_id, (bool) $node['is_abstract'] );
			}
			if ( array_key_exists( 'deletable', $node ) ) {
				Node_Type::set_deletable( $term_id, (bool) $node['deletable'] );
			} elseif ( array_key_exists( 'is_datatype', $node ) && true === (bool) $node['is_datatype'] ) {
				Node_Type::set_deletable( $term_id, false );
			}

			if ( array_key_exists( 'has_footer', $node ) ) {
				Node_Type::set_has_footer( $taxonomy, $term_id, (bool) $node['has_footer'] );
			}

			if ( array_key_exists( 'type_props', $node ) && is_array( $node['type_props'] ) ) {
				Node_Type::set_type_props( $taxonomy, $term_id, $node['type_props'] );
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

			if ( array_key_exists( 'ref_scope_path', $node ) && is_array( $node['ref_scope_path'] ) ) {
				$scope_id = self::find_term_by_path( $taxonomy, $node['ref_scope_path'] );
				if ( $scope_id > 0 ) {
					Node_Type::set_ref_scope_id( $taxonomy, $term_id, $scope_id );
				}
			}
		}
	}

	/**
	 * @param array<int, string> $aliases Former names under the same parent (rename in place).
	 */
	private static function ensure_term(
		string $taxonomy,
		string $name,
		int $parent_id,
		string $description,
		int &$created,
		int &$existing,
		array $aliases = array()
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
			if ( '' !== $description && Tree_Model::decode_term_description( (string) $found[0]->description ) !== $description ) {
				wp_update_term(
					$term_id,
					$taxonomy,
					array( 'description' => $description )
				);
			}
			return $term_id;
		}

		foreach ( $aliases as $alias ) {
			$alias_found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => $alias,
					'parent'     => $parent_id,
					'hide_empty' => false,
					'number'     => 1,
				)
			);
			if ( ! is_array( $alias_found ) || ! isset( $alias_found[0] ) || ! ( $alias_found[0] instanceof \WP_Term ) ) {
				continue;
			}
			$canonical = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => $name,
					'parent'     => $parent_id,
					'hide_empty' => false,
					'number'     => 1,
				)
			);
			if ( is_array( $canonical ) && isset( $canonical[0] ) && $canonical[0] instanceof \WP_Term ) {
				break;
			}
			$renamed = wp_update_term(
				(int) $alias_found[0]->term_id,
				$taxonomy,
				array( 'name' => $name )
			);
			if ( is_wp_error( $renamed ) ) {
				continue;
			}
			++$existing;
			$term_id = (int) $alias_found[0]->term_id;
			if ( '' !== $description ) {
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
