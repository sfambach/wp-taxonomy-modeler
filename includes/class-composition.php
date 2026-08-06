<?php
/**
 * Collection / table composition helpers (definition + instance shape).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists table-typed Collections and catalog set lists; column schemas from taxonomy trees.
 */
final class Composition {

	public const META_KEY_ROWS = '_wtt_composition_rows';

	/** Per-record field values for catalog leaves (JSON object keyed by slot term id). */
	public const META_KEY_RECORD_VALUES = '_wtt_record_values';

	/** Synthetic Name column id in catalog list schemas. */
	public const CATALOG_NAME_COL_ID = 0;

	public const KIND_TABLE   = 'table';
	public const KIND_CATALOG = 'catalog';
	/** Attribute-host model node (e.g. Fallstudie/Model/Kontakt) — not a legacy table Collection. */
	public const KIND_MODEL   = 'model';

	/**
	 * All pickable block hosts across scaffold taxonomies:
	 * legacy table + catalog roots, plus attribute-host model nodes.
	 *
	 * @return list<array{id:int,name:string,path:string,taxonomy:string,kind:string,hasFooter:bool,columnCount:int}>
	 */
	public static function list_all_collections(): array {
		$out = array();
		$seen = array();
		foreach ( Taxonomy::scaffold_slugs() as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			foreach ( array_merge(
				self::list_table_collections( $taxonomy ),
				self::list_catalog_collections( $taxonomy ),
				self::list_model_hosts( $taxonomy )
			) as $row ) {
				$id = (int) ( $row['id'] ?? 0 );
				if ( $id <= 0 || isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$out[]       = $row;
			}
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				$tax = strcasecmp( (string) ( $a['taxonomy'] ?? '' ), (string) ( $b['taxonomy'] ?? '' ) );
				if ( 0 !== $tax ) {
					return $tax;
				}
				return strcasecmp( $a['path'], $b['path'] );
			}
		);

		return $out;
	}

