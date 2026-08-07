<?php
/**
 * Case-study taxonomy tree seeder (Definition / Implementation).
 *
 * Slim parallel to Demo_Data — Definition / Implementation + Relationstypen for composition.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs the Fallstudie hierarchy under wtt_fs (idempotent).
 */
final class Case_Data {

	public const ROOT_NAME = 'Fallstudie';

	/**
	 * @return array<int, array{name:string, description?:string, children?:array<int, array<string, mixed>>}>
	 */
	/**
	 * Scalar catalog under Definition/Data Types/Simple (parity with BOM Datentypen/Simple).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function simple_datatype_leaves(): array {
		return array(
			array( 'name' => 'int', 'description' => 'Whole number.', 'is_datatype' => true ),
			array( 'name' => 'double', 'description' => 'Floating point.', 'is_datatype' => true ),
			array( 'name' => 'text', 'description' => 'Single-line text.', 'is_datatype' => true ),
			array( 'name' => 'email', 'description' => 'Email address (validated input).', 'is_datatype' => true ),
			array( 'name' => 'textarea', 'description' => 'Multi-line text.', 'is_datatype' => true ),
			array( 'name' => 'char', 'description' => 'Single character.', 'is_datatype' => true ),
			array( 'name' => 'bool', 'description' => 'Boolean.', 'is_datatype' => true ),
			array(
				'name'        => 'date',
				'description' => 'Calendar date or date+time (mode on type). Store: Unix timestamp.',
				'is_datatype' => true,
				'date_mode'   => 'date',
			),
			array(
				'name'        => 'display_node_name',
				'description' => 'Read-only: shows the host Node.name (no user input).',
				'is_datatype' => true,
			),
			array(
				'name'        => 'media',
				'description' => 'WP Media Library and/or URL (Q65). MIME-based display.',
				'is_datatype' => true,
			),
		);
	}

	/**
	 * Collection / pick catalog under Definition/Data Types/Complex.
	 * Keys stay English (`list`, `enum`, …) — type pickers and Node_Type match by name.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function complex_datatype_leaves(): array {
		return array(
			array(
				'name'              => 'quantity',
				'description'       => 'Größe: value + optional prefix + base unit (not a measurement act; not BOM Menge). Alias: measure.',
				'short_description' => 'Größe',
				'is_datatype'       => true,
				'deletable'         => false,
				/* Former informal names → rename in place via ensure_term. */
				'aliases'           => array( 'measure', 'Größe', 'Groesse' ),
			),
			array(
				'name'        => 'list',
				'description' => 'Collection with exactly one column; rows open.',
				'is_datatype' => true,
				'deletable'   => false,
			),
			array(
				'name'        => 'table',
				'description' => 'Collection with n columns; rows open.',
				'is_datatype' => true,
				'deletable'   => false,
			),
			array(
				'name'        => 'enum',
				'description' => 'Like list + closed options under the column.',
				'is_datatype' => true,
				'deletable'   => false,
				'children'    => array(
					Demo_Data::bauart_enum_node(),
				),
			),
			array(
				'name'        => 'set',
				'description' => 'Collection of named members; schema = child nodes.',
				'is_datatype' => true,
				'deletable'   => false,
			),
			array(
				'name'        => 'node_pick',
				'description' => 'Shared parent (Q73): ref_scope + allowed catalog children.',
				'is_datatype' => true,
				'is_abstract' => true,
				'deletable'   => false,
				'children'    => array(
					array(
						'name'        => 'node_embed',
						'description' => 'Pick under catalog root; embed target fields.',
						'is_datatype' => true,
						'deletable'   => false,
					),
					array(
						'name'        => 'node_ref',
						'description' => 'Pick under catalog root; store id only.',
						'is_datatype' => true,
						'deletable'   => false,
					),
				),
			),
		);
	}

	/**
	 * Package / footprint constants under Definition/Konstanten/Bauformen.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function bauformen_catalog_leaves(): array {
		$leaf = static function ( string $name, string $description ): array {
			return array(
				'name'        => $name,
				'description' => $description,
				'is_datatype' => true,
				'is_abstract' => false,
				'deletable'   => false,
			);
		};

		return array(
			$leaf( 'Durchloch Axial', 'Leads left/right of body (e.g. resistors, older capacitors).' ),
			$leaf( 'Durchloch Radial', 'Both leads on one side (e.g. many capacitors).' ),
			$leaf( 'SMD 0201', 'Imperial 0201 (metric 0603).' ),
			$leaf( 'SMD 0402', 'Imperial 0402 (metric 1005).' ),
			$leaf( 'SMD 0603', 'Imperial 0603 (metric 1608).' ),
			$leaf( 'SMD 0805', 'Imperial 0805 (metric 2012).' ),
			$leaf( 'SMD 1206', 'Imperial 1206 (metric 3216).' ),
		);
	}

	public static function blueprint(): array {
		$simple_leaves  = self::simple_datatype_leaves();
		$complex_leaves = self::complex_datatype_leaves();

		/*
		 * Display names mirror the live Fallstudie tree (full SI names).
		 * short_description keeps the letter symbol (p/n/u/…). aliases = obsolete
		 * short seed names so ensure_term renames instead of duplicating.
		 */
		$prefix_leaves = array(
			array(
				'name'              => 'pico',
				'aliases'           => array( 'p' ),
				'short_description' => 'p',
				'description'       => 'SI prefix pico (10⁻¹²).',
				'multiplikator'     => 1.0e-12,
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'nano',
				'aliases'           => array( 'n' ),
				'short_description' => 'n',
				'description'       => 'SI prefix nano (10⁻⁹).',
				'multiplikator'     => 1.0e-9,
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Micro',
				'aliases'           => array( 'u', 'µ', 'micro' ),
				'short_description' => 'u',
				'description'       => 'SI prefix micro (10⁻⁶).',
				'multiplikator'     => 1.0e-6,
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Milli',
				'aliases'           => array( 'm', 'milli' ),
				'short_description' => 'm',
				'description'       => 'SI prefix milli (10⁻³); with Meter → mm.',
				'multiplikator'     => 1.0e-3,
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Centi',
				'aliases'           => array( 'c', 'centi' ),
				'short_description' => 'c',
				'description'       => 'SI prefix centi (10⁻²).',
				'multiplikator'     => 1.0e-2,
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Kilo',
				'aliases'           => array( 'k', 'kilo' ),
				'short_description' => 'k',
				'description'       => 'SI prefix kilo (10³).',
				'multiplikator'     => 1.0e3,
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Mega',
				'aliases'           => array( 'M' ),
				'short_description' => 'Mega',
				'description'       => 'SI prefix mega (10⁶); name Mega avoids slug clash with milli m.',
				'multiplikator'     => 1.0e6,
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
		);

		$bauformen_leaves = self::bauformen_catalog_leaves();

		$unit_leaves = array(
			array(
				'name'              => 'Meter',
				'short_description' => 'm',
				'description'       => 'Length; prefixes Micro/Milli/Centi/Kilo.',
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Liter',
				'short_description' => 'l',
				'description'       => 'Volume; prefixes Milli/Centi/Kilo.',
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Kilogramm',
				'short_description' => 'g',
				'description'       => 'SI base kg; prefixes attach to gram (mg/kg/Mg).',
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Sekunde',
				'short_description' => 's',
				'description'       => 'Time; prefixes pico/nano/Micro/Milli.',
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Kelvin',
				'short_description' => 'K',
				'description'       => 'Thermodynamic temperature; no prefixes.',
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Celsius',
				'short_description' => '°C',
				'description'       => 'Celsius temperature; no SI prefixes.',
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Ampere',
				'short_description' => 'A',
				'description'       => 'Electric current.',
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Ohm',
				'short_description' => 'Ω',
				'description'       => 'Resistance; k+Ω → kΩ.',
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Farad',
				'short_description' => 'F',
				'description'       => 'Capacitance; no k/Mega.',
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Watt',
				'short_description' => 'W',
				'description'       => 'Power.',
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Volt',
				'short_description' => 'V',
				'description'       => 'Voltage.',
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Henry',
				'short_description' => 'H',
				'description'       => 'Inductance; DigiKey Inductors / Coils / Chokes.',
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Hertz',
				'short_description' => 'Hz',
				'description'       => 'Frequency; crystals / oscillators.',
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
			array(
				'name'              => 'Stück',
				'short_description' => 'Stk',
				'description'       => 'Count / Menge; no prefixes.',
				'is_datatype'       => true,
				'is_abstract'       => false,
			),
		);

		return array(
			array(
				'name'        => self::ROOT_NAME,
				'description' => 'Case-study Project: Definition (types + constants) and empty Implementation. Parallel to BOM Testprojekt — not a model sign-off.',
				'children'    => array(
					array(
						'name'        => 'Definition',
						'description' => 'Type catalog and constant folders for the case study.',
						'children'    => array(
							array(
								'name'        => 'Knoten',
								'description' => 'General assignable node type (project roots and ordinary nodes).',
								'is_datatype' => true,
								'is_abstract' => false,
							),
							array(
								'name'        => 'Data Types',
								'description' => 'Simple (scalars) and Complex (collections / picks).',
								'is_datatype' => true,
								'is_abstract' => true,
								'children'    => array(
									array(
										'name'        => 'Simple',
										'description' => 'Scalar and simple reference types.',
										'is_datatype' => true,
										'is_abstract' => true,
										'children'    => $simple_leaves,
									),
									array(
										'name'        => 'Complex',
										'description' => 'Collection kinds (list / table / enum / set).',
										'is_datatype' => true,
										'is_abstract' => true,
										'children'    => $complex_leaves,
									),
								),
							),
							array(
								'name'        => 'Konstanten',
								'description' => 'SI prefixes, base units, and package / Bauformen catalog.',
								'children'    => array(
									array(
										'name'        => 'Präfixe',
										'description' => 'SI prefixes. multiplikator = scale vs the unit’s prefix root (Q51).',
										'is_datatype' => true,
										'is_abstract' => true,
										'deletable'   => false,
										'children'    => $prefix_leaves,
									),
									array(
										'name'        => 'Basiseinheiten',
										'description' => 'Base units (catalog). Short description = symbol / Kuerzel.',
										'is_datatype' => true,
										'is_abstract' => true,
										'deletable'   => false,
										'children'    => $unit_leaves,
									),
									array(
										'name'        => 'Bauformen',
										'description' => 'Package / footprint constants (axial, radial, SMD sizes).',
										'is_datatype' => true,
										'is_abstract' => true,
										'deletable'   => false,
										'children'    => $bauformen_leaves,
									),
								),
							),
							array(
								'name'        => 'Eigene Datentypen',
								'description' => 'User-defined types (empty).',
							),
							Demo_Data::bauteilarten_catalog_node(),
						),
					),
					array(
						'name'        => 'Implementation',
						'description' => 'Instance / project content (BOM definition sample).',
						'children'    => array(
							array(
								'name'        => 'BOM',
								'description' => 'Zusammenstellungs-Definition: composition of Name + Tabelle (Q61).',
								'children'    => array(
									array(
										'name'        => 'Name',
										'type_name'   => 'text',
										'description' => 'Instance title field (filled on WP page).',
									),
									array(
										'name'        => 'Tabelle',
										'type_name'   => 'table',
										'description' => 'Typed table: Zeile required; Kopf/Fuss optional.',
										'children'    => array(
											array(
												'name'        => 'Zeile',
												'description' => 'Required body band; 1..n fields.',
												'children'    => array(
													array(
														'name'        => 'Reference',
														'type_name'   => 'text',
														'description' => 'Board references (e.g. R1,R2).',
													),
													array(
														'name'        => 'Wert',
														'type_name'   => 'text',
														'description' => 'Value / rating display.',
													),
													array(
														'name'        => 'Menge',
														'type_name'   => 'int',
														'description' => 'Quantity (Stück).',
													),
												),
											),
										),
									),
								),
							),
							Demo_Data::bauteile_implementation_node(),
							Demo_Data::lieferanten_catalog_node(),
						),
					),
				),
			),
		);
	}

	/**
	 * @return array{created:int,existing:int,taxonomy:string}|\WP_Error
	 */
	public static function install( string $taxonomy = Taxonomy::FS ) {
		if ( Taxonomy::FS !== $taxonomy ) {
			return new \WP_Error(
				'wtt_bad_taxonomy',
				__( 'Case study seed only applies to wtt_fs.', 'wp-taxonomy-tree' )
			);
		}

		if ( ! Tree_Model::is_hierarchical_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Not a hierarchical taxonomy.', 'wp-taxonomy-tree' ) );
		}

		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			return new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) );
		}

		$created  = 0;
		$existing = 0;
		self::install_nodes( $taxonomy, self::blueprint(), 0, $created, $existing );
		self::ensure_relation_types( $taxonomy );
		self::ensure_knoten_datatype( $taxonomy );
		self::ensure_root_typed_knoten( $taxonomy );
		Trash::ensure_trash_node( $taxonomy );
		self::ensure_konstanten( $taxonomy );
		Attribute::migrate_detach_hierarchy( $taxonomy );
		self::ensure_simple_datatypes( $taxonomy );
		self::ensure_email_datatype( $taxonomy );
		self::ensure_date_datatype( $taxonomy );
		self::ensure_complex_datatypes( $taxonomy );
		Catalog_Bindings::ensure( $taxonomy );
		self::ensure_bauart_enum( $taxonomy );
		self::ensure_table_datatype_bands( $taxonomy );
		self::ensure_aggregate_catalog( $taxonomy );
		self::ensure_bom_implementation( $taxonomy );
		self::ensure_bauteile_catalog( $taxonomy );
		self::ensure_kontakt_model( $taxonomy );
		self::ensure_platine_model( $taxonomy );
		Demo_Data::ensure_set_composition_members( $taxonomy );
		self::ensure_deletable_flags( $taxonomy );
		self::ensure_root_typed_knoten( $taxonomy );
		Node_Type::ensure_hierarchy_datatype_inheritance( $taxonomy );

		return array(
			'created'  => $created,
			'existing' => $existing,
			'taxonomy' => $taxonomy,
		);
	}

	/**
	 * Seed when the case taxonomy has no terms yet; always refresh Relationstypen.
	 */
	public static function maybe_install( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			self::install( $taxonomy );
		}

		self::ensure_relation_types( $taxonomy );
		self::ensure_knoten_datatype( $taxonomy );
		self::ensure_root_typed_knoten( $taxonomy );
		Trash::ensure_trash_node( $taxonomy );
		self::ensure_konstanten( $taxonomy );
		Attribute::migrate_detach_hierarchy( $taxonomy );
		self::ensure_simple_datatypes( $taxonomy );
		self::ensure_email_datatype( $taxonomy );
		self::ensure_date_datatype( $taxonomy );
		self::ensure_complex_datatypes( $taxonomy );
		Catalog_Bindings::ensure( $taxonomy );
		self::ensure_bauart_enum( $taxonomy );
		self::ensure_table_datatype_bands( $taxonomy );
		self::ensure_aggregate_catalog( $taxonomy );
		self::ensure_bom_implementation( $taxonomy );
		self::ensure_bauteile_catalog( $taxonomy );
		self::ensure_kontakt_model( $taxonomy );
		self::ensure_platine_model( $taxonomy );
		Demo_Data::ensure_set_composition_members( $taxonomy );
		Demo_Data::strip_distributor_samples_under_enum( $taxonomy );
		self::ensure_deletable_flags( $taxonomy );
		self::ensure_root_typed_knoten( $taxonomy );
		Node_Type::ensure_hierarchy_datatype_inheritance( $taxonomy );
	}

	/**
	 * Ensure concrete enum Bauart under Complex/enum; retype Passiv/Bauart attribute when mistyped.
	 *
	 * @return int Bauart catalog term id, or 0.
	 */
	public static function ensure_bauart_enum( string $taxonomy = Taxonomy::FS ): int {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$bauart_id = Demo_Data::ensure_bauart_enum( $taxonomy );
		if ( $bauart_id <= 0 ) {
			return 0;
		}

		self::ensure_passiv_bauart_attribute_typed( $taxonomy, $bauart_id );

		return $bauart_id;
	}

	/**
	 * Model/Bauteil/Passiv/Bauart is an attribute slot — type it to the Bauart enum
	 * when still unbound or wrongly bound to Konstanten/Bauformen folders.
	 */
	private static function ensure_passiv_bauart_attribute_typed( string $taxonomy, int $bauart_type_id ): void {
		if ( $bauart_type_id <= 0 ) {
			return;
		}

		$attr_id = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Model', 'Bauteil', 'Passiv', 'Bauart' )
		);
		if ( $attr_id <= 0 || $attr_id === $bauart_type_id ) {
			return;
		}

		$current = Node_Type::get_type_id( $attr_id );
		if ( $current === $bauart_type_id ) {
			return;
		}

		$rewrite = ( $current <= 0 );
		if ( $current > 0 ) {
			$cur_term = get_term( $current, $taxonomy );
			if ( $cur_term instanceof \WP_Term ) {
				$rewrite = in_array( $cur_term->name, array( 'Konstanten', 'Bauformen', 'Präfixe' ), true );
			}
		}

		if ( $rewrite ) {
			Node_Type::set_type_id( $taxonomy, $attr_id, $bauart_type_id );
		}
	}

	/**
	 * Lock seeded catalog datatypes + Relationstypen; user nodes stay deletable by default.
	 */
	public static function ensure_deletable_flags( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$extra = array();
		$folder = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, Relation::ROOT_NAME )
		);
		if ( $folder > 0 ) {
			$extra[] = $folder;
			$seeds   = array( 'child_of', 'has_type', 'ref_scope', 'besteht_aus', 'composition', 'aggregation' );
			foreach ( $seeds as $name ) {
				$id = Relation::find_type_id_by_name( $taxonomy, $name );
				if ( $id > 0 ) {
					$extra[] = $id;
				}
			}
		}

		Node_Type::lock_seeded_catalog_deletable( $taxonomy, $extra );
	}

	/**
	 * Ensure Definition/Konstanten with Präfixe + Basiseinheiten + Bauformen (idempotent).
	 * Creates the Konstanten folder when Definition exists but Konstanten was removed (e.g. Q85 clear).
	 */
	public static function ensure_konstanten( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$blueprint = self::blueprint();
		$root      = isset( $blueprint[0] ) && is_array( $blueprint[0] ) ? $blueprint[0] : null;
		if ( null === $root ) {
			return;
		}

		$definition = null;
		foreach ( (array) ( $root['children'] ?? array() ) as $child ) {
			if ( is_array( $child ) && 'Definition' === ( $child['name'] ?? '' ) ) {
				$definition = $child;
				break;
			}
		}
		if ( null === $definition ) {
			return;
		}

		$konstanten = null;
		foreach ( (array) ( $definition['children'] ?? array() ) as $child ) {
			if ( is_array( $child ) && 'Konstanten' === ( $child['name'] ?? '' ) ) {
				$konstanten = $child;
				break;
			}
		}
		if ( null === $konstanten ) {
			return;
		}

		$definition_id = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Definition' )
		);
		if ( $definition_id <= 0 ) {
			return;
		}

		$created  = 0;
		$existing = 0;
		$parent_id = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Definition', 'Konstanten' )
		);
		if ( $parent_id <= 0 ) {
			/* Recreate Konstanten + children in one pass (folder was deleted). */
			self::install_nodes( $taxonomy, array( $konstanten ), $definition_id, $created, $existing );
			$parent_id = self::find_term_by_path(
				$taxonomy,
				array( self::ROOT_NAME, 'Definition', 'Konstanten' )
			);
		} else {
			$folders = isset( $konstanten['children'] ) && is_array( $konstanten['children'] )
				? $konstanten['children']
				: array();
			self::install_nodes( $taxonomy, $folders, $parent_id, $created, $existing );
		}

		if ( $parent_id > 0 ) {
			/* Soft-deleted catalogs stay invisible in get_tree until restored. */
			Trash::restore_subtree( $taxonomy, $parent_id );
			Node_Type::set_deletable( $parent_id, false );
			self::configure_konstanten_bauformen( $taxonomy, $parent_id );
			self::strip_obsolete_prefix_aliases( $taxonomy, $parent_id );
		}

		Demo_Data::ensure_prefix_multiplikators( $taxonomy );
	}

	/**
	 * Map of obsolete short SI seed names → canonical display names under Präfixe.
	 *
	 * @return array<string, string>
	 */
	private static function obsolete_prefix_alias_map(): array {
		return array(
			'p'     => 'pico',
			'n'     => 'nano',
			'u'     => 'Micro',
			'µ'     => 'Micro',
			'micro' => 'Micro',
			'm'     => 'Milli',
			'milli' => 'Milli',
			'c'     => 'Centi',
			'centi' => 'Centi',
			'k'     => 'Kilo',
			'kilo'  => 'Kilo',
		);
	}

	/**
	 * Remove obsolete short-name Präfixe siblings when the renamed canonical exists.
	 * Keeps the live renamed terms (pico/nano/…); moves leftovers to Trash.
	 */
	private static function strip_obsolete_prefix_aliases( string $taxonomy, int $konstanten_id ): void {
		$praefixe_id = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Definition', 'Konstanten', 'Präfixe' )
		);
		if ( $praefixe_id <= 0 ) {
			$praefixe_id = self::find_child_named( $taxonomy, $konstanten_id, 'Präfixe' );
		}
		if ( $praefixe_id <= 0 ) {
			$praefixe_id = self::find_child_named( $taxonomy, $konstanten_id, 'Praefixe' );
		}
		if ( $praefixe_id <= 0 ) {
			return;
		}

		foreach ( self::obsolete_prefix_alias_map() as $obsolete => $canonical ) {
			if ( $obsolete === $canonical ) {
				continue;
			}
			$obs_id = self::find_child_named( $taxonomy, $praefixe_id, $obsolete );
			if ( $obs_id <= 0 ) {
				continue;
			}
			$can_id = self::find_child_named( $taxonomy, $praefixe_id, $canonical );
			if ( $can_id <= 0 || $can_id === $obs_id ) {
				continue;
			}
			/* Prefer keeping the canonical; trash the obsolete short duplicate. */
			Node_Type::set_deletable( $obs_id, true );
			Tree_Model::delete_term( $taxonomy, $obs_id, 'leaf' );
		}
	}

	/**
	 * Flags + restore for Konstanten/Bauformen catalog folder and leaves.
	 */
	private static function configure_konstanten_bauformen( string $taxonomy, int $konstanten_id ): void {
		$bauformen_id = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Definition', 'Konstanten', 'Bauformen' )
		);
		if ( $bauformen_id <= 0 ) {
			/* Fallback: direct child of Konstanten (path may lag after create). */
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => 'Bauformen',
					'parent'     => $konstanten_id,
					'hide_empty' => false,
					'number'     => 1,
				)
			);
			if ( is_array( $found ) && isset( $found[0] ) && $found[0] instanceof \WP_Term ) {
				$bauformen_id = (int) $found[0]->term_id;
			}
		}
		if ( $bauformen_id <= 0 ) {
			return;
		}

		Trash::restore_subtree( $taxonomy, $bauformen_id );
		Node_Type::set_is_datatype( $taxonomy, $bauformen_id, true );
		Node_Type::set_is_abstract( $taxonomy, $bauformen_id, true );
		Node_Type::set_deletable( $bauformen_id, false );

		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $bauformen_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $kids ) ) {
			return;
		}
		foreach ( $kids as $kid ) {
			if ( ! $kid instanceof \WP_Term ) {
				continue;
			}
			$kid_id = (int) $kid->term_id;
			Trash::restore_subtree( $taxonomy, $kid_id );
			Node_Type::set_is_datatype( $taxonomy, $kid_id, true );
			Node_Type::set_is_abstract( $taxonomy, $kid_id, false );
			Node_Type::set_deletable( $kid_id, false );
		}
	}

	/**
	 * Q74/Q75: Relationstypen under Fallstudie (composition + protected system types).
	 */
	public static function ensure_relation_types( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$root = self::find_term_by_path( $taxonomy, array( self::ROOT_NAME ) );
		if ( $root <= 0 ) {
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => self::ROOT_NAME,
					'parent'     => 0,
					'hide_empty' => false,
					'number'     => 1,
				)
			);
			if ( is_array( $found ) && isset( $found[0] ) && $found[0] instanceof \WP_Term ) {
				$root = (int) $found[0]->term_id;
			}
		}
		if ( $root <= 0 ) {
			return;
		}

		$created  = 0;
		$existing = 0;
		$folder   = self::ensure_term(
			$taxonomy,
			Relation::ROOT_NAME,
			$root,
			'RelationType catalog (Q35/Q54/Q74). Additive types such as composition are chosen here.',
			$created,
			$existing
		);
		if ( $folder <= 0 ) {
			return;
		}

		$seeds = array(
			'child_of'     => 'Hierarchy Kind von (system). Multiplicity always 1 (exactly one parent). Not creatable via Add relation — use Reparent.',
			'has_type'     => 'Data-type binding (has_type). Managed via Relations UI; persists as type_id (Q74).',
			'ref_scope'    => 'Catalog root for node_embed / node_ref (system).',
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
		Relation::migrate_composition_type_name( $taxonomy );
		Relation::migrate_drop_erbt_von( $taxonomy );
		Relation::repair_child_of_multiplicity( $taxonomy );
		Node_Type::set_is_abstract( $taxonomy, $folder, true );
		Node_Type::set_is_datatype( $taxonomy, $folder, false );
		Node_Type::set_deletable( $folder, false );
	}

	/**
	 * General datatype "Knoten" under Definition (sibling of Data Types).
	 */
	public static function ensure_knoten_datatype( string $taxonomy = Taxonomy::FS ): int {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$existing = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Definition', 'Knoten' )
		);
		if ( $existing > 0 ) {
			Node_Type::set_is_datatype( $taxonomy, $existing, true );
			Node_Type::set_is_abstract( $taxonomy, $existing, false );
			Node_Type::set_deletable( $existing, false );
			return $existing;
		}

		$parent = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Definition' )
		);
		if ( $parent <= 0 ) {
			return 0;
		}

		$created    = 0;
		$existing_n = 0;
		$id         = self::ensure_term(
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
	public static function ensure_root_typed_knoten( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$root   = self::find_term_by_path( $taxonomy, array( self::ROOT_NAME ) );
		$knoten = self::ensure_knoten_datatype( $taxonomy );
		if ( $root <= 0 || $knoten <= 0 ) {
			return;
		}
		Node_Type::set_type_id( $taxonomy, $root, $knoten );
	}

	/**
	 * Resolve a term id by name path under the case taxonomy (root-first).
	 *
	 * @param list<string> $path
	 */
	public static function find_term_by_path( string $taxonomy, array $path ): int {
		$parent = 0;
		foreach ( $path as $name ) {
			$name = (string) $name;
			if ( '' === $name ) {
				return 0;
			}
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => $name,
					'parent'     => $parent,
					'hide_empty' => false,
					'number'     => 1,
				)
			);
			if ( ! is_array( $found ) || ! isset( $found[0] ) || ! $found[0] instanceof \WP_Term ) {
				return 0;
			}
			$parent = (int) $found[0]->term_id;
		}
		return $parent;
	}

	/**
	 * Delete Fallstudie root and reinstall.
	 *
	 * @return array{deleted:int,created:int,existing:int,taxonomy:string}|\WP_Error
	 */
	public static function reset( string $taxonomy = Taxonomy::FS ) {
		if ( Taxonomy::FS !== $taxonomy ) {
			return new \WP_Error(
				'wtt_bad_taxonomy',
				__( 'Case study reset only applies to wtt_fs.', 'wp-taxonomy-tree' )
			);
		}

		if ( ! Tree_Model::is_hierarchical_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Not a hierarchical taxonomy.', 'wp-taxonomy-tree' ) );
		}

		$can_edit   = ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( Capabilities::edit_terms( $taxonomy ) );
		$can_delete = ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( Capabilities::delete_terms( $taxonomy ) );
		if ( ! $can_edit || ! $can_delete ) {
			return new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) );
		}

		$deleted = self::delete_root_by_name( $taxonomy, self::ROOT_NAME );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		$install = self::install( $taxonomy );
		if ( is_wp_error( $install ) ) {
			return $install;
		}

		self::ensure_relation_types( $taxonomy );
		self::ensure_knoten_datatype( $taxonomy );
		self::ensure_root_typed_knoten( $taxonomy );
		Trash::ensure_trash_node( $taxonomy );
		self::ensure_konstanten( $taxonomy );
		Attribute::migrate_detach_hierarchy( $taxonomy );
		/* install() already ran complex/simple ensures; refresh bands after reset. */
		self::ensure_complex_datatypes( $taxonomy );
		self::ensure_bauart_enum( $taxonomy );
		self::ensure_table_datatype_bands( $taxonomy );
		self::ensure_aggregate_catalog( $taxonomy );
		self::ensure_bom_implementation( $taxonomy );
		self::ensure_bauteile_catalog( $taxonomy );
		Demo_Data::ensure_set_composition_members( $taxonomy );

		return array(
			'deleted'  => $deleted,
			'created'  => $install['created'],
			'existing' => $install['existing'],
			'taxonomy' => $taxonomy,
		);
	}

	/**
	 * Candidate paths for the Simple scalar catalog (canonical first, legacy next).
	 *
	 * Canonical: Definition/Data Types/Simple. Legacy: Datentypen/Simple and a
	 * Definition/Simple sibling from older blueprints (purged by ensure).
	 *
	 * @return list<list<string>>
	 */
	private static function simple_catalog_paths(): array {
		return array(
			array( self::ROOT_NAME, 'Definition', 'Data Types', 'Simple' ),
			array( self::ROOT_NAME, 'Definition', 'Datentypen', 'Simple' ),
			array( self::ROOT_NAME, 'Definition', 'Simple' ),
		);
	}

	/**
	 * Ensure the Simple scalar catalog exists and leaf term names match the seed
	 * (idempotent). Canonical SoT is Definition/Data Types/Simple; a legacy
	 * Definition/Simple sibling is merged away so the type picker has one catalog.
	 *
	 * @return array{created:int,existing:int,repaired:int,removed:int}
	 */
	public static function ensure_simple_datatypes( string $taxonomy = Taxonomy::FS ): array {
		$out = array(
			'created'  => 0,
			'existing' => 0,
			'repaired' => 0,
			'removed'  => 0,
		);
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return $out;
		}

		$found_ids = array();
		foreach ( self::simple_catalog_paths() as $path ) {
			$id = self::find_term_by_path( $taxonomy, $path );
			if ( $id > 0 ) {
				$found_ids[] = $id;
			}
		}
		$found_ids = array_values( array_unique( $found_ids ) );

		/*
		 * Rename on datatype catalogs first (picker SoT), then others.
		 * Avoids marking a ghost leaf as datatype while the real leaf is still
		 * wrongly named (unique-name gate would then accept the ghost).
		 */
		$ordered = $found_ids;
		usort(
			$ordered,
			static function ( int $a, int $b ) use ( $taxonomy ): int {
				$da = Node_Type::is_datatype( $taxonomy, $a ) ? 0 : 1;
				$db = Node_Type::is_datatype( $taxonomy, $b ) ? 0 : 1;
				return $da <=> $db;
			}
		);
		foreach ( $ordered as $simple_id ) {
			$out['repaired'] += self::repair_simple_datatype_leaf_names( $taxonomy, $simple_id );
		}

		$simple = self::resolve_canonical_simple_catalog( $taxonomy, $found_ids );
		if ( $simple <= 0 ) {
			$created    = 0;
			$existing   = 0;
			$data_types = self::ensure_data_types_folder( $taxonomy );
			if ( $data_types <= 0 ) {
				return $out;
			}
			$simple = self::ensure_term(
				$taxonomy,
				'Simple',
				$data_types,
				'Scalar and simple reference types.',
				$created,
				$existing
			);
			if ( $simple <= 0 ) {
				return $out;
			}
			Node_Type::set_is_datatype( $taxonomy, $simple, true );
			Node_Type::set_is_abstract( $taxonomy, $simple, true );
			Node_Type::set_deletable( $simple, false );
		} elseif ( ! Node_Type::is_datatype( $taxonomy, $simple ) ) {
			Node_Type::set_is_datatype( $taxonomy, $simple, true );
			Node_Type::set_is_abstract( $taxonomy, $simple, true );
			Node_Type::set_deletable( $simple, false );
		}

		$created  = 0;
		$existing = 0;
		self::install_nodes( $taxonomy, self::simple_datatype_leaves(), $simple, $created, $existing );
		$out['repaired'] += self::repair_simple_datatype_leaf_names( $taxonomy, $simple );
		$out['created']   = $created;
		$out['existing']  = $existing;

		/* Merge + remove legacy Definition/Simple (not under Data Types). */
		$out['removed'] += self::purge_legacy_definition_simple( $taxonomy, $simple );

		return $out;
	}

	/**
	 * Candidate paths for the Complex catalog (canonical first, legacy next).
	 *
	 * @return list<list<string>>
	 */
	private static function complex_catalog_paths(): array {
		return array(
			array( self::ROOT_NAME, 'Definition', 'Data Types', 'Complex' ),
			array( self::ROOT_NAME, 'Definition', 'Datentypen', 'Complex' ),
			array( self::ROOT_NAME, 'Definition', 'Complex' ),
		);
	}

	/**
	 * Ensure Complex collection/pick catalog leaves exist (idempotent).
	 * Restores soft-deleted list/enum/table/set/node_pick so the attribute
	 * Type chooser can pick `enum` / `list` again.
	 *
	 * @return array{created:int,existing:int,restored:int}
	 */
	public static function ensure_complex_datatypes( string $taxonomy = Taxonomy::FS ): array {
		$out = array(
			'created'  => 0,
			'existing' => 0,
			'restored' => 0,
		);
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return $out;
		}

		$complex = 0;
		foreach ( self::complex_catalog_paths() as $path ) {
			$id = self::find_term_by_path( $taxonomy, $path );
			if ( $id > 0 ) {
				$complex = $id;
				break;
			}
		}

		if ( $complex <= 0 ) {
			$data_types = self::ensure_data_types_folder( $taxonomy );
			if ( $data_types <= 0 ) {
				return $out;
			}
			$created  = 0;
			$existing = 0;
			$complex  = self::ensure_term(
				$taxonomy,
				'Complex',
				$data_types,
				'Collection kinds (list / table / enum / set).',
				$created,
				$existing
			);
			if ( $complex <= 0 ) {
				return $out;
			}
		}

		Node_Type::set_is_datatype( $taxonomy, $complex, true );
		Node_Type::set_is_abstract( $taxonomy, $complex, true );
		Node_Type::set_deletable( $complex, false );
		$out['restored'] += Trash::restore_subtree( $taxonomy, $complex );

		$created  = 0;
		$existing = 0;
		self::install_nodes( $taxonomy, self::complex_datatype_leaves(), $complex, $created, $existing );
		$out['created']  = $created;
		$out['existing'] = $existing;

		/* Flags + untrash each catalog leaf (and node_pick children). */
		foreach ( self::complex_datatype_leaves() as $leaf ) {
			$name = isset( $leaf['name'] ) ? (string) $leaf['name'] : '';
			if ( '' === $name ) {
				continue;
			}
			$leaf_id = self::find_child_named( $taxonomy, $complex, $name );
			if ( $leaf_id <= 0 ) {
				continue;
			}
			$out['restored'] += Trash::restore_subtree( $taxonomy, $leaf_id );
			Node_Type::set_is_datatype( $taxonomy, $leaf_id, true );
			Node_Type::set_deletable( $leaf_id, false );
			if ( array_key_exists( 'is_abstract', $leaf ) ) {
				Node_Type::set_is_abstract( $taxonomy, $leaf_id, (bool) $leaf['is_abstract'] );
			} else {
				Node_Type::set_is_abstract( $taxonomy, $leaf_id, false );
			}
			$kids = isset( $leaf['children'] ) && is_array( $leaf['children'] ) ? $leaf['children'] : array();
			foreach ( $kids as $kid ) {
				$kid_name = isset( $kid['name'] ) ? (string) $kid['name'] : '';
				if ( '' === $kid_name ) {
					continue;
				}
				$kid_id = self::find_child_named( $taxonomy, $leaf_id, $kid_name );
				if ( $kid_id <= 0 ) {
					continue;
				}
				$out['restored'] += Trash::restore_subtree( $taxonomy, $kid_id );
				Node_Type::set_is_datatype( $taxonomy, $kid_id, true );
				Node_Type::set_deletable( $kid_id, false );
				Node_Type::set_is_abstract(
					$taxonomy,
					$kid_id,
					array_key_exists( 'is_abstract', $kid ) ? (bool) $kid['is_abstract'] : false
				);
			}
		}

		return $out;
	}

	/**
	 * Pick the Simple catalog to keep: Data Types/Datentypen first, else any is_datatype.
	 *
	 * @param list<int> $found_ids Candidate Simple term ids.
	 */
	private static function resolve_canonical_simple_catalog( string $taxonomy, array $found_ids ): int {
		$preferred = array(
			self::find_term_by_path(
				$taxonomy,
				array( self::ROOT_NAME, 'Definition', 'Data Types', 'Simple' )
			),
			self::find_term_by_path(
				$taxonomy,
				array( self::ROOT_NAME, 'Definition', 'Datentypen', 'Simple' )
			),
		);
		foreach ( $preferred as $id ) {
			if ( $id > 0 ) {
				return $id;
			}
		}

		foreach ( $found_ids as $simple_id ) {
			if ( Node_Type::is_datatype( $taxonomy, $simple_id ) ) {
				return $simple_id;
			}
		}

		foreach ( $found_ids as $simple_id ) {
			if ( $simple_id > 0 ) {
				return $simple_id;
			}
		}

		return 0;
	}

	/**
	 * Ensure Definition/Data Types folder exists (parent of Simple + Complex).
	 * Marks the folder as abstract datatype so the type forest nests Simple/Complex under it.
	 */
	private static function ensure_data_types_folder( string $taxonomy ): int {
		$def = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Definition' )
		);
		if ( $def <= 0 ) {
			return 0;
		}

		$existing = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Definition', 'Data Types' )
		);
		if ( $existing <= 0 ) {
			$legacy = self::find_term_by_path(
				$taxonomy,
				array( self::ROOT_NAME, 'Definition', 'Datentypen' )
			);
			if ( $legacy > 0 ) {
				$existing = $legacy;
			}
		}
		if ( $existing <= 0 ) {
			$created  = 0;
			$count    = 0;
			$existing = self::ensure_term(
				$taxonomy,
				'Data Types',
				$def,
				'Simple (scalars) and Complex (collections / picks).',
				$created,
				$count
			);
		}
		if ( $existing > 0 ) {
			Node_Type::set_is_datatype( $taxonomy, $existing, true );
			Node_Type::set_is_abstract( $taxonomy, $existing, true );
		}
		return $existing > 0 ? $existing : 0;
	}

	/**
	 * Remove Definition/Simple when Data Types/Simple (or Datentypen/Simple) is SoT.
	 * Repoints type_id refs from ghost leaves to matching canonical leaves first.
	 *
	 * @return int Number of terms hard-deleted.
	 */
	private static function purge_legacy_definition_simple( string $taxonomy, int $canonical_simple ): int {
		if ( $canonical_simple <= 0 ) {
			return 0;
		}

		$ghost = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Definition', 'Simple' )
		);
		if ( $ghost <= 0 || $ghost === $canonical_simple ) {
			return 0;
		}

		/* Safety: only purge when canonical is under Data Types / Datentypen. */
		$canon_term = get_term( $canonical_simple, $taxonomy );
		if ( ! $canon_term instanceof \WP_Term ) {
			return 0;
		}
		$parent = get_term( (int) $canon_term->parent, $taxonomy );
		if ( ! $parent instanceof \WP_Term ) {
			return 0;
		}
		$parent_name = (string) $parent->name;
		if ( 'Data Types' !== $parent_name && 'Datentypen' !== $parent_name ) {
			return 0;
		}

		self::merge_simple_catalog_refs( $taxonomy, $ghost, $canonical_simple );

		$deleted = self::hard_delete_term_cascade( $taxonomy, $ghost );
		return is_wp_error( $deleted ) ? 0 : (int) $deleted;
	}

	/**
	 * Repoint type_id from each ghost Simple leaf to the same-named canonical leaf.
	 */
	private static function merge_simple_catalog_refs( string $taxonomy, int $from_simple, int $to_simple ): void {
		if ( $from_simple <= 0 || $to_simple <= 0 || $from_simple === $to_simple ) {
			return;
		}

		$from_kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $from_simple,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $from_kids ) ) {
			return;
		}

		foreach ( $from_kids as $kid ) {
			if ( ! $kid instanceof \WP_Term ) {
				continue;
			}
			$to_leaf = self::find_simple_catalog_leaf( $taxonomy, $to_simple, (string) $kid->name );
			if ( $to_leaf <= 0 ) {
				continue;
			}
			self::repoint_type_id( $taxonomy, (int) $kid->term_id, $to_leaf );
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
	 * Permanently delete a term and its descendants (seed cleanup; not Trash).
	 *
	 * @return int|\WP_Error Number of terms deleted.
	 */
	private static function hard_delete_term_cascade( string $taxonomy, int $term_id ) {
		$deleted  = 0;
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_array( $children ) ) {
			foreach ( $children as $child ) {
				if ( ! $child instanceof \WP_Term ) {
					continue;
				}
				$nested = self::hard_delete_term_cascade( $taxonomy, (int) $child->term_id );
				if ( is_wp_error( $nested ) ) {
					return $nested;
				}
				$deleted += $nested;
			}
		}

		/* Seed ghosts may be locked non-deletable; unlock before hard delete. */
		Node_Type::set_deletable( $term_id, true );
		$result = wp_delete_term( $term_id, $taxonomy );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result || 0 === $result ) {
			return new \WP_Error( 'wtt_delete_failed', __( 'Could not delete legacy Simple catalog.', 'wp-taxonomy-tree' ) );
		}

		return $deleted + 1;
	}

	/**
	 * Restore catalog leaf names when a datatype was renamed in the UI but the
	 * slug still identifies the seed leaf (e.g. name Namen, slug text-simple).
	 * Does not set is_datatype — install_nodes / the install target owns flags.
	 *
	 * @return int Number of terms renamed.
	 */
	public static function repair_simple_datatype_leaf_names( string $taxonomy, int $simple_id ): int {
		if ( $simple_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$repaired = 0;
		foreach ( self::simple_datatype_leaves() as $leaf ) {
			$want = isset( $leaf['name'] ) ? (string) $leaf['name'] : '';
			if ( '' === $want ) {
				continue;
			}
			$child_id = self::find_simple_catalog_leaf( $taxonomy, $simple_id, $want );
			if ( $child_id <= 0 ) {
				continue;
			}
			$term = get_term( $child_id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$update = array();
			if ( $term->name !== $want ) {
				$update['name'] = $want;
			}
			if ( array_key_exists( 'description', $leaf ) && '' !== (string) $leaf['description'] ) {
				$desc = (string) $leaf['description'];
				if ( Tree_Model::decode_term_description( (string) $term->description ) !== $desc ) {
					$update['description'] = $desc;
				}
			}
			if ( empty( $update ) ) {
				continue;
			}
			$result = wp_update_term( $child_id, $taxonomy, $update );
			if ( ! is_wp_error( $result ) && isset( $update['name'] ) ) {
				++$repaired;
			}
		}

		return $repaired;
	}

	/**
	 * Find a Simple catalog leaf by exact name, else by slug stem (name or name-*).
	 */
	private static function find_simple_catalog_leaf( string $taxonomy, int $simple_id, string $leaf_name ): int {
		$by_name = self::find_child_named( $taxonomy, $simple_id, $leaf_name );
		if ( $by_name > 0 ) {
			return $by_name;
		}

		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $simple_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $kids ) ) {
			return 0;
		}

		$needle = strtolower( $leaf_name );
		foreach ( $kids as $kid ) {
			if ( ! $kid instanceof \WP_Term ) {
				continue;
			}
			$slug = strtolower( (string) $kid->slug );
			if ( $slug === $needle || str_starts_with( $slug, $needle . '-' ) ) {
				return (int) $kid->term_id;
			}
		}

		return 0;
	}

	/**
	 * Seed Fallstudie/Model/Kontakt with attributes Name(text) + E-Mail(email).
	 * Attributes via Attribute::add (besteht_aus) — never child_of to the host.
	 *
	 * @return int Kontakt host term id, or 0.
	 */
	public static function ensure_kontakt_model( string $taxonomy = Taxonomy::FS ): int {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		self::ensure_email_datatype( $taxonomy );

		$root_id = self::find_term_by_path( $taxonomy, array( self::ROOT_NAME ) );
		if ( $root_id <= 0 ) {
			return 0;
		}

		$created  = 0;
		$existing = 0;
		$model_id = self::ensure_term(
			$taxonomy,
			'Model',
			$root_id,
			'Example schema hosts for Form/Table/Compact preview (Kontakt, Platine, …).',
			$created,
			$existing
		);
		if ( $model_id <= 0 ) {
			return 0;
		}

		$kontakt_id = self::ensure_term(
			$taxonomy,
			'Kontakt',
			$model_id,
			'Contact person schema: Name + E-Mail attributes.',
			$created,
			$existing
		);
		if ( $kontakt_id <= 0 ) {
			return 0;
		}

		$text_id  = self::find_case_datatype_id( $taxonomy, 'text' );
		$email_id = self::find_case_datatype_id( $taxonomy, 'email' );
		if ( $email_id <= 0 ) {
			$email_id = self::ensure_email_datatype( $taxonomy );
		}

		$have = array();
		foreach ( Attribute::list_own( $taxonomy, $kontakt_id ) as $row ) {
			$key            = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
			$have[ $key ] = true;
		}

		if ( $text_id > 0 && empty( $have['name'] ) ) {
			Attribute::add( $taxonomy, $kontakt_id, 'Name', $text_id );
		}
		if ( $email_id > 0 && empty( $have['e-mail'] ) && empty( $have['email'] ) ) {
			Attribute::add( $taxonomy, $kontakt_id, 'E-Mail', $email_id );
		}

		return $kontakt_id;
	}

	/**
	 * Seed Fallstudie/Model/Platine with attribute Name(text).
	 * Preview sample: Name = "Prototype PCB".
	 *
	 * @return int Platine host term id, or 0.
	 */
	public static function ensure_platine_model( string $taxonomy = Taxonomy::FS ): int {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$root_id = self::find_term_by_path( $taxonomy, array( self::ROOT_NAME ) );
		if ( $root_id <= 0 ) {
			return 0;
		}

		$created  = 0;
		$existing = 0;
		$model_id = self::ensure_term(
			$taxonomy,
			'Model',
			$root_id,
			'Example schema hosts for Form/Table preview (Kontakt, Platine, …).',
			$created,
			$existing
		);
		if ( $model_id <= 0 ) {
			return 0;
		}

		$platine_id = self::ensure_term(
			$taxonomy,
			'Platine',
			$model_id,
			'PCB / board schema: Name attribute (preview sample Prototype PCB).',
			$created,
			$existing
		);
		if ( $platine_id <= 0 ) {
			return 0;
		}

		$text_id = self::find_case_datatype_id( $taxonomy, 'text' );
		$have    = array();
		foreach ( Attribute::list_own( $taxonomy, $platine_id ) as $row ) {
			$key          = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
			$have[ $key ] = true;
		}

		if ( $text_id > 0 && empty( $have['name'] ) ) {
			Attribute::add( $taxonomy, $platine_id, 'Name', $text_id );
		}

		return $platine_id;
	}

	/**
	 * Ensure Simple → email datatype exists (idempotent). Retype E-Mail/Email fields when still text.
	 *
	 * @return int Email type term id, or 0.
	 */
	public static function ensure_email_datatype( string $taxonomy = Taxonomy::FS ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		self::ensure_simple_datatypes( $taxonomy );

		$simple = 0;
		foreach ( self::simple_catalog_paths() as $path ) {
			$id = self::find_term_by_path( $taxonomy, $path );
			if ( $id > 0 && Node_Type::is_datatype( $taxonomy, $id ) ) {
				$simple = $id;
				break;
			}
			if ( $id > 0 && 0 === $simple ) {
				$simple = $id;
			}
		}
		if ( $simple <= 0 ) {
			return 0;
		}

		$created  = 0;
		$existing = 0;
		$email_id = self::ensure_term(
			$taxonomy,
			'email',
			$simple,
			'Email address (validated input).',
			$created,
			$existing
		);
		if ( $email_id <= 0 ) {
			$email_id = self::find_simple_catalog_leaf( $taxonomy, $simple, 'email' );
		}
		if ( $email_id <= 0 ) {
			return 0;
		}

		Node_Type::set_is_datatype( $taxonomy, $email_id, true );
		Node_Type::set_deletable( $email_id, false );

		/* Retype common contact fields that were seeded as text. */
		foreach ( array( 'E-Mail', 'Email', 'e-mail', 'e_mail' ) as $field_name ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => $field_name,
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
				if ( (int) $term->term_id === $email_id ) {
					continue;
				}
				$type      = Node_Type::get_assignment( $taxonomy, (int) $term->term_id );
				$type_name = is_array( $type ) ? strtolower( (string) ( $type['name'] ?? '' ) ) : '';
				if ( '' === $type_name || 'text' === $type_name ) {
					Node_Type::set_type_id( $taxonomy, (int) $term->term_id, $email_id );
				}
			}
		}

		return $email_id;
	}

	/**
	 * Ensure Simple → date datatype exists (idempotent). Default mode: date.
	 *
	 * @return int Date type term id, or 0.
	 */
	public static function ensure_date_datatype( string $taxonomy = Taxonomy::FS ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		self::ensure_simple_datatypes( $taxonomy );

		$simple = 0;
		foreach ( self::simple_catalog_paths() as $path ) {
			$id = self::find_term_by_path( $taxonomy, $path );
			if ( $id > 0 && Node_Type::is_datatype( $taxonomy, $id ) ) {
				$simple = $id;
				break;
			}
			if ( $id > 0 && 0 === $simple ) {
				$simple = $id;
			}
		}
		if ( $simple <= 0 ) {
			return 0;
		}

		$created  = 0;
		$existing = 0;
		$date_id  = self::ensure_term(
			$taxonomy,
			'date',
			$simple,
			'Calendar date or date+time (mode on type). Store: Unix timestamp.',
			$created,
			$existing
		);
		if ( $date_id <= 0 ) {
			$date_id = self::find_simple_catalog_leaf( $taxonomy, $simple, 'date' );
		}
		if ( $date_id <= 0 ) {
			return 0;
		}

		Node_Type::set_is_datatype( $taxonomy, $date_id, true );
		Node_Type::set_deletable( $date_id, false );
		if ( ! metadata_exists( 'term', $date_id, Node_Type::META_KEY_DATE_MODE ) ) {
			Node_Type::set_date_mode( $taxonomy, $date_id, 'date' );
		}

		return $date_id;
	}

	/**
	 * Catalog datatype `table`: composition → Zeile (+ optional Kopf / Fuss band nodes).
	 * Bands are hierarchy children under `table` (org) and composition targets (membership).
	 * Strips obsolete English aliases Head/Line/Foot left from earlier experiments.
	 */
	public static function ensure_table_datatype_bands( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$table_id = 0;
		foreach (
			array(
				array( self::ROOT_NAME, 'Definition', 'Data Types', 'Complex', 'table' ),
				array( self::ROOT_NAME, 'Definition', 'Datentypen', 'Complex', 'table' ),
				array( self::ROOT_NAME, 'Definition', 'Complex', 'table' ),
			) as $table_path
		) {
			$table_id = self::find_term_by_path( $taxonomy, $table_path );
			if ( $table_id > 0 ) {
				break;
			}
		}
		if ( $table_id <= 0 ) {
			return;
		}

		$comp_type = Relation::find_type_id_by_name( $taxonomy, Relation::TYPE_COMPOSITION );
		if ( $comp_type <= 0 ) {
			return;
		}

		$created  = 0;
		$existing = 0;
		$bands    = array(
			'Zeile' => 'Required body band skeleton (fields filled on table-typed instances).',
			'Kopf'  => 'Optional header band; same field count as Zeile when used.',
			'Fuss'  => 'Optional footer band; same field count as Zeile when used.',
		);
		$band_ids = array();
		foreach ( $bands as $name => $description ) {
			$band_id = self::ensure_term( $taxonomy, $name, $table_id, $description, $created, $existing );
			if ( $band_id <= 0 ) {
				continue;
			}
			$band_ids[ strtolower( $name ) ] = $band_id;
			self::ensure_composition_edge( $taxonomy, $table_id, $comp_type, $band_id, 'Zeile' === $name ? '1' : '0..1' );
		}

		/* Catalog table: band SoT = prop bindings (names are labels only). */
		$bindings = array();
		foreach ( array( 'kopf', 'zeile', 'fuss' ) as $key ) {
			if ( isset( $band_ids[ $key ] ) ) {
				$bindings[ $key ] = $band_ids[ $key ];
			}
		}
		if ( ! empty( $bindings ) ) {
			Node_Type::set_prop_bindings( $taxonomy, $table_id, $bindings );
		}

		self::strip_obsolete_table_band_aliases( $taxonomy, $table_id );
	}

	/**
	 * Definition/Aggregate catalog for Fuss cell ops (Q57): none, text, sum, avg, min, max, count.
	 * Idempotent; locks seeded terms as non-deletable.
	 */
	public static function ensure_aggregate_catalog( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$definition_id = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Definition' )
		);
		if ( $definition_id <= 0 ) {
			return;
		}

		$created  = 0;
		$existing = 0;
		$folder   = self::ensure_term(
			$taxonomy,
			Footer_Ops::CATALOG_FOLDER,
			$definition_id,
			'Fuss aggregate operations (Q57). Op is chosen on each Fuss field slot; type stays the column value type.',
			$created,
			$existing
		);
		if ( $folder <= 0 ) {
			return;
		}
		Node_Type::set_deletable( $folder, false );

		foreach ( Footer_Ops::catalog() as $key => $def ) {
			$op_id = self::ensure_term(
				$taxonomy,
				(string) $key,
				$folder,
				(string) ( $def['label'] ?? $key ),
				$created,
				$existing
			);
			if ( $op_id <= 0 ) {
				continue;
			}
			Node_Type::set_deletable( $op_id, false );
			$symbol = (string) ( $def['symbol'] ?? '' );
			if ( '' !== $symbol && '—' !== $symbol ) {
				Tree_Model::set_short_description( $taxonomy, $op_id, $symbol );
			}
		}
	}

	/**
	 * Remove empty English band aliases under catalog table (Head/Line/Foot).
	 * Canonical names are Kopf / Zeile / Fuss.
	 */
	private static function strip_obsolete_table_band_aliases( string $taxonomy, int $table_id ): void {
		if ( $table_id <= 0 ) {
			return;
		}
		$aliases = array(
			'Head' => 'Kopf',
			'Line' => 'Zeile',
			'Foot' => 'Fuss',
		);
		foreach ( $aliases as $obsolete => $canonical ) {
			$obs_id = self::find_child_named( $taxonomy, $table_id, $obsolete );
			if ( $obs_id <= 0 ) {
				continue;
			}
			/* Keep if canonical missing — rename path not handled here. */
			if ( self::find_child_named( $taxonomy, $table_id, $canonical ) <= 0 ) {
				continue;
			}
			$kids = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $obs_id,
					'hide_empty' => false,
					'number'     => 1,
					'fields'     => 'ids',
				)
			);
			if ( is_array( $kids ) && ! empty( $kids ) ) {
				continue;
			}
			/* Drop any composition edge pointing at the obsolete band. */
			foreach ( Relation::list_outgoing( $taxonomy, $table_id ) as $edge ) {
				if ( (int) ( $edge['toId'] ?? 0 ) === $obs_id ) {
					Relation::remove(
						$taxonomy,
						$table_id,
						(int) ( $edge['typeId'] ?? 0 ),
						$obs_id,
						(string) ( $edge['id'] ?? '' )
					);
				}
			}
			Tree_Model::delete_term( $taxonomy, $obs_id, 'leaf' );
		}
	}

	/**
	 * Definition/Bauteilarten + Implementation/Bauteile + Lieferanten (Q83 split).
	 */
	public static function ensure_bauteile_catalog( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$impl_id = self::find_term_by_path( $taxonomy, array( self::ROOT_NAME, 'Implementation' ) );
		$def_id  = self::find_term_by_path( $taxonomy, array( self::ROOT_NAME, 'Definition' ) );
		if ( $impl_id <= 0 || $def_id <= 0 ) {
			return;
		}

		$created  = 0;
		$existing = 0;

		/* Ensure node_ref exists for Bauteil.Lieferant / Bauteil picks. */
		$complex_id = 0;
		foreach (
			array(
				array( self::ROOT_NAME, 'Definition', 'Data Types', 'Complex' ),
				array( self::ROOT_NAME, 'Definition', 'Datentypen', 'Complex' ),
				array( self::ROOT_NAME, 'Definition', 'Complex' ),
			) as $complex_path
		) {
			$complex_id = self::find_term_by_path( $taxonomy, $complex_path );
			if ( $complex_id > 0 ) {
				break;
			}
		}
		if ( $complex_id > 0 ) {
			Demo_Data::install_node_tree(
				$taxonomy,
				array(
					array(
						'name'        => 'node_pick',
						'description' => 'Shared parent (Q73): ref_scope + allowed catalog children.',
						'is_datatype' => true,
						'is_abstract' => true,
						'children'    => array(
							array(
								'name'        => 'node_embed',
								'description' => 'Pick under catalog root; embed target fields.',
								'is_datatype' => true,
							),
							array(
								'name'        => 'node_ref',
								'description' => 'Pick under catalog root; store id only.',
								'is_datatype' => true,
							),
						),
					),
				),
				$complex_id,
				$created,
				$existing
			);
		}

		Demo_Data::ensure_bauteile_split(
			$taxonomy,
			array( self::ROOT_NAME, 'Definition' ),
			array( self::ROOT_NAME, 'Implementation' )
		);
		Demo_Data::ensure_lieferanten_catalog(
			$taxonomy,
			array( self::ROOT_NAME, 'Implementation' ),
			array( self::ROOT_NAME, 'Definition', 'Bauteilarten' )
		);
	}

	/**
	 * Implementation → BOM = Name + Tabelle; Tabelle → Zeile → fields (idempotent).
	 */
	public static function ensure_bom_implementation( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$impl_id = self::find_term_by_path( $taxonomy, array( self::ROOT_NAME, 'Implementation' ) );
		if ( $impl_id <= 0 ) {
			return;
		}

		$comp_type = Relation::find_type_id_by_name( $taxonomy, Relation::TYPE_COMPOSITION );
		if ( $comp_type <= 0 ) {
			return;
		}

		$created  = 0;
		$existing = 0;

		/* Prefer canonical BOM; also adopt user-created "Bom" under Implementation. */
		$bom_id = self::find_term_by_path( $taxonomy, array( self::ROOT_NAME, 'Implementation', 'BOM' ) );
		if ( $bom_id <= 0 ) {
			$bom_id = self::find_child_named( $taxonomy, $impl_id, 'Bom' );
		}
		if ( $bom_id <= 0 ) {
			$bom_id = self::ensure_term(
				$taxonomy,
				'BOM',
				$impl_id,
				'Zusammenstellungs-Definition: composition of Name + Tabelle (Q61).',
				$created,
				$existing
			);
		}
		if ( $bom_id <= 0 ) {
			return;
		}

		$name_id = self::find_child_named( $taxonomy, $bom_id, 'Name' );
		if ( $name_id <= 0 ) {
			$name_id = self::ensure_term(
				$taxonomy,
				'Name',
				$bom_id,
				'Instance title field (filled on WP page).',
				$created,
				$existing
			);
		}
		self::ensure_type_named( $taxonomy, $name_id, 'text' );
		self::ensure_composition_edge( $taxonomy, $bom_id, $comp_type, $name_id, '1' );

		$tabelle_id = self::find_bom_table_child( $taxonomy, $bom_id );
		if ( $tabelle_id <= 0 ) {
			$tabelle_id = self::ensure_term(
				$taxonomy,
				'Tabelle',
				$bom_id,
				'Typed table: bind Zeile (and optional Kopf/Fuss) via type properties.',
				$created,
				$existing
			);
		}
		self::ensure_type_named( $taxonomy, $tabelle_id, 'table' );
		self::ensure_composition_edge( $taxonomy, $bom_id, $comp_type, $tabelle_id, '1' );
		self::strip_composition_to_table_catalog( $taxonomy, $tabelle_id );

		$bindings = Node_Type::get_prop_bindings( $tabelle_id );
		$zeile_id = isset( $bindings['zeile'] ) ? (int) $bindings['zeile'] : 0;
		if ( $zeile_id <= 0 ) {
			$zeile_id = self::find_child_named( $taxonomy, $tabelle_id, 'Zeile' );
		}
		if ( $zeile_id <= 0 ) {
			$kids = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $tabelle_id,
					'hide_empty' => false,
					'number'     => 0,
					'fields'     => 'ids',
				)
			);
			if ( is_array( $kids ) && 1 === count( $kids ) ) {
				$zeile_id = (int) $kids[0];
			}
		}
		if ( $zeile_id <= 0 ) {
			$zeile_id = self::ensure_term(
				$taxonomy,
				'Zeile',
				$tabelle_id,
				'Required body band; 1..n fields. Identity = Zeile prop binding, not this name.',
				$created,
				$existing
			);
		}
		self::ensure_composition_edge( $taxonomy, $tabelle_id, $comp_type, $zeile_id, '1' );

		$bindings['zeile'] = $zeile_id;
		Node_Type::set_prop_bindings( $taxonomy, $tabelle_id, $bindings );

		/* Seed default columns only when the bound Zeile band has no fields yet. */
		$existing_fields = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $zeile_id,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
			)
		);
		$has_fields = is_array( $existing_fields ) && ! empty( $existing_fields );

		$field_types = array(
			'Reference'  => 'text',
			'Bauteil'    => 'text',
			'Menge'      => 'int',
			'Kommentar'  => 'textarea',
			'Preis'      => 'double',
			'Bestellt'   => 'bool',
			'Vorhanden'  => 'bool',
			'Wert'       => 'text',
			'Name'       => 'text',
			'E-Mail'     => 'email',
			'Email'      => 'email',
		);

		if ( ! $has_fields ) {
			$fields = array(
				'Reference' => array( 'text', 'Board references (e.g. R1,R2).' ),
				'Wert'      => array( 'text', 'Value / rating display.' ),
				'Menge'     => array( 'int', 'Quantity (Stück).' ),
			);
			foreach ( $fields as $fname => $meta ) {
				$field_id = self::find_child_named( $taxonomy, $zeile_id, $fname );
				if ( $field_id <= 0 ) {
					$field_id = self::ensure_term(
						$taxonomy,
						$fname,
						$zeile_id,
						(string) $meta[1],
						$created,
						$existing
					);
				}
				self::ensure_type_named( $taxonomy, $field_id, (string) $meta[0] );
				self::ensure_composition_edge( $taxonomy, $zeile_id, $comp_type, $field_id, '1' );
			}
		} else {
			$kids = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $zeile_id,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			if ( is_array( $kids ) ) {
				$terms = array();
				foreach ( $kids as $kid ) {
					if ( $kid instanceof \WP_Term ) {
						$terms[] = $kid;
					}
				}
				usort(
					$terms,
					static function ( \WP_Term $a, \WP_Term $b ): int {
						$pa = Tree_Model::get_position( (int) $a->term_id );
						$pb = Tree_Model::get_position( (int) $b->term_id );
						if ( $pa !== $pb ) {
							return $pa <=> $pb;
						}
						return strcasecmp( $a->name, $b->name );
					}
				);
				foreach ( $terms as $kid ) {
					$fname = $kid->name;
					if ( isset( $field_types[ $fname ] ) && Node_Type::get_type_id( (int) $kid->term_id ) <= 0 ) {
						self::ensure_type_named( $taxonomy, (int) $kid->term_id, $field_types[ $fname ] );
					}
					self::ensure_composition_edge( $taxonomy, $zeile_id, $comp_type, (int) $kid->term_id, '1' );
				}
			}
		}
	}

	/**
	 * Prefer an existing table-typed child (e.g. Kontent / Tabelle) under BOM.
	 */
	private static function find_bom_table_child( string $taxonomy, int $bom_id ): int {
		foreach ( array( 'Tabelle', 'Table', 'Kontent', 'Content' ) as $name ) {
			$id = self::find_child_named( $taxonomy, $bom_id, $name );
			if ( $id > 0 ) {
				return $id;
			}
		}
		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $bom_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $kids ) ) {
			return 0;
		}
		foreach ( $kids as $kid ) {
			if ( ! $kid instanceof \WP_Term ) {
				continue;
			}
			if ( Node_Type::has_type_named( $taxonomy, (int) $kid->term_id, 'table' ) ) {
				return (int) $kid->term_id;
			}
		}
		return 0;
	}

	private static function find_child_named( string $taxonomy, int $parent_id, string $name ): int {
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

	private static function ensure_type_named( string $taxonomy, int $term_id, string $type_name ): void {
		if ( $term_id <= 0 || '' === $type_name ) {
			return;
		}
		$type_id = Node_Type::find_type_by_name( $taxonomy, $term_id, $type_name );
		if ( $type_id <= 0 ) {
			$type_id = self::find_case_datatype_id( $taxonomy, $type_name );
		}
		if ( $type_id > 0 && Node_Type::get_type_id( $term_id ) !== $type_id ) {
			Node_Type::set_type_id( $taxonomy, $term_id, $type_id );
		}
	}

	/**
	 * Fallstudie datatype leaves live under Definition/Data Types/Simple|Complex.
	 * Also accept legacy Definition/Simple|Complex and Datentypen paths. Prefer is_datatype.
	 */
	private static function find_case_datatype_id( string $taxonomy, string $type_name ): int {
		$paths = array(
			array( self::ROOT_NAME, 'Definition', 'Data Types', 'Simple', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Data Types', 'Complex', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Data Types', 'Complex', 'enum', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Datentypen', 'Simple', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Datentypen', 'Complex', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Datentypen', 'Complex', 'enum', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Simple', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Complex', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Complex', 'enum', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Eigene Datentypen', $type_name ),
		);
		$fallback = 0;
		foreach ( $paths as $path ) {
			$found = self::find_term_by_path( $taxonomy, $path );
			if ( $found <= 0 ) {
				continue;
			}
			if ( Node_Type::is_datatype( $taxonomy, $found ) ) {
				return $found;
			}
			if ( 0 === $fallback ) {
				$fallback = $found;
			}
		}

		/* Slug-stem match under any Simple catalog (renamed leaves). */
		foreach ( self::simple_catalog_paths() as $simple_path ) {
			$simple = self::find_term_by_path( $taxonomy, $simple_path );
			if ( $simple <= 0 ) {
				continue;
			}
			$leaf = self::find_simple_catalog_leaf( $taxonomy, $simple, $type_name );
			if ( $leaf > 0 && Node_Type::is_datatype( $taxonomy, $leaf ) ) {
				return $leaf;
			}
			if ( $leaf > 0 && 0 === $fallback ) {
				$fallback = $leaf;
			}
		}

		return $fallback;
	}

	private static function ensure_composition_edge(
		string $taxonomy,
		int $from_id,
		int $type_id,
		int $to_id,
		string $multiplicity = Relation::MULTIPLICITY_DEFAULT
	): void {
		if ( $from_id <= 0 || $type_id <= 0 || $to_id <= 0 ) {
			return;
		}
		if ( Relation::has_identical( $from_id, $type_id, $to_id ) ) {
			return;
		}
		Relation::add( $taxonomy, $from_id, $type_id, $to_id, $multiplicity );
	}

	/**
	 * Type binding is has_type / type_id — not composition to the catalog `table` node.
	 */
	private static function strip_composition_to_table_catalog( string $taxonomy, int $from_id ): void {
		if ( $from_id <= 0 ) {
			return;
		}
		foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $from_id, Relation::TYPE_COMPOSITION ) as $edge ) {
			$to_id = (int) ( $edge['toId'] ?? 0 );
			if ( $to_id > 0 && Node_Type::is_table_type_catalog( $taxonomy, $to_id ) ) {
				Relation::remove(
					$taxonomy,
					$from_id,
					(int) ( $edge['typeId'] ?? 0 ),
					$to_id,
					(string) ( $edge['id'] ?? '' )
				);
			}
		}
	}

	/**
	 * @return int|\WP_Error Number of roots deleted (cascade).
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

		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( ! is_array( $found ) || empty( $found ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $found as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$removed = Tree_Model::delete_term( $taxonomy, (int) $term->term_id, 'cascade' );
			if ( is_wp_error( $removed ) ) {
				return $removed;
			}
			++$deleted;
		}

		return $deleted;
	}

	/**
	 * @param array<int, array<string, mixed>> $nodes
	 */
	private static function install_nodes( string $taxonomy, array $nodes, int $parent_id, int &$created, int &$existing ): void {
		foreach ( $nodes as $index => $node ) {
			$name = isset( $node['name'] ) ? (string) $node['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			$description = isset( $node['description'] ) ? (string) $node['description'] : '';
			$aliases     = array();
			if ( isset( $node['aliases'] ) && is_array( $node['aliases'] ) ) {
				foreach ( $node['aliases'] as $alias ) {
					$alias = is_string( $alias ) ? trim( $alias ) : '';
					if ( '' !== $alias && $alias !== $name ) {
						$aliases[] = $alias;
					}
				}
			}
			$slug    = isset( $node['slug'] ) ? sanitize_title( (string) $node['slug'] ) : '';
			$term_id = self::ensure_term(
				$taxonomy,
				$name,
				$parent_id,
				$description,
				$created,
				$existing,
				$aliases,
				$slug
			);
			if ( $term_id <= 0 ) {
				continue;
			}

			Tree_Model::set_position( $term_id, (int) $index );

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
			if ( array_key_exists( 'short_description', $node ) ) {
				Tree_Model::set_short_description(
					$taxonomy,
					$term_id,
					(string) $node['short_description']
				);
			}
			if ( array_key_exists( 'multiplikator', $node ) && is_numeric( $node['multiplikator'] ) ) {
				Node_Type::set_multiplikator( $term_id, (float) $node['multiplikator'] );
			}
			if ( array_key_exists( 'date_mode', $node ) ) {
				Node_Type::set_date_mode( $taxonomy, $term_id, (string) $node['date_mode'] );
			}

			$type_name = isset( $node['type_name'] ) ? (string) $node['type_name'] : '';
			if ( '' !== $type_name ) {
				self::ensure_type_named( $taxonomy, $term_id, $type_name );
			}

			$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
			if ( ! empty( $children ) ) {
				self::install_nodes( $taxonomy, $children, $term_id, $created, $existing );
			}
		}
	}

	/**
	 * Idempotent term ensure: match by name, then slug, then aliases (rename alias → name).
	 *
	 * @param array<int, string> $aliases Former names under the same parent.
	 */
	private static function ensure_term(
		string $taxonomy,
		string $name,
		int $parent_id,
		string $description,
		int &$created,
		int &$existing,
		array $aliases = array(),
		string $slug = ''
	): int {
		$term = self::find_child_term( $taxonomy, $parent_id, $name );
		if ( $term instanceof \WP_Term ) {
			++$existing;
			return self::refresh_existing_term( $taxonomy, $term, $description, $slug );
		}

		if ( '' !== $slug ) {
			$by_slug = self::find_child_by_slug( $taxonomy, $parent_id, $slug );
			if ( $by_slug instanceof \WP_Term ) {
				++$existing;
				if ( $by_slug->name !== $name ) {
					wp_update_term(
						(int) $by_slug->term_id,
						$taxonomy,
						array(
							'name' => $name,
							'slug' => $slug,
						)
					);
					$by_slug = get_term( (int) $by_slug->term_id, $taxonomy );
				}
				if ( $by_slug instanceof \WP_Term ) {
					return self::refresh_existing_term( $taxonomy, $by_slug, $description, $slug );
				}
			}
		}

		foreach ( $aliases as $alias ) {
			$alias_term = self::find_child_term( $taxonomy, $parent_id, $alias );
			if ( ! $alias_term instanceof \WP_Term ) {
				continue;
			}
			/*
			 * Alias found and canonical missing: rename in place (stable term_id).
			 * When both exist, strip_obsolete_* removes the alias sibling later.
			 */
			$canonical = self::find_child_term( $taxonomy, $parent_id, $name );
			if ( $canonical instanceof \WP_Term ) {
				break;
			}
			$update = array( 'name' => $name );
			if ( '' !== $slug ) {
				$update['slug'] = $slug;
			}
			$renamed = wp_update_term( (int) $alias_term->term_id, $taxonomy, $update );
			if ( is_wp_error( $renamed ) ) {
				continue;
			}
			$alias_term = get_term( (int) $alias_term->term_id, $taxonomy );
			if ( $alias_term instanceof \WP_Term ) {
				++$existing;
				return self::refresh_existing_term( $taxonomy, $alias_term, $description, $slug );
			}
		}

		$insert = array(
			'parent'      => max( 0, $parent_id ),
			'description' => $description,
		);
		if ( '' !== $slug ) {
			$insert['slug'] = $slug;
		}

		$result = wp_insert_term( $name, $taxonomy, $insert );

		if ( is_wp_error( $result ) ) {
			if ( 'term_exists' === $result->get_error_code() ) {
				$term_id = (int) $result->get_error_data();
				if ( $term_id > 0 ) {
					++$existing;
					$existing_term = get_term( $term_id, $taxonomy );
					if ( $existing_term instanceof \WP_Term ) {
						return self::refresh_existing_term( $taxonomy, $existing_term, $description, $slug );
					}
					return $term_id;
				}
			}
			return 0;
		}

		++$created;
		return (int) $result['term_id'];
	}

	/**
	 * @return \WP_Term|null
	 */
	private static function find_child_term( string $taxonomy, int $parent_id, string $name ) {
		if ( '' === $name ) {
			return null;
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
			return $found[0];
		}
		return null;
	}

	/**
	 * @return \WP_Term|null
	 */
	private static function find_child_by_slug( string $taxonomy, int $parent_id, string $slug ) {
		if ( '' === $slug ) {
			return null;
		}
		$found = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'slug'       => $slug,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'number'     => 1,
			)
		);
		if ( is_array( $found ) && isset( $found[0] ) && $found[0] instanceof \WP_Term ) {
			return $found[0];
		}
		return null;
	}

	private static function refresh_existing_term(
		string $taxonomy,
		\WP_Term $term,
		string $description,
		string $slug = ''
	): int {
		$term_id = (int) $term->term_id;
		if ( Trash::is_trashed( $term_id ) ) {
			Trash::restore_subtree( $taxonomy, $term_id );
		}
		$update = array();
		if ( '' !== $description && Tree_Model::decode_term_description( (string) $term->description ) !== $description ) {
			$update['description'] = $description;
		}
		if ( '' !== $slug && $term->slug !== $slug ) {
			$update['slug'] = $slug;
		}
		if ( ! empty( $update ) ) {
			wp_update_term( $term_id, $taxonomy, $update );
		}
		return $term_id;
	}
}
