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
			array( 'name' => 'int', 'description' => 'Whole number.', 'deletable' => false ),
			array( 'name' => 'double', 'description' => 'Floating point.', 'deletable' => false ),
			array( 'name' => 'text', 'description' => 'Single-line text.', 'deletable' => false ),
			array( 'name' => 'email', 'description' => 'Email address (validated input).', 'deletable' => false ),
			array( 'name' => 'textarea', 'description' => 'Multi-line text.', 'deletable' => false ),
			array( 'name' => 'char', 'description' => 'Single character.', 'deletable' => false ),
			array( 'name' => 'bool', 'description' => 'Boolean.', 'deletable' => false ),
			array(
				'name'        => 'date',
				'description' => 'Calendar date. Store: Unix timestamp (UTC midnight).',
				'date_mode'   => 'date',
				'deletable'   => false,
			),
			array(
				'name'        => 'time',
				'description' => 'Clock time (HH:MM). Store: string HH:MM (24h).',
				'deletable'   => false,
			),
			array(
				'name'        => 'datetime',
				'description' => 'Date and time. Store: Unix timestamp.',
				'deletable'   => false,
			),
			array(
				'name'        => 'color',
				'description' => 'CSS color (#rrggbb). Store: hex string.',
				'deletable'   => false,
			),
			array(
				'name'        => 'node_presentation',
				'description' => 'Read-only: shows one host Node presentation field (form/table/select/symbol/help/icon). Alias: display_node_name.',
				'deletable'   => false,
				'aliases'     => array( 'display_node_name' ),
			),
			array(
				'name'        => 'media',
				'description' => 'WP Media Library and/or URL (Q65). MIME-based display.',
				'deletable'   => false,
			),
		);
	}

	/**
	 * Active Complex catalog leaves under Definition/Data Types/Complex.
	 *
	 * Q90 parked collection kinds (`enum` / `list` / `table` as product types) and
	 * OQ-W15 `node_pick` / `node_embed` / `node_ref` are **not** seeded — see
	 * {@see parked_complex_datatype_names()}. `table` remains as **legacy** leaf
	 * while BOM::Tabelle still binds it (remove after BOM composition rewrite).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function complex_datatype_leaves(): array {
		return array(
			array(
				'name'              => 'quantity',
				'description'       => 'Größe: value + optional prefix + base unit (not a measurement act; not BOM Menge). Alias: measure.',
				'short_description' => 'Größe',
				'deletable'         => false,
				/* Former informal names → rename in place via ensure_term. */
				'aliases'           => array( 'measure', 'Größe', 'Groesse' ),
			),
			array(
				'name'        => 'set',
				'description' => 'Collection of named members; schema = child nodes (e.g. unit Typ/Praefix/Kuerzel).',
				'deletable'   => false,
			),
			array(
				'name'        => 'table',
				'description' => 'DEPRECATED (Q90) — legacy BOM Tabelle only. Do not use for new models; prefer composition attributes.',
				'deletable'   => false,
			),
		);
	}

	/**
	 * Complex leaves removed from the Fallstudie tree (soft-trash on ensure).
	 *
	 * @return list<string>
	 */
	public static function parked_complex_datatype_names(): array {
		return array( 'list', 'enum', 'node_pick', 'node_embed', 'node_ref' );
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

	/**
	 * Currency catalog under Definition/Konstanten/Währung (CatalogChoice host).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function waehrung_catalog_leaves(): array {
		return array(
			array(
				'name'              => 'Euro',
				'short_description' => '€',
				'description'       => 'Euro (EUR).',
				'deletable'         => false,
			),
			array(
				'name'              => 'US Dollar',
				'short_description' => '$',
				'description'       => 'US Dollar (USD).',
				'aliases'           => array( 'Dollar' ),
				'deletable'         => false,
			),
			array(
				'name'              => 'Pound',
				'short_description' => '£',
				'description'       => 'Pound Sterling (GBP).',
				'aliases'           => array( 'Pound Sterling', 'GBP' ),
				'deletable'         => false,
			),
		);
	}

	public static function blueprint(): array {
		$simple_leaves  = self::simple_datatype_leaves();
		$complex_leaves = self::complex_datatype_leaves();
		$bauformen_leaves = self::bauformen_catalog_leaves();
		$waehrung_leaves  = self::waehrung_catalog_leaves();

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
			),
			array(
				'name'              => 'nano',
				'aliases'           => array( 'n' ),
				'short_description' => 'n',
				'description'       => 'SI prefix nano (10⁻⁹).',
				'multiplikator'     => 1.0e-9,
			),
			array(
				'name'              => 'Micro',
				'aliases'           => array( 'u', 'µ', 'micro' ),
				'short_description' => 'u',
				'description'       => 'SI prefix micro (10⁻⁶).',
				'multiplikator'     => 1.0e-6,
			),
			array(
				'name'              => 'Milli',
				'aliases'           => array( 'm', 'milli' ),
				'short_description' => 'm',
				'description'       => 'SI prefix milli (10⁻³); with Meter → mm.',
				'multiplikator'     => 1.0e-3,
			),
			array(
				'name'              => 'Centi',
				'aliases'           => array( 'c', 'centi' ),
				'short_description' => 'c',
				'description'       => 'SI prefix centi (10⁻²).',
				'multiplikator'     => 1.0e-2,
			),
			array(
				'name'              => 'Kilo',
				'aliases'           => array( 'k', 'kilo' ),
				'short_description' => 'k',
				'description'       => 'SI prefix kilo (10³).',
				'multiplikator'     => 1.0e3,
			),
			array(
				'name'              => 'Mega',
				'aliases'           => array( 'M' ),
				'short_description' => 'Mega',
				'description'       => 'SI prefix mega (10⁶); name Mega avoids slug clash with milli m.',
				'multiplikator'     => 1.0e6,
			),
		);

		$units_with_prefix = array(
			array(
				'name'              => 'Meter',
				'short_description' => 'm',
				'description'       => 'Length; prefixes Micro/Milli/Centi/Kilo.',
			),
			array(
				'name'              => 'Liter',
				'short_description' => 'l',
				'description'       => 'Volume; prefixes Milli/Centi/Kilo.',
			),
			array(
				'name'              => 'Kilogramm',
				'short_description' => 'g',
				'description'       => 'SI base kg; prefixes attach to gram (mg/kg/Mg).',
			),
			array(
				'name'              => 'Sekunde',
				'short_description' => 's',
				'description'       => 'Time; prefixes pico/nano/Micro/Milli.',
			),
			array(
				'name'              => 'Ampere',
				'short_description' => 'A',
				'description'       => 'Electric current.',
			),
			array(
				'name'              => 'Ohm',
				'short_description' => 'Ω',
				'description'       => 'Resistance; k+Ω → kΩ.',
			),
			array(
				'name'              => 'Farad',
				'short_description' => 'F',
				'description'       => 'Capacitance; no k/Mega.',
			),
			array(
				'name'              => 'Watt',
				'short_description' => 'W',
				'description'       => 'Power.',
			),
			array(
				'name'              => 'Volt',
				'short_description' => 'V',
				'description'       => 'Voltage.',
			),
			array(
				'name'              => 'Henry',
				'short_description' => 'H',
				'description'       => 'Inductance; DigiKey Inductors / Coils / Chokes.',
			),
			array(
				'name'              => 'Hertz',
				'short_description' => 'Hz',
				'description'       => 'Frequency; crystals / oscillators.',
			),
		);

		$units_without_prefix = array(
			array(
				'name'              => 'Kelvin',
				'short_description' => 'K',
				'description'       => 'Thermodynamic temperature; no prefixes.',
			),
			array(
				'name'              => 'Celsius',
				'short_description' => '°C',
				'description'       => 'Celsius temperature; no SI prefixes.',
			),
			array(
				'name'              => 'Stück',
				'short_description' => 'Stk',
				'description'       => 'Count / Menge; no prefixes.',
			),
		);

		return array(
			array(
				'name'        => self::ROOT_NAME,
				'description' => 'Case-study Project: Definition (type catalog) and Implementation samples. Standard scaffold tree — not a model sign-off.',
				'children'    => array(
					array(
						'name'        => 'Definition',
						'description' => 'Type catalog: simples, complex, prefixes, units (Q120).',
						'children'    => array(
							array(
								'name'        => 'Knoten',
								'description' => 'General assignable node type (project roots and ordinary nodes).',
							),
							array(
								'name'        => 'Data Types',
								'description' => 'Simple / Complex scalars and composed type hosts (e.g. Unit type). Constant catalogs live under Definition/Konstanten.',
								'children'    => array(
									array(
										'name'        => 'Simple',
										'description' => 'Scalar and simple reference types.',
										'children'    => $simple_leaves,
									),
									array(
										'name'        => 'Complex',
										'description' => 'Collection kinds (list / table / enum / set).',
										'children'    => $complex_leaves,
									),
								),
							),
							array(
								'name'        => 'Konstanten',
								'description' => 'Shared constant catalogs: SI prefixes, base units, footprints, currencies (CatalogChoice roots).',
								'deletable'   => false,
								'children'    => array(
									array(
										'name'        => 'Präfixe',
										'description' => 'Global prefix catalog. multiplikator = scale vs the unit’s prefix root (Q51). Married to units via allowlist.',
										'deletable'   => false,
										'children'    => $prefix_leaves,
									),
									array(
										'name'        => 'Basiseinheiten',
										'description' => 'Base unit catalog (Q120). Concrete units under With prefix / Without prefix.',
										'deletable'   => false,
										'aliases'     => array( 'Unit', 'Basiseinheit' ),
										'slug'        => 'basiseinheiten',
										'children'    => array(
											array(
												'name'        => 'With prefix',
												'description' => 'Units that may marry SI prefixes (allowlist on each unit).',
												'deletable'   => false,
												'children'    => $units_with_prefix,
											),
											array(
												'name'        => 'Without prefix',
												'description' => 'Units with prefix allowlist empty (Stück, temperature).',
												'deletable'   => false,
												'children'    => $units_without_prefix,
											),
										),
									),
									array(
										'name'        => 'Währung',
										'description' => 'Currency catalog (Euro / US Dollar / Pound). Short description = symbol. CatalogChoice host for Preis.',
										'deletable'   => false,
										'aliases'     => array( 'Waehrung', 'Currency' ),
										'slug'        => 'waehrung-units',
										'children'    => $waehrung_leaves,
									),
									array(
										'name'        => 'Bauformen',
										'description' => 'Package / footprint catalog (axial, radial, SMD sizes).',
										'deletable'   => false,
										'children'    => $bauformen_leaves,
									),
								),
							),
							array(
								'name'        => 'Eigene Datentypen',
								'description' => 'User-defined types (empty).',
							),
						),
					),
					array(
						'name'        => 'Implementation',
						'description' => 'Instance / project content (BOM definition sample).',
						'children'    => array(
							array(
								'name'        => 'BOM',
								'description' => 'Zusammenstellungs-Definition: Name + Tabelle seeded by ensure_bom_implementation (Q61/Q87 slots).',
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
		if ( class_exists( Attribute_Q123_Migrate::class ) ) {
			Attribute_Q123_Migrate::maybe_migrate( $taxonomy );
		}
		if ( class_exists( Json_Meta::class ) ) {
			Json_Meta::maybe_repair_taxonomy( $taxonomy );
		}
		Attribute::prune_dangling_edges( $taxonomy );
		self::ensure_simple_datatypes( $taxonomy );
		self::ensure_email_datatype( $taxonomy );
		self::ensure_date_datatype( $taxonomy );
		self::ensure_complex_datatypes( $taxonomy );
		Catalog_Bindings::ensure( $taxonomy );
		self::ensure_bauart_enum( $taxonomy );
		self::ensure_table_datatype_bands( $taxonomy );
		self::ensure_aggregate_catalog( $taxonomy );
		self::ensure_bom_implementation( $taxonomy );
		self::ensure_model_branch( $taxonomy );
		self::ensure_bauteile_catalog( $taxonomy );
		Demo_Data::ensure_set_composition_members( $taxonomy );
		Demo_Data::purge_root_band_orphans( $taxonomy );
		self::ensure_deletable_flags( $taxonomy );
		self::ensure_root_typed_knoten( $taxonomy );
		Node_Type::ensure_hierarchy_datatype_inheritance( $taxonomy );
		Node_Type::repair_legacy_preferred_form_seeds( $taxonomy );

		/*
		 * Composition members created above may still be WP children. Detach to
		 * Q87 parent=0 slots, then re-bind table bands (set_prop_bindings now
		 * accepts composition targets — not only hierarchy children).
		 */
		Attribute::migrate_detach_hierarchy( $taxonomy );
		self::ensure_table_datatype_bands( $taxonomy );
		self::ensure_bom_implementation( $taxonomy );

		return array(
			'created'  => $created,
			'existing' => $existing,
			'taxonomy' => $taxonomy,
		);
	}

	/**
	 * First-open seed only: install blueprint when the taxonomy has zero terms.
	 *
	 * Does **not** re-run ensure_* / prune / purge / composition sync on every
	 * Tree screen load — that restored deleted seed nodes, rewrote attribute
	 * Mult/Binding/type, and forced catalog icons / Preferred defaults over
	 * user edits. Explicit repair: Case_Data::install() / reset() / CLI.
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
		/* One-shot cleanups that must not wait for empty-taxonomy install. */
		self::maybe_retire_unit_type_c1_demo( $taxonomy );
		self::maybe_refresh_walk_caches_after_c1_retire( $taxonomy );
	}

	/**
	 * Force-refresh Walk caches that still listed soft-trashed C1 under Unit type.
	 */
	public static function maybe_refresh_walk_caches_after_c1_retire( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$opt = 'wtt_refreshed_walk_after_c1_' . $taxonomy;
		if ( get_option( $opt ) ) {
			return;
		}
		$ut_id = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Definition', 'Data Types', 'Unit type' )
		);
		if ( $ut_id > 0 && class_exists( Attribute::class ) ) {
			Attribute::refresh_settings_walk_caches_for_type_node( $taxonomy, $ut_id, true );
		}
		update_option( $opt, 1, false );
	}

	/**
	 * Ensure Bauart CatalogChoice host under Complex (was Complex/enum — Q90).
	 * Retypes Passiv/Bauart attribute when mistyped.
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
	 * Lock seeded catalog templates + Relationstypen; user nodes stay deletable by default.
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
			$seeds   = array( 'child_of', 'ref_scope', 'besteht_aus', 'aggregation' );
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
	 * Ensure Definition/Konstanten catalogs: Präfixe, Basiseinheiten, Bauformen, Währung.
	 * Migrates leftovers from Data Types (Q120 interim) back under Konstanten.
	 * Alias ensure_unit_catalog kept for call sites.
	 */
	public static function ensure_konstanten( string $taxonomy = Taxonomy::FS ): void {
		self::ensure_unit_catalog( $taxonomy );
	}

	/**
	 * @see ensure_konstanten()
	 */
	public static function ensure_unit_catalog( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$def_id = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Definition' )
		);
		if ( $def_id <= 0 ) {
			return;
		}

		$data_types_id = self::ensure_data_types_folder( $taxonomy );
		$created       = 0;
		$existing      = 0;

		$konstanten_id = self::ensure_term(
			$taxonomy,
			'Konstanten',
			$def_id,
			'Shared constant catalogs: SI prefixes, base units, footprints, currencies.',
			$created,
			$existing,
			array(),
			'konstanten'
		);
		if ( $konstanten_id <= 0 ) {
			return;
		}
		Trash::restore_subtree( $taxonomy, $konstanten_id );
		Node_Type::set_deletable( $konstanten_id, false );

		self::ensure_unit_catalog_folder_shells( $taxonomy, $konstanten_id, $created, $existing );

		/* Pull catalogs that still sit under Data Types (post-Q120 interim). */
		if ( $data_types_id > 0 ) {
			self::migrate_data_types_catalogs_to_konstanten( $taxonomy, $data_types_id, $konstanten_id );
		}

		$prefixes_id  = self::find_catalog_folder( $taxonomy, 'prefixes' );
		$unit_id      = self::find_catalog_folder( $taxonomy, 'basiseinheiten' );
		$with_id      = self::find_catalog_folder( $taxonomy, 'with_prefix' );
		$without_id   = self::find_catalog_folder( $taxonomy, 'without_prefix' );
		$bauformen_id = self::find_catalog_folder( $taxonomy, 'bauformen' );
		$waehrung_id  = self::find_catalog_folder( $taxonomy, 'waehrung' );

		/* Refresh / create missing leaves under Konstanten (idempotent by name). */
		$k_blueprint = self::konstanten_catalog_blueprint();
		if ( $k_blueprint ) {
			self::install_nodes( $taxonomy, $k_blueprint, $konstanten_id, $created, $existing );
			$prefixes_id  = self::find_catalog_folder( $taxonomy, 'prefixes' );
			$unit_id      = self::find_catalog_folder( $taxonomy, 'basiseinheiten' );
			$with_id      = self::find_catalog_folder( $taxonomy, 'with_prefix' );
			$without_id   = self::find_catalog_folder( $taxonomy, 'without_prefix' );
			$bauformen_id = self::find_catalog_folder( $taxonomy, 'bauformen' );
			$waehrung_id  = self::find_catalog_folder( $taxonomy, 'waehrung' );
		}

		if ( $prefixes_id > 0 ) {
			Trash::restore_subtree( $taxonomy, $prefixes_id );
			Node_Type::set_deletable( $prefixes_id, false );
		}
		if ( $unit_id > 0 ) {
			Trash::restore_subtree( $taxonomy, $unit_id );
			Node_Type::set_deletable( $unit_id, false );
		}
		if ( $with_id > 0 ) {
			Node_Type::set_deletable( $with_id, false );
		}
		if ( $without_id > 0 ) {
			Node_Type::set_deletable( $without_id, false );
		}
		if ( $bauformen_id > 0 ) {
			Trash::restore_subtree( $taxonomy, $bauformen_id );
			Node_Type::set_deletable( $bauformen_id, false );
		}
		if ( $waehrung_id > 0 ) {
			Trash::restore_subtree( $taxonomy, $waehrung_id );
			Node_Type::set_deletable( $waehrung_id, false );
		}

		/*
		 * Heal double-seed AFTER restore — restore_subtree must not revive
		 * the duplicates we are about to trash.
		 */
		if ( $prefixes_id > 0 ) {
			self::dedupe_same_name_children( $taxonomy, $prefixes_id );
			self::strip_obsolete_prefix_aliases( $taxonomy, $prefixes_id );
		}
		if ( $with_id > 0 ) {
			self::dedupe_same_name_children( $taxonomy, $with_id );
		}
		if ( $without_id > 0 ) {
			self::dedupe_same_name_children( $taxonomy, $without_id );
			self::cleanup_currency_flat_siblings( $taxonomy, $without_id );
		}
		if ( $waehrung_id > 0 ) {
			self::configure_konstanten_waehrung( $taxonomy, $konstanten_id );
		}
		if ( $bauformen_id > 0 ) {
			self::dedupe_same_name_children( $taxonomy, $bauformen_id );
			self::configure_konstanten_bauformen( $taxonomy, $konstanten_id );
		}

		Demo_Data::ensure_prefix_multiplikators( $taxonomy );
		self::ensure_prefix_multiplikator_attribute( $taxonomy );
		self::ensure_with_prefix_default_allowlists( $taxonomy );
		/* OQ-W11: With prefix = father knot composed of Praefix + Kuerzel Relations. */
		self::ensure_with_prefix_composition( $taxonomy );
		/* Unit-type sketch: Menge + Base unit + Praefix host under Data Types. */
		self::ensure_unit_type( $taxonomy );
		self::ensure_quantity_preis_example( $taxonomy );
		self::remap_stale_unit_catalog_attribute_types( $taxonomy );
		self::hoist_waehrung_under_konstanten( $taxonomy );
		if ( $data_types_id > 0 ) {
			self::trash_empty_data_types_catalog_shells( $taxonomy, $data_types_id );
		}
	}

	/**
	 * Keep Währung as direct child of Konstanten (not nested under Without prefix).
	 */
	private static function hoist_waehrung_under_konstanten( string $taxonomy ): void {
		$konstanten_id = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Definition', 'Konstanten' )
		);
		if ( $konstanten_id <= 0 ) {
			return;
		}
		$target = self::find_child_named( $taxonomy, $konstanten_id, 'Währung' );
		if ( $target <= 0 ) {
			$target = self::find_child_named( $taxonomy, $konstanten_id, 'Waehrung' );
		}
		$without_id = self::find_catalog_folder( $taxonomy, 'without_prefix' );
		if ( $without_id <= 0 ) {
			return;
		}
		$nested = self::find_child_named( $taxonomy, $without_id, 'Währung' );
		if ( $nested <= 0 ) {
			$nested = self::find_child_named( $taxonomy, $without_id, 'Waehrung' );
		}
		if ( $nested <= 0 || $nested === $target ) {
			return;
		}
		if ( $target > 0 ) {
			self::reparent_all_children( $taxonomy, $nested, $target );
			self::maybe_trash_empty_folder( $taxonomy, $nested, true );
		} else {
			wp_update_term( $nested, $taxonomy, array( 'parent' => $konstanten_id ) );
		}
	}

	/**
	 * Resolve a constant-catalog folder. Prefer Definition/Konstanten; fall back to
	 * Data Types interim paths (≈ 0.0.402–0.0.457).
	 *
	 * @param string $key prefixes|basiseinheiten|with_prefix|without_prefix|bauformen|waehrung
	 */
	public static function find_catalog_folder( string $taxonomy, string $key ): int {
		foreach ( self::catalog_folder_path_candidates( $key ) as $path ) {
			$found = self::find_term_by_path( $taxonomy, $path );
			if ( $found > 0 && ! Trash::is_trashed( $found ) ) {
				return $found;
			}
		}
		foreach ( self::catalog_folder_path_candidates( $key ) as $path ) {
			$found = self::find_term_by_path( $taxonomy, $path );
			if ( $found > 0 ) {
				return $found;
			}
		}
		return 0;
	}

	/**
	 * @return list<list<string>>
	 */
	private static function catalog_folder_path_candidates( string $key ): array {
		$root = self::ROOT_NAME;
		switch ( $key ) {
			case 'prefixes':
				return array(
					array( $root, 'Definition', 'Konstanten', 'Präfixe' ),
					array( $root, 'Definition', 'Konstanten', 'Praefixe' ),
					array( $root, 'Definition', 'Data Types', 'Präfixe' ),
					array( $root, 'Definition', 'Data Types', 'Praefixe' ),
				);
			case 'basiseinheiten':
				return array(
					array( $root, 'Definition', 'Konstanten', 'Basiseinheiten' ),
					array( $root, 'Definition', 'Konstanten', 'Basiseinheit' ),
					array( $root, 'Definition', 'Konstanten', 'Unit' ),
					array( $root, 'Definition', 'Data Types', 'Unit' ),
					array( $root, 'Definition', 'Data Types', 'Basiseinheiten' ),
				);
			case 'with_prefix':
				return array(
					array( $root, 'Definition', 'Konstanten', 'Basiseinheiten', 'With prefix' ),
					array( $root, 'Definition', 'Konstanten', 'Basiseinheiten', 'Mit Präfix' ),
					array( $root, 'Definition', 'Konstanten', 'Unit', 'With prefix' ),
					array( $root, 'Definition', 'Data Types', 'Unit', 'With prefix' ),
					array( $root, 'Definition', 'Data Types', 'Unit', 'Mit Präfix' ),
				);
			case 'without_prefix':
				return array(
					array( $root, 'Definition', 'Konstanten', 'Basiseinheiten', 'Without prefix' ),
					array( $root, 'Definition', 'Konstanten', 'Basiseinheiten', 'Ohne Präfix' ),
					array( $root, 'Definition', 'Konstanten', 'Unit', 'Without prefix' ),
					array( $root, 'Definition', 'Data Types', 'Unit', 'Without prefix' ),
				);
			case 'bauformen':
				return array(
					array( $root, 'Definition', 'Konstanten', 'Bauformen' ),
					array( $root, 'Definition', 'Data Types', 'Bauformen' ),
				);
			case 'waehrung':
				return array(
					array( $root, 'Definition', 'Konstanten', 'Währung' ),
					array( $root, 'Definition', 'Konstanten', 'Waehrung' ),
					array( $root, 'Definition', 'Konstanten', 'Basiseinheiten', 'Without prefix', 'Währung' ),
					array( $root, 'Definition', 'Konstanten', 'Unit', 'Without prefix', 'Währung' ),
					array( $root, 'Definition', 'Data Types', 'Unit', 'Without prefix', 'Währung' ),
				);
			default:
				return array();
		}
	}

	/**
	 * Move Präfixe / Unit / Bauformen / Währung from Data Types into Konstanten.
	 */
	private static function migrate_data_types_catalogs_to_konstanten(
		string $taxonomy,
		int $data_types_id,
		int $konstanten_id
	): void {
		if ( $data_types_id <= 0 || $konstanten_id <= 0 ) {
			return;
		}

		$prefixes_k = self::find_child_named( $taxonomy, $konstanten_id, 'Präfixe' );
		if ( $prefixes_k <= 0 ) {
			$prefixes_k = self::find_child_named( $taxonomy, $konstanten_id, 'Praefixe' );
		}
		$prefixes_dt = self::find_child_named( $taxonomy, $data_types_id, 'Präfixe' );
		if ( $prefixes_dt <= 0 ) {
			$prefixes_dt = self::find_child_named( $taxonomy, $data_types_id, 'Praefixe' );
		}
		if ( $prefixes_dt > 0 && $prefixes_k > 0 && $prefixes_dt !== $prefixes_k ) {
			self::reparent_all_children( $taxonomy, $prefixes_dt, $prefixes_k );
			self::maybe_trash_empty_folder( $taxonomy, $prefixes_dt );
		} elseif ( $prefixes_dt > 0 && $prefixes_k <= 0 ) {
			wp_update_term( $prefixes_dt, $taxonomy, array( 'parent' => $konstanten_id ) );
		}

		$base_k = self::find_child_named( $taxonomy, $konstanten_id, 'Basiseinheiten' );
		if ( $base_k <= 0 ) {
			$base_k = self::find_child_named( $taxonomy, $konstanten_id, 'Unit' );
		}
		$unit_dt = self::find_child_named( $taxonomy, $data_types_id, 'Unit' );
		if ( $unit_dt <= 0 ) {
			$unit_dt = self::find_child_named( $taxonomy, $data_types_id, 'Basiseinheiten' );
		}
		if ( $unit_dt > 0 && $base_k > 0 && $unit_dt !== $base_k ) {
			$with_k     = self::find_child_named( $taxonomy, $base_k, 'With prefix' );
			$without_k  = self::find_child_named( $taxonomy, $base_k, 'Without prefix' );
			$with_dt    = self::find_child_named( $taxonomy, $unit_dt, 'With prefix' );
			$without_dt = self::find_child_named( $taxonomy, $unit_dt, 'Without prefix' );
			if ( $with_dt > 0 && $with_k > 0 && $with_dt !== $with_k ) {
				self::reparent_all_children( $taxonomy, $with_dt, $with_k );
				self::maybe_trash_empty_folder( $taxonomy, $with_dt );
			} elseif ( $with_dt > 0 && $with_k <= 0 ) {
				wp_update_term( $with_dt, $taxonomy, array( 'parent' => $base_k ) );
			}
			if ( $without_dt > 0 && $without_k > 0 && $without_dt !== $without_k ) {
				self::reparent_all_children( $taxonomy, $without_dt, $without_k );
				self::maybe_trash_empty_folder( $taxonomy, $without_dt );
			} elseif ( $without_dt > 0 && $without_k <= 0 ) {
				wp_update_term( $without_dt, $taxonomy, array( 'parent' => $base_k ) );
			}
			self::maybe_trash_empty_folder( $taxonomy, $unit_dt );
		} elseif ( $unit_dt > 0 && $base_k <= 0 ) {
			wp_update_term(
				$unit_dt,
				$taxonomy,
				array(
					'parent' => $konstanten_id,
					'name'   => 'Basiseinheiten',
					'slug'   => 'basiseinheiten',
				)
			);
		}

		$bau_k  = self::find_child_named( $taxonomy, $konstanten_id, 'Bauformen' );
		$bau_dt = self::find_child_named( $taxonomy, $data_types_id, 'Bauformen' );
		if ( $bau_dt > 0 && $bau_k > 0 && $bau_dt !== $bau_k ) {
			self::reparent_all_children( $taxonomy, $bau_dt, $bau_k );
			self::maybe_trash_empty_folder( $taxonomy, $bau_dt );
		} elseif ( $bau_dt > 0 && $bau_k <= 0 ) {
			wp_update_term( $bau_dt, $taxonomy, array( 'parent' => $konstanten_id ) );
		}

		/* Währung → Konstanten top-level (not nested under Without prefix). */
		$waehrung_k = self::find_child_named( $taxonomy, $konstanten_id, 'Währung' );
		if ( $waehrung_k <= 0 ) {
			$waehrung_k = self::find_child_named( $taxonomy, $konstanten_id, 'Waehrung' );
		}
		$without_live = self::find_catalog_folder( $taxonomy, 'without_prefix' );
		$waehrung_src = 0;
		if ( $without_live > 0 ) {
			$waehrung_src = self::find_child_named( $taxonomy, $without_live, 'Währung' );
			if ( $waehrung_src <= 0 ) {
				$waehrung_src = self::find_child_named( $taxonomy, $without_live, 'Waehrung' );
			}
		}
		if ( $waehrung_src > 0 && $waehrung_k > 0 && $waehrung_src !== $waehrung_k ) {
			self::reparent_all_children( $taxonomy, $waehrung_src, $waehrung_k );
			self::maybe_trash_empty_folder( $taxonomy, $waehrung_src );
		} elseif ( $waehrung_src > 0 && $waehrung_k <= 0 ) {
			wp_update_term( $waehrung_src, $taxonomy, array( 'parent' => $konstanten_id ) );
		}

		/* Drop empty interim Data Types catalog shells (Präfixe / Unit / Bauformen). */
		self::trash_empty_data_types_catalog_shells( $taxonomy, $data_types_id );

		Node_Type::ensure_konstanten_child_list_preferred( $taxonomy, $konstanten_id );
	}

	/**
	 * Trash empty Data Types / Präfixe|Unit|Bauformen shells left after migrate to Konstanten.
	 */
	private static function trash_empty_data_types_catalog_shells( string $taxonomy, int $data_types_id ): void {
		if ( $data_types_id <= 0 ) {
			return;
		}
		foreach ( array( 'Präfixe', 'Praefixe', 'Bauformen', 'Unit', 'Basiseinheiten' ) as $name ) {
			$id = self::find_child_named( $taxonomy, $data_types_id, $name );
			if ( $id <= 0 || Trash::is_trashed( $id ) ) {
				continue;
			}
			/* Unit may still hold empty With/Without — trash those first. */
			if ( in_array( $name, array( 'Unit', 'Basiseinheiten' ), true ) ) {
				foreach ( array( 'With prefix', 'Mit Präfix', 'Without prefix', 'Ohne Präfix' ) as $bucket ) {
					$bid = self::find_child_named( $taxonomy, $id, $bucket );
					if ( $bid > 0 ) {
						self::maybe_trash_empty_folder( $taxonomy, $bid, true );
					}
				}
			}
			self::maybe_trash_empty_folder( $taxonomy, $id, true );
		}
	}

	/**
	 * Ensure Data Types / Unit type host: Menge + Base unit + Praefix (besteht_aus).
	 * CatalogChoice roots: Base unit → Konstanten/Basiseinheiten/With prefix; Praefix → Präfixe.
	 * Demo heir **C1** is retired (example only — do not re-seed).
	 *
	 * @return int Unit type term id, or 0.
	 */
	public static function ensure_unit_type( string $taxonomy = Taxonomy::FS ): int {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$data_types_id = self::ensure_data_types_folder( $taxonomy );
		if ( $data_types_id <= 0 ) {
			return 0;
		}

		$created  = 0;
		$existing = 0;
		$ut_id    = self::ensure_term(
			$taxonomy,
			'Unit type',
			$data_types_id,
			'Composed measure host (Amount/Menge + Base unit + optional Praefix). Heirs via child_of inherit attrs (Q66).',
			$created,
			$existing,
			array( 'Unit Type', 'UnitType' ),
			'unit-type'
		);
		if ( $ut_id <= 0 ) {
			return 0;
		}

		Node_Type::set_deletable( $ut_id, false );

		$double_id = self::find_case_datatype_id( $taxonomy, 'double' );
		$with_id   = self::find_catalog_folder( $taxonomy, 'with_prefix' );
		$praefixe_id = self::find_catalog_folder( $taxonomy, 'prefixes' );
		if ( $double_id <= 0 || $with_id <= 0 || $praefixe_id <= 0 ) {
			self::maybe_retire_unit_type_c1_demo( $taxonomy, $ut_id );
			return $ut_id;
		}

		$wanted = array(
			'Menge'      => $double_id,
			'Base unit'  => $with_id,
			'Praefix'    => $praefixe_id,
		);
		$mult = array(
			'Menge'     => '1',
			'Base unit' => '1',
			'Praefix'   => '0..1',
		);
		$bindings = array(
			'Menge'     => 'besteht_aus',
			'Base unit' => 'besteht_aus',
			'Praefix'   => 'besteht_aus',
		);
		self::sync_model_host_attributes( $taxonomy, $ut_id, $wanted, $mult, false, $bindings );

		Node_Type::ensure_preferred_render( $taxonomy, $ut_id );
		$qty_id = self::find_case_datatype_id( $taxonomy, 'quantity' );
		if ( $qty_id > 0 ) {
			$qty_pref = (string) get_term_meta( $qty_id, '_wtt_preferred_render', true );
			if ( '' !== $qty_pref ) {
				update_term_meta( $ut_id, '_wtt_preferred_render', $qty_pref );
			} else {
				update_term_meta( $ut_id, '_wtt_preferred_render', 'QuantityRenderer' );
			}
		}

		self::maybe_retire_unit_type_c1_demo( $taxonomy, $ut_id );

		return $ut_id;
	}

	/**
	 * Soft-trash the former Unit type demo heir **C1** once (seed example only).
	 * Stops ensure_term from restoring it; leaves user-created heirs alone after option set.
	 *
	 * @param int $ut_id Optional Unit type id (resolved when 0).
	 */
	public static function maybe_retire_unit_type_c1_demo( string $taxonomy = Taxonomy::FS, int $ut_id = 0 ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$opt = 'wtt_retired_unit_type_c1_' . $taxonomy;
		if ( get_option( $opt ) ) {
			return;
		}

		if ( $ut_id <= 0 ) {
			$ut_id = self::find_term_by_path(
				$taxonomy,
				array( self::ROOT_NAME, 'Definition', 'Data Types', 'Unit type' )
			);
		}
		if ( $ut_id <= 0 ) {
			update_option( $opt, 1, false );
			return;
		}

		$c1_id = 0;
		$by_slug = get_term_by( 'slug', 'unit-type-c1', $taxonomy );
		if ( $by_slug instanceof \WP_Term && (int) $by_slug->parent === $ut_id ) {
			$c1_id = (int) $by_slug->term_id;
		}
		if ( $c1_id <= 0 ) {
			$c1_id = self::find_child_named( $taxonomy, $ut_id, 'C1' );
		}

		if ( $c1_id > 0 ) {
			Node_Type::set_deletable( $c1_id, true );
			if ( ! Trash::is_trashed( $c1_id ) ) {
				Trash::move_to_trash( $taxonomy, $c1_id, true );
			}
			/* Stale Walk caches still listed C1 under Unit type Choices. */
			if ( class_exists( Attribute::class ) ) {
				Attribute::refresh_settings_walk_caches_for_type_node( $taxonomy, $ut_id, true );
			}
		}

		update_option( $opt, 1, false );
	}

	/**
	 * Q51 / attribute-choice-inheritance: Präfixe host has `multiplikator`
	 * (double, Mult=1, Default+RO+Hide). Each leaf overrides Default from SI factor.
	 * Meta `_wtt_multiplikator` stays in sync as conversion fallback.
	 */
	public static function ensure_prefix_multiplikator_attribute( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$prefixes_id = self::find_catalog_folder( $taxonomy, 'prefixes' );
		$double_id   = self::find_case_datatype_id( $taxonomy, 'double' );
		if ( $prefixes_id <= 0 || $double_id <= 0 ) {
			return;
		}

		Demo_Data::ensure_prefix_multiplikators( $taxonomy );

		self::sync_model_host_attributes(
			$taxonomy,
			$prefixes_id,
			array( 'multiplikator' => $double_id ),
			array( 'multiplikator' => '1' ),
			false,
			array( 'multiplikator' => 'besteht_aus' )
		);

		$attr_id = '';
		foreach ( Attribute::list_own( $taxonomy, $prefixes_id ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( 'multiplikator' !== strtolower( trim( (string) ( $row['name'] ?? '' ) ) ) ) {
				continue;
			}
			$attr_id = Attribute::normalize_attr_id( $row['id'] ?? '' );
			break;
		}
		if ( '' === $attr_id ) {
			return;
		}

		Attribute::set_readonly( $taxonomy, $prefixes_id, $attr_id, true );
		Attribute::set_hidden( $taxonomy, $prefixes_id, $attr_id, true );

		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $prefixes_id,
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
			$leaf_id = (int) $child->term_id;
			$factor  = Node_Type::get_multiplikator( $leaf_id );
			if ( null === $factor || $factor <= 0.0 ) {
				continue;
			}
			/* Keep meta SoT; leaf Default override on inherited attr. */
			Node_Type::set_multiplikator( $leaf_id, $factor );
			Attribute::set_fixed_values(
				$taxonomy,
				$leaf_id,
				$attr_id,
				array( (string) $factor )
			);
		}
	}

	/**
	 * OQ-W11 / Q123: ensure Unit/With prefix has named composition attrs
	 * Praefix → Präfixe catalog and Kuerzel → node_presentation (symbol).
	 * Idempotent; does not strip extras.
	 * Unit leaves (Meter, Ohm, …) stay catalog leaves — no fake attribute slots.
	 */
	public static function ensure_with_prefix_composition( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$with_id = self::find_catalog_folder( $taxonomy, 'with_prefix' );
		if ( $with_id <= 0 ) {
			return;
		}

		$praefixe_id = self::find_catalog_folder( $taxonomy, 'prefixes' );
		$pres_id     = self::find_case_datatype_id( $taxonomy, 'node_presentation' );
		if ( $pres_id <= 0 ) {
			$pres_id = self::find_case_datatype_id( $taxonomy, 'display_node_name' );
		}
		$text_id = self::find_case_datatype_id( $taxonomy, 'text' );
		$kuerzel_type = $pres_id > 0 ? $pres_id : $text_id;
		if ( $praefixe_id <= 0 || $kuerzel_type <= 0 ) {
			return;
		}

		if ( $pres_id > 0 ) {
			Node_Type::set_presentation_context( $taxonomy, $pres_id, 'form' );
		}

		Node_Type::set_deletable( $with_id, false );

		$wanted = array(
			'Praefix' => $praefixe_id,
			'Kuerzel' => $kuerzel_type,
		);
		$bindings = array(
			'Praefix' => 'besteht_aus',
			'Kuerzel' => 'besteht_aus',
		);
		/* Keep any user extras on the father knot; only ensure the two composition edges. */
		self::sync_model_host_attributes( $taxonomy, $with_id, $wanted, array(), false, $bindings );

		/* Kuerzel shows presentation.symbol of the host unit leaf (Meter → m). */
		if ( $pres_id > 0 ) {
			foreach ( Attribute::list_own( $taxonomy, $with_id ) as $row ) {
				if ( ! is_array( $row ) || (string) ( $row['name'] ?? '' ) !== 'Kuerzel' ) {
					continue;
				}
				$attr_id = Attribute::normalize_attr_id( $row['id'] ?? '' );
				if ( '' === $attr_id ) {
					break;
				}
				$extras = isset( $row['typeExtras'] ) && is_array( $row['typeExtras'] )
					? $row['typeExtras']
					: array();
				$extras['presentationContext'] = 'symbol';
				Attribute::set_type_extras( $taxonomy, $with_id, $attr_id, $extras );
				break;
			}
		}

		self::ensure_percent_unit_presentation( $taxonomy );

		/*
		 * Glyph SoT = Presentation.symbol. Align Identity shortDescription so
		 * Compact/Sample fallbacks never re-paint stale "kg" after rename.
		 */
		if ( class_exists( Node_Presentation::class ) ) {
			$unit_kids = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $with_id,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			if ( is_array( $unit_kids ) ) {
				foreach ( $unit_kids as $unit ) {
					if ( $unit instanceof \WP_Term ) {
						Node_Presentation::sync_short_description_from_symbol( (int) $unit->term_id );
					}
				}
			}
		}
	}

	/**
	 * Percent.Unit is node_presentation → default context Symbol (% glyph).
	 */
	public static function ensure_percent_unit_presentation( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$hits = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => 'Percent',
				'hide_empty' => false,
				'number'     => 5,
			)
		);
		if ( ! is_array( $hits ) ) {
			return;
		}
		foreach ( $hits as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$host_id = (int) $term->term_id;
			foreach ( Attribute::list_own( $taxonomy, $host_id ) as $row ) {
				if ( ! is_array( $row ) || 'Unit' !== (string) ( $row['name'] ?? '' ) ) {
					continue;
				}
				$type_key = strtolower( (string) ( $row['typeKey'] ?? $row['typeName'] ?? '' ) );
				if (
					'node_presentation' !== $type_key
					&& 'display_node_name' !== $type_key
					&& false === strpos( $type_key, 'node_presentation' )
					&& false === strpos( $type_key, 'display_node_name' )
				) {
					continue;
				}
				$attr_id = Attribute::normalize_attr_id( $row['id'] ?? '' );
				if ( '' === $attr_id ) {
					continue;
				}
				$cfg = isset( $row['presentationConfig'] ) && is_array( $row['presentationConfig'] )
					? $row['presentationConfig']
					: array();
				/* Keep explicit heir/relation overrides; only set when still type-default form. */
				if ( ! empty( $cfg['hasOverride'] ) ) {
					continue;
				}
				$extras = isset( $row['typeExtras'] ) && is_array( $row['typeExtras'] )
					? $row['typeExtras']
					: array();
				$extras['presentationContext'] = 'symbol';
				Attribute::set_type_extras( $taxonomy, $host_id, $attr_id, $extras );
			}
		}
	}

	/**
	 * OQ-W10/W11: ensure `size` as child_of `quantity` with Value→double, Unit→With prefix.
	 *
	 * @return int size term id (0 if unavailable).
	 */
	public static function ensure_size_datatype( string $taxonomy = Taxonomy::FS ): int {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$quantity_id = self::find_case_datatype_id( $taxonomy, 'quantity' );
		if ( $quantity_id <= 0 ) {
			return 0;
		}

		$created  = 0;
		$existing = 0;
		$size_id  = self::ensure_term(
			$taxonomy,
			'size',
			$quantity_id,
			'Physical size quantity: Value + Unit (With prefix). Specialization of quantity (OQ-W10/W11).',
			$created,
			$existing,
			array( 'Größe', 'Groesse' ),
			'size-quantity'
		);
		if ( $size_id <= 0 ) {
			return 0;
		}

		$term = get_term( $size_id, $taxonomy );
		if ( $term instanceof \WP_Term && (int) $term->parent !== $quantity_id ) {
			wp_update_term( $size_id, $taxonomy, array( 'parent' => $quantity_id ) );
		}

		Node_Type::set_deletable( $size_id, false );
		Node_Type::set_type_id( $taxonomy, $size_id, $quantity_id, true );

		/* Keep size Preferred aligned with quantity (QuantityRenderer compact chrome). */
		$qty_pref = (string) get_term_meta( $quantity_id, '_wtt_preferred_render', true );
		$want_pref = '' !== $qty_pref ? $qty_pref : 'QuantityRenderer';
		$pref      = (string) get_term_meta( $size_id, '_wtt_preferred_render', true );
		if ( '' === $pref || Renderer::Form->value === Node_Type::normalize_preferred_render( $pref ) ) {
			update_term_meta( $size_id, '_wtt_preferred_render', $want_pref );
		}

		$double_id = self::find_case_datatype_id( $taxonomy, 'double' );
		$with_id   = self::find_catalog_folder( $taxonomy, 'with_prefix' );
		if ( $double_id <= 0 || $with_id <= 0 ) {
			return $size_id;
		}

		$wanted = array(
			'Value' => $double_id,
			'Unit'  => $with_id,
		);
		$bindings = array(
			'Value' => 'besteht_aus',
			'Unit'  => 'besteht_aus',
		);
		self::sync_model_host_attributes( $taxonomy, $size_id, $wanted, array(), true, $bindings );

		return $size_id;
	}

	/**
	 * Align Model/Bauteil/Passiv Wert → size when safe (idempotent; no BOM Wert→Bauteil).
	 */
	public static function ensure_passiv_wert_size( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$size_id = self::ensure_size_datatype( $taxonomy );
		if ( $size_id <= 0 ) {
			return;
		}

		$passiv_id = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Model', 'Bauteil', 'Passiv' )
		);
		if ( $passiv_id <= 0 ) {
			return;
		}

		$quantity_id = self::find_case_datatype_id( $taxonomy, 'quantity' );
		$double_id   = self::find_case_datatype_id( $taxonomy, 'double' );

		$have_wert = false;
		foreach ( Attribute::list_own( $taxonomy, $passiv_id ) as $row ) {
			if ( 'Wert' !== (string) ( $row['name'] ?? '' ) ) {
				continue;
			}
			$have_wert = true;
			$attr_id   = Attribute::normalize_attr_id( $row['id'] ?? '' );
			$type_id   = (int) ( $row['typeId'] ?? 0 );
			if ( '' === $attr_id ) {
				break;
			}
			Attribute::set_binding( $taxonomy, $passiv_id, $attr_id, 'besteht_aus' );
			if ( $type_id === $size_id ) {
				break;
			}
			/* Retype only soft quantity shapes — never Bauteil / media / catalogs. */
			$safe = ( $type_id === $quantity_id && $quantity_id > 0 )
				|| ( $type_id === $double_id && $double_id > 0 )
				|| 0 === $type_id;
			if ( $safe ) {
				Attribute::set_type( $taxonomy, $passiv_id, $attr_id, $size_id );
			}
			break;
		}

		if ( ! $have_wert ) {
			Attribute::add(
				$taxonomy,
				$passiv_id,
				'Wert',
				$size_id,
				'1',
				'besteht_aus'
			);
		}
	}

	/**
	 * Public repair entry: With prefix composition + size + Passiv Wert→size (OQ-W11).
	 */
	public static function ensure_unit_quantity_structure( string $taxonomy = Taxonomy::FS ): void {
		self::ensure_with_prefix_composition( $taxonomy );
		self::ensure_size_datatype( $taxonomy );
		self::ensure_passiv_wert_size( $taxonomy );
	}

	/**
	 * With-prefix unit leaves inherit Meter’s SI allowlist when still empty
	 * (Ohm/Farad/… otherwise show Value+Unit with no Präfix chrome).
	 */
	private static function ensure_with_prefix_default_allowlists( string $taxonomy ): void {
		$with_id = self::find_catalog_folder( $taxonomy, 'with_prefix' );
		if ( $with_id <= 0 ) {
			return;
		}

		$meter_id = self::find_child_named( $taxonomy, $with_id, 'Meter' );
		$template = $meter_id > 0
			? Node_Type::get_allowed_prefix_ids( $meter_id )
			: array();
		if ( array() === $template ) {
			$prefixes_id = self::find_catalog_folder( $taxonomy, 'prefixes' );
			if ( $prefixes_id > 0 ) {
				$kids = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'parent'     => $prefixes_id,
						'hide_empty' => false,
						'fields'     => 'ids',
						'number'     => 0,
					)
				);
				if ( is_array( $kids ) ) {
					foreach ( $kids as $kid_id ) {
						$template[] = (int) $kid_id;
					}
				}
			}
		}
		if ( array() === $template ) {
			/* Still heal glyph shortDescription even when allowlists already set. */
			if ( class_exists( Node_Presentation::class ) ) {
				$units_only = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'parent'     => $with_id,
						'hide_empty' => false,
						'number'     => 0,
					)
				);
				if ( is_array( $units_only ) ) {
					foreach ( $units_only as $unit ) {
						if ( $unit instanceof \WP_Term ) {
							Node_Presentation::sync_short_description_from_symbol( (int) $unit->term_id );
						}
					}
				}
			}
			return;
		}

		$units = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $with_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $units ) ) {
			return;
		}
		foreach ( $units as $unit ) {
			if ( ! $unit instanceof \WP_Term ) {
				continue;
			}
			$uid = (int) $unit->term_id;
			if ( ! Node_Type::is_basiseinheit_unit_node( $taxonomy, $uid ) ) {
				continue;
			}
			$have = Node_Type::get_allowed_prefix_ids( $uid );
			if ( array() === $have && array() !== $template ) {
				Node_Type::set_allowed_prefix_ids( $taxonomy, $uid, $template );
			}
			/* Q91/Q120: unit leaf Preferred = UnitRenderer (Prefix? + Symbol). */
			$pref = Node_Type::get_preferred_render( $uid );
			if (
				Renderer::Form->value === $pref
				|| Renderer::Quantity->value === $pref
			) {
				Node_Type::set_preferred_render( $taxonomy, $uid, Renderer::Unit->value );
			}
		}

		/*
		 * Unit glyph SoT = Presentation.symbol (seed shortDescription = g for mass).
		 * Never force "kg" into shortDescription — that broke renamed Gramm and
		 * Compact/node_presentation fallbacks (Q117). Keep Identity short in sync.
		 */
		if ( class_exists( Node_Presentation::class ) ) {
			foreach ( $units as $unit ) {
				if ( ! $unit instanceof \WP_Term ) {
					continue;
				}
				Node_Presentation::sync_short_description_from_symbol( (int) $unit->term_id );
			}
		}
	}

	/**
	 * After catalog moves, attribute slots may still type_id empty legacy folders.
	 * Rebind to live Konstanten roots (fall back via find_catalog_folder).
	 */
	private static function remap_stale_unit_catalog_attribute_types( string $taxonomy ): void {
		$live_prefixes = self::find_catalog_folder( $taxonomy, 'prefixes' );
		$live_unit     = self::find_catalog_folder( $taxonomy, 'basiseinheiten' );
		if ( $live_prefixes <= 0 && $live_unit <= 0 ) {
			return;
		}

		$stale_prefix = array();
		$stale_unit   = array();
		foreach ( array( 'Präfixe', 'Praefixe' ) as $pname ) {
			$hits = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'name'       => $pname,
					'number'     => 0,
				)
			);
			if ( ! is_array( $hits ) ) {
				continue;
			}
			foreach ( $hits as $hit ) {
				if ( ! $hit instanceof \WP_Term ) {
					continue;
				}
				$id = (int) $hit->term_id;
				if ( $live_prefixes > 0 && $id === $live_prefixes ) {
					continue;
				}
				$kids = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'parent'     => $id,
						'hide_empty' => false,
						'fields'     => 'ids',
						'number'     => 1,
					)
				);
				if ( is_array( $kids ) && array() === $kids ) {
					$stale_prefix[ $id ] = true;
				}
			}
		}
		foreach ( array( 'Basiseinheiten', 'Basiseinheit' ) as $uname ) {
			$hits = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'name'       => $uname,
					'number'     => 0,
				)
			);
			if ( ! is_array( $hits ) ) {
				continue;
			}
			foreach ( $hits as $hit ) {
				if ( ! $hit instanceof \WP_Term ) {
					continue;
				}
				$id = (int) $hit->term_id;
				$kids = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'parent'     => $id,
						'hide_empty' => false,
						'fields'     => 'ids',
						'number'     => 1,
					)
				);
				if ( is_array( $kids ) && array() === $kids ) {
					$stale_unit[ $id ] = true;
				}
			}
		}

		if ( array() === $stale_prefix && array() === $stale_unit ) {
			return;
		}

		$slots = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Node_Type::META_KEY,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		if ( ! is_array( $slots ) ) {
			return;
		}

		foreach ( $slots as $slot ) {
			if ( ! $slot instanceof \WP_Term ) {
				continue;
			}
			$slot_id = (int) $slot->term_id;
			if ( ! Attribute::is_slot( $slot_id ) ) {
				continue;
			}
			$type_id = (int) get_term_meta( $slot_id, Node_Type::META_KEY, true );
			if ( $type_id <= 0 ) {
				continue;
			}
			$target = 0;
			if ( $live_prefixes > 0 && isset( $stale_prefix[ $type_id ] ) ) {
				$target = $live_prefixes;
			} elseif ( $live_unit > 0 && isset( $stale_unit[ $type_id ] ) ) {
				$target = $live_unit;
			}
			if ( $target <= 0 || $target === $type_id ) {
				continue;
			}
			Node_Type::set_type_id( $taxonomy, $slot_id, $target, true );
		}
	}

	/**
	 * Create Präfixe / Basiseinheiten / With|Without prefix / Bauformen / Währung under Konstanten.
	 */
	private static function ensure_unit_catalog_folder_shells(
		string $taxonomy,
		int $konstanten_id,
		int &$created,
		int &$existing
	): void {
		if ( $konstanten_id <= 0 ) {
			return;
		}

		$prefixes = self::ensure_term(
			$taxonomy,
			'Präfixe',
			$konstanten_id,
			'Global prefix catalog. multiplikator = scale vs the unit’s prefix root (Q51).',
			$created,
			$existing
		);
		if ( $prefixes > 0 ) {
			Node_Type::set_deletable( $prefixes, false );
		}

		$base = self::ensure_term(
			$taxonomy,
			'Basiseinheiten',
			$konstanten_id,
			'Base unit catalog (Q120). Concrete units under With prefix / Without prefix.',
			$created,
			$existing,
			array( 'Unit', 'Basiseinheit' ),
			'basiseinheiten'
		);
		if ( $base > 0 ) {
			Node_Type::set_deletable( $base, false );
			$with = self::ensure_term(
				$taxonomy,
				'With prefix',
				$base,
				'Units that may marry SI prefixes (allowlist on each unit).',
				$created,
				$existing
			);
			if ( $with > 0 ) {
				Node_Type::set_deletable( $with, false );
			}
			$without = self::ensure_term(
				$taxonomy,
				'Without prefix',
				$base,
				'Units with prefix allowlist empty (Stück, temperature).',
				$created,
				$existing
			);
			if ( $without > 0 ) {
				Node_Type::set_deletable( $without, false );
			}
		}

		$bau = self::ensure_term(
			$taxonomy,
			'Bauformen',
			$konstanten_id,
			'Package / footprint catalog (axial, radial, SMD sizes).',
			$created,
			$existing
		);
		if ( $bau > 0 ) {
			Node_Type::set_deletable( $bau, false );
		}

		$waehrung = self::ensure_term(
			$taxonomy,
			'Währung',
			$konstanten_id,
			'Currency catalog (Euro / US Dollar / Pound). CatalogChoice host for Preis.',
			$created,
			$existing,
			array( 'Waehrung', 'Currency' ),
			'waehrung-units'
		);
		if ( $waehrung > 0 ) {
			Node_Type::set_deletable( $waehrung, false );
		}
	}

	/**
	 * Keep one child per display name (lowest term_id). Trash extras; remap prefix allowlists.
	 *
	 * @return int Number of duplicate terms trashed.
	 */
	public static function dedupe_same_name_children( string $taxonomy, int $parent_id ): int {
		if ( $parent_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
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
		if ( ! is_array( $kids ) || empty( $kids ) ) {
			return 0;
		}

		$by_name = array();
		foreach ( $kids as $kid ) {
			if ( ! $kid instanceof \WP_Term ) {
				continue;
			}
			$key = $kid->name;
			if ( ! isset( $by_name[ $key ] ) ) {
				$by_name[ $key ] = array();
			}
			$by_name[ $key ][] = (int) $kid->term_id;
		}

		$id_map  = array(); /* discarded → kept */
		$trashed = 0;
		foreach ( $by_name as $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			sort( $ids, SORT_NUMERIC );
			$keep = (int) $ids[0];
			for ( $i = 1, $n = count( $ids ); $i < $n; $i++ ) {
				$drop = (int) $ids[ $i ];
				$id_map[ $drop ] = $keep;
				/* Prefer multiplikator from either side. */
				$keep_m = Node_Type::get_multiplikator( $keep );
				$drop_m = Node_Type::get_multiplikator( $drop );
				if ( ( null === $keep_m || $keep_m <= 0.0 ) && null !== $drop_m && $drop_m > 0.0 ) {
					Node_Type::set_multiplikator( $keep, $drop_m );
				}
				Node_Type::set_deletable( $drop, true );
				/* Permanent remove — soft-trash stays under the same parent and can reappear via restore_subtree. */
				$result = wp_delete_term( $drop, $taxonomy );
				if ( ! is_wp_error( $result ) && false !== $result ) {
					++$trashed;
				} else {
					Trash::move_to_trash( $taxonomy, $drop, true );
					++$trashed;
				}
			}
		}

		if ( array() !== $id_map ) {
			self::remap_allowed_prefix_ids( $taxonomy, $id_map );
		}

		return $trashed;
	}

	/**
	 * Replace discarded prefix ids inside unit allowlists.
	 *
	 * @param array<int, int> $id_map discarded_id → kept_id
	 */
	private static function remap_allowed_prefix_ids( string $taxonomy, array $id_map ): void {
		if ( array() === $id_map ) {
			return;
		}
		$meta_key = Node_Type::META_KEY_ALLOWED_PREFIX_IDS;
		$terms    = get_terms(
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
			$raw = get_term_meta( (int) $term->term_id, $meta_key, true );
			if ( ! is_array( $raw ) || array() === $raw ) {
				continue;
			}
			$changed = false;
			$next    = array();
			$seen    = array();
			foreach ( $raw as $id ) {
				$id = (int) $id;
				if ( isset( $id_map[ $id ] ) ) {
					$id      = (int) $id_map[ $id ];
					$changed = true;
				}
				if ( $id <= 0 || isset( $seen[ $id ] ) ) {
					$changed = true;
					continue;
				}
				$seen[ $id ] = true;
				$next[]      = $id;
			}
			if ( $changed ) {
				update_term_meta( (int) $term->term_id, $meta_key, array_values( $next ) );
			}
		}
	}

	/**
	 * Without prefix must host Währung as CatalogChoice folder — not flat Euro/Dollar siblings.
	 * Removes accidental seed duplicates beside the Währung host.
	 */
	private static function cleanup_currency_flat_siblings( string $taxonomy, int $without_prefix_id ): void {
		if ( $without_prefix_id <= 0 ) {
			return;
		}
		$waehrung_id = self::find_child_named( $taxonomy, $without_prefix_id, 'Währung' );
		if ( $waehrung_id <= 0 ) {
			$waehrung_id = self::find_child_named( $taxonomy, $without_prefix_id, 'Waehrung' );
		}
		if ( $waehrung_id <= 0 ) {
			$waehrung_id = self::find_child_named( $taxonomy, $without_prefix_id, 'Currency' );
		}
		if ( $waehrung_id <= 0 ) {
			return;
		}

		$currency_names = array();
		$host_kids      = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $waehrung_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_array( $host_kids ) ) {
			foreach ( $host_kids as $kid ) {
				if ( $kid instanceof \WP_Term ) {
					$currency_names[ $kid->name ] = true;
				}
			}
		}
		/* Always treat common leaves as currency even if host empty. */
		foreach ( array( 'Euro', 'US Dollar', 'Pound', 'Dollar', 'GBP' ) as $n ) {
			$currency_names[ $n ] = true;
		}

		$siblings = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $without_prefix_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $siblings ) ) {
			return;
		}
		foreach ( $siblings as $sib ) {
			if ( ! $sib instanceof \WP_Term ) {
				continue;
			}
			$sid = (int) $sib->term_id;
			if ( $sid === $waehrung_id ) {
				continue;
			}
			if ( ! isset( $currency_names[ $sib->name ] ) ) {
				continue;
			}
			/* Prefer keeping the copy under Währung host. */
			$under_host = self::find_child_named( $taxonomy, $waehrung_id, $sib->name );
			if ( $under_host <= 0 ) {
				/* Move stray into host instead of deleting. */
				wp_update_term( $sid, $taxonomy, array( 'parent' => $waehrung_id ) );
				continue;
			}
			Node_Type::set_deletable( $sid, true );
			$result = wp_delete_term( $sid, $taxonomy );
			if ( is_wp_error( $result ) || false === $result ) {
				Trash::move_to_trash( $taxonomy, $sid, true );
			}
		}
	}

	/**
	 * Blueprint slices for Data Types: Präfixe, Unit buckets, Bauformen (no Simple/Complex).
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function konstanten_catalog_blueprint(): array {
		$full = self::blueprint();
		$root = isset( $full[0] ) && is_array( $full[0] ) ? $full[0] : null;
		if ( null === $root ) {
			return array();
		}
		foreach ( (array) ( $root['children'] ?? array() ) as $child ) {
			if ( ! is_array( $child ) || 'Definition' !== ( $child['name'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $child['children'] ?? array() ) as $def_child ) {
				if ( ! is_array( $def_child ) || 'Konstanten' !== ( $def_child['name'] ?? '' ) ) {
					continue;
				}
				return is_array( $def_child['children'] ?? null )
					? (array) $def_child['children']
					: array();
			}
		}
		return array();
	}

	/**
	 * @deprecated 0.0.458 Use konstanten_catalog_blueprint(); kept for any external callers.
	 */
	private static function data_types_unit_catalog_blueprint(): array {
		return self::konstanten_catalog_blueprint();
	}

	/**
	 * @return list<string>
	 */
	private static function unit_names_without_prefix(): array {
		return array( 'Kelvin', 'Celsius', 'Stück', 'Stuck', 'Stueck' );
	}

	private static function reparent_all_children( string $taxonomy, int $from_parent, int $to_parent ): void {
		if ( $from_parent <= 0 || $to_parent <= 0 || $from_parent === $to_parent ) {
			return;
		}
		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $from_parent,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $kids ) ) {
			return;
		}
		foreach ( $kids as $kid ) {
			if ( $kid instanceof \WP_Term ) {
				wp_update_term( (int) $kid->term_id, $taxonomy, array( 'parent' => $to_parent ) );
			}
		}
	}

	private static function reparent_units_into_prefix_buckets(
		string $taxonomy,
		int $legacy_base,
		int $with_id,
		int $without_id
	): void {
		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $legacy_base,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $kids ) ) {
			return;
		}
		$without = array_fill_keys( self::unit_names_without_prefix(), true );
		foreach ( $kids as $kid ) {
			if ( ! $kid instanceof \WP_Term ) {
				continue;
			}
			$target = isset( $without[ $kid->name ] ) ? $without_id : $with_id;
			if ( (int) $kid->parent !== $target ) {
				wp_update_term( (int) $kid->term_id, $taxonomy, array( 'parent' => $target ) );
			}
		}
	}

	private static function maybe_trash_empty_folder( string $taxonomy, int $term_id, bool $force = false ): void {
		if ( $term_id <= 0 ) {
			return;
		}
		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_array( $kids ) ) {
			foreach ( $kids as $kid ) {
				if ( ! $kid instanceof \WP_Term ) {
					continue;
				}
				/* Soft-trashed children do not keep the folder "alive". */
				if ( Trash::is_trashed( (int) $kid->term_id ) ) {
					continue;
				}
				return;
			}
		}
		if ( $force && ! Node_Type::is_deletable( $term_id ) ) {
			Node_Type::set_deletable( $term_id, true );
		}
		Trash::move_to_trash( $taxonomy, $term_id, false );
	}

	/**
	 * Flags + restore for Währung catalog folder and leaves (under Unit/Without prefix).
	 * Children inherit type = Währung (CatalogChoice host for Preis).
	 */
	private static function configure_konstanten_waehrung( string $taxonomy, int $parent_hint ): void {
		$waehrung_id = self::find_catalog_folder( $taxonomy, 'waehrung' );
		if ( $waehrung_id <= 0 && $parent_hint > 0 ) {
			$waehrung_id = self::find_child_named( $taxonomy, $parent_hint, 'Währung' );
			if ( $waehrung_id <= 0 ) {
				$waehrung_id = self::find_child_named( $taxonomy, $parent_hint, 'Waehrung' );
			}
		}
		if ( $waehrung_id <= 0 && $parent_hint > 0 ) {
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => 'Währung',
					'parent'     => $parent_hint,
					'hide_empty' => false,
					'number'     => 1,
				)
			);
			if ( is_array( $found ) && isset( $found[0] ) && $found[0] instanceof \WP_Term ) {
				$waehrung_id = (int) $found[0]->term_id;
			}
		}
		if ( $waehrung_id <= 0 ) {
			return;
		}

		Trash::restore_subtree( $taxonomy, $waehrung_id );
		Node_Type::set_deletable( $waehrung_id, false );

		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $waehrung_id,
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
			Node_Type::set_deletable( $kid_id, false );
			/* Specialization under Währung — type = father (Q88 / CatalogChoice). */
			Node_Type::set_type_id( $taxonomy, $kid_id, $waehrung_id, true );
		}
	}

	/**
	 * Quantity → Preis example: Wert + Währung (not SI Basiseinheit).
	 * Keeps quantity catalog preview on money chrome (€ / $ / £).
	 */
	private static function ensure_quantity_preis_example( string $taxonomy ): void {
		$quantity_id = self::find_case_datatype_id( $taxonomy, 'quantity' );
		if ( $quantity_id <= 0 ) {
			return;
		}
		$waehrung_id = self::find_catalog_folder( $taxonomy, 'waehrung' );
		if ( $waehrung_id <= 0 ) {
			return;
		}
		$double_id = self::find_case_datatype_id( $taxonomy, 'double' );
		if ( $double_id <= 0 ) {
			return;
		}

		$created  = 0;
		$existing = 0;
		$preis_id = self::ensure_term(
			$taxonomy,
			'Preis',
			$quantity_id,
			'Money amount + currency (quantity example).',
			$created,
			$existing,
			array(),
			'preis-quantity'
		);
		if ( $preis_id <= 0 ) {
			return;
		}

		Node_Type::set_deletable( $preis_id, false );
		Node_Type::set_type_id( $taxonomy, $preis_id, $quantity_id, true );
		Node_Type::ensure_preferred_render( $taxonomy, $preis_id );
		/* Prefer Quantity chrome when the type default is QuantityRenderer. */
		$qty_pref = (string) get_term_meta( $quantity_id, '_wtt_preferred_render', true );
		if ( '' !== $qty_pref ) {
			update_term_meta( $preis_id, '_wtt_preferred_render', $qty_pref );
		} else {
			update_term_meta( $preis_id, '_wtt_preferred_render', 'QuantityRenderer' );
		}

		$wanted = array(
			'Wert'    => $double_id,
			'Währung' => $waehrung_id,
		);
		self::sync_model_host_attributes( $taxonomy, $preis_id, $wanted, array(), true, array() );

		/* Festwert Euro when present — constant currency symbol beside the amount. */
		$euro_id = self::find_child_named( $taxonomy, $waehrung_id, 'Euro' );
		if ( $euro_id <= 0 ) {
			return;
		}
		$attrs = Attribute::list_own( $taxonomy, $preis_id );
		foreach ( $attrs as $row ) {
			if ( ! is_array( $row ) || 'Währung' !== (string) ( $row['name'] ?? '' ) ) {
				continue;
			}
			$attr_id = Attribute::normalize_attr_id( $row['id'] ?? '' );
			if ( '' === $attr_id ) {
				continue;
			}
			$existing_fixed = isset( $row['fixedValues'] ) && is_array( $row['fixedValues'] )
				? $row['fixedValues']
				: array();
			if ( array() !== $existing_fixed ) {
				break;
			}
			Attribute::set_fixed_values( $taxonomy, $preis_id, $attr_id, (string) $euro_id );
			break;
		}
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
	private static function strip_obsolete_prefix_aliases( string $taxonomy, int $prefixes_or_parent_id ): void {
		$praefixe_id = self::find_catalog_folder( $taxonomy, 'prefixes' );
		if ( $praefixe_id <= 0 ) {
			$praefixe_id = self::find_child_named( $taxonomy, $prefixes_or_parent_id, 'Präfixe' );
		}
		if ( $praefixe_id <= 0 ) {
			$praefixe_id = self::find_child_named( $taxonomy, $prefixes_or_parent_id, 'Praefixe' );
		}
		if ( $praefixe_id <= 0 && $prefixes_or_parent_id > 0 ) {
			$term = get_term( $prefixes_or_parent_id, $taxonomy );
			if ( $term instanceof \WP_Term && in_array( $term->name, array( 'Präfixe', 'Praefixe' ), true ) ) {
				$praefixe_id = $prefixes_or_parent_id;
			}
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
	private static function configure_konstanten_bauformen( string $taxonomy, int $parent_hint ): void {
		$bauformen_id = self::find_catalog_folder( $taxonomy, 'bauformen' );
		if ( $bauformen_id <= 0 && $parent_hint > 0 ) {
			/* Fallback: direct child of hint (Konstanten or legacy Data Types). */
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name'       => 'Bauformen',
					'parent'     => $parent_hint,
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
			'child_of'            => 'Hierarchy Kind von (system). Multiplicity always 1 (exactly one parent). Not creatable via Add relation — use Reparent.',
			'ref_scope'           => 'Catalog root for node_embed / node_ref (system).',
			'besteht_aus'         => 'Composition / besteht aus — dies with the object (Q75/Q85; attribute Bindung).',
			'aggregation'         => 'Aggregation — member lives on when the host object no longer exists (attribute Bindung).',
			'calc'                => 'Calculation (Q125): settings.data.op + optional props. First op default_from (= Q124 seed). Later scale_factor / scale_ref / contains. SI Präfix not via calc.',
			'defaultvalue_from'   => 'Legacy alias for calc + op=default_from (Q124). Prefer RelationType calc. Hidden from Add when calc exists.',
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
		Node_Type::set_type_id( $taxonomy, $root, $knoten, true );
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
	 * Hard-delete every term in the case taxonomy (Fallstudie, attribute slots,
	 * Trash/Hidden bins, band orphans). Soft-delete / deletable flags are ignored.
	 *
	 * Safe for scaffold reset only — does not touch posts, users, or other taxonomies.
	 *
	 * @return int|\WP_Error Number of terms permanently deleted.
	 */
	public static function wipe_all_terms( string $taxonomy = Taxonomy::FS ) {
		if ( Taxonomy::FS !== $taxonomy ) {
			return new \WP_Error(
				'wtt_bad_taxonomy',
				__( 'Case study wipe only applies to wtt_fs.', 'wp-taxonomy-tree' )
			);
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$can_delete = ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'WTT_ALLOW_DEMO_MUTATIONS' ) && WTT_ALLOW_DEMO_MUTATIONS )
			|| current_user_can( Capabilities::delete_terms( $taxonomy ) );
		if ( ! $can_delete ) {
			return new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) );
		}

		$ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			Catalog_Bindings::set_for_taxonomy( $taxonomy, array() );
			Model_Data::clear_taxonomy( $taxonomy );
			return 0;
		}

		$ids = array_map( 'intval', $ids );
		usort(
			$ids,
			static function ( int $a, int $b ) use ( $taxonomy ): int {
				$da = count( get_ancestors( $a, $taxonomy ) );
				$db = count( get_ancestors( $b, $taxonomy ) );
				return $db <=> $da;
			}
		);

		$deleted = 0;
		foreach ( $ids as $term_id ) {
			if ( $term_id <= 0 ) {
				continue;
			}
			$term = get_term( $term_id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			Node_Type::set_deletable( $term_id, true );
			$result = wp_delete_term( $term_id, $taxonomy );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( false !== $result && 0 !== $result ) {
				++$deleted;
			}
		}

		Catalog_Bindings::set_for_taxonomy( $taxonomy, array() );
		Model_Data::clear_taxonomy( $taxonomy );

		return $deleted;
	}

	/**
	 * Wipe all wtt_fs terms (including Q87 attribute slots), then reinstall blueprint.
	 *
	 * Unlike Tree_Model::delete_term, this hard-deletes protected catalog nodes so
	 * reset cannot leave Aggregate / slots / Trash litter behind.
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

		$can_edit   = ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'WTT_ALLOW_DEMO_MUTATIONS' ) && WTT_ALLOW_DEMO_MUTATIONS )
			|| current_user_can( Capabilities::edit_terms( $taxonomy ) );
		$can_delete = ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'WTT_ALLOW_DEMO_MUTATIONS' ) && WTT_ALLOW_DEMO_MUTATIONS )
			|| current_user_can( Capabilities::delete_terms( $taxonomy ) );
		if ( ! $can_edit || ! $can_delete ) {
			return new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) );
		}

		$deleted = self::wipe_all_terms( $taxonomy );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		$install = self::install( $taxonomy );
		if ( is_wp_error( $install ) ) {
			return $install;
		}

		/* install() already ran ensures; refresh model hosts + catalog locks after wipe. */
		self::ensure_model_branch( $taxonomy );
		Catalog_Bindings::ensure( $taxonomy );
		Node_Type::ensure_hierarchy_datatype_inheritance( $taxonomy );
		Attribute::migrate_detach_hierarchy( $taxonomy );
		self::ensure_table_datatype_bands( $taxonomy );
		self::ensure_bom_implementation( $taxonomy );

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
				$da = ($a > 0 && get_term( $a, $taxonomy ) instanceof \WP_Term) ? 0 : 1;
				$db = ($b > 0 && get_term( $b, $taxonomy ) instanceof \WP_Term) ? 0 : 1;
				return $da <=> $db;
			}
		);
		foreach ( $ordered as $simple_id ) {
			$out['repaired'] += self::repair_simple_datatype_leaf_names( $taxonomy, $simple_id );
		}

		/* Keep Data Types live before resolving Simple (trashed parent hides leaves). */
		self::ensure_data_types_folder( $taxonomy );

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
			Node_Type::set_deletable( $simple, false );
		} else {
			if ( Trash::is_trashed( $simple ) ) {
				Trash::restore_subtree( $taxonomy, $simple );
			}
			$simple_term = get_term( $simple, $taxonomy );
			if ( $simple_term instanceof \WP_Term ) {
				$parent_id = (int) $simple_term->parent;
				if ( $parent_id > 0 && Trash::is_trashed( $parent_id ) ) {
					Trash::restore_subtree( $taxonomy, $parent_id );
				}
			}
			Node_Type::set_deletable( $simple, false );
		}

		$created  = 0;
		$existing = 0;
		self::install_nodes( $taxonomy, self::simple_datatype_leaves(), $simple, $created, $existing );
		$out['repaired'] += self::repair_simple_datatype_leaf_names( $taxonomy, $simple );
		self::strip_obsolete_simple_datatype_aliases( $taxonomy, $simple );
		foreach ( $found_ids as $extra_simple ) {
			if ( $extra_simple !== $simple && $extra_simple > 0 ) {
				self::strip_obsolete_simple_datatype_aliases( $taxonomy, $extra_simple );
			}
		}
		$pres_id = self::find_simple_catalog_leaf( $taxonomy, $simple, 'node_presentation' );
		$out['removed'] += self::purge_legacy_display_node_name_terms( $taxonomy, $pres_id );
		$out['created']  = $created;
		$out['existing'] = $existing;

		/* Type defaults when meta empty (preferred render/converter + validators). */
		self::ensure_simple_datatype_defaults( $taxonomy, $simple );

		/* Tree icons: standard-by-name on Simple + known scalar leaves (force catalog standards). */
		self::ensure_simple_datatype_icons( $taxonomy, $simple );

		/* Merge + remove legacy Definition/Simple (not under Data Types). */
		$out['removed'] += self::purge_legacy_definition_simple( $taxonomy, $simple );

		/* Q96: write builtin.* → leaf term ids after Simple leaves exist. */
		Catalog_Bindings::ensure_builtins( $taxonomy );

		return $out;
	}

	/**
	 * Seed preferred render/converter + default validators on Simple leaves
	 * when meta is missing (idempotent; does not overwrite customized lists).
	 */
	private static function ensure_simple_datatype_defaults( string $taxonomy, int $simple_id ): void {
		if ( $simple_id <= 0 ) {
			return;
		}
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $simple_id,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $children ) ) {
			return;
		}
		foreach ( $children as $child_id ) {
			$term_id = (int) $child_id;
			if ( $term_id <= 0 ) {
				continue;
			}
			Node_Type::ensure_preferred_render( $taxonomy, $term_id );
			Node_Type::ensure_preferred_converter( $taxonomy, $term_id );
			Node_Type::ensure_validators( $taxonomy, $term_id );
		}
	}

	/**
	 * Apply standard icons on Simple + every child leaf (implanted catalog only).
	 *
	 * Only fills empty `_wtt_icon` — never overwrites a user/custom icon on
	 * repeated ensure/install. Scoped to this Simple parent + its WP children.
	 * Simple parent falls back to `marker` when still empty.
	 */
	private static function ensure_simple_datatype_icons( string $taxonomy, int $simple_id ): void {
		if ( $simple_id <= 0 ) {
			return;
		}

		$apply_term = static function ( int $term_id, bool $is_simple_parent = false ) use ( $taxonomy ): void {
			$term = get_term( $term_id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				return;
			}
			$current = Tree_Icons::get( $term_id );
			if ( '' !== $current ) {
				return;
			}
			$key = Tree_Icons::standard_for_name( (string) $term->name );
			if ( '' === $key && $is_simple_parent ) {
				$key = 'marker';
			}
			if ( '' === $key ) {
				return;
			}
			Tree_Icons::apply_standard( $taxonomy, $term_id, $key );
		};

		$apply_term( $simple_id, true );

		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $simple_id,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $children ) ) {
			return;
		}
		foreach ( $children as $child_id ) {
			$apply_term( (int) $child_id, false );
		}
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
	 * Ensure Complex catalog leaves exist (idempotent).
	 * Seeds quantity / set / legacy table only; soft-trashes Q90/OQ-W15 parked kinds.
	 *
	 * @return array{created:int,existing:int,restored:int,purged:int}
	 */
	public static function ensure_complex_datatypes( string $taxonomy = Taxonomy::FS ): array {
		$out = array(
			'created'  => 0,
			'existing' => 0,
			'restored' => 0,
			'purged'   => 0,
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
				'Composed / specialty types (quantity, set). Parked collection kinds are not seeded.',
				$created,
				$existing
			);
			if ( $complex <= 0 ) {
				return $out;
			}
		}

		Node_Type::set_deletable( $complex, false );
		if ( ! Trash::is_trashed( $complex ) ) {
			/* Do not restore whole Complex — would revive parked leaves. */
		}

		$created  = 0;
		$existing = 0;
		self::install_nodes( $taxonomy, self::complex_datatype_leaves(), $complex, $created, $existing );
		$out['created']  = $created;
		$out['existing'] = $existing;

		/* Flags + untrash only active catalog leaves. */
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
			Node_Type::set_deletable( $leaf_id, false );
		}

		$out['purged'] = self::purge_parked_complex_types( $taxonomy, $complex );

		/* Bauart CatalogChoice host under Complex (was under parked enum). */
		self::ensure_bauart_enum( $taxonomy );

		/* Q96: bind quantity after Complex leaves exist. */
		Catalog_Bindings::ensure_builtins( $taxonomy );

		/* OQ-W10/W11: size under quantity (needs Unit/With prefix from ensure_unit_catalog). */
		self::ensure_size_datatype( $taxonomy );

		return $out;
	}

	/**
	 * Soft-trash Q90 / OQ-W15 Complex leaves. Reparents Bauart (and other enum kids)
	 * onto Complex before removing `enum`.
	 *
	 * @return int Number of top-level parked nodes trashed.
	 */
	private static function purge_parked_complex_types( string $taxonomy, int $complex_id ): int {
		if ( $complex_id <= 0 ) {
			return 0;
		}
		$purged = 0;

		$enum_id = self::find_child_named( $taxonomy, $complex_id, 'enum' );
		if ( $enum_id > 0 && ! Trash::is_trashed( $enum_id ) ) {
			$kids = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $enum_id,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			if ( is_array( $kids ) ) {
				foreach ( $kids as $kid ) {
					if ( ! $kid instanceof \WP_Term ) {
						continue;
					}
					if ( Trash::is_trashed( (int) $kid->term_id ) ) {
						continue;
					}
					wp_update_term(
						(int) $kid->term_id,
						$taxonomy,
						array( 'parent' => $complex_id )
					);
				}
			}
		}

		foreach ( self::parked_complex_datatype_names() as $name ) {
			$id = self::find_child_named( $taxonomy, $complex_id, $name );
			if ( $id <= 0 || Trash::is_trashed( $id ) ) {
				continue;
			}
			Node_Type::set_deletable( $id, true );
			Trash::move_to_trash( $taxonomy, $id, true );
			++$purged;
		}

		return $purged;
	}

	/**
	 * Pick the Simple catalog to keep: Data Types/Datentypen path first, else first candidate.
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
			if ( ($simple_id > 0 && get_term( $simple_id, $taxonomy ) instanceof \WP_Term) ) {
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
	 * Always restores if soft-trashed — otherwise Simple leaves (time/datetime/…)
	 * disappear from the live tree while still existing under a trashed parent.
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
			if ( Trash::is_trashed( $existing ) ) {
				Trash::restore_subtree( $taxonomy, $existing );
			}
			Node_Type::set_deletable( $existing, false );
		}
		return $existing > 0 ? $existing : 0;
	}

	/**
	 * Remove Definition/Simple or Definition/Simple Datatypes when Data Types/Simple is SoT.
	 * Repoints type_id refs from ghost leaves to matching canonical leaves first.
	 *
	 * @return int Number of terms hard-deleted.
	 */
	private static function purge_legacy_definition_simple( string $taxonomy, int $canonical_simple ): int {
		if ( $canonical_simple <= 0 ) {
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

		$ghosts = array(
			self::find_term_by_path(
				$taxonomy,
				array( self::ROOT_NAME, 'Definition', 'Simple' )
			),
			self::find_term_by_path(
				$taxonomy,
				array( self::ROOT_NAME, 'Definition', 'Simple Datatypes' )
			),
			self::find_term_by_path(
				$taxonomy,
				array( self::ROOT_NAME, 'Definition', 'Simple Datatype' )
			),
		);

		$deleted_total = 0;
		foreach ( array_unique( array_filter( $ghosts ) ) as $ghost ) {
			$ghost = (int) $ghost;
			if ( $ghost <= 0 || $ghost === $canonical_simple ) {
				continue;
			}
			self::merge_simple_catalog_refs( $taxonomy, $ghost, $canonical_simple );
			$deleted = self::hard_delete_term_cascade( $taxonomy, $ghost );
			if ( ! is_wp_error( $deleted ) ) {
				$deleted_total += (int) $deleted;
			}
		}

		return $deleted_total;
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
	 * Does not set catalog flags — install_nodes / the install target owns template/deletable.
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
	 * Find a Simple catalog leaf by exact name, seed aliases, else by slug stem (name or name-*).
	 */
	private static function find_simple_catalog_leaf( string $taxonomy, int $simple_id, string $leaf_name ): int {
		$by_name = self::find_child_named( $taxonomy, $simple_id, $leaf_name );
		if ( $by_name > 0 ) {
			return $by_name;
		}

		foreach ( self::simple_datatype_leaves() as $leaf ) {
			$want = isset( $leaf['name'] ) ? (string) $leaf['name'] : '';
			if ( $want !== $leaf_name ) {
				continue;
			}
			if ( empty( $leaf['aliases'] ) || ! is_array( $leaf['aliases'] ) ) {
				break;
			}
			foreach ( $leaf['aliases'] as $alias ) {
				$alias = is_string( $alias ) ? trim( $alias ) : '';
				if ( '' === $alias ) {
					continue;
				}
				$by_alias = self::find_child_named( $taxonomy, $simple_id, $alias );
				if ( $by_alias > 0 ) {
					return $by_alias;
				}
			}
			break;
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
	 * When both canonical Simple leaf and an obsolete alias sibling exist, trash the alias.
	 * Example: node_presentation + leftover display_node_name under the same Simple parent.
	 */
	public static function strip_obsolete_simple_datatype_aliases( string $taxonomy, int $simple_id ): void {
		if ( $simple_id <= 0 ) {
			return;
		}
		foreach ( self::simple_datatype_leaves() as $leaf ) {
			$canonical = isset( $leaf['name'] ) ? (string) $leaf['name'] : '';
			if ( '' === $canonical || empty( $leaf['aliases'] ) || ! is_array( $leaf['aliases'] ) ) {
				continue;
			}
			$can_id = self::find_child_named( $taxonomy, $simple_id, $canonical );
			if ( $can_id <= 0 ) {
				continue;
			}
			foreach ( $leaf['aliases'] as $obsolete ) {
				$obsolete = is_string( $obsolete ) ? trim( $obsolete ) : '';
				if ( '' === $obsolete || $obsolete === $canonical ) {
					continue;
				}
				$obs_id = self::find_child_named( $taxonomy, $simple_id, $obsolete );
				if ( $obs_id <= 0 || $obs_id === $can_id ) {
					continue;
				}
				Node_Type::set_deletable( $obs_id, true );
				Node_Type::set_is_template( $taxonomy, $obs_id, false );
				delete_term_meta( $obs_id, Trash::META_KEY_TRASHED );
				$result = wp_delete_term( $obs_id, $taxonomy );
				if ( is_wp_error( $result ) || false === $result ) {
					Tree_Model::delete_term( $taxonomy, $obs_id, 'leaf' );
				}
			}
		}
	}

	/**
	 * Remap relation/type refs from leftover display_node_name terms → node_presentation, then trash.
	 * Covers legacy parents like "Simple Datatypes" outside the canonical Simple path.
	 *
	 * @return int Number of obsolete terms trashed.
	 */
	public static function purge_legacy_display_node_name_terms( string $taxonomy, int $canonical_pres_id ): int {
		if ( $canonical_pres_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}
		$hits = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'name'       => 'display_node_name',
				'number'     => 0,
			)
		);
		if ( ! is_array( $hits ) || array() === $hits ) {
			return 0;
		}

		$removed = 0;
		foreach ( $hits as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$old_id = (int) $term->term_id;
			if ( $old_id <= 0 || $old_id === $canonical_pres_id ) {
				continue;
			}
			self::remap_term_id_refs( $taxonomy, $old_id, $canonical_pres_id );
			/*
			 * Seed alias cleanup: hard-delete after remap so the obsolete name
			 * does not linger in Trash / datatype lists.
			 */
			Node_Type::set_deletable( $old_id, true );
			Node_Type::set_is_template( $taxonomy, $old_id, false );
			delete_term_meta( $old_id, Trash::META_KEY_TRASHED );
			$result = wp_delete_term( $old_id, $taxonomy );
			if ( ! is_wp_error( $result ) && false !== $result ) {
				++$removed;
			} else {
				/* Fallback: soft-trash if hard delete blocked. */
				Tree_Model::delete_term( $taxonomy, $old_id, 'leaf' );
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Rewrite relation edge toId/typeId and host _wtt_type_id from $from_id → $to_id.
	 */
	private static function remap_term_id_refs( string $taxonomy, int $from_id, int $to_id ): void {
		if ( $from_id <= 0 || $to_id <= 0 || $from_id === $to_id ) {
			return;
		}

		global $wpdb;
		$type_hosts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s",
				Node_Type::META_KEY,
				(string) $from_id
			)
		);
		if ( is_array( $type_hosts ) ) {
			foreach ( $type_hosts as $host_id ) {
				$host_id = (int) $host_id;
				if ( $host_id > 0 ) {
					Node_Type::set_type_id( $taxonomy, $host_id, $to_id, true );
				}
			}
		}

		$rel_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				Relation::META_KEY,
				'%' . $wpdb->esc_like( (string) $from_id ) . '%'
			)
		);
		if ( ! is_array( $rel_rows ) ) {
			return;
		}
		foreach ( $rel_rows as $row ) {
			$host_id = (int) $row->term_id;
			$raw     = (string) $row->meta_value;
			$decoded = json_decode( wp_unslash( $raw ), true );
			if ( ! is_array( $decoded ) ) {
				$decoded = json_decode( $raw, true );
			}
			$edges = is_array( $decoded ) ? $decoded : null;
			if ( ! is_array( $edges ) ) {
				continue;
			}
			$changed = false;
			foreach ( $edges as $idx => $edge ) {
				if ( ! is_array( $edge ) ) {
					continue;
				}
				foreach ( array( 'toId', 'typeId' ) as $field ) {
					if ( isset( $edge[ $field ] ) && (int) $edge[ $field ] === $from_id ) {
						$edges[ $idx ][ $field ] = $to_id;
						$changed                 = true;
					}
				}
			}
			if ( $changed ) {
				Json_Meta::update_term_meta( $host_id, Relation::META_KEY, array_values( $edges ) );
			}
			delete_term_meta( $host_id, '_wtt_attribute_walk_cache' );
		}
	}

	/**
	 * Idempotent Model-branch repair only (hosts + Bauteil groups/kinds/Arten + attributes).
	 * Does not wipe unrelated Fallstudie roots (Implementation, Definition, …).
	 *
	 * @return array{
	 *   kontaktId:int,
	 *   platineId:int,
	 *   bauteilId:int,
	 *   diodenId:int,
	 *   kindAttrs:array{added:int,skipped:int,stripped:int},
	 *   purgedTrashedFields:int
	 * }
	 */
	public static function ensure_model_branch( string $taxonomy = Taxonomy::FS ): array {
		$empty = array(
			'kontaktId'           => 0,
			'platineId'           => 0,
			'bauteilId'           => 0,
			'bauteillisteId'      => 0,
			'positionId'          => 0,
			'diodenId'            => 0,
			'kindAttrs'           => array(
				'added'    => 0,
				'skipped'  => 0,
				'stripped' => 0,
			),
			'purgedTrashedFields'  => 0,
		);
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return $empty;
		}

		/* Top-down BOM spine (no Model/Lieferant in this cut). */
		$kontakt_id = self::ensure_kontakt_model( $taxonomy );
		$bauteil    = self::ensure_bauteil_model( $taxonomy );
		$bauteil_id = (int) ( $bauteil['targetId'] ?? 0 );

		if ( $bauteil_id > 0 ) {
			Demo_Data::ensure_bauteil_kind_groups( $taxonomy, $bauteil_id );
		}

		$bauteilliste = self::ensure_bauteilliste_model( $taxonomy );
		$platine_id   = self::ensure_platine_model( $taxonomy );
		self::purge_model_lieferant( $taxonomy );
		$dioden_id  = self::ensure_dioden_model( $taxonomy );
		$kind_attrs = Demo_Data::ensure_bauteil_kind_attributes( $taxonomy );
		/* OQ-W11: Passiv Wert → size when missing / soft-typed (idempotent). */
		self::ensure_passiv_wert_size( $taxonomy );
		Attribute::migrate_detach_hierarchy( $taxonomy );
		$purged = self::purge_model_trashed_field_litter( $taxonomy );

		return array(
			'kontaktId'           => $kontakt_id,
			'platineId'           => $platine_id,
			'bauteilId'           => $bauteil_id,
			'bauteillisteId'      => (int) ( $bauteilliste['bauteillisteId'] ?? 0 ),
			'positionId'          => (int) ( $bauteilliste['positionId'] ?? 0 ),
			'diodenId'            => $dioden_id,
			'kindAttrs'           => $kind_attrs,
			'purgedTrashedFields' => $purged,
		);
	}

	/**
	 * Hard-delete soft-trashed leftover field terms under Model/Bauteil.
	 * Those were pre-Q87 hierarchy field-children; kinds keep real attributes as slots.
	 *
	 * @return int Number of terms permanently deleted.
	 */
	public static function purge_model_trashed_field_litter( string $taxonomy = Taxonomy::FS ): int {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$bauteil_id = Demo_Data::find_model_bauteil_id( $taxonomy );
		if ( $bauteil_id <= 0 ) {
			return 0;
		}

		$ids = self::collect_trashed_descendants( $taxonomy, $bauteil_id );
		if ( empty( $ids ) ) {
			return 0;
		}

		/* Deepest first. */
		usort(
			$ids,
			static function ( int $a, int $b ) use ( $taxonomy ): int {
				return self::term_depth( $taxonomy, $b ) <=> self::term_depth( $taxonomy, $a );
			}
		);

		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( Attribute::is_slot( $id ) ) {
				continue;
			}
			/* Never delete live kind / group / Art hosts — only soft-trashed litter. */
			if ( ! Trash::is_trashed( $id ) ) {
				continue;
			}
			Node_Type::set_deletable( $id, true );
			$result = wp_delete_term( $id, $taxonomy );
			if ( ! is_wp_error( $result ) && false !== $result ) {
				++$deleted;
			}
		}

		if ( $deleted > 0 && class_exists( Trash::class ) ) {
			$trash_id = Trash::find_trash_node_id( $taxonomy );
			if ( $trash_id > 0 ) {
				/* Rebuild trash item list without hard-deleted ids. */
				$items = Trash::list_all_trashed_ids( $taxonomy );
				$keep  = array();
				foreach ( $items as $tid ) {
					$tid = (int) $tid;
					if ( $tid > 0 && get_term( $tid, $taxonomy ) instanceof \WP_Term ) {
						$keep[] = $tid;
					}
				}
				Json_Meta::update_term_meta( $trash_id, Trash::META_KEY_TRASH_ITEMS, array_values( array_unique( $keep ) ) );
			}
		}

		return $deleted;
	}

	/**
	 * @return list<int>
	 */
	private static function collect_trashed_descendants( string $taxonomy, int $root_id ): array {
		$out   = array();
		$queue = array( $root_id );
		while ( ! empty( $queue ) ) {
			$parent = (int) array_shift( $queue );
			$kids   = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $parent,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			foreach ( (array) $kids as $kid ) {
				if ( ! $kid instanceof \WP_Term ) {
					continue;
				}
				$kid_id = (int) $kid->term_id;
				$queue[] = $kid_id;
				if ( Trash::is_trashed( $kid_id ) ) {
					$out[] = $kid_id;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Term depth for delete ordering (0 = root).
	 */
	private static function term_depth( string $taxonomy, int $term_id ): int {
		$depth = 0;
		$guard = 0;
		$id    = $term_id;
		while ( $id > 0 && $guard++ < 40 ) {
			$term = get_term( $id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				break;
			}
			$id = (int) $term->parent;
			if ( $id > 0 ) {
				++$depth;
			}
		}
		return $depth;
	}

	/**
	 * Remove Model/Lieferant host (out of this BOM cut — user asked to omit suppliers).
	 * Does not touch Implementation/Lieferanten.
	 */
	public static function purge_model_lieferant( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$position_id = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Model', 'Position' )
		);
		if ( $position_id > 0 ) {
			foreach ( Attribute::list_own( $taxonomy, $position_id ) as $row ) {
				$key = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
				if ( 'lieferant' !== $key ) {
					continue;
				}
				$attr_id = Attribute::normalize_attr_id( $row['id'] ?? '' );
				if ( '' !== $attr_id ) {
					Attribute::remove( $taxonomy, $position_id, $attr_id );
				}
			}
		}

		$lieferant_id = self::find_term_by_path(
			$taxonomy,
			array( self::ROOT_NAME, 'Model', 'Lieferant' )
		);
		if ( $lieferant_id <= 0 ) {
			return;
		}

		foreach ( Attribute::list_own( $taxonomy, $lieferant_id ) as $row ) {
			$attr_id = Attribute::normalize_attr_id( $row['id'] ?? '' );
			if ( '' !== $attr_id ) {
				Attribute::remove( $taxonomy, $lieferant_id, $attr_id );
			}
		}

		Node_Type::set_deletable( $lieferant_id, true );
		/* Hard-delete — Tree_Model::delete_term only soft-trashes. */
		$result = wp_delete_term( $lieferant_id, $taxonomy );
		if ( is_wp_error( $result ) || false === $result ) {
			/* Retry after clearing type locks. */
			wp_delete_term( $lieferant_id, $taxonomy );
		}
	}

	/**
	 * @deprecated Model/Lieferant omitted from top-down BOM cut; use purge_model_lieferant().
	 *
	 * @return int Always 0.
	 */
	public static function ensure_lieferant_model( string $taxonomy = Taxonomy::FS ): int {
		self::purge_model_lieferant( $taxonomy );
		return 0;
	}

	/**
	 * Seed Fallstudie/Model/Kontakt with person + address attributes.
	 * Titel, Name, Vorname, E-Mail, Telefon, Strasse, Hausnummer, Postleitzahl, Ort.
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
			'Contact person + address: Titel, Name, Vorname, E-Mail, Telefon, Strasse, Hausnummer, Postleitzahl, Ort.',
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

		Node_Type::set_deletable( $kontakt_id, false );

		$wanted = array(
			'Titel'        => $text_id,
			'Name'         => $text_id,
			'Vorname'      => $text_id,
			'E-Mail'       => $email_id,
			'Telefon'      => $text_id,
			'Strasse'      => $text_id,
			'Hausnummer'   => $text_id,
			'Postleitzahl' => $text_id,
			'Ort'          => $text_id,
		);

		$have = array();
		foreach ( Attribute::list_own( $taxonomy, $kontakt_id ) as $row ) {
			$key          = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
			$have[ $key ] = Attribute::normalize_attr_id( $row['id'] ?? '' );
		}

		$keep = array();
		foreach ( $wanted as $name => $type_id ) {
			$key = strtolower( $name );
			if ( 'e-mail' === $key ) {
				$key_aliases = array( 'e-mail', 'email' );
			} else {
				$key_aliases = array( $key );
			}
			$exists = false;
			foreach ( $key_aliases as $alias ) {
				if ( ! empty( $have[ $alias ] ) ) {
					$keep[ $have[ $alias ] ] = true;
					$exists                  = true;
					break;
				}
			}
			if ( $exists || $type_id <= 0 ) {
				continue;
			}
			$added = Attribute::add( $taxonomy, $kontakt_id, $name, $type_id );
			if ( ! is_wp_error( $added ) && isset( $added['id'] ) ) {
				$aid = Attribute::normalize_attr_id( $added['id'] ?? '' );
				if ( '' !== $aid ) {
					$keep[ $aid ] = true;
				}
			}
		}

		/* Q111: Model hosts → aggregation (repair existing slots). */
		foreach ( Attribute::list_own( $taxonomy, $kontakt_id ) as $row ) {
			$attr_id = Attribute::normalize_attr_id( $row['id'] ?? '' );
			if ( '' === $attr_id ) {
				continue;
			}
			Attribute::set_binding( $taxonomy, $kontakt_id, $attr_id, 'aggregation' );
		}

		/* Drop leftover slots (Firma, Anrede, Nachname, …) not in the restored set. */
		foreach ( Attribute::list_own( $taxonomy, $kontakt_id ) as $row ) {
			$attr_id = Attribute::normalize_attr_id( $row['id'] ?? '' );
			if ( '' === $attr_id || isset( $keep[ $attr_id ] ) ) {
				continue;
			}
			$key = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
			foreach ( array_keys( $wanted ) as $wanted_name ) {
				$wkey = strtolower( $wanted_name );
				if ( $key === $wkey || ( 'e-mail' === $wkey && 'email' === $key ) ) {
					continue 2;
				}
			}
			Attribute::remove( $taxonomy, $kontakt_id, $attr_id );
		}

		/* Canonical display order. */
		$by_name = array();
		foreach ( Attribute::list_own( $taxonomy, $kontakt_id ) as $row ) {
			$by_name[ strtolower( trim( (string) ( $row['name'] ?? '' ) ) ) ] = Attribute::normalize_attr_id( $row['id'] ?? '' );
		}
		$order_ids = array();
		foreach ( array_keys( $wanted ) as $wanted_name ) {
			$wkey = strtolower( $wanted_name );
			if ( ! empty( $by_name[ $wkey ] ) ) {
				$order_ids[] = $by_name[ $wkey ];
			} elseif ( 'e-mail' === $wkey && ! empty( $by_name['email'] ) ) {
				$order_ids[] = $by_name['email'];
			}
		}
		if ( ! empty( $order_ids ) ) {
			update_term_meta( $kontakt_id, Attribute::META_KEY_ORDER, $order_ids );
		}

		return $kontakt_id;
	}

	/**
	 * Seed Fallstudie/Model/Platine — slim board host (BOM spine only).
	 *
	 * Keeps identity + fab order + link to Bauteilliste. Review / Protokoll /
	 * Optionen extras dropped for the top-down BOM cut.
	 *
	 * @return int Platine host term id, or 0.
	 */
	public static function ensure_platine_model( string $taxonomy = Taxonomy::FS ): int {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$kontakt_id = self::ensure_kontakt_model( $taxonomy );
		$liste      = self::ensure_bauteilliste_model( $taxonomy );
		$list_id    = (int) ( $liste['bauteillisteId'] ?? 0 );

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
			'PCB / board: Name, fab order, Bauteilliste (BOM). Slim top-down cut.',
			$created,
			$existing
		);
		if ( $platine_id <= 0 ) {
			return 0;
		}

		Node_Type::set_deletable( $platine_id, false );
		Node_Type::apply_parent_as_type( $taxonomy, $platine_id );

		$text_id   = self::find_case_datatype_id( $taxonomy, 'text' );
		$int_id    = self::find_case_datatype_id( $taxonomy, 'int' );
		$double_id = self::find_case_datatype_id( $taxonomy, 'double' );
		$bool_id   = self::find_case_datatype_id( $taxonomy, 'bool' );
		$media_id  = self::find_case_datatype_id( $taxonomy, 'media' );

		$wanted = array(
			'Name'             => $text_id,
			'Version'          => $text_id,
			'Gerber vorhanden' => $bool_id,
			'Gerberdatei'      => $media_id,
			'Bestellt wo'      => $kontakt_id,
			'Stück'            => $int_id,
			'Preis'            => $double_id,
			'Besonderheiten'   => $text_id,
		);
		if ( $list_id > 0 && ($list_id > 0 && get_term( $list_id, $taxonomy ) instanceof \WP_Term) ) {
			$wanted['Bauteilliste'] = $list_id;
		}

		$mult = array(
			'Version'        => '0..1',
			'Besonderheiten' => '0..1',
			'Bauteilliste'   => '0..1',
		);
		/* Platine → Bauteilliste = aggregation; other Model attrs default aggregation (Q111). */
		$bindings = array(
			'Bauteilliste' => 'aggregation',
		);
		self::sync_model_host_attributes( $taxonomy, $platine_id, $wanted, $mult, true, $bindings );

		return $platine_id;
	}

	/**
	 * Top-down BOM under Model: Bauteilliste + Bauteillisten Position (siblings, Q85).
	 *
	 * Bauteilliste           = Name + Bauart + Position[0..*] (besteht_aus → Bauteillisten Position)
	 * Bauteillisten Position = minimal line: Referenz, Wert→Bauteil, Menge, Beschreibung, Auf Lager, Bauart
	 *
	 * Platine → Bauteilliste uses Bindung **aggregation**.
	 * Bauteilliste → Position uses Bindung **besteht_aus** (composition).
	 * Line host is a sibling under Model (not child_of Bauteilliste).
	 * Wert Mult = `1`; Model/Bauteil preferred render = `embed` (UR-B6 / Q93 id-only).
	 * Position.Bauart --defaultvalue_from--> Bauteilliste.Bauart (Q124; create/empty seed).
	 *
	 * @return array{bauteillisteId:int,positionId:int}
	 */
	public static function ensure_bauteilliste_model( string $taxonomy = Taxonomy::FS ): array {
		$empty = array(
			'bauteillisteId' => 0,
			'positionId'     => 0,
		);
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return $empty;
		}

		$root_id = self::find_term_by_path( $taxonomy, array( self::ROOT_NAME ) );
		if ( $root_id <= 0 ) {
			return $empty;
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
			return $empty;
		}

		$bauteil_id = (int) ( self::ensure_bauteil_model( $taxonomy )['targetId'] ?? 0 );

		$list_id = self::ensure_term(
			$taxonomy,
			'Bauteilliste',
			$model_id,
			'BOM / parts list (Q85): Name + Position lines → Bauteillisten Position. Alias: BOM.',
			$created,
			$existing
		);
		if ( $list_id <= 0 ) {
			return $empty;
		}

		Node_Type::set_deletable( $list_id, false );
		Node_Type::apply_parent_as_type( $taxonomy, $list_id );

		$line_name = 'Bauteillisten Position';

		/* Prefer canonical name; migrate legacy "Position" sibling or nested child. */
		$position_id = self::find_child_named( $taxonomy, $model_id, $line_name );
		if ( $position_id <= 0 ) {
			$legacy = self::find_child_named( $taxonomy, $model_id, 'Position' );
			if ( $legacy <= 0 ) {
				$legacy = self::find_child_named( $taxonomy, $list_id, 'Position' );
				if ( $legacy <= 0 ) {
					$legacy = self::find_child_named( $taxonomy, $list_id, $line_name );
				}
			}
			if ( $legacy > 0 ) {
				$term = get_term( $legacy, $taxonomy );
				if ( $term instanceof \WP_Term && (int) $term->parent !== $model_id ) {
					$reparented = Tree_Model::reparent_term( $taxonomy, $legacy, $model_id, true );
					if ( is_wp_error( $reparented ) ) {
						$legacy = 0;
					}
				}
				if ( $legacy > 0 ) {
					$term = get_term( $legacy, $taxonomy );
					if ( $term instanceof \WP_Term && $term->name !== $line_name ) {
						wp_update_term(
							$legacy,
							$taxonomy,
							array(
								'name' => $line_name,
								'slug' => sanitize_title( $line_name . '-' . $legacy ),
							)
						);
					}
					$position_id = $legacy;
				}
			}
		}
		if ( $position_id <= 0 ) {
			$position_id = self::ensure_term(
				$taxonomy,
				$line_name,
				$model_id,
				'Minimal BOM line: Referenz, Wert (Bauteil), Menge, Beschreibung, Auf Lager.',
				$created,
				$existing
			);
		}
		if ( $position_id <= 0 ) {
			return array(
				'bauteillisteId' => $list_id,
				'positionId'     => 0,
			);
		}

		Node_Type::set_deletable( $position_id, false );
		Node_Type::apply_parent_as_type( $taxonomy, $position_id );

		$text_id   = self::find_case_datatype_id( $taxonomy, 'text' );
		$int_id    = self::find_case_datatype_id( $taxonomy, 'int' );
		$bool_id   = self::find_case_datatype_id( $taxonomy, 'bool' );
		$bauart_id = self::ensure_bauart_enum( $taxonomy );

		$line_wanted = array(
			'Referenz'     => $text_id,
			'Wert'         => ( $bauteil_id > 0 && get_term( $bauteil_id, $taxonomy ) instanceof \WP_Term ) ? $bauteil_id : $text_id,
			'Menge'        => $int_id,
			'Beschreibung' => $text_id,
			'Auf Lager'    => $bool_id,
		);
		if ( $bauart_id > 0 ) {
			$line_wanted['Bauart'] = $bauart_id;
		}

		/* Wert Mult `1` = required part pick (UR-B6); empty draft = error badge, save OK (Q107). */
		$line_mult = array(
			'Referenz'     => '1',
			'Wert'         => '1',
			'Menge'        => '1',
			'Beschreibung' => '0..1',
			'Auf Lager'    => '0..1',
			'Bauart'       => '0..1',
		);
		self::sync_model_host_attributes( $taxonomy, $position_id, $line_wanted, $line_mult, true );

		$list_wanted = array(
			'Name' => $text_id,
		);
		if ( $bauart_id > 0 ) {
			$list_wanted['Bauart'] = $bauart_id;
		}
		if ( $position_id > 0 && get_term( $position_id, $taxonomy ) instanceof \WP_Term ) {
			$list_wanted['Position'] = $position_id;
		}
		$list_mult = array(
			'Bauart'   => '0..1',
			'Position' => '0..*',
		);
		/* Only list→Position is composition; Name and line fields use aggregation (Q111). */
		$list_bindings = array(
			'Name'     => 'aggregation',
			'Bauart'   => 'aggregation',
			'Position' => 'besteht_aus',
		);
		self::sync_model_host_attributes( $taxonomy, $list_id, $list_wanted, $list_mult, true, $list_bindings );

		self::ensure_defaultvalue_from_link( $taxonomy, $position_id, $list_id, 'Bauart' );

		self::ensure_position_sample_instances( $taxonomy, $position_id );
		self::ensure_bauteilliste_sample_instance( $taxonomy, $list_id );
		self::ensure_bauteilliste_composition_link( $taxonomy, $list_id, $position_id );

		return array(
			'bauteillisteId' => $list_id,
			'positionId'     => $position_id,
		);
	}

	/**
	 * Idempotent Q124/Q125 link: consumer --calc op=default_from--> provider (same attr name).
	 */
	private static function ensure_defaultvalue_from_link(
		string $taxonomy,
		int $consumer_host_id,
		int $provider_host_id,
		string $attr_name
	): void {
		if ( $consumer_host_id <= 0 || $provider_host_id <= 0 || $consumer_host_id === $provider_host_id ) {
			return;
		}
		$name = Relation::normalize_edge_name( $attr_name );
		if ( '' === $name ) {
			return;
		}
		self::ensure_relation_types( $taxonomy );
		$type_id = Relation::find_type_id_by_name( $taxonomy, Relation::TYPE_CALC );
		if ( $type_id <= 0 ) {
			$type_id = Relation::find_type_id_by_name( $taxonomy, Relation::TYPE_DEFAULTVALUE_FROM );
		}
		if ( $type_id <= 0 ) {
			return;
		}
		/* Already linked via calc or legacy defaultvalue_from. */
		foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $consumer_host_id, Relation::TYPE_CALC ) as $edge ) {
			if ( ! Relation::is_default_from_calc_edge( $edge ) ) {
				continue;
			}
			$en = strtolower( trim( (string) ( $edge['name'] ?? '' ) ) );
			$to = (int) ( $edge['toId'] ?? 0 );
			if ( strtolower( $name ) === $en && $to === $provider_host_id ) {
				return;
			}
		}
		if ( Relation::has_identical( $consumer_host_id, $type_id, $provider_host_id, '', $name ) ) {
			return;
		}
		Relation::add(
			$taxonomy,
			$consumer_host_id,
			$type_id,
			$provider_host_id,
			'0..1',
			$name,
			Relation::calc_settings_for_op( Relation::CALC_OP_DEFAULT_FROM )
		);
	}

	/**
	 * Seed one sample BOM line under Position when none exist (ESP8266-RS232 PCB row).
	 */
	private static function ensure_position_sample_instances( string $taxonomy, int $position_id ): void {
		if ( $position_id <= 0 ) {
			return;
		}
		$existing = Model_Data::list( $taxonomy, $position_id );
		if ( array() !== $existing ) {
			return;
		}

		$values = array();
		foreach ( Attribute::list( $taxonomy, $position_id ) as $row ) {
			if ( ! empty( $row['hidden'] ) ) {
				continue;
			}
			$attr_id = Attribute::normalize_attr_id( $row['id'] ?? '' );
			if ( '' === $attr_id ) {
				continue;
			}
			$hint               = $row;
			$hint['hostName']   = 'Bauteillisten Position';
			$hint['definedOnName'] = 'Bauteillisten Position';
			$values[ $attr_id ] = Sample_Data::for_attribute( $hint );
		}
		if ( array() === $values ) {
			return;
		}
		Model_Data::save(
			$taxonomy,
			$position_id,
			array(
				'values' => $values,
			)
		);
	}

	/**
	 * Seed one sample Bauteilliste (named BOM) when none exist.
	 */
	private static function ensure_bauteilliste_sample_instance( string $taxonomy, int $list_id ): void {
		if ( $list_id <= 0 ) {
			return;
		}
		$existing = Model_Data::list( $taxonomy, $list_id );
		if ( array() !== $existing ) {
			return;
		}

		$values = array();
		foreach ( Attribute::list( $taxonomy, $list_id ) as $row ) {
			if ( ! empty( $row['hidden'] ) ) {
				continue;
			}
			$name = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
			/* Skip Position[0..*] — lines live as Position Model_Data, not embedded here for the sample. */
			if ( 'position' === $name ) {
				continue;
			}
			$attr_id = Attribute::normalize_attr_id( $row['id'] ?? '' );
			if ( '' === $attr_id ) {
				continue;
			}
			$hint                  = $row;
			$hint['hostName']      = 'Bauteilliste';
			$hint['definedOnName'] = 'Bauteilliste';
			$values[ $attr_id ] = Sample_Data::for_attribute( $hint );
		}
		if ( array() === $values ) {
			return;
		}
		Model_Data::save(
			$taxonomy,
			$list_id,
			array(
				'values' => $values,
			)
		);
	}

	/**
	 * OQ-B1: sample Position line is linked to sample Bauteilliste via composition (not inline blob).
	 */
	private static function ensure_bauteilliste_composition_link( string $taxonomy, int $list_id, int $position_id ): void {
		if ( $list_id <= 0 || $position_id <= 0 ) {
			return;
		}
		$lists = Model_Data::list( $taxonomy, $list_id );
		$lines = Model_Data::list( $taxonomy, $position_id );
		if ( array() === $lists || array() === $lines ) {
			return;
		}
		/* Prefer oldest list + oldest line (stable seed). */
		usort(
			$lists,
			static function ( array $a, array $b ): int {
				return strcmp( (string) ( $a['createdAt'] ?? '' ), (string) ( $b['createdAt'] ?? '' ) );
			}
		);
		usort(
			$lines,
			static function ( array $a, array $b ): int {
				return strcmp( (string) ( $a['createdAt'] ?? '' ), (string) ( $b['createdAt'] ?? '' ) );
			}
		);
		$parent_id = (string) ( $lists[0]['id'] ?? '' );
		$child_id  = (string) ( $lines[0]['id'] ?? '' );
		if ( '' === $parent_id || '' === $child_id ) {
			return;
		}
		Model_Data::link(
			$taxonomy,
			$list_id,
			$parent_id,
			$position_id,
			$child_id,
			Model_Data::LINK_COMPOSITION
		);
	}

	/**
	 * Sync own attributes on a Model host to an exact wanted map (add / order / optional strip).
	 *
	 * @param array<string,int>    $wanted          Attribute name → type term id.
	 * @param array<string,string> $multiplicity    Optional name → multiplicity (default 1).
	 * @param bool                 $remove_extras   Drop own slots not in $wanted.
	 * @param array<string,string> $bindings        Optional name → Bindung (besteht_aus|aggregation).
	 */
	private static function sync_model_host_attributes(
		string $taxonomy,
		int $host_id,
		array $wanted,
		array $multiplicity = array(),
		bool $remove_extras = true,
		array $bindings = array()
	): void {
		if ( $host_id <= 0 || empty( $wanted ) ) {
			return;
		}

		$have = array();
		$have_meta = array();
		foreach ( Attribute::list_own( $taxonomy, $host_id ) as $row ) {
			$key = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
			$aid = Attribute::normalize_attr_id( $row['id'] ?? '' );
			$have[ $key ] = $aid;
			$have_meta[ $key ] = array(
				'typeId'       => (int) ( $row['typeId'] ?? 0 ),
				'binding'      => Attribute::normalize_binding( (string) ( $row['binding'] ?? '' ) ),
				'multiplicity' => (string) ( $row['multiplicity'] ?? '' ),
			);
		}

		$keep = array();
		foreach ( $wanted as $name => $type_id ) {
			$key  = strtolower( (string) $name );
			$bind = isset( $bindings[ $name ] )
				? Attribute::normalize_binding( (string) $bindings[ $name ] )
				: null;
			if ( ! empty( $have[ $key ] ) ) {
				$attr_id          = $have[ $key ];
				$keep[ $attr_id ] = true;
				$meta             = $have_meta[ $key ] ?? array();
				/*
				 * Never force Mult/Binding/type on every ensure when unchanged.
				 * Repeated ensure used to silently rewrite father edges and look
				 * like “old settings came back”.
				 */
				if (
					array_key_exists( $name, $multiplicity )
					&& (string) $multiplicity[ $name ] !== (string) ( $meta['multiplicity'] ?? '' )
				) {
					Attribute::set_multiplicity(
						$taxonomy,
						$host_id,
						$attr_id,
						(string) $multiplicity[ $name ]
					);
				}
				if ( null !== $bind && $bind !== (string) ( $meta['binding'] ?? '' ) ) {
					Attribute::set_binding( $taxonomy, $host_id, $attr_id, $bind );
				}
				if ( $type_id > 0 && (int) $type_id !== (int) ( $meta['typeId'] ?? 0 ) ) {
					Attribute::set_type( $taxonomy, $host_id, $attr_id, (int) $type_id );
				}
				continue;
			}
			if ( $type_id <= 0 ) {
				continue;
			}
			$mult = array_key_exists( $name, $multiplicity )
				? (string) $multiplicity[ $name ]
				: Attribute::DEFAULT_MULTIPLICITY;
			$added = Attribute::add(
				$taxonomy,
				$host_id,
				(string) $name,
				(int) $type_id,
				$mult,
				null !== $bind ? $bind : Attribute::DEFAULT_BINDING
			);
			if ( ! is_wp_error( $added ) && isset( $added['id'] ) ) {
				$aid = Attribute::normalize_attr_id( $added['id'] ?? '' );
				if ( '' !== $aid ) {
					$keep[ $aid ] = true;
				}
			}
		}

		if ( $remove_extras ) {
			foreach ( Attribute::list_own( $taxonomy, $host_id ) as $row ) {
				$attr_id = Attribute::normalize_attr_id( $row['id'] ?? '' );
				if ( '' === $attr_id || isset( $keep[ $attr_id ] ) ) {
					continue;
				}
				Attribute::remove( $taxonomy, $host_id, $attr_id );
			}
		}

		$by_name = array();
		foreach ( Attribute::list_own( $taxonomy, $host_id ) as $row ) {
			$by_name[ strtolower( trim( (string) ( $row['name'] ?? '' ) ) ) ] = Attribute::normalize_attr_id( $row['id'] ?? '' );
		}
		$order_ids = array();
		foreach ( array_keys( $wanted ) as $wanted_name ) {
			$wkey = strtolower( (string) $wanted_name );
			if ( ! empty( $by_name[ $wkey ] ) ) {
				$order_ids[] = $by_name[ $wkey ];
			}
		}
		if ( ! empty( $order_ids ) ) {
			update_term_meta( $host_id, Attribute::META_KEY_ORDER, $order_ids );
		}
	}

	/**
	 * Ensure Fallstudie/Model/Bauteil exists once; merge stray Bauteil hierarchy nodes into it.
	 *
	 * Does not touch Bom Zeile (etc.) attribute slots named Bauteil — those are typed picks
	 * of Model/Bauteil, not duplicate hosts.
	 *
	 * @return array{targetId:int,merged:list<string>,skipped:list<string>,trashed:list<int>,prunedEdges:int}
	 */
	public static function ensure_bauteil_model( string $taxonomy = Taxonomy::FS ): array {
		$empty = array(
			'targetId'     => 0,
			'merged'       => array(),
			'skipped'      => array(),
			'trashed'      => array(),
			'prunedEdges'  => 0,
		);
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return $empty;
		}

		$root_id = self::find_term_by_path( $taxonomy, array( self::ROOT_NAME ) );
		if ( $root_id <= 0 ) {
			return $empty;
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
			return $empty;
		}

		$target_id = self::find_child_named( $taxonomy, $model_id, 'Bauteil' );
		if ( $target_id <= 0 ) {
			$target_id = self::ensure_term(
				$taxonomy,
				'Bauteil',
				$model_id,
				'Part schema host (specializations via child_of; properties via attributes).',
				$created,
				$existing
			);
		}
		if ( $target_id <= 0 ) {
			return $empty;
		}

		Node_Type::set_deletable( $target_id, false );
		Node_Type::apply_parent_as_type( $taxonomy, $target_id );
		/* UR-B6 / Q72: pick+fill chrome = preferred render embed (not catalog node_embed). */
		Node_Type::set_preferred_render( $taxonomy, $target_id, Renderer::Embedded->value );

		$result               = $empty;
		$result['targetId']   = $target_id;
		$result['prunedEdges'] = self::prune_dangling_composition_edges( $taxonomy, $target_id );

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'name'       => 'Bauteil',
				'number'     => 0,
			)
		);
		if ( ! is_array( $terms ) ) {
			return $result;
		}

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$stray_id = (int) $term->term_id;
			if ( $stray_id === $target_id ) {
				continue;
			}

			/* Owned attribute slot (e.g. Bom Zeile → Bauteil pick) — keep. */
			if ( Attribute::is_slot( $stray_id ) ) {
				$hosts = self::attribute_host_ids( $taxonomy, $stray_id );
				if ( ! empty( $hosts ) ) {
					$result['skipped'][] = 'slot#' . $stray_id . '@' . implode( ',', $hosts );
					continue;
				}
			}

			$merge = self::merge_bauteil_host_into( $taxonomy, $stray_id, $target_id );
			$result['merged']  = array_merge( $result['merged'], $merge['merged'] );
			$result['skipped'] = array_merge( $result['skipped'], $merge['skipped'] );

			if ( class_exists( Trash::class ) ) {
				Trash::move_to_trash( $taxonomy, $stray_id );
				$result['trashed'][] = $stray_id;
			} else {
				Tree_Model::delete_term( $taxonomy, $stray_id, 'cascade' );
				$result['trashed'][] = $stray_id;
			}
		}

		$result['merged']  = array_values( array_unique( $result['merged'] ) );
		$result['skipped'] = array_values( array_unique( $result['skipped'] ) );

		return $result;
	}

	/**
	 * Diode type leaves under Model/Bauteil/Halbleiter/Dioden (CatalogChoice / Q90).
	 * LED is a sibling kind under Halbleiter — not an Art here.
	 *
	 * @return list<array{name:string,short_description?:string,description:string}>
	 */
	public static function dioden_arten_leaves(): array {
		return array(
			array(
				'name'              => 'Schalt',
				'short_description' => 'Signal',
				'description'       => 'Small-signal / switching diode (e.g. 1N4148).',
			),
			array(
				'name'              => 'Schottky',
				'short_description' => 'Schottky',
				'description'       => 'Schottky barrier diode (low Vf).',
			),
			array(
				'name'              => 'Zener',
				'short_description' => 'Zener',
				'description'       => 'Zener / voltage-reference diode (Vz).',
			),
			array(
				'name'              => 'Gleichrichter',
				'short_description' => 'Rectifier',
				'description'       => 'Power rectifier diode.',
			),
			array(
				'name'              => 'TVS',
				'short_description' => 'TVS',
				'description'       => 'Transient voltage suppressor.',
			),
			array(
				'name'              => 'LDD',
				'short_description' => 'LDD',
				'description'       => 'Laser diode (LDD).',
			),
		);
	}

	/**
	 * Seed Halbleiter/Dioden with Arten only (CatalogChoice). Strip leftover slot children / LED art.
	 *
	 * @return int Dioden host term id, or 0.
	 */
	public static function ensure_dioden_model( string $taxonomy = Taxonomy::FS ): int {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$bauteil = self::ensure_bauteil_model( $taxonomy );
		$bauteil_id = (int) ( $bauteil['targetId'] ?? 0 );
		if ( $bauteil_id <= 0 ) {
			return 0;
		}

		Demo_Data::ensure_bauteil_kind_groups( $taxonomy, $bauteil_id );

		$created  = 0;
		$existing = 0;

		$halbleiter_id = self::find_child_named( $taxonomy, $bauteil_id, 'Halbleiter' );
		$parent_id     = $halbleiter_id > 0 ? $halbleiter_id : $bauteil_id;

		/* Prefer Dioden under Halbleiter; adopt flat Diode/Dioden under Bauteil. */
		$dioden_id = Demo_Data::find_bauteil_kind_under( $taxonomy, $bauteil_id, 'Dioden' );
		if ( $dioden_id <= 0 ) {
			$dioden_id = Demo_Data::find_bauteil_kind_under( $taxonomy, $bauteil_id, 'Diode' );
			if ( $dioden_id > 0 ) {
				wp_update_term(
					$dioden_id,
					$taxonomy,
					array(
						'name'   => 'Dioden',
						'parent' => $parent_id,
					)
				);
			}
		}
		if ( $dioden_id <= 0 ) {
			$dioden_id = self::ensure_term(
				$taxonomy,
				'Dioden',
				$parent_id,
				'Diode specialization host: choose an Art (CatalogChoice). Children = Arten only.',
				$created,
				$existing,
				array( 'Diode' )
			);
		} elseif ( $halbleiter_id > 0 ) {
			$term = get_term( $dioden_id, $taxonomy );
			if ( $term instanceof \WP_Term && (int) $term->parent !== $halbleiter_id ) {
				wp_update_term(
					$dioden_id,
					$taxonomy,
					array(
						'parent' => $halbleiter_id,
					)
				);
			}
		}
		if ( $dioden_id <= 0 ) {
			return 0;
		}

		Node_Type::set_deletable( $dioden_id, false );
		Node_Type::apply_parent_as_type( $taxonomy, $dioden_id );

		$arten_names = array();
		foreach ( self::dioden_arten_leaves() as $leaf ) {
			$name = (string) ( $leaf['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$arten_names[ $name ] = true;
			$art_id = self::ensure_term(
				$taxonomy,
				$name,
				$dioden_id,
				(string) ( $leaf['description'] ?? '' ),
				$created,
				$existing
			);
			if ( $art_id <= 0 ) {
				continue;
			}
			if ( ! empty( $leaf['short_description'] ) ) {
				Tree_Model::set_short_description(
					$taxonomy,
					$art_id,
					(string) $leaf['short_description']
				);
			}
			Node_Type::set_deletable( $art_id, false );
			Node_Type::apply_parent_as_type( $taxonomy, $art_id );
		}

		self::cleanup_dioden_host_children( $taxonomy, $dioden_id, $arten_names );

		return $dioden_id;
	}

	/**
	 * Keep only CatalogChoice Arten under Dioden — drop slot leftovers (U_r, Bauform, …) and LED art.
	 *
	 * @param array<string, true> $arten_names Canonical Art names to keep.
	 */
	private static function cleanup_dioden_host_children( string $taxonomy, int $dioden_id, array $arten_names ): void {
		if ( $dioden_id <= 0 ) {
			return;
		}

		/* Also drop attribute slots that duplicate old hierarchy fields. */
		foreach ( Attribute::list_own( $taxonomy, $dioden_id ) as $row ) {
			$aid = Attribute::normalize_attr_id( $row['id'] ?? '' );
			if ( '' !== $aid ) {
				Attribute::remove( $taxonomy, $dioden_id, $aid );
			}
		}

		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $dioden_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		foreach ( (array) $kids as $kid ) {
			if ( ! $kid instanceof \WP_Term ) {
				continue;
			}
			$name = $kid->name;
			if ( isset( $arten_names[ $name ] ) ) {
				continue;
			}
			/* LED lives as Halbleiter/LED kind — not under Dioden. */
			Node_Type::set_deletable( (int) $kid->term_id, true );
			Tree_Model::delete_term( $taxonomy, (int) $kid->term_id, 'cascade' );
		}
	}

	/**
	 * Whether an attribute name is commercial / order-spec (skip on Model/Bauteil merge).
	 */
	public static function is_commercial_bauteil_attr_name( string $name ): bool {
		$key = strtolower( trim( $name ) );
		$key = str_replace( array( ' ', '_', '-', '.' ), '', $key );
		if ( '' === $key ) {
			return false;
		}
		$exact = array( 'hersteller', 'lieferant', 'bestellnummer', 'bestellnr', 'sku', 'ean', 'mpn', 'datenblatt' );
		if ( in_array( $key, $exact, true ) ) {
			return true;
		}
		if ( str_starts_with( $key, 'hersteller' ) || str_starts_with( $key, 'lieferant' ) ) {
			return true;
		}
		if ( str_starts_with( $key, 'bestell' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Host ids that own an attribute slot via besteht_aus / aggregation.
	 *
	 * @return list<int>
	 */
	private static function attribute_host_ids( string $taxonomy, int $attr_id ): array {
		$hosts = array();
		foreach ( Relation::list_incoming( $taxonomy, $attr_id ) as $edge ) {
			$key = (string) ( $edge['typeKey'] ?? '' );
			if ( ! Attribute::is_attribute_binding( $key ) ) {
				continue;
			}
			$from = (int) ( $edge['fromId'] ?? 0 );
			if ( $from > 0 ) {
				$hosts[] = $from;
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Drop besteht_aus / aggregation edges whose target term no longer exists.
	 */
	private static function prune_dangling_composition_edges( string $taxonomy, int $host_id ): int {
		if ( $host_id <= 0 ) {
			return 0;
		}
		$raw = get_term_meta( $host_id, Relation::META_KEY, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return 0;
		}
		$pruned = 0;
		$next   = array();
		foreach ( $raw as $edge ) {
			if ( ! is_array( $edge ) ) {
				continue;
			}
			$to_id       = (int) ( $edge['toId'] ?? 0 );
			$key         = strtolower( (string) ( $edge['typeKey'] ?? '' ) );
			$is_attr_edge = Attribute::is_attribute_binding( $key )
				|| Relation::type_keys_match( $key, Relation::TYPE_COMPOSITION )
				|| 'composition' === $key;
			if ( $is_attr_edge && $to_id > 0 ) {
				$to = get_term( $to_id, $taxonomy );
				if ( ! $to instanceof \WP_Term ) {
					++$pruned;
					continue;
				}
			}
			$next[] = $edge;
		}
		if ( $pruned > 0 ) {
			if ( empty( $next ) ) {
				delete_term_meta( $host_id, Relation::META_KEY );
			} else {
				Json_Meta::update_term_meta( $host_id, Relation::META_KEY, array_values( $next ) );
			}
		}
		return $pruned;
	}

	/**
	 * Copy missing non-commercial attributes from $source_id onto $target_id.
	 * Hierarchy field-children on the source become attributes when they look like slots.
	 *
	 * @return array{merged:list<string>,skipped:list<string>}
	 */
	private static function merge_bauteil_host_into( string $taxonomy, int $source_id, int $target_id ): array {
		$merged  = array();
		$skipped = array();
		if ( $source_id <= 0 || $target_id <= 0 || $source_id === $target_id ) {
			return array(
				'merged'  => $merged,
				'skipped' => $skipped,
			);
		}

		$have = array();
		foreach ( Attribute::list_own( $taxonomy, $target_id ) as $row ) {
			$key          = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
			$have[ $key ] = true;
		}

		$candidates = array();
		foreach ( Attribute::list_own( $taxonomy, $source_id ) as $row ) {
			$candidates[] = array(
				'name'         => (string) ( $row['name'] ?? '' ),
				'typeId'       => (int) ( $row['typeId'] ?? 0 ),
				'multiplicity' => (string) ( $row['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY ),
				'binding'      => (string) ( $row['binding'] ?? Attribute::DEFAULT_BINDING ),
			);
		}

		/* Hierarchy children that look like attribute fields (typed leaves), not specializations. */
		$specialize = array( 'passiv', 'aktiv', 'diode', 'widerstand', 'kondensator', 'spule', 'led', 'transistor', 'ic' );
		$kids       = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $source_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_array( $kids ) ) {
			foreach ( $kids as $kid ) {
				if ( ! $kid instanceof \WP_Term ) {
					continue;
				}
				$kid_key = strtolower( trim( $kid->name ) );
				if ( in_array( $kid_key, $specialize, true ) ) {
					/* Reparent missing specialization under target. */
					$existing = self::find_child_named( $taxonomy, $target_id, $kid->name );
					if ( $existing <= 0 ) {
						wp_update_term( (int) $kid->term_id, $taxonomy, array( 'parent' => $target_id ) );
						$merged[] = 'child:' . $kid->name;
					} else {
						$skipped[] = 'child-exists:' . $kid->name;
					}
					continue;
				}
				if ( Attribute::is_slot( (int) $kid->term_id ) ) {
					continue;
				}
				$type_id = Node_Type::get_type_id( (int) $kid->term_id );
				if ( $type_id <= 0 ) {
					$skipped[] = 'untyped-child:' . $kid->name;
					continue;
				}
				$candidates[] = array(
					'name'         => $kid->name,
					'typeId'       => $type_id,
					'multiplicity' => Attribute::DEFAULT_MULTIPLICITY,
					'binding'      => Attribute::DEFAULT_BINDING,
				);
			}
		}

		foreach ( $candidates as $cand ) {
			$name = trim( (string) ( $cand['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			if ( self::is_commercial_bauteil_attr_name( $name ) ) {
				$skipped[] = 'commercial:' . $name;
				continue;
			}
			$key = strtolower( $name );
			if ( ! empty( $have[ $key ] ) ) {
				$skipped[] = 'exists:' . $name;
				continue;
			}
			$type_id = (int) ( $cand['typeId'] ?? 0 );
			if ( $type_id <= 0 || ! ( get_term( $type_id, $taxonomy ) instanceof \WP_Term ) ) {
				$skipped[] = 'bad-type:' . $name;
				continue;
			}
			$added = Attribute::add(
				$taxonomy,
				$target_id,
				$name,
				$type_id,
				(string) ( $cand['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY ),
				(string) ( $cand['binding'] ?? Attribute::DEFAULT_BINDING )
			);
			if ( is_wp_error( $added ) ) {
				$skipped[] = 'add-fail:' . $name . ':' . $added->get_error_code();
				continue;
			}
			$have[ $key ] = true;
			$merged[]     = $name;
		}

		return array(
			'merged'  => $merged,
			'skipped' => $skipped,
		);
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
			if ( $id > 0 && ($id > 0 && get_term( $id, $taxonomy ) instanceof \WP_Term) ) {
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
			if ( $id > 0 && ($id > 0 && get_term( $id, $taxonomy ) instanceof \WP_Term) ) {
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

		Node_Type::set_deletable( $date_id, false );
		if ( ! metadata_exists( 'term', $date_id, Node_Type::META_KEY_DATE_MODE ) ) {
			Node_Type::set_date_mode( $taxonomy, $date_id, 'date' );
		}

		return $date_id;
	}

	/**
	 * Catalog datatype `table`: composition → Zeile (+ optional Kopf / Fuss band nodes).
	 * Bands may live at parent=0 after Q87 detach — resolve via composition / bindings
	 * before creating new children under `table`.
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
		$bindings = Node_Type::get_prop_bindings( $table_id );
		$band_ids = array();
		foreach ( $bands as $name => $description ) {
			$key     = strtolower( $name );
			$band_id = isset( $bindings[ $key ] ) ? (int) $bindings[ $key ] : 0;
			if ( $band_id <= 0 || ! get_term( $band_id, $taxonomy ) instanceof \WP_Term ) {
				$band_id = self::find_composition_member_named( $taxonomy, $table_id, $name );
			}
			if ( $band_id <= 0 ) {
				$band_id = self::find_child_named( $taxonomy, $table_id, $name );
			}
			if ( $band_id <= 0 ) {
				$band_id = self::ensure_term( $taxonomy, $name, $table_id, $description, $created, $existing );
			}
			if ( $band_id <= 0 ) {
				continue;
			}
			$band_ids[ $key ] = $band_id;
			self::ensure_composition_edge(
				$taxonomy,
				$table_id,
				$comp_type,
				$band_id,
				'Zeile' === $name ? '1' : '0..1'
			);
		}

		/* Catalog table: band SoT = prop bindings (names are labels only). */
		$next_bindings = array();
		foreach ( array( 'kopf', 'zeile', 'fuss' ) as $key ) {
			if ( isset( $band_ids[ $key ] ) ) {
				$next_bindings[ $key ] = $band_ids[ $key ];
			}
		}
		if ( ! empty( $next_bindings ) ) {
			Node_Type::set_prop_bindings( $taxonomy, $table_id, $next_bindings );
		}

		self::dedupe_composition_members_by_name( $taxonomy, $table_id );
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
	 * Implementation/Bauteile (MPN records) + Lieferanten; kinds under Model/Bauteil (Q83).
	 * No-op when Fallstudie/Implementation is absent (intentionally removed).
	 */
	public static function ensure_bauteile_catalog( string $taxonomy = Taxonomy::FS ): void {
		if ( Taxonomy::FS !== $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$impl_id = self::find_term_by_path( $taxonomy, array( self::ROOT_NAME, 'Implementation' ) );
		if ( $impl_id <= 0 ) {
			return;
		}

		/* Q90 / OQ-W15: do not seed node_pick / node_embed / node_ref (purge via ensure_complex_datatypes). */

		Demo_Data::ensure_bauteile_split(
			$taxonomy,
			array(),
			array( self::ROOT_NAME, 'Implementation' )
		);
		Demo_Data::ensure_lieferanten_catalog(
			$taxonomy,
			array( self::ROOT_NAME, 'Implementation' )
		);
	}

	/**
	 * Implementation → BOM = Name + Tabelle; Tabelle → Zeile → fields (idempotent).
	 *
	 * Composition (`besteht_aus`) members may live at parent=0 after Q87 detach.
	 * Always resolve by existing composition edge / Attribute list before creating
	 * new WP children — otherwise each ensure recreates Name/Tabelle/Zeile fields.
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

		$name_id = self::find_or_create_composition_member(
			$taxonomy,
			$bom_id,
			$comp_type,
			'Name',
			'Instance title field (filled on WP page).',
			'text',
			$created,
			$existing
		);
		$tabelle_id = self::find_or_create_composition_member(
			$taxonomy,
			$bom_id,
			$comp_type,
			'Tabelle',
			'Typed table: bind Zeile (and optional Kopf/Fuss) via type properties.',
			'table',
			$created,
			$existing
		);
		if ( $tabelle_id <= 0 ) {
			return;
		}
		self::strip_composition_to_table_catalog( $taxonomy, $tabelle_id );
		self::dedupe_composition_members_by_name( $taxonomy, $bom_id );

		$bindings = Node_Type::get_prop_bindings( $tabelle_id );
		$zeile_id = isset( $bindings['zeile'] ) ? (int) $bindings['zeile'] : 0;
		if ( $zeile_id <= 0 || ! get_term( $zeile_id, $taxonomy ) instanceof \WP_Term ) {
			$zeile_id = self::find_composition_member_named( $taxonomy, $tabelle_id, 'Zeile' );
		}
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
		if ( $zeile_id <= 0 ) {
			return;
		}
		self::ensure_composition_edge( $taxonomy, $tabelle_id, $comp_type, $zeile_id, '1' );

		$bindings['zeile'] = $zeile_id;
		Node_Type::set_prop_bindings( $taxonomy, $tabelle_id, $bindings );

		$field_types = array(
			'Reference' => 'text',
			'Bauteil'   => 'text',
			'Menge'     => 'int',
			'Kommentar' => 'textarea',
			'Preis'     => 'double',
			'Bestellt'  => 'bool',
			'Vorhanden' => 'bool',
			'Wert'      => 'text',
			'Name'      => 'text',
			'E-Mail'    => 'email',
			'Email'     => 'email',
		);

		$seed_fields = array(
			'Reference' => array( 'text', 'Board references (e.g. R1,R2).' ),
			'Wert'      => array( 'text', 'Value / rating display.' ),
			'Menge'     => array( 'int', 'Quantity (Stück).' ),
		);

		/* Existing band fields = composition members (may be parent=0 slots). */
		$have = array();
		foreach ( self::composition_member_terms( $taxonomy, $zeile_id ) as $member ) {
			$have[ strtolower( $member->name ) ] = (int) $member->term_id;
			if ( isset( $field_types[ $member->name ] ) && Node_Type::get_type_id( (int) $member->term_id ) <= 0 ) {
				self::ensure_type_named( $taxonomy, (int) $member->term_id, $field_types[ $member->name ] );
			}
		}
		/* Legacy: still-attached hierarchy children (pre-detach). */
		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $zeile_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		foreach ( (array) $kids as $kid ) {
			if ( ! $kid instanceof \WP_Term ) {
				continue;
			}
			$key = strtolower( $kid->name );
			if ( ! isset( $have[ $key ] ) ) {
				$have[ $key ] = (int) $kid->term_id;
				self::ensure_composition_edge( $taxonomy, $zeile_id, $comp_type, (int) $kid->term_id, '1' );
			}
			if ( isset( $field_types[ $kid->name ] ) && Node_Type::get_type_id( (int) $kid->term_id ) <= 0 ) {
				self::ensure_type_named( $taxonomy, (int) $kid->term_id, $field_types[ $kid->name ] );
			}
		}

		if ( empty( $have ) ) {
			foreach ( $seed_fields as $fname => $meta ) {
				$type_id = self::find_case_datatype_id( $taxonomy, (string) $meta[0] );
				if ( $type_id <= 0 ) {
					continue;
				}
				$added = Attribute::add( $taxonomy, $zeile_id, $fname, $type_id );
				if ( ! is_wp_error( $added ) && is_array( $added ) ) {
					$fid = (int) ( $added['id'] ?? 0 );
					if ( $fid > 0 && '' !== (string) $meta[1] ) {
						wp_update_term( $fid, $taxonomy, array( 'description' => (string) $meta[1] ) );
					}
				}
			}
		} else {
			foreach ( $seed_fields as $fname => $meta ) {
				$key = strtolower( $fname );
				if ( isset( $have[ $key ] ) ) {
					continue;
				}
				$type_id = self::find_case_datatype_id( $taxonomy, (string) $meta[0] );
				if ( $type_id <= 0 ) {
					continue;
				}
				Attribute::add( $taxonomy, $zeile_id, $fname, $type_id );
			}
		}

		self::dedupe_composition_members_by_name( $taxonomy, $zeile_id );
	}

	/**
	 * Resolve a composition/attribute member by name (edge target), else WP child.
	 */
	private static function find_composition_member_named( string $taxonomy, int $from_id, string $name ): int {
		$name_l = strtolower( trim( $name ) );
		if ( $from_id <= 0 || '' === $name_l ) {
			return 0;
		}
		foreach ( self::composition_member_terms( $taxonomy, $from_id ) as $term ) {
			if ( strtolower( $term->name ) === $name_l ) {
				return (int) $term->term_id;
			}
		}
		return 0;
	}

	/**
	 * @return list<\WP_Term>
	 */
	private static function composition_member_terms( string $taxonomy, int $from_id ): array {
		$out  = array();
		$seen = array();
		foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $from_id, Relation::TYPE_COMPOSITION ) as $edge ) {
			$to_id = (int) ( $edge['toId'] ?? 0 );
			if ( $to_id <= 0 || isset( $seen[ $to_id ] ) ) {
				continue;
			}
			$term = get_term( $to_id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$seen[ $to_id ] = true;
			$out[]          = $term;
		}
		return $out;
	}

	/**
	 * Find existing composition member / child, or create + edge (typed).
	 */
	private static function find_or_create_composition_member(
		string $taxonomy,
		int $host_id,
		int $comp_type,
		string $name,
		string $description,
		string $type_name,
		int &$created,
		int &$existing
	): int {
		$member_id = self::find_composition_member_named( $taxonomy, $host_id, $name );
		if ( $member_id <= 0 ) {
			$member_id = self::find_child_named( $taxonomy, $host_id, $name );
		}
		if ( $member_id <= 0 && 'Tabelle' === $name ) {
			$member_id = self::find_bom_table_child( $taxonomy, $host_id );
		}
		if ( $member_id <= 0 ) {
			$member_id = self::ensure_term(
				$taxonomy,
				$name,
				$host_id,
				$description,
				$created,
				$existing
			);
		}
		if ( $member_id <= 0 ) {
			return 0;
		}
		if ( '' !== $type_name ) {
			self::ensure_type_named( $taxonomy, $member_id, $type_name );
		}
		self::ensure_composition_edge( $taxonomy, $host_id, $comp_type, $member_id, '1' );
		return $member_id;
	}

	/**
	 * Keep one composition edge per member name; hard-delete duplicate targets
	 * when they are attribute slots not referenced elsewhere.
	 */
	private static function dedupe_composition_members_by_name( string $taxonomy, int $host_id ): void {
		if ( $host_id <= 0 ) {
			return;
		}
		$by_name = array();
		foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $host_id, Relation::TYPE_COMPOSITION ) as $edge ) {
			$to_id  = (int) ( $edge['toId'] ?? 0 );
			$edge_id = (string) ( $edge['id'] ?? '' );
			if ( $to_id <= 0 || '' === $edge_id ) {
				continue;
			}
			$term = get_term( $to_id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$key = strtolower( $term->name );
			if ( ! isset( $by_name[ $key ] ) ) {
				$by_name[ $key ] = array(
					'keep'  => $to_id,
					'dups'  => array(),
				);
				continue;
			}
			$by_name[ $key ]['dups'][] = array(
				'toId'   => $to_id,
				'edgeId' => $edge_id,
			);
		}

		$referenced = array_fill_keys( Attribute::collect_referenced_term_ids( $taxonomy ), true );
		foreach ( $by_name as $row ) {
			$keep = (int) $row['keep'];
			foreach ( $row['dups'] as $dup ) {
				$to_id   = (int) $dup['toId'];
				$edge_id = (string) $dup['edgeId'];
				Relation::remove( $taxonomy, $host_id, 0, $to_id, $edge_id );
				if ( $to_id === $keep ) {
					continue;
				}
				/* Only purge unused attribute-slot duplicates — never hierarchy hosts. */
				if ( ! Attribute::is_slot( $to_id ) ) {
					continue;
				}
				unset( $referenced[ $keep ] );
				if ( isset( $referenced[ $to_id ] ) ) {
					continue;
				}
				/* Recompute after remove: still referenced by another host? */
				$still = false;
				foreach ( Attribute::collect_referenced_term_ids( $taxonomy ) as $rid ) {
					if ( (int) $rid === $to_id ) {
						$still = true;
						break;
					}
				}
				if ( $still ) {
					continue;
				}
				Node_Type::set_deletable( $to_id, true );
				wp_delete_term( $to_id, $taxonomy );
			}
		}
	}

	/**
	 * Prefer an existing table-typed composition member or child under BOM.
	 */
	private static function find_bom_table_child( string $taxonomy, int $bom_id ): int {
		foreach ( array( 'Tabelle', 'Table', 'Kontent', 'Content' ) as $name ) {
			$id = self::find_composition_member_named( $taxonomy, $bom_id, $name );
			if ( $id > 0 ) {
				return $id;
			}
			$id = self::find_child_named( $taxonomy, $bom_id, $name );
			if ( $id > 0 ) {
				return $id;
			}
		}
		foreach ( self::composition_member_terms( $taxonomy, $bom_id ) as $member ) {
			if ( Node_Type::has_type_named( $taxonomy, (int) $member->term_id, 'table' ) ) {
				return (int) $member->term_id;
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
	 * Also accept legacy Definition/Simple|Complex and Datentypen paths.
	 */
	private static function find_case_datatype_id( string $taxonomy, string $type_name ): int {
		$paths = array(
			array( self::ROOT_NAME, 'Definition', 'Data Types', 'Simple', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Data Types', 'Complex', $type_name ),
			/* OQ-W10: size is a specialization child of quantity. */
			array( self::ROOT_NAME, 'Definition', 'Data Types', 'Complex', 'quantity', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Data Types', 'Complex', 'enum', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Datentypen', 'Simple', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Datentypen', 'Complex', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Datentypen', 'Complex', 'quantity', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Datentypen', 'Complex', 'enum', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Simple', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Complex', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Complex', 'quantity', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Complex', 'enum', $type_name ),
			array( self::ROOT_NAME, 'Definition', 'Eigene Datentypen', $type_name ),
		);
		$fallback = 0;
		foreach ( $paths as $path ) {
			$found = self::find_term_by_path( $taxonomy, $path );
			if ( $found <= 0 ) {
				continue;
			}
			if ( ($found > 0 && get_term( $found, $taxonomy ) instanceof \WP_Term) ) {
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
			if ( $leaf > 0 && ($leaf > 0 && get_term( $leaf, $taxonomy ) instanceof \WP_Term) ) {
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
	 * Type binding is type_id / parent-as-type — not composition to the catalog `table` node.
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
	 * Legacy helper — prefer wipe_all_terms() for reset (slots + protected nodes).
	 *
	 * @return int|\WP_Error Number of terms hard-deleted under named root(s).
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
			$removed = self::hard_delete_term_cascade( $taxonomy, (int) $term->term_id );
			if ( is_wp_error( $removed ) ) {
				return $removed;
			}
			$deleted += (int) $removed;
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

			if ( array_key_exists( 'is_template', $node ) ) {
				Node_Type::set_is_template( $taxonomy, $term_id, (bool) $node['is_template'] );
			} elseif ( array_key_exists( 'deletable', $node ) && false === (bool) $node['deletable'] ) {
				/* Seeded protected catalog → is_template (#5 lock signal). */
				Node_Type::set_is_template( $taxonomy, $term_id, true );
			}
			if ( array_key_exists( 'deletable', $node ) ) {
				Node_Type::set_deletable( $term_id, (bool) $node['deletable'] );
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
		$term_id = (int) $result['term_id'];
		/* Q95: standard-by-name first, else parent copy (same as Tree_Model::create). */
		Tree_Icons::apply_on_create( $taxonomy, $term_id, max( 0, $parent_id ) );
		return $term_id;
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
