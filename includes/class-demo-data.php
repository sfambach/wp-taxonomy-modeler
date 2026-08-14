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
						'description' => 'Type branch (Q26/Q77): selectable types under the catalog binding (Q92).',
						'children'    => array(
							array(
								'name'        => 'Datentypen',
								'description' => 'Simple (scalars + node_ref) and Complex (quantity, node_embed, Collection).',
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
												'name'        => 'node_presentation',
												'description' => 'Read-only: shows one host Node presentation field (form/table/select/symbol/help/icon). Alias: display_node_name.',
												'aliases'     => array( 'display_node_name' ),
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
																	array( 'name' => 'Element', 'description' => 'Single list column - type text (proto).' ),
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
										'type_name'   => 'node_presentation',
										'description' => 'Zum Beispiel Widerstaende, Anschluesse rechts und links vom Koerper. Auch alte Kondensatoren haben oft dieses Format.',
									),
									array(
										'name'        => 'Durchloch Radial',
										'type_name'   => 'node_presentation',
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
					self::bauteile_implementation_node(),
					self::lieferanten_catalog_node(),
					array(
						'name'        => 'Relationstypen',
						'description' => 'RelationType catalog (Q35/Q54/Q74). Seed: child_of, ref_scope (system/synthetic) + composition (additive).',
						'children'    => array(
							array(
								'name'        => 'child_of',
								'description' => 'Hierarchy Kind von (system). Multiplicity always 1. Not creatable via Add relation — use Reparent.',
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
			'type_name'   => 'node_presentation',
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
			'description'     => 'Supplier / vendor catalog (electronics DigiKey, grocery Rewe, …). Each record: Url, optional Suchstring, optional Bewertung. Separate from Bauteil kinds (no Lieferant slot on parts).',
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
	 * @deprecated Lieferant slots removed from Bauteil kinds (Q83 merge). No-op kept for old callers.
	 *
	 * @param array<int, string> $bauteilarten_path Unused.
	 * @param array<int, string> $lieferanten_path  Unused.
	 */
	public static function ensure_lieferant_slot_ref_scopes(
		string $taxonomy,
		array $bauteilarten_path,
		array $lieferanten_path
	): void {
		unset( $taxonomy, $bauteilarten_path, $lieferanten_path );
	}

	/**
	 * Install / refresh Lieferanten catalog (separate from Bauteil kind slots).
	 *
	 * @param array<int, string> $parent_path       Parent of Lieferanten (project root or Implementation).
	 * @param array<int, string> $bauteilarten_path Unused (BC); kinds no longer carry Lieferant slots.
	 */
	public static function ensure_lieferanten_catalog(
		string $taxonomy,
		array $parent_path,
		array $bauteilarten_path = array()
	): void {
		unset( $bauteilarten_path );
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
	 * Kind name → group under Model/Bauteil (Q88 hierarchy folders).
	 *
	 * @return array<string, string>
	 */
	public static function bauteil_kind_group_map(): array {
		return array(
			'Widerstand'     => 'Passiv',
			'Kondensator'    => 'Passiv',
			'Spule'          => 'Passiv',
			'Dioden'         => 'Halbleiter',
			'Diode'          => 'Halbleiter',
			'Transistor'     => 'Halbleiter',
			'LED'            => 'Halbleiter',
			'IC'             => 'Halbleiter',
			'Relais'         => 'Elektromechanik',
			'Steckverbinder' => 'Elektromechanik',
			'Schalter'       => 'Elektromechanik',
			'Quarz'          => 'Sonstige',
			'Sicherung'      => 'Sonstige',
		);
	}

	/**
	 * DigiKey / Mouser-style kinds under Model/Bauteil, grouped (Passiv / Halbleiter / …).
	 * No Lieferant / Bestellnummer / Hersteller on kinds.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function bauteile_kind_nodes(): array {
		return array(
			self::bauteil_group(
				'Passiv',
				'Passive components (R / C / L).',
				array(
					self::bauteil_kind(
						'Widerstand',
						'Resistors (DigiKey/Mouser). Primary value in Ohm.',
						array_merge(
							self::quantity_member_slots( 'Ohm', 'Resistance value.' ),
							array(
								self::slot( 'Bauform', 'text', false, 'Package / footprint (e.g. 0603, 0805, axial).' ),
								self::slot( 'Toleranz', 'text', false, 'Tolerance (e.g. 1%, 5%).' ),
								self::slot( 'Nennleistung', 'text', false, 'Power rating (e.g. 0.1 W, 0.125 W).' ),
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
								self::slot( 'Datenblatt', 'media', false ),
							)
						)
					),
				)
			),
			self::bauteil_group(
				'Halbleiter',
				'Semiconductors (diodes, transistors, LEDs, ICs).',
				array(
					self::bauteil_kind(
						'Dioden',
						'Discrete diodes — choose an Art (CatalogChoice). Spec slots live on the Art later.',
						array()
					),
					self::bauteil_kind(
						'Transistor',
						'Discrete transistors (BJT, MOSFET, …).',
						array(
							self::slot( 'Transistortyp', 'text', true, 'e.g. N-MOSFET, NPN BJT.' ),
							self::slot( 'U_max', 'text', false, 'Vds / Vceo rating.' ),
							self::slot( 'I_max', 'text', false, 'Id / Ic rating.' ),
							self::slot( 'Bauform', 'text', false, 'e.g. SOT-23, TO-220.' ),
							self::slot( 'Datenblatt', 'media', false ),
						)
					),
					self::bauteil_kind(
						'LED',
						'Optoelectronics — LEDs (Conrad/Mouser Opto). Separate from Dioden Arten.',
						array(
							self::slot( 'Farbe', 'text', true, 'e.g. red, green, white, IR.' ),
							self::slot( 'U_f', 'text', false, 'Forward voltage.' ),
							self::slot( 'I_f', 'text', false, 'Forward current.' ),
							self::slot( 'Bauform', 'text', false, 'e.g. 0603, 5 mm THT.' ),
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
							self::slot( 'Datenblatt', 'media', false ),
						)
					),
				)
			),
			self::bauteil_group(
				'Elektromechanik',
				'Electromechanical parts (relays, connectors, switches).',
				array(
					self::bauteil_kind(
						'Relais',
						'Relays (DigiKey Relays category).',
						array(
							self::slot( 'Spulenspannung', 'text', true, 'Coil voltage (e.g. 5 V, 12 V).' ),
							self::slot( 'Kontakt', 'text', false, 'Contact form / rating (e.g. SPDT 2 A).' ),
							self::slot( 'Bauform', 'text', false ),
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
							self::slot( 'Datenblatt', 'media', false ),
						)
					),
				)
			),
			self::bauteil_group(
				'Sonstige',
				'Other categories (frequency control, circuit protection, …).',
				array(
					self::bauteil_kind(
						'Quarz',
						'Crystals / oscillators (frequency control).',
						array_merge(
							self::quantity_member_slots( 'Hertz', 'Nominal frequency.' ),
							array(
								self::slot( 'Lastkapazitaet', 'text', false, 'Load capacitance (e.g. 18 pF).' ),
								self::slot( 'Bauform', 'text', false, 'e.g. 3225, HC-49.' ),
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
							self::slot( 'Datenblatt', 'media', false ),
						)
					),
				)
			),
		);
	}

	/**
	 * Abstract group folder under Model/Bauteil (inheritance only — Q88).
	 *
	 * @param array<int, array<string, mixed>> $kinds Kind children.
	 * @return array<string, mixed>
	 */
	private static function bauteil_group( string $name, string $description, array $kinds ): array {
		return array(
			'name'        => $name,
			'description' => $description,
			'deletable'   => false,
			'children'    => $kinds,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $slots Attribute defs (Q87 — not hierarchy children).
	 * @return array<string, mixed>
	 */
	private static function bauteil_kind( string $name, string $description, array $slots ): array {
		return array(
			'name'        => $name,
			'type_name'   => 'set',
			'description' => $description,
			/* Q87: materialize via ensure_bauteil_kind_attributes(), not child_of. */
			'attributes'  => $slots,
		);
	}

	/**
	 * @deprecated Q83 — kinds live under Model/Bauteil. Kept for legacy path lookups only.
	 *
	 * @return array<string, mixed>
	 */
	public static function bauteilarten_catalog_node(): array {
		return array(
			'name'        => 'Bauteilarten',
			'description' => 'Deprecated folder — kinds live under Model/Bauteil (Q83).',
			'children'    => array(),
		);
	}

	/**
	 * Part master list (Implementation): MPN records only. Kinds live under Model/Bauteil.
	 *
	 * @return array<string, mixed>
	 */
	public static function bauteile_implementation_node(): array {
		return array(
			'name'        => 'Bauteile',
			'description' => 'Part master data (MPNs). Schema kinds under Model/Bauteil; each record type_id → kind. BOM Bauteil Wahl = node_embed → this root.',
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
	 * Primary quantity slot: Wert → size (OQ-W10/W11).
	 * Unit marriage (Praefix + Kuerzel) lives on With prefix / size → Unit — not on the host.
	 *
	 * @param string $unit_name Unused (kept for call-site BC; unit pick is on size.Unit).
	 * @return array<int, array<string, mixed>>
	 */
	private static function quantity_member_slots( string $unit_name, string $wert_description = '' ): array {
		unset( $unit_name );
		return array(
			array(
				'name'        => 'Wert',
				'type_name'   => 'size',
				'required'    => true,
				'description' => '' !== $wert_description ? $wert_description : 'Numeric magnitude with unit (size).',
			),
		);
	}

	/**
	 * Example MPNs under Implementation/Bauteile, typed to kinds under Model/Bauteil (Q83).
	 *
	 * @param array<int, string> $bauteile_path     Path ending in Bauteile (records root).
	 * @param array<int, string> $bauteilarten_path Unused (BC); kinds live under Model/Bauteil.
	 */
	public static function ensure_bauteil_examples(
		string $taxonomy,
		array $bauteile_path = array(),
		array $bauteilarten_path = array()
	): void {
		unset( $bauteilarten_path );
		if ( empty( $bauteile_path ) ) {
			$bauteile_path = array( self::ROOT_NAME, 'Bauteile' );
		}
		$bauteile = self::find_term_by_path( $taxonomy, $bauteile_path );
		if ( $bauteile <= 0 ) {
			return;
		}

		$kinds_root = self::find_model_bauteil_id( $taxonomy );
		if ( $kinds_root <= 0 ) {
			$kinds_root = $bauteile;
		}

		$examples = self::bauteil_example_seed();

		$created  = 0;
		$existing = 0;
		$position = 100;
		foreach ( $examples as $kind_name => $leaves ) {
			$kind_id = self::resolve_bauteil_kind_id( $taxonomy, $kinds_root, $bauteile, $kind_name );
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

		self::lift_catalog_examples_from_kinds( $taxonomy, $bauteile );
	}

	/**
	 * Resolve Model/Bauteil host id (Fallstudie or legacy roots).
	 */
	public static function find_model_bauteil_id( string $taxonomy ): int {
		$paths = array(
			array( 'Fallstudie', 'Model', 'Bauteil' ),
			array( self::ROOT_NAME, 'Model', 'Bauteil' ),
		);
		foreach ( $paths as $path ) {
			$id = self::find_term_by_path( $taxonomy, $path );
			if ( $id > 0 ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * Find a kind under Model/Bauteil (direct or under Passiv/Halbleiter/…) or legacy Bauteile.
	 */
	private static function resolve_bauteil_kind_id(
		string $taxonomy,
		int $kinds_root,
		int $bauteile_id,
		string $kind_name
	): int {
		$aliases = array( $kind_name );
		if ( 'Dioden' === $kind_name ) {
			$aliases[] = 'Diode';
		} elseif ( 'Diode' === $kind_name ) {
			$aliases[] = 'Dioden';
		}

		if ( $kinds_root > 0 ) {
			foreach ( $aliases as $alias ) {
				$id = self::find_bauteil_kind_under( $taxonomy, $kinds_root, $alias );
				if ( $id > 0 ) {
					return $id;
				}
			}
		}
		if ( $bauteile_id > 0 ) {
			foreach ( $aliases as $alias ) {
				$id = self::find_direct_child_named( $taxonomy, $bauteile_id, $alias );
				if ( $id > 0 ) {
					return $id;
				}
			}
		}
		return 0;
	}

	/**
	 * Find kind by name under Model/Bauteil or one level of group folders.
	 */
	public static function find_bauteil_kind_under( string $taxonomy, int $model_bauteil_id, string $kind_name ): int {
		if ( $model_bauteil_id <= 0 || '' === $kind_name ) {
			return 0;
		}
		$direct = self::find_direct_child_named( $taxonomy, $model_bauteil_id, $kind_name );
		if ( $direct > 0 ) {
			return $direct;
		}
		$groups = array_unique( array_values( self::bauteil_kind_group_map() ) );
		foreach ( $groups as $group_name ) {
			$group_id = self::find_direct_child_named( $taxonomy, $model_bauteil_id, $group_name );
			if ( $group_id <= 0 ) {
				continue;
			}
			$id = self::find_direct_child_named( $taxonomy, $group_id, $kind_name );
			if ( $id > 0 ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * Ensure group folders and move flat kinds under the right group.
	 * Collapses same-name siblings under each group (WP allows duplicate names).
	 *
	 * @return int Number of kinds reparented into groups.
	 */
	public static function ensure_bauteil_kind_groups( string $taxonomy, int $model_bauteil_id ): int {
		if ( $model_bauteil_id <= 0 ) {
			return 0;
		}

		$created  = 0;
		$existing = 0;
		self::install_node_tree(
			$taxonomy,
			self::bauteile_kind_nodes(),
			$model_bauteil_id,
			$created,
			$existing
		);

		$map   = self::bauteil_kind_group_map();
		$moved = 0;

		$scan_parents = array( $model_bauteil_id );
		foreach ( array_unique( array_values( $map ) ) as $group_name ) {
			$gid = self::find_direct_child_named( $taxonomy, $model_bauteil_id, $group_name );
			if ( $gid > 0 ) {
				Node_Type::set_deletable( $gid, false );
				Node_Type::apply_parent_as_type( $taxonomy, $gid );
				$scan_parents[] = $gid;
			}
		}

		foreach ( $scan_parents as $parent_id ) {
			$kids = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $parent_id,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			foreach ( (array) $kids as $kid ) {
				if ( ! $kid instanceof \WP_Term ) {
					continue;
				}
				$kind_id = (int) $kid->term_id;
				if ( self::is_catalog_example( $kind_id ) ) {
					continue;
				}
				$name = $kid->name;
				if ( 'Diode' === $name ) {
					$name = 'Dioden';
				}
				if ( ! isset( $map[ $name ] ) && ! isset( $map[ $kid->name ] ) ) {
					continue;
				}
				$group_name = $map[ $name ] ?? $map[ $kid->name ];
				$group_id   = self::find_direct_child_named( $taxonomy, $model_bauteil_id, $group_name );
				if ( $group_id <= 0 || $group_id === $kind_id ) {
					continue;
				}

				/* Already under the right group â€” leave for dedupe. */
				if ( (int) $kid->parent === $group_id && 'Diode' !== $kid->name ) {
					continue;
				}

				$existing_in_group = self::find_direct_child_named( $taxonomy, $group_id, $name );
				if ( $existing_in_group <= 0 && 'Dioden' === $name ) {
					$existing_in_group = self::find_direct_child_named( $taxonomy, $group_id, 'Diode' );
				}

				/*
				 * Group already has this kind (from seed). Do not reparent a second
				 * copy in â€” that created Halbleiter/Dioden Ã—2 etc.
				 */
				if ( $existing_in_group > 0 && $existing_in_group !== $kind_id ) {
					self::retarget_type_id_refs( $taxonomy, $kind_id, $existing_in_group );
					Node_Type::set_deletable( $kind_id, true );
					Tree_Model::delete_term( $taxonomy, $kind_id, 'cascade' );
					continue;
				}

				$update = array();
				if ( (int) $kid->parent !== $group_id ) {
					$update['parent'] = $group_id;
				}
				if ( 'Diode' === $kid->name ) {
					$update['name'] = 'Dioden';
				}
				if ( empty( $update ) ) {
					continue;
				}
				$upd = wp_update_term( $kind_id, $taxonomy, $update );
				if ( ! is_wp_error( $upd ) ) {
					++$moved;
					Node_Type::apply_parent_as_type( $taxonomy, $kind_id );
				}
			}
		}

		/*
		 * Soft-trash keeps the same parent — seed duplicates must be hard-deleted
		 * or get_terms still sees Widerstand×2 etc.
		 */
		self::dedupe_named_children_subtree( $taxonomy, $model_bauteil_id, 4 );

		return $moved;
	}

	/**
	 * Seed Q87 attributes on Model/Bauteil kinds from bauteile_kind_nodes() blueprints.
	 * Strips leftover hierarchy field-children that duplicate those attribute names.
	 *
	 * @return array{added:int,skipped:int,stripped:int}
	 */
	public static function ensure_bauteil_kind_attributes( string $taxonomy ): array {
		$stats = array(
			'added'    => 0,
			'skipped'  => 0,
			'stripped' => 0,
		);
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $stats;
		}

		$model_bauteil = self::find_model_bauteil_id( $taxonomy );
		if ( $model_bauteil <= 0 ) {
			return $stats;
		}

		foreach ( self::bauteile_kind_nodes() as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$kinds = isset( $group['children'] ) && is_array( $group['children'] ) ? $group['children'] : array();
			foreach ( $kinds as $kind ) {
				if ( ! is_array( $kind ) ) {
					continue;
				}
				$kind_name = isset( $kind['name'] ) ? (string) $kind['name'] : '';
				if ( '' === $kind_name ) {
					continue;
				}
				$kind_id = self::find_bauteil_kind_under( $taxonomy, $model_bauteil, $kind_name );
				if ( $kind_id <= 0 ) {
					continue;
				}
				$slots = isset( $kind['attributes'] ) && is_array( $kind['attributes'] )
					? $kind['attributes']
					: array();
				$part  = self::ensure_kind_attributes_from_slots( $taxonomy, $kind_id, $slots );
				$stats['added']    += $part['added'];
				$stats['skipped']  += $part['skipped'];
				$stats['stripped'] += $part['stripped'];
			}
		}

		return $stats;
	}

	/**
	 * @param list<array<string, mixed>> $slots
	 * @return array{added:int,skipped:int,stripped:int}
	 */
	private static function ensure_kind_attributes_from_slots( string $taxonomy, int $kind_id, array $slots ): array {
		$stats = array(
			'added'    => 0,
			'skipped'  => 0,
			'stripped' => 0,
		);
		if ( $kind_id <= 0 ) {
			return $stats;
		}

		$have = array();
		foreach ( Attribute::list_own( $taxonomy, $kind_id ) as $row ) {
			$key          = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
			$have[ $key ] = true;
		}

		$slot_names = array();
		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}
			$name = isset( $slot['name'] ) ? trim( (string) $slot['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$slot_names[ strtolower( $name ) ] = $name;

			$key = strtolower( $name );
			if ( ! empty( $have[ $key ] ) ) {
				++$stats['skipped'];
				continue;
			}

			$type_name = isset( $slot['type_name'] ) ? trim( (string) $slot['type_name'] ) : '';
			$type_id   = '' !== $type_name
				? Node_Type::find_type_by_name( $taxonomy, $kind_id, $type_name )
				: 0;
			if ( $type_id <= 0 ) {
				++$stats['skipped'];
				continue;
			}

			$required = ! empty( $slot['required'] );
			$mult     = $required ? '1' : Attribute::DEFAULT_MULTIPLICITY;

			$fixed_values = null;
			$fixed_name   = isset( $slot['fixed_node_name'] ) ? trim( (string) $slot['fixed_node_name'] ) : '';
			if ( '' !== $fixed_name ) {
				$fixed_id = Node_Type::find_fixed_by_name( $taxonomy, $kind_id, $fixed_name );
				if ( $fixed_id <= 0 ) {
					$fixed_id = Node_Type::find_type_by_name( $taxonomy, $kind_id, $fixed_name );
				}
				if ( $fixed_id > 0 ) {
					$fixed_values = (string) $fixed_id;
				}
			}

			$added = Attribute::add(
				$taxonomy,
				$kind_id,
				$name,
				$type_id,
				$mult,
				Attribute::DEFAULT_BINDING,
				false,
				$fixed_values
			);
			if ( is_wp_error( $added ) ) {
				++$stats['skipped'];
				continue;
			}
			$have[ $key ] = true;
			++$stats['added'];
		}

		/* Q111: Model/Bauteil kinds → aggregation (repair create-only leftovers). */
		foreach ( Attribute::list_own( $taxonomy, $kind_id ) as $row ) {
			$attr_id = Attribute::normalize_attr_id( $row['id'] ?? '' );
			if ( '' === $attr_id ) {
				continue;
			}
			Attribute::set_binding( $taxonomy, $kind_id, $attr_id, Attribute::DEFAULT_BINDING );
		}

		/* Remove leftover hierarchy field-children that mirror attribute slots. */
		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $kind_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $kids ) ) {
			return $stats;
		}
		foreach ( $kids as $kid ) {
			if ( ! $kid instanceof \WP_Term ) {
				continue;
			}
			$kid_id = (int) $kid->term_id;
			$key    = strtolower( trim( $kid->name ) );
			if ( ! isset( $slot_names[ $key ] ) ) {
				continue;
			}
			if ( Attribute::is_slot( $kid_id ) ) {
				continue;
			}
			Node_Type::set_deletable( $kid_id, true );
			$result = Tree_Model::delete_term( $taxonomy, $kid_id, 'leaf' );
			if ( ! is_wp_error( $result ) ) {
				++$stats['stripped'];
			}
		}

		return $stats;
	}

	/**
	 * Recursively collapse same-name siblings under $root_id (depth-limited).
	 *
	 * @return int Number of duplicate terms hard-deleted.
	 */
	public static function dedupe_named_children_subtree( string $taxonomy, int $root_id, int $max_depth = 6 ): int {
		if ( $root_id <= 0 ) {
			return 0;
		}

		$removed = self::dedupe_named_children( $taxonomy, $root_id );
		if ( $max_depth <= 0 ) {
			return $removed;
		}

		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $root_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		foreach ( (array) $kids as $kid ) {
			if ( ! $kid instanceof \WP_Term ) {
				continue;
			}
			$kid_id = (int) $kid->term_id;
			if ( Trash::is_trashed( $kid_id ) || Trash::is_trash_node( $kid_id ) ) {
				continue;
			}
			$removed += self::dedupe_named_children_subtree( $taxonomy, $kid_id, $max_depth - 1 );
		}

		return $removed;
	}

	/**
	 * Legacy band/field names that often leaked to taxonomy root (parent=0).
	 *
	 * @return list<string>
	 */
	public static function root_band_orphan_names(): array {
		return array(
			'Einheit',
			'Fuss',
			'Kopf',
			'Menge',
			'Name',
			'Präfix',
			'Reference',
			'Tabelle',
			'Wert',
			'Zeile',
		);
	}

	/**
	 * Hard-delete unreferenced parent=0 litter that matches known band/field orphan names.
	 *
	 * Never deletes attribute slots (Q87: slots live at parent=0 with `_wtt_attribute_slot`)
	 * or terms still referenced by Attribute bindings / prop bindings.
	 *
	 * @return int Number of terms deleted.
	 */
	public static function purge_root_band_orphans( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$orphan_names = array_fill_keys( self::root_band_orphan_names(), true );
		$referenced   = array_fill_keys( Attribute::collect_referenced_term_ids( $taxonomy ), true );
		$keep_names   = array(
			Case_Data::ROOT_NAME => true,
			self::ROOT_NAME      => true,
		);

		$roots = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => 0,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		$deleted = 0;
		foreach ( (array) $roots as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$tid = (int) $term->term_id;
			if ( $tid <= 0 || Trash::is_trash_node( $tid ) || Trash::is_trashed( $tid ) ) {
				continue;
			}
			if ( class_exists( Hidden_Nodes::class ) && Hidden_Nodes::is_bin( $tid ) ) {
				continue;
			}
			if ( isset( $keep_names[ $term->name ] ) ) {
				continue;
			}
			/* Q87 attribute slots are intentionally parent=0 — never purge them here. */
			if ( Attribute::is_slot( $tid ) ) {
				continue;
			}
			if ( isset( $referenced[ $tid ] ) ) {
				continue;
			}
			if ( ! isset( $orphan_names[ $term->name ] ) ) {
				continue;
			}

			self::force_deletable_subtree( $taxonomy, $tid );
			$result = self::delete_term_cascade( $taxonomy, $tid );
			if ( ! is_wp_error( $result ) ) {
				$deleted += (int) $result;
			}
		}

		return $deleted;
	}

	/**
	 * Collapse same-name siblings under one parent.
	 * Keeps the richest non-trashed term; hard-deletes losers (soft-trash leaves parent unchanged).
	 *
	 * @return int Number of duplicate terms removed.
	 */
	public static function dedupe_named_children( string $taxonomy, int $parent_id ): int {
		if ( $parent_id <= 0 ) {
			return 0;
		}

		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $kids ) || count( $kids ) < 2 ) {
			return 0;
		}

		$by_name = array();
		foreach ( $kids as $kid ) {
			if ( ! $kid instanceof \WP_Term ) {
				continue;
			}
			$key = $kid->name;
			if ( 'Diode' === $key ) {
				$key = 'Dioden';
			}
			$by_name[ $key ][] = $kid;
		}

		$removed = 0;
		foreach ( $by_name as $list ) {
			if ( count( $list ) < 2 ) {
				continue;
			}
			usort(
				$list,
				static function ( \WP_Term $a, \WP_Term $b ) use ( $taxonomy ): int {
					$ta = Trash::is_trashed( (int) $a->term_id ) ? 1 : 0;
					$tb = Trash::is_trashed( (int) $b->term_id ) ? 1 : 0;
					if ( $ta !== $tb ) {
						return $ta <=> $tb;
					}
					$ca = self::count_active_direct_children( $taxonomy, (int) $a->term_id );
					$cb = self::count_active_direct_children( $taxonomy, (int) $b->term_id );
					if ( $ca !== $cb ) {
						return $cb <=> $ca;
					}
					return (int) $a->term_id <=> (int) $b->term_id;
				}
			);
			$keep = array_shift( $list );
			if ( ! $keep instanceof \WP_Term ) {
				continue;
			}
			$keep_id = (int) $keep->term_id;
			if ( 'Diode' === $keep->name ) {
				wp_update_term( $keep_id, $taxonomy, array( 'name' => 'Dioden' ) );
			}
			foreach ( $list as $dup ) {
				if ( ! $dup instanceof \WP_Term ) {
					continue;
				}
				$dup_id = (int) $dup->term_id;
				self::retarget_type_id_refs( $taxonomy, $dup_id, $keep_id );
				self::force_deletable_subtree( $taxonomy, $dup_id );
				$deleted = self::delete_term_cascade( $taxonomy, $dup_id );
				if ( ! is_wp_error( $deleted ) ) {
					$removed += (int) $deleted;
				}
			}
		}

		return $removed;
	}

	/**
	 * Mark a subtree deletable so seed cleanup can hard-delete duplicate kinds.
	 */
	private static function force_deletable_subtree( string $taxonomy, int $term_id ): void {
		Node_Type::set_deletable( $term_id, true );
		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		foreach ( (array) $kids as $kid_id ) {
			self::force_deletable_subtree( $taxonomy, (int) $kid_id );
		}
	}

	private static function count_direct_children( string $taxonomy, int $parent_id ): int {
		$ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		return is_array( $ids ) ? count( $ids ) : 0;
	}

	private static function count_active_direct_children( string $taxonomy, int $parent_id ): int {
		$ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $ids ) ) {
			return 0;
		}
		$n = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( Trash::is_trashed( $id ) ) {
				continue;
			}
			++$n;
		}
		return $n;
	}

	/**
	 * Remap nodes typed to $from_id so they point at $to_id (MPN records after kind dedupe).
	 */
	public static function retarget_type_id_refs( string $taxonomy, int $from_id, int $to_id ): void {
		if ( $from_id <= 0 || $to_id <= 0 || $from_id === $to_id ) {
			return;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
				'meta_key'   => Node_Type::META_KEY,
				'meta_value' => (string) $from_id,
			)
		);
		if ( ! is_array( $terms ) ) {
			return;
		}
		foreach ( $terms as $tid ) {
			Node_Type::set_type_id( $taxonomy, (int) $tid, $to_id );
		}
	}

	/**
	 * If MPN examples were nested under a kind folder, lift them to Bauteile root (keep kind + slots).
	 */
	public static function lift_catalog_examples_from_kinds( string $taxonomy, int $bauteile_id ): void {
		if ( $bauteile_id <= 0 ) {
			return;
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
			$kind_id = (int) $kid->term_id;
			if ( self::is_catalog_example( $kind_id ) ) {
				continue;
			}
			$grand = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $kind_id,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			foreach ( (array) $grand as $g ) {
				if ( ! $g instanceof \WP_Term ) {
					continue;
				}
				$gid = (int) $g->term_id;
				if ( ! self::is_catalog_example( $gid ) ) {
					continue;
				}
				wp_update_term(
					$gid,
					$taxonomy,
					array(
						'parent' => $bauteile_id,
					)
				);
				Node_Type::set_type_id( $taxonomy, $gid, $kind_id );
			}
		}
	}

	/**
	 * Move Definition/Bauteilarten kinds under Implementation/Bauteile; drop empty Bauteilarten.
	 *
	 * @return int Number of kinds reparented.
	 */
	public static function merge_bauteilarten_into_bauteile( string $taxonomy, int $bauteile_id ): int {
		if ( $bauteile_id <= 0 ) {
			return 0;
		}

		$moved = 0;
		$arten_paths = array(
			array( 'Fallstudie', 'Definition', 'Bauteilarten' ),
			array( 'Fallstudie', 'Bauteilarten' ),
			array( self::ROOT_NAME, 'Bauteilarten' ),
			array( self::ROOT_NAME, 'Definition', 'Bauteilarten' ),
		);
		$arten_ids = array();
		foreach ( $arten_paths as $path ) {
			$id = self::find_term_by_path( $taxonomy, $path );
			if ( $id > 0 ) {
				$arten_ids[ $id ] = true;
			}
		}

		foreach ( array_keys( $arten_ids ) as $arten_id ) {
			if ( $arten_id === $bauteile_id ) {
				continue;
			}
			$kinds = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $arten_id,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			foreach ( (array) $kinds as $kind ) {
				if ( ! $kind instanceof \WP_Term ) {
					continue;
				}
				$kind_id   = (int) $kind->term_id;
				$existing  = self::find_direct_child_named( $taxonomy, $bauteile_id, $kind->name );
				if ( $existing > 0 && $existing !== $kind_id ) {
					/* Prefer kind already under Bauteile; drop duplicate schema shell. */
					self::strip_bauteil_kind_supplier_slots( $taxonomy, $kind_id );
					Node_Type::set_deletable( $kind_id, true );
					Tree_Model::delete_term( $taxonomy, $kind_id, 'cascade' );
					continue;
				}
				$term = get_term( $kind_id, $taxonomy );
				if ( $term instanceof \WP_Term && (int) $term->parent !== $bauteile_id ) {
					$upd = wp_update_term(
						$kind_id,
						$taxonomy,
						array(
							'parent' => $bauteile_id,
						)
					);
					if ( ! is_wp_error( $upd ) ) {
						++$moved;
					}
				}
				self::strip_bauteil_kind_supplier_slots( $taxonomy, $kind_id );
			}

			/* Drop empty Bauteilarten folder. */
			$left = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $arten_id,
					'hide_empty' => false,
					'number'     => 1,
				)
			);
			if ( empty( $left ) ) {
				Node_Type::set_deletable( $arten_id, true );
				Tree_Model::delete_term( $taxonomy, $arten_id, 'leaf' );
			}
		}

		return $moved;
	}

	/**
	 * Remove Lieferant / Bestellnummer / Hersteller / Diodentyp from a kind.
	 */
	public static function strip_bauteil_kind_supplier_slots( string $taxonomy, int $kind_id ): void {
		if ( $kind_id <= 0 ) {
			return;
		}

		$drop = array(
			'lieferant'     => true,
			'bestellnummer' => true,
			'hersteller'    => true,
			'diodentyp'     => true,
		);

		foreach ( Attribute::list_own( $taxonomy, $kind_id ) as $row ) {
			$key = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
			$aid = Attribute::normalize_attr_id( $row['id'] ?? '' );
			if ( '' !== $aid && isset( $drop[ $key ] ) ) {
				Attribute::remove( $taxonomy, $kind_id, $aid );
			}
		}

		foreach ( array( 'Lieferant', 'Bestellnummer', 'Hersteller', 'Diodentyp' ) as $name ) {
			$child_id = self::find_direct_child_named( $taxonomy, $kind_id, $name );
			if ( $child_id <= 0 ) {
				continue;
			}
			Node_Type::set_deletable( $child_id, true );
			Tree_Model::delete_term( $taxonomy, $child_id, 'leaf' );
		}
	}

	/**
	 * Strip commercial / obsolete slots from every kind under Model/Bauteil (incl. groups).
	 */
	public static function strip_all_bauteil_supplier_slots( string $taxonomy, int $parent_id ): void {
		if ( $parent_id <= 0 ) {
			return;
		}
		$kind_names = array_fill_keys( array_keys( self::bauteil_example_seed() ), true );
		$kind_names['Diode'] = true;
		$group_names = array_fill_keys( array_values( self::bauteil_kind_group_map() ), true );

		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
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
			if ( isset( $group_names[ $kid->name ] ) ) {
				self::strip_all_bauteil_supplier_slots( $taxonomy, $kid_id );
				continue;
			}
			if ( ! isset( $kind_names[ $kid->name ] ) && ! Node_Type::is_under_type_catalog( $taxonomy, $kid_id ) ) {
				continue;
			}
			self::strip_bauteil_kind_supplier_slots( $taxonomy, $kid_id );
		}
	}

	/**
	 * Move kind schemas from Implementation/Bauteile (and legacy Bauteilarten) under Model/Bauteil.
	 *
	 * @return int Number of kinds reparented.
	 */
	public static function merge_kinds_into_model_bauteil( string $taxonomy, int $model_bauteil_id ): int {
		if ( $model_bauteil_id <= 0 ) {
			return 0;
		}

		$kind_names = array_fill_keys( array_keys( self::bauteil_example_seed() ), true );
		$kind_names['Diode'] = true;

		$sources = array();
		$bauteile_paths = array(
			array( 'Fallstudie', 'Implementation', 'Bauteile' ),
			array( self::ROOT_NAME, 'Implementation', 'Bauteile' ),
			array( self::ROOT_NAME, 'Bauteile' ),
		);
		foreach ( $bauteile_paths as $path ) {
			$id = self::find_term_by_path( $taxonomy, $path );
			if ( $id > 0 ) {
				$sources[ $id ] = true;
			}
		}
		foreach (
			array(
				array( 'Fallstudie', 'Definition', 'Bauteilarten' ),
				array( self::ROOT_NAME, 'Definition', 'Bauteilarten' ),
				array( self::ROOT_NAME, 'Bauteilarten' ),
			) as $path
		) {
			$id = self::find_term_by_path( $taxonomy, $path );
			if ( $id > 0 ) {
				$sources[ $id ] = true;
			}
		}

		$moved = 0;
		foreach ( array_keys( $sources ) as $source_id ) {
			if ( $source_id === $model_bauteil_id ) {
				continue;
			}
			$kids = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $source_id,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			foreach ( (array) $kids as $kid ) {
				if ( ! $kid instanceof \WP_Term ) {
					continue;
				}
				$kind_id = (int) $kid->term_id;
				if ( self::is_catalog_example( $kind_id ) ) {
					continue;
				}
				$canonical = $kid->name;
				if ( 'Diode' === $canonical ) {
					$canonical = 'Dioden';
				}
				if ( ! isset( $kind_names[ $canonical ] ) && ! isset( $kind_names[ $kid->name ] ) ) {
					if ( ! Node_Type::is_under_type_catalog( $taxonomy, $kind_id ) ) {
						continue;
					}
				}

				$existing = self::find_bauteil_kind_under( $taxonomy, $model_bauteil_id, $canonical );
				if ( $existing <= 0 && 'Dioden' === $canonical ) {
					$existing = self::find_bauteil_kind_under( $taxonomy, $model_bauteil_id, 'Diode' );
				}

				$group_map  = self::bauteil_kind_group_map();
				$group_name = $group_map[ $canonical ] ?? ( $group_map[ $kid->name ] ?? '' );
				$group_id   = '' !== $group_name
					? self::find_direct_child_named( $taxonomy, $model_bauteil_id, $group_name )
					: 0;
				$target_parent = $group_id > 0 ? $group_id : $model_bauteil_id;

				if ( $existing > 0 && $existing !== $kind_id ) {
					self::strip_bauteil_kind_supplier_slots( $taxonomy, $kind_id );
					/* Prefer Model host; drop duplicate schema shell under Bauteile. */
					Node_Type::set_deletable( $kind_id, true );
					Tree_Model::delete_term( $taxonomy, $kind_id, 'cascade' );
					self::strip_bauteil_kind_supplier_slots( $taxonomy, $existing );
					continue;
				}

				$term = get_term( $kind_id, $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$update = array( 'parent' => $target_parent );
				if ( 'Diode' === $term->name ) {
					$update['name'] = 'Dioden';
				}
				if ( (int) $term->parent !== $target_parent || isset( $update['name'] ) ) {
					$upd = wp_update_term( $kind_id, $taxonomy, $update );
					if ( ! is_wp_error( $upd ) ) {
						++$moved;
					}
				}
				Node_Type::apply_parent_as_type( $taxonomy, $kind_id );
				self::strip_bauteil_kind_supplier_slots( $taxonomy, $kind_id );
			}
		}

		self::ensure_bauteil_kind_groups( $taxonomy, $model_bauteil_id );

		return $moved;
	}

	/**
	 * Install kinds under Model/Bauteil; Bauteile keeps MPN records only.
	 *
	 * @param array<int, string> $arten_parent_path    Unused (BC).
	 * @param array<int, string> $bauteile_parent_path Parent of Bauteile (Implementation).
	 */
	public static function ensure_bauteile_split(
		string $taxonomy,
		array $arten_parent_path,
		array $bauteile_parent_path
	): void {
		unset( $arten_parent_path );
		$bau_parent = self::find_term_by_path( $taxonomy, $bauteile_parent_path );
		if ( $bau_parent <= 0 ) {
			return;
		}

		$created  = 0;
		$existing = 0;
		self::install_node_tree(
			$taxonomy,
			array( self::bauteile_implementation_node() ),
			$bau_parent,
			$created,
			$existing
		);

		$model_bauteil = self::find_model_bauteil_id( $taxonomy );
		if ( $model_bauteil > 0 ) {
			self::ensure_bauteil_kind_groups( $taxonomy, $model_bauteil );
			self::merge_kinds_into_model_bauteil( $taxonomy, $model_bauteil );
			self::ensure_bauteil_kind_groups( $taxonomy, $model_bauteil );
			self::ensure_bauteil_kind_attributes( $taxonomy );
			self::strip_all_bauteil_supplier_slots( $taxonomy, $model_bauteil );
			Node_Type::apply_parent_as_type( $taxonomy, $model_bauteil );
		}

		$bau_path = array_merge( $bauteile_parent_path, array( 'Bauteile' ) );
		$bauteile = self::find_term_by_path( $taxonomy, $bau_path );
		self::merge_bauteilarten_into_bauteile( $taxonomy, $bauteile );
		if ( $bauteile > 0 && $model_bauteil > 0 ) {
			/* Move any remaining kinds off Bauteile onto Model/Bauteil. */
			self::merge_kinds_into_model_bauteil( $taxonomy, $model_bauteil );
			self::lift_catalog_examples_from_kinds( $taxonomy, $bauteile );
		}
		self::ensure_set_composition_members( $taxonomy );
		if ( $model_bauteil > 0 ) {
			self::strip_all_bauteil_supplier_slots( $taxonomy, $model_bauteil );
		}
		self::ensure_bauteil_examples( $taxonomy, $bau_path );
	}

	/**
	 * @deprecated Use lift_catalog_examples_from_kinds + merge_kinds_into_model_bauteil.
	 */
	public static function migrate_legacy_bauteile_kind_folders(
		string $taxonomy,
		int $bauteile_id,
		int $bauteilarten_id
	): void {
		unset( $bauteilarten_id );
		self::lift_catalog_examples_from_kinds( $taxonomy, $bauteile_id );
		$model = self::find_model_bauteil_id( $taxonomy );
		if ( $model > 0 ) {
			self::merge_kinds_into_model_bauteil( $taxonomy, $model );
			self::strip_all_bauteil_supplier_slots( $taxonomy, $model );
		}
		self::strip_all_bauteil_supplier_slots( $taxonomy, $bauteile_id );
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
			'Dioden'         => array(
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
		self::strip_demo_obsolete_simple_aliases( $taxonomy );
		self::migrate_basiseinheit_wert_to_typ( $taxonomy );
		self::migrate_abmessung_t_to_h( $taxonomy );
		self::ensure_prefix_multiplikators( $taxonomy );
		self::ensure_short_descriptions( $taxonomy );
		self::ensure_bom_bauteil_ref_scope( $taxonomy );
		self::migrate_subtree_type_to_node_embed( $taxonomy );
		/* Q90 / OQ-W15: ensure_node_pick_type_group is a no-op (parked types purged). */
		self::ensure_node_pick_type_group( $taxonomy );
		self::ensure_datatype_flags( $taxonomy );
		Catalog_Bindings::ensure( $taxonomy );
		self::ensure_relation_types( $taxonomy );
		self::ensure_knoten_datatype( $taxonomy );
		self::ensure_root_typed_knoten( $taxonomy );
		self::ensure_set_composition_members( $taxonomy );
		$bauteile_id = self::find_term_by_path( $taxonomy, array( self::ROOT_NAME, 'Bauteile' ) );
		self::merge_bauteilarten_into_bauteile( $taxonomy, $bauteile_id );
		self::strip_all_bauteil_supplier_slots( $taxonomy, $bauteile_id );
		self::ensure_bauteil_examples(
			$taxonomy,
			array( self::ROOT_NAME, 'Bauteile' )
		);
		self::ensure_lieferanten_catalog(
			$taxonomy,
			array( self::ROOT_NAME )
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
	 * Shape: enum → Bauart → Option typed as text → closed options.
	 *
	 * @return array<string, mixed>
	 */
	public static function bauart_enum_node(): array {
		return array(
			'name'        => 'Bauart',
			'description' => 'Concrete enum for footprints / package styles (0201, 0603, axial, …).',
			'deletable'   => false,
			'children'    => array(
				array(
					'name'        => 'Option',
					'description' => 'Single enum column - type text (proto).',
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
	 * Ensure Bauart CatalogChoice host under Data Types/Complex (idempotent).
	 * Formerly nested under parked Complex/enum (Q90) — reparents when still there.
	 *
	 * @return int Bauart term id, or 0 on failure.
	 */
	public static function ensure_bauart_enum( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$complex_id = 0;
		foreach (
			array(
				array( 'Fallstudie', 'Definition', 'Data Types', 'Complex' ),
				array( 'Fallstudie', 'Definition', 'Datentypen', 'Complex' ),
				array( 'Fallstudie', 'Definition', 'Complex' ),
			) as $path
		) {
			$id = self::find_term_by_path( $taxonomy, $path );
			if ( $id > 0 ) {
				$complex_id = $id;
				break;
			}
		}
		if ( $complex_id <= 0 ) {
			return 0;
		}

		/* Prefer Bauart already under Complex; else under legacy enum. */
		$bauart_id = self::find_direct_child_named( $taxonomy, $complex_id, 'Bauart' );
		if ( $bauart_id <= 0 ) {
			$enum_id = self::find_enum_catalog_id( $taxonomy );
			if ( $enum_id > 0 ) {
				$bauart_id = self::find_direct_child_named( $taxonomy, $enum_id, 'Bauart' );
				if ( $bauart_id > 0 ) {
					wp_update_term( $bauart_id, $taxonomy, array( 'parent' => $complex_id ) );
				}
			}
		}

		if ( $bauart_id <= 0 ) {
			$created  = 0;
			$existing = 0;
			self::install_node_tree(
				$taxonomy,
				array( self::bauart_enum_node() ),
				$complex_id,
				$created,
				$existing
			);
			$bauart_id = self::find_direct_child_named( $taxonomy, $complex_id, 'Bauart' );
		}

		if ( $bauart_id <= 0 ) {
			return 0;
		}

		Trash::restore_subtree( $taxonomy, $bauart_id );
		Node_Type::set_deletable( $bauart_id, false );

		/* Q90: drop stale type meta pointing at parked catalog `enum` (effective type = parent Complex). */
		$bauart_type = Node_Type::get_type_id( $bauart_id );
		if ( $bauart_type > 0 ) {
			$type_term = get_term( $bauart_type, $taxonomy );
			if ( $type_term instanceof \WP_Term && 0 === strcasecmp( $type_term->name, 'enum' ) ) {
				delete_term_meta( $bauart_id, Node_Type::META_KEY );
			}
		}

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
			if ( ($id > 0 && get_term( $id, $taxonomy ) instanceof \WP_Term) ) {
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
				'number'     => 0,
			)
		);
		if ( ! is_array( $found ) ) {
			return 0;
		}
		foreach ( $found as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$tid = (int) $term->term_id;
			if ( Trash::is_trashed( $tid ) ) {
				continue;
			}
			return $tid;
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
		Node_Type::set_type_id( $taxonomy, $root, $knoten, true );
	}

	/**
	 * @see Relation::migrate_composition_type_name()
	 */
	public static function migrate_composition_relation_type_name( string $taxonomy, int $folder_id = 0 ): void {
		unset( $folder_id );
		Relation::migrate_composition_type_name( $taxonomy );
	}

	/**
	 * Lock seeded catalog templates + Relationstypen.
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
			foreach ( array( 'child_of', 'ref_scope', 'besteht_aus', 'aggregation' ) as $name ) {
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
	 * Q77 / Q92: no type-role or abstract flags — chooser scope is bindings only.
	 * Kept as a named seed hook for callers; intentionally a no-op.
	 */
	public static function ensure_datatype_flags( string $taxonomy ): void {
		unset( $taxonomy );
	}

	/**
	 * Q73 / OQ-W15 / Q90: formerly ensured Complex/node_pick/{node_embed,node_ref}.
	 * Parked — no-op. Fallstudie purge: Case_Data::ensure_complex_datatypes.
	 */
	public static function ensure_node_pick_type_group( string $taxonomy ): void {
		unset( $taxonomy );
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
			Node_Type::set_deletable( $email, false );
			Node_Type::ensure_validators( $taxonomy, $email );
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
			Node_Type::set_deletable( $date, false );
			if ( ! metadata_exists( 'term', $date, Node_Type::META_KEY_DATE_MODE ) ) {
				Node_Type::set_date_mode( $taxonomy, $date, 'date' );
			}
			Node_Type::ensure_validators( $taxonomy, $date );
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
			Node_Type::ensure_validators( $taxonomy, $media );
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
	 * Trash leftover Simple alias siblings (e.g. display_node_name after node_presentation).
	 */
	private static function strip_demo_obsolete_simple_aliases( string $taxonomy ): void {
		$paths = array(
			array( self::ROOT_NAME, 'Typen', 'Datentypen', 'Simple' ),
			array( self::ROOT_NAME, 'Definition', 'Data Types', 'Simple' ),
			array( self::ROOT_NAME, 'Data Types', 'Simple' ),
		);
		$pres_id = 0;
		foreach ( $paths as $path ) {
			$simple_id = self::find_term_by_path( $taxonomy, $path );
			if ( $simple_id > 0 ) {
				Case_Data::strip_obsolete_simple_datatype_aliases( $taxonomy, $simple_id );
				if ( $pres_id <= 0 ) {
					$found = get_terms(
						array(
							'taxonomy'   => $taxonomy,
							'parent'     => $simple_id,
							'name'       => 'node_presentation',
							'hide_empty' => false,
							'number'     => 1,
						)
					);
					if ( is_array( $found ) && isset( $found[0] ) && $found[0] instanceof \WP_Term ) {
						$pres_id = (int) $found[0]->term_id;
					}
				}
			}
		}
		if ( $pres_id <= 0 ) {
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => 'node_presentation',
					'hide_empty' => false,
					'number'     => 1,
				)
			);
			if ( is_array( $found ) && isset( $found[0] ) && $found[0] instanceof \WP_Term ) {
				$pres_id = (int) $found[0]->term_id;
			}
		}
		if ( $pres_id > 0 ) {
			Case_Data::purge_legacy_display_node_name_terms( $taxonomy, $pres_id );
		}
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

			Node_Type::ensure_preferred_render( $taxonomy, $term_id );
			Node_Type::ensure_preferred_converter( $taxonomy, $term_id );
			Node_Type::ensure_validators( $taxonomy, $term_id );

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

			if ( array_key_exists( 'is_template', $node ) ) {
				Node_Type::set_is_template( $taxonomy, $term_id, (bool) $node['is_template'] );
			} elseif ( array_key_exists( 'deletable', $node ) && false === (bool) $node['deletable'] ) {
				/* Seeded protected catalog → is_template (#5 lock signal). */
				Node_Type::set_is_template( $taxonomy, $term_id, true );
			}
			if ( array_key_exists( 'deletable', $node ) ) {
				Node_Type::set_deletable( $term_id, (bool) $node['deletable'] );
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