	/**
	 * Attribute-host model nodes (any node with effective attributes) for the table block.
	 * Prefer Fallstudie/Model/… hosts; any node with attributes is eligible.
	 *
	 * @return list<array{id:int,name:string,path:string,taxonomy:string,kind:string,hasFooter:bool,columnCount:int}>
	 */
	public static function list_model_hosts( string $taxonomy = '' ): array {
		$taxonomy = '' !== $taxonomy ? $taxonomy : Taxonomy::FS;
		if ( ! taxonomy_exists( $taxonomy ) ) {
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
			/* Skip dedicated table/catalog roots — those use their own kinds. */
			if ( Node_Type::has_type_named( $taxonomy, $term_id, 'table' ) ) {
				continue;
			}
			if ( self::is_catalog_list_root( $taxonomy, $term_id ) ) {
				continue;
			}
			$columns = self::get_attribute_columns( $taxonomy, $term_id );
			if ( array() === $columns ) {
				continue;
			}
			$out[] = array(
				'id'          => $term_id,
				'name'        => $term->name,
				'path'        => self::term_path( $taxonomy, $term_id ),
				'taxonomy'    => $taxonomy,
				'kind'        => self::KIND_MODEL,
				'hasFooter'   => false,
				'columnCount' => count( $columns ),
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
	 * Table-typed terms suitable for the collection-table block picker.
	 *
	 * @return list<array{id:int,name:string,path:string,taxonomy:string,kind:string,hasFooter:bool,columnCount:int}>
	 */
	public static function list_table_collections( string $taxonomy = '' ): array {
		$taxonomy = '' !== $taxonomy ? $taxonomy : Taxonomy::TREE;
		if ( ! taxonomy_exists( $taxonomy ) ) {
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
			if ( ! Node_Type::has_type_named( $taxonomy, $term_id, 'table' ) ) {
				continue;
			}
			if ( Node_Type::is_table_type_catalog( $taxonomy, $term_id ) ) {
				continue;
			}
			$columns    = self::get_columns( $taxonomy, $term_id );
			$validation = Table_Validator::validate( $taxonomy, $term_id );
			/* Include legacy tables with column children even when Zeile band is not bound yet. */
			if ( empty( $validation['ok'] ) && array() === $columns ) {
				continue;
			}
			$out[] = array(
				'id'          => $term_id,
				'name'        => $term->name,
				'path'        => self::term_path( $taxonomy, $term_id ),
				'taxonomy'    => $taxonomy,
				'kind'        => self::KIND_TABLE,
				'hasFooter'   => Node_Type::has_footer( $term_id )
					|| null !== ( $validation['bands']['fuss'] ?? null ),
				'columnCount' => count( $columns ),
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
	 * Set catalogs (e.g. Lieferanten) — rows = record leaves, columns = set slots.
	 *
	 * @return list<array{id:int,name:string,path:string,taxonomy:string,kind:string,hasFooter:bool,columnCount:int}>
	 */
	public static function list_catalog_collections( string $taxonomy = '' ): array {
		$taxonomy = '' !== $taxonomy ? $taxonomy : Taxonomy::TREE;
		if ( ! taxonomy_exists( $taxonomy ) ) {
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
			if ( ! self::is_catalog_list_root( $taxonomy, $term_id ) ) {
				continue;
			}
			$columns = self::get_catalog_columns( $taxonomy, $term_id );
			if ( array() === $columns ) {
				continue;
			}
			$out[] = array(
				'id'          => $term_id,
				'name'        => $term->name,
				'path'        => self::term_path( $taxonomy, $term_id ),
				'taxonomy'    => $taxonomy,
				'kind'        => self::KIND_CATALOG,
				'hasFooter'   => false,
				'columnCount' => count( $columns ),
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
	 * Inheriting set with catalog-example record children → listable in the block.
	 * (Lieferanten yes; Bauteile kinds root no — examples live under kinds.)
	 */
	public static function is_catalog_list_root( string $taxonomy, int $term_id ): bool {
		if ( $term_id <= 0 || ! Node_Type::is_set_typed( $taxonomy, $term_id ) ) {
			return false;
		}
		if ( ! Node_Type::is_type_inheriting( $term_id ) ) {
			return false;
		}

		$has_slot = false;
		foreach ( Node_Type::get_set_members( $taxonomy, $term_id ) as $member ) {
			$mid = (int) ( $member['id'] ?? 0 );
			if ( $mid > 0 && ! Demo_Data::is_catalog_example( $mid ) ) {
				$has_slot = true;
				break;
			}
		}
		if ( ! $has_slot ) {
			return false;
		}

		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $children ) ) {
			return false;
		}
		foreach ( $children as $cid ) {
			if ( Demo_Data::is_catalog_example( (int) $cid ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Full schema for one Collection (table or catalog).
	 *
	 * @return array{
	 *   id:int,
	 *   name:string,
	 *   path:string,
	 *   taxonomy:string,
	 *   kind:string,
	 *   hasFooter:bool,
	 *   columns:list<array<string,mixed>>,
	 *   rows?:list<array{id:string,cells:array<string,string>}>
	 * }|null
	 */
	public static function get_schema( string $taxonomy, int $collection_id ): ?array {
		if ( $collection_id <= 0 ) {
			return null;
		}
		if ( '' === $taxonomy ) {
			$taxonomy = Taxonomy::taxonomy_for_term( $collection_id );
		}
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		$term = get_term( $collection_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		if ( self::is_catalog_list_root( $taxonomy, $collection_id ) ) {
			$columns = self::get_catalog_columns( $taxonomy, $collection_id );
			return array(
				'id'        => $collection_id,
				'name'      => $term->name,
				'path'      => self::term_path( $taxonomy, $collection_id ),
				'taxonomy'  => $taxonomy,
				'kind'      => self::KIND_CATALOG,
				'hasFooter' => false,
				'columns'   => $columns,
				'rows'      => self::get_catalog_rows( $taxonomy, $collection_id, $columns ),
			);
		}

		/* Attribute-host model (Kontakt, Platine, …) — preferred over legacy table Collection. */
		$attr_columns = self::get_attribute_columns( $taxonomy, $collection_id );
		if ( array() !== $attr_columns ) {
			return array(
				'id'        => $collection_id,
				'name'      => $term->name,
				'path'      => self::term_path( $taxonomy, $collection_id ),
				'taxonomy'  => $taxonomy,
				'kind'      => self::KIND_MODEL,
				'hasFooter' => false,
				'columns'   => $attr_columns,
			);
		}

		if ( ! Node_Type::has_type_named( $taxonomy, $collection_id, 'table' ) ) {
			return null;
		}
		if ( Node_Type::is_table_type_catalog( $taxonomy, $collection_id ) ) {
			return null;
		}
		$validation = Table_Validator::validate( $taxonomy, $collection_id );
		$columns    = self::get_columns( $taxonomy, $collection_id );
		if ( empty( $validation['ok'] ) && array() === $columns ) {
			return null;
		}

		return array(
			'id'        => $collection_id,
			'name'      => $term->name,
			'path'      => self::term_path( $taxonomy, $collection_id ),
			'taxonomy'  => $taxonomy,
			'kind'      => self::KIND_TABLE,
			'hasFooter' => Node_Type::has_footer( $collection_id )
				|| null !== ( $validation['bands']['fuss'] ?? null ),
			'columns'   => $columns,
		);
	}

	/**
	 * Columns = Zeile band fields when present; else direct children (legacy).
	 *
	 * @return list<array{id:int,name:string,slug:string,description:string,shortDescription:string,required:bool,typeId:int,typeName:string,typePath:string}>
	 */
	public static function get_columns( string $taxonomy, int $collection_id ): array {
		$zeile_cols = Table_Validator::get_zeile_columns( $taxonomy, $collection_id );
		if ( ! empty( $zeile_cols ) ) {
			return $zeile_cols;
		}

		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $collection_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $children ) ) {
			return array();
		}

		$terms = array();
		foreach ( $children as $child ) {
			if ( $child instanceof \WP_Term ) {
				$terms[] = $child;
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

		$columns = array();
		foreach ( $terms as $child ) {
			$child_id = (int) $child->term_id;
			if ( Demo_Data::is_catalog_example( $child_id ) ) {
				continue;
			}
			$type      = Node_Type::get_assignment( $taxonomy, $child_id );
			$columns[] = array(
				'id'               => $child_id,
				'name'             => $child->name,
				'slug'             => $child->slug,
				'description'      => Tree_Model::decode_term_description( (string) $child->description ),
				'shortDescription' => Tree_Model::get_short_description( $child_id ),
				'required'         => Node_Type::is_required( $child_id ),
				'typeId'           => Node_Type::get_type_id( $child_id ),
				'typeName'         => is_array( $type ) ? (string) ( $type['name'] ?? '' ) : '',
				'typePath'         => is_array( $type ) ? (string) ( $type['path'] ?? '' ) : '',
			);
		}

		return $columns;
	}

	/**
	 * Columns from effective attributes on a model / schema host node.
	 *
	 * @return list<array{id:int,name:string,slug:string,description:string,shortDescription:string,required:bool,typeId:int,typeName:string,typeKey:string,typePath:string}>
	 */
	public static function get_attribute_columns( string $taxonomy, int $host_id ): array {
		if ( $host_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$columns = array();
		foreach ( Attribute::list( $taxonomy, $host_id ) as $row ) {
			if ( ! empty( $row['hidden'] ) ) {
				continue;
			}
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$type_name = (string) ( $row['typeName'] ?? '' );
			$type_key  = (string) ( $row['typeKey'] ?? '' );
			if ( '' === $type_key && '' !== $type_name ) {
				$type_key = strtolower( $type_name );
				if ( false !== strpos( $type_key, '/' ) ) {
					$parts    = explode( '/', $type_key );
					$type_key = trim( (string) end( $parts ) );
				}
			}
			$columns[] = array(
				'id'               => $id,
				'name'             => (string) ( $row['name'] ?? '' ),
				'slug'             => sanitize_title( (string) ( $row['name'] ?? '' ) ),
				'description'      => (string) ( $row['description'] ?? '' ),
				'shortDescription' => (string) ( $row['shortDescription'] ?? '' ),
				'required'         => ! Attribute::multiplicity_allows_empty(
					(string) ( $row['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY )
				),
				'typeId'           => (int) ( $row['typeId'] ?? 0 ),
				'typeName'         => $type_name,
				'typeKey'          => $type_key,
				'typePath'         => '',
				'readonly'         => ! empty( $row['readonly'] ),
				'fixedLabel'       => (string) ( $row['fixedLabel'] ?? '' ),
			);
		}

		return $columns;
	}

	/**
	 * Catalog list columns: Name + set slots (not record leaves).
	 *
	 * @return list<array{id:int,name:string,slug:string,description:string,shortDescription:string,required:bool,typeId:int,typeName:string,typePath:string}>
	 */
	public static function get_catalog_columns( string $taxonomy, int $catalog_id ): array {
		$columns   = array();
		$columns[] = array(
			'id'               => self::CATALOG_NAME_COL_ID,
			'name'             => __( 'Name', 'wp-taxonomy-tree' ),
			'slug'             => '_name',
			'description'      => __( 'Supplier / vendor display name.', 'wp-taxonomy-tree' ),
			'shortDescription' => '',
			'required'         => true,
			'typeId'           => 0,
			'typeName'         => 'text',
			'typePath'         => '',
		);

		foreach ( Node_Type::get_set_members( $taxonomy, $catalog_id ) as $member ) {
			$mid = (int) ( $member['id'] ?? 0 );
			if ( $mid <= 0 || Demo_Data::is_catalog_example( $mid ) ) {
				continue;
			}
			/*
			 * Prefer own type_id for catalog slot columns: under an inheriting set root,
			 * effective type is often `set`, while the slot’s intended scalar (text/double/…)
			 * remains stored as own type without Override.
			 */
			$own_type_id = Node_Type::get_type_id( $mid );
			$type        = null;
			$type_id     = $own_type_id;
			if ( $own_type_id > 0 ) {
				$own_term = get_term( $own_type_id, $taxonomy );
				if ( $own_term instanceof \WP_Term ) {
					$type = array(
						'id'   => $own_type_id,
						'name' => $own_term->name,
						'path' => Node_Type::term_path_from_typen( $taxonomy, $own_type_id ),
					);
				}
			}
			if ( null === $type ) {
				$type    = isset( $member['type'] ) && is_array( $member['type'] ) ? $member['type'] : null;
				$type_id = (int) ( $member['typeId'] ?? 0 );
			}
			$columns[] = array(
				'id'               => $mid,
				'name'             => (string) ( $member['name'] ?? '' ),
				'slug'             => '',
				'description'      => (string) ( $member['description'] ?? '' ),
				'shortDescription' => (string) ( $member['shortDescription'] ?? '' ),
				'required'         => ! empty( $member['required'] ),
				'typeId'           => $type_id,
				'typeName'         => is_array( $type ) ? (string) ( $type['name'] ?? '' ) : '',
				'typePath'         => is_array( $type ) ? (string) ( $type['path'] ?? '' ) : '',
			);
		}

		return $columns;
	}

	/**
	 * Catalog rows from example/record children under the set root.
	 *
	 * @param list<array{id:int}> $columns Schema columns.
	 * @return list<array{id:string,cells:array<string,string>}>
	 */
	public static function get_catalog_rows( string $taxonomy, int $catalog_id, array $columns = array() ): array {
		if ( array() === $columns ) {
			$columns = self::get_catalog_columns( $taxonomy, $catalog_id );
		}

		$member_ids = array();
		foreach ( Node_Type::get_set_members( $taxonomy, $catalog_id ) as $member ) {
			$mid = (int) ( $member['id'] ?? 0 );
			if ( $mid > 0 ) {
				$member_ids[ $mid ] = true;
			}
		}

		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $catalog_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $children ) ) {
			return array();
		}

		$terms = array();
		foreach ( $children as $child ) {
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			$cid = (int) $child->term_id;
			if ( isset( $member_ids[ $cid ] ) ) {
				continue;
			}
			$terms[] = $child;
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

		$rows = array();
		foreach ( $terms as $child ) {
			$cid    = (int) $child->term_id;
			$values = self::get_record_values( $cid );
			$cells  = array(
				(string) self::CATALOG_NAME_COL_ID => $child->name,
			);
			foreach ( $columns as $col ) {
				$col_id = (int) ( $col['id'] ?? 0 );
				if ( self::CATALOG_NAME_COL_ID === $col_id ) {
					continue;
				}
				$key           = (string) $col_id;
				$cells[ $key ] = isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
			}
			$rows[] = array(
				'id'    => (string) $cid,
				'cells' => $cells,
			);
		}

		return $rows;
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_record_values( int $term_id ): array {
		$raw = get_term_meta( $term_id, self::META_KEY_RECORD_VALUES, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $key => $value ) {
			$out[ (string) $key ] = is_scalar( $value ) ? (string) $value : '';
		}
		return $out;
	}

	/**
	 * @param array<string, string> $values Slot id => value.
	 */
	public static function set_record_values( int $term_id, array $values ): void {
		$clean = array();
		foreach ( $values as $key => $value ) {
			$kid = (int) $key;
			if ( $kid <= 0 ) {
				continue;
			}
			$clean[ (string) $kid ] = is_scalar( $value ) ? (string) $value : '';
		}
		if ( array() === $clean ) {
			delete_term_meta( $term_id, self::META_KEY_RECORD_VALUES );
			return;
		}
		update_term_meta( $term_id, self::META_KEY_RECORD_VALUES, wp_json_encode( $clean ) );
	}

	/**
	 * Whether a set root has composition slots suitable for catalog records
	 * (even when no example rows exist yet).
	 */
	public static function catalog_schema_ready( string $taxonomy, int $term_id ): bool {
		if ( $term_id <= 0 || ! Node_Type::is_set_typed( $taxonomy, $term_id ) ) {
			return false;
		}
		if ( ! Node_Type::is_type_inheriting( $term_id ) ) {
			return false;
		}
		foreach ( Node_Type::get_set_members( $taxonomy, $term_id ) as $member ) {
			$mid = (int) ( $member['id'] ?? 0 );
			if ( $mid > 0 && ! Demo_Data::is_catalog_example( $mid ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mini-form fields for creating a node_ref target under a catalog root.
	 * Always includes Name; adds simple scalar catalog slots when schema is ready.
	 *
	 * @return list<array{id:int,key:string,name:string,typeName:string,required:bool,description?:string}>
	 */
	public static function get_node_ref_create_fields( string $taxonomy, int $scope_id ): array {
		$fields   = array();
		$fields[] = array(
			'id'          => self::CATALOG_NAME_COL_ID,
			'key'         => 'name',
			'name'        => __( 'Name', 'wp-taxonomy-tree' ),
			'typeName'    => 'text',
			'required'    => true,
			'description' => '',
		);

		if ( $scope_id <= 0 || ! self::catalog_schema_ready( $taxonomy, $scope_id ) ) {
			return $fields;
		}

		foreach ( self::get_catalog_columns( $taxonomy, $scope_id ) as $col ) {
			$cid = (int) ( $col['id'] ?? 0 );
			if ( $cid <= 0 ) {
				continue;
			}
			$type = strtolower( (string) ( $col['typeName'] ?? '' ) );
			if ( 'integer' === $type ) {
				$type = 'int';
			}
			if ( ! self::is_simple_scalar_type_name( $type ) ) {
				continue;
			}
			$fields[] = array(
				'id'          => $cid,
				'key'         => (string) $cid,
				'name'        => (string) ( $col['name'] ?? '' ),
				'typeName'    => $type,
				'required'    => ! empty( $col['required'] ),
				'description' => (string) ( $col['description'] ?? '' ),
			);
		}

		return $fields;
	}

	/**
	 * Create a catalog / folder leaf under ref_scope for node_ref picking.
	 *
	 * @param array<string, mixed> $field_values Keyed by slot id string or "name".
	 * @return array{option:array{id:int,name:string,path:string,shortDescription?:string},nodeRefOptions:list<array{id:int,name:string,path:string,shortDescription?:string}>}|\WP_Error
	 */
	public static function create_node_ref_target(
		string $taxonomy,
		int $scope_id,
		string $name,
		array $field_values = array(),
		int $slot_id = 0,
		int $parent_id = 0
	) {
		$name = trim( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'wtt_empty_name', __( 'Name is required.', 'wp-taxonomy-tree' ), array( 'status' => 400 ) );
		}
		if ( $scope_id <= 0 ) {
			return new \WP_Error( 'wtt_no_ref_scope', __( 'Catalog root (ref_scope) is required.', 'wp-taxonomy-tree' ), array( 'status' => 400 ) );
		}

		$scope = get_term( $scope_id, $taxonomy );
		if ( ! $scope instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_bad_scope', __( 'Catalog root not found.', 'wp-taxonomy-tree' ), array( 'status' => 404 ) );
		}

		$parent = $parent_id > 0 ? $parent_id : $scope_id;
		if ( $parent !== $scope_id && ! self::term_is_under( $taxonomy, $parent, $scope_id ) ) {
			return new \WP_Error( 'wtt_bad_parent', __( 'Parent must be under the catalog root.', 'wp-taxonomy-tree' ), array( 'status' => 400 ) );
		}

		$created = Tree_Model::create_term( $taxonomy, $name, $parent );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$term_id = (int) ( $created['id'] ?? 0 );
		if ( $term_id <= 0 ) {
			return new \WP_Error( 'wtt_create_failed', __( 'Could not create catalog entry.', 'wp-taxonomy-tree' ) );
		}

		if ( self::catalog_schema_ready( $taxonomy, $scope_id ) ) {
			update_term_meta( $term_id, Demo_Data::META_CATALOG_EXAMPLE, '1' );
			$values   = array();
			$create_f = self::get_node_ref_create_fields( $taxonomy, $scope_id );
			foreach ( $create_f as $field ) {
				$fid = (int) ( $field['id'] ?? 0 );
				if ( $fid <= 0 ) {
					continue;
				}
				$key = (string) $fid;
				$raw = '';
				if ( array_key_exists( $key, $field_values ) ) {
					$raw = $field_values[ $key ];
				} elseif ( array_key_exists( $fid, $field_values ) ) {
					$raw = $field_values[ $fid ];
				}
				$type           = (string) ( $field['typeName'] ?? 'text' );
				$values[ $key ] = self::sanitize_scalar_field_value( $raw, $type );
			}
			self::set_record_values( $term_id, $values );
		}

		$term = get_term( $term_id, $taxonomy );
		$path = $term instanceof \WP_Term
			? self::term_path( $taxonomy, $term_id )
			: $name;
		$option = array(
			'id'               => $term_id,
			'name'             => $term instanceof \WP_Term ? $term->name : $name,
			'path'             => $path,
			'shortDescription' => Tree_Model::get_short_description( $term_id ),
		);

		$options = $slot_id > 0
			? Node_Type::get_node_ref_options_for_slot( $taxonomy, $slot_id )
			: Node_Type::get_node_ref_options( $taxonomy, $scope_id );

		return array(
			'option'         => $option,
			'nodeRefOptions' => $options,
		);
	}

	/**
	 * @param mixed $raw Raw POST value.
	 */
	public static function sanitize_scalar_field_value( $raw, string $type_name ): string {
		$type = strtolower( $type_name );
		if ( 'integer' === $type ) {
			$type = 'int';
		}
		$str = is_scalar( $raw ) ? (string) $raw : '';
		switch ( $type ) {
			case 'int':
				return (string) (int) $str;
			case 'double':
				return is_numeric( $str ) ? (string) (float) $str : '';
			case 'bool':
				$lower = strtolower( trim( $str ) );
				return in_array( $lower, array( '1', 'true', 'yes', 'on' ), true ) ? '1' : '0';
			case 'textarea':
				return sanitize_textarea_field( $str );
			case 'email':
				$email = sanitize_email( $str );
				return is_string( $email ) ? $email : '';
			case 'char':
			case 'text':
			default:
				return sanitize_text_field( $str );
		}
	}

	public static function is_simple_scalar_type_name( string $type_name ): bool {
		$type = strtolower( $type_name );
		if ( 'integer' === $type ) {
			$type = 'int';
		}
		return in_array( $type, array( 'text', 'textarea', 'int', 'double', 'char', 'bool', 'email' ), true );
	}

	/**
	 * Whether $term_id is $ancestor_id or a descendant thereof.
	 */
	private static function term_is_under( string $taxonomy, int $term_id, int $ancestor_id ): bool {
		if ( $term_id === $ancestor_id ) {
			return true;
		}
		$cur   = $term_id;
		$guard = 0;
		while ( $cur > 0 && $guard++ < 64 ) {
			$term = get_term( $cur, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				return false;
			}
			$parent = (int) $term->parent;
			if ( $parent === $ancestor_id ) {
				return true;
			}
			if ( $parent <= 0 ) {
				return false;
			}
			$cur = $parent;
		}
		return false;
	}

	/**
	 * Sync catalog list rows to taxonomy terms (create / update / delete).
	 *
	 * @param list<array{id?:string,cells?:array<string,string>}> $rows
	 * @return array{rows:list<array{id:string,cells:array<string,string>}>}|\WP_Error
	 */
	public static function sync_catalog_rows( string $taxonomy, int $catalog_id, array $rows ) {
		if ( ! self::is_catalog_list_root( $taxonomy, $catalog_id ) ) {
			return new \WP_Error( 'wtt_not_catalog', __( 'Not a catalog list collection.', 'wp-taxonomy-tree' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			return new \WP_Error( 'wtt_forbidden', __( 'Permission denied.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) );
		}

		$columns    = self::get_catalog_columns( $taxonomy, $catalog_id );
		$prior_rows = self::get_catalog_rows( $taxonomy, $catalog_id, $columns );
		$keep_ids   = array();
		$position   = 100;
		$slot_ids   = array();
		foreach ( $columns as $col ) {
			$cid = (int) ( $col['id'] ?? 0 );
			if ( $cid > 0 ) {
				$slot_ids[] = $cid;
			}
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cells = isset( $row['cells'] ) && is_array( $row['cells'] ) ? $row['cells'] : array();
			$name  = isset( $cells[ (string) self::CATALOG_NAME_COL_ID ] )
				? sanitize_text_field( (string) $cells[ (string) self::CATALOG_NAME_COL_ID ] )
				: '';
			if ( '' === $name ) {
				continue;
			}

			$row_id  = isset( $row['id'] ) ? (string) $row['id'] : '';
			$term_id = ctype_digit( $row_id ) ? (int) $row_id : 0;
			$term    = $term_id > 0 ? get_term( $term_id, $taxonomy ) : null;

			if ( ! $term instanceof \WP_Term || (int) $term->parent !== $catalog_id ) {
				$created = wp_insert_term(
					$name,
					$taxonomy,
					array(
						'parent' => $catalog_id,
					)
				);
				if ( is_wp_error( $created ) ) {
					return $created;
				}
				$term_id = (int) $created['term_id'];
			} elseif ( $term->name !== $name ) {
				$updated = wp_update_term(
					$term_id,
					$taxonomy,
					array(
						'name' => $name,
					)
				);
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
			}

			update_term_meta( $term_id, Demo_Data::META_CATALOG_EXAMPLE, '1' );
			Tree_Model::set_position( $term_id, $position );
			++$position;

			$values = array();
			foreach ( $slot_ids as $slot_id ) {
				$key            = (string) $slot_id;
				$values[ $key ] = isset( $cells[ $key ] ) ? sanitize_text_field( (string) $cells[ $key ] ) : '';
			}
			self::set_record_values( $term_id, $values );
			$keep_ids[ $term_id ] = true;
		}

		foreach ( $prior_rows as $existing_row ) {
			$eid = (int) $existing_row['id'];
			if ( $eid <= 0 || isset( $keep_ids[ $eid ] ) ) {
				continue;
			}
			if ( ! Demo_Data::is_catalog_example( $eid ) ) {
				continue;
			}
			wp_delete_term( $eid, $taxonomy );
		}

		return array(
			'rows' => self::get_catalog_rows( $taxonomy, $catalog_id, $columns ),
		);
	}

	/**
	 * Normalize instance rows: keep orphan cell keys (removed columns) for Q69 later.
	 *
	 * @param mixed               $raw     Decoded rows.
	 * @param list<array{id:int}> $columns Active columns.
	 * @return list<array{id:string,cells:array<string,string>}>
	 */
	public static function normalize_rows( $raw, array $columns ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$active = array();
		foreach ( $columns as $col ) {
			$active[ (string) (int) $col['id'] ] = true;
		}

		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( '' === $id ) {
				$id = self::new_row_id();
			}
			$cells_in = isset( $row['cells'] ) && is_array( $row['cells'] ) ? $row['cells'] : array();
			$cells    = array();
			foreach ( $cells_in as $key => $value ) {
				$cells[ (string) $key ] = is_scalar( $value ) ? (string) $value : '';
			}
			foreach ( array_keys( $active ) as $col_id ) {
				if ( ! array_key_exists( $col_id, $cells ) ) {
					$cells[ $col_id ] = '';
				}
			}
			$out[] = array(
				'id'    => $id,
				'cells' => $cells,
			);
		}

		return $out;
	}

	public static function new_row_id(): string {
		return 'r' . wp_generate_uuid4();
	}

	public static function term_path( string $taxonomy, int $term_id ): string {
		$names = array();
		$id    = $term_id;
		$guard = 0;
		while ( $id > 0 && $guard < 32 ) {
			$term = get_term( $id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				break;
			}
			array_unshift( $names, $term->name );
			$id = (int) $term->parent;
			++$guard;
		}
		return implode( ' / ', $names );
	}
}
