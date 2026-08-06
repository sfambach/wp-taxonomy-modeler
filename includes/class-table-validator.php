<?php
/**
 * Table definition validator (Kopf / Zeile / Fuss bands).
 *
 * Band identity = type-prop bindings on the table instance (or catalog type),
 * not the display name of the bound child node.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared rules for table-typed nodes: Zeile required with 1..n fields;
 * optional Kopf/Fuss must match Zeile field count when present.
 */
final class Table_Validator {

	public const BAND_ZEILE = 'zeile';
	public const BAND_KOPF  = 'kopf';
	public const BAND_FUSS  = 'fuss';

	/**
	 * Validate a table-typed node (or the catalog `table` datatype skeleton).
	 *
	 * @return array{
	 *   ok:bool,
	 *   blocking:bool,
	 *   errors:list<string>,
	 *   bands:array{
	 *     zeile:?array{id:int,name:string,fieldCount:int,fields:list<array{id:int,name:string}>},
	 *     kopf:?array{id:int,name:string,fieldCount:int,fields:list<array{id:int,name:string}>},
	 *     fuss:?array{id:int,name:string,fieldCount:int,fields:list<array{id:int,name:string}>}
	 *   },
	 *   isCatalog:bool
	 * }
	 */
	public static function validate( string $taxonomy, int $term_id ): array {
		$empty_bands = array(
			self::BAND_ZEILE => null,
			self::BAND_KOPF  => null,
			self::BAND_FUSS  => null,
		);

		$base = array(
			'ok'        => false,
			'blocking'  => false,
			'errors'    => array(),
			'bands'     => $empty_bands,
			'isCatalog' => false,
			'fixes'     => array(),
		);

		if ( $term_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			$base['errors'][] = __( 'Invalid table node.', 'wp-taxonomy-tree' );
			return $base;
		}

		$is_catalog = Node_Type::is_table_type_catalog( $taxonomy, $term_id );
		$is_table   = $is_catalog || Node_Type::has_type_named( $taxonomy, $term_id, 'table' );
		if ( ! $is_table ) {
			$base['ok']     = true;
			$base['errors'] = array();
			return $base;
		}

		$bands    = self::resolve_bands( $taxonomy, $term_id );
		$errors   = array();
		$blocking = false;

		$zeile = $bands[ self::BAND_ZEILE ];
		if ( null === $zeile ) {
			$errors[] = __( 'Table requires a Zeile band: bind the Zeile type property to a direct child node.', 'wp-taxonomy-tree' );
			$blocking = true;
		} elseif ( ! $is_catalog && $zeile['fieldCount'] < 1 ) {
			$errors[] = __( 'Zeile must have at least one field.', 'wp-taxonomy-tree' );
			$blocking = true;
		}

		$zeile_count = null !== $zeile ? (int) $zeile['fieldCount'] : 0;

		foreach ( array( self::BAND_KOPF, self::BAND_FUSS ) as $optional ) {
			$band = $bands[ $optional ];
			if ( null === $band ) {
				continue;
			}
			$label = self::BAND_KOPF === $optional ? 'Kopf' : 'Fuss';
			if ( null === $zeile ) {
				$errors[] = sprintf(
					/* translators: %s: band name Kopf or Fuss */
					__( '%s is bound but Zeile is missing.', 'wp-taxonomy-tree' ),
					$label
				);
				$blocking = true;
				continue;
			}
			if ( ! $is_catalog && (int) $band['fieldCount'] !== $zeile_count ) {
				$errors[] = sprintf(
					/* translators: 1: band name, 2: band field count, 3: Zeile field count */
					__( '%1$s has %2$d fields but Zeile has %3$d — counts must match.', 'wp-taxonomy-tree' ),
					$label,
					(int) $band['fieldCount'],
					$zeile_count
				);
				$blocking = true;
			}
		}

		return array(
			'ok'        => empty( $errors ),
			'blocking'  => $blocking,
			'errors'    => $errors,
			'bands'     => $bands,
			'isCatalog' => $is_catalog,
			'fixes'     => self::collect_fixes( $bands, $is_catalog ),
		);
	}

	/**
	 * Suggested auto-fixes (one rule → 0..n fixes).
	 *
	 * Actions:
	 * - create_zeile — Zeile unbound
	 * - create_zeile_field — Zeile bound but has 0 fields
	 * - create_fields — Kopf/Fuss fewer fields than Zeile
	 *
	 * @param array{
	 *   zeile:?array{id:int,name:string,fieldCount:int,fields:list<array{id:int,name:string}>},
	 *   kopf:?array{id:int,name:string,fieldCount:int,fields:list<array{id:int,name:string}>},
	 *   fuss:?array{id:int,name:string,fieldCount:int,fields:list<array{id:int,name:string}>}
	 * } $bands
	 * @return list<array<string,mixed>>
	 */
	public static function collect_fixes( array $bands, bool $is_catalog ): array {
		if ( $is_catalog ) {
			return array();
		}

		$fixes = array();
		$zeile = $bands[ self::BAND_ZEILE ] ?? null;

		if ( null === $zeile || ! is_array( $zeile ) ) {
			$fixes[] = array(
				'band'     => self::BAND_ZEILE,
				'bandId'   => 0,
				'bandName' => 'Zeile',
				'action'   => 'create_zeile',
			);
			return $fixes;
		}

		$zeile_id    = (int) ( $zeile['id'] ?? 0 );
		$zeile_count = (int) ( $zeile['fieldCount'] ?? 0 );

		if ( $zeile_count < 1 ) {
			$fixes[] = array(
				'band'     => self::BAND_ZEILE,
				'bandId'   => $zeile_id,
				'bandName' => (string) ( $zeile['name'] ?? 'Zeile' ),
				'action'   => 'create_zeile_field',
			);
			return $fixes;
		}

		foreach ( array( self::BAND_KOPF, self::BAND_FUSS ) as $key ) {
			$band = $bands[ $key ] ?? null;
			if ( ! is_array( $band ) ) {
				continue;
			}
			$have    = (int) ( $band['fieldCount'] ?? 0 );
			$missing = $zeile_count - $have;
			if ( $missing <= 0 ) {
				continue;
			}
			$fixes[] = array(
				'band'       => $key,
				'bandId'     => (int) ( $band['id'] ?? 0 ),
				'bandName'   => (string) ( $band['name'] ?? $key ),
				'missing'    => $missing,
				'zeileCount' => $zeile_count,
				'action'     => 'create_fields',
			);
		}
		return $fixes;
	}

	/**
	 * Apply a table rule fix by action key.
	 *
	 * @return array{created:list<array<string,mixed>>,bands:array,validation:array}|\WP_Error
	 */
	public static function apply_fix( string $taxonomy, int $table_id, string $action, string $band_key = '' ) {
		$action = strtolower( sanitize_key( $action ) );
		if ( 'create_zeile' === $action ) {
			return self::create_zeile_band( $taxonomy, $table_id );
		}
		if ( 'create_zeile_field' === $action ) {
			return self::create_zeile_field( $taxonomy, $table_id );
		}
		if ( 'create_fields' === $action || '' === $action ) {
			return self::create_missing_band_fields( $taxonomy, $table_id, $band_key );
		}
		return new \WP_Error( 'wtt_bad_fix', __( 'Unknown table fix action.', 'wp-taxonomy-tree' ) );
	}

	/**
	 * Fix: create a direct child “Zeile” and bind the zeile type property.
	 *
	 * @return array{created:list<array<string,mixed>>,bands:array,validation:array}|\WP_Error
	 */
	public static function create_zeile_band( string $taxonomy, int $table_id ) {
		if ( $table_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_table', __( 'Invalid table node.', 'wp-taxonomy-tree' ) );
		}
		if ( ! Node_Type::has_type_named( $taxonomy, $table_id, 'table' ) ) {
			return new \WP_Error( 'wtt_not_table', __( 'Node is not a table.', 'wp-taxonomy-tree' ) );
		}

		$bands = self::resolve_bands( $taxonomy, $table_id );
		if ( null !== ( $bands[ self::BAND_ZEILE ] ?? null ) ) {
			return new \WP_Error( 'wtt_zeile_exists', __( 'Zeile is already bound.', 'wp-taxonomy-tree' ) );
		}

		$node = Tree_Model::create_term( $taxonomy, 'Zeile', $table_id );
		if ( is_wp_error( $node ) ) {
			return $node;
		}
		$zeile_id = (int) ( $node['id'] ?? 0 );
		if ( $zeile_id <= 0 ) {
			return new \WP_Error( 'wtt_create_failed', __( 'Could not create Zeile.', 'wp-taxonomy-tree' ) );
		}

		$bindings           = Node_Type::get_prop_bindings( $table_id );
		$bindings['zeile']  = $zeile_id;
		$bound              = Node_Type::set_prop_bindings( $taxonomy, $table_id, $bindings );
		if ( is_wp_error( $bound ) ) {
			return $bound;
		}

		$comp_type_id = Relation::find_type_id_by_name( $taxonomy, Relation::TYPE_COMPOSITION );
		if ( $comp_type_id > 0 ) {
			$rel = Relation::add(
				$taxonomy,
				$table_id,
				$comp_type_id,
				$zeile_id,
				Relation::MULTIPLICITY_DEFAULT
			);
			if ( is_wp_error( $rel ) && 'wtt_duplicate_relation' !== $rel->get_error_code() ) {
				return $rel;
			}
		}

		$validation = self::validate( $taxonomy, $table_id );

		return array(
			'created'    => array(
				array(
					'id'   => $zeile_id,
					'name' => (string) ( $node['name'] ?? 'Zeile' ),
					'band' => self::BAND_ZEILE,
				),
			),
			'bands'      => $validation['bands'],
			'validation' => $validation,
		);
	}

	/**
	 * Fix: create one field under the bound Zeile (text column).
	 *
	 * @return array{created:list<array<string,mixed>>,bands:array,validation:array}|\WP_Error
	 */
	public static function create_zeile_field( string $taxonomy, int $table_id ) {
		if ( $table_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_table', __( 'Invalid table node.', 'wp-taxonomy-tree' ) );
		}
		if ( ! Node_Type::has_type_named( $taxonomy, $table_id, 'table' ) ) {
			return new \WP_Error( 'wtt_not_table', __( 'Node is not a table.', 'wp-taxonomy-tree' ) );
		}

		$bands = self::resolve_bands( $taxonomy, $table_id );
		$zeile = $bands[ self::BAND_ZEILE ] ?? null;
		if ( ! is_array( $zeile ) || (int) ( $zeile['id'] ?? 0 ) <= 0 ) {
			return new \WP_Error(
				'wtt_no_zeile',
				__( 'Bind Zeile before creating a Zeile field.', 'wp-taxonomy-tree' )
			);
		}
		if ( (int) ( $zeile['fieldCount'] ?? 0 ) >= 1 ) {
			return new \WP_Error( 'wtt_zeile_has_fields', __( 'Zeile already has fields.', 'wp-taxonomy-tree' ) );
		}

		$zeile_id = (int) $zeile['id'];
		$field_name = __( 'Column 1', 'wp-taxonomy-tree' );
		$node       = Tree_Model::create_term( $taxonomy, $field_name, $zeile_id );
		if ( is_wp_error( $node ) ) {
			return $node;
		}
		$field_id = (int) ( $node['id'] ?? 0 );
		if ( $field_id <= 0 ) {
			return new \WP_Error( 'wtt_create_failed', __( 'Could not create Zeile field.', 'wp-taxonomy-tree' ) );
		}

		$text_type_id = Node_Type::find_type_by_name( $taxonomy, $zeile_id, 'text' );
		if ( $text_type_id > 0 ) {
			$typed = Node_Type::set_type_id( $taxonomy, $field_id, $text_type_id );
			if ( is_wp_error( $typed ) ) {
				return $typed;
			}
		}

		$comp_type_id = Relation::find_type_id_by_name( $taxonomy, Relation::TYPE_COMPOSITION );
		if ( $comp_type_id > 0 ) {
			$rel = Relation::add(
				$taxonomy,
				$zeile_id,
				$comp_type_id,
				$field_id,
				Relation::MULTIPLICITY_DEFAULT
			);
			if ( is_wp_error( $rel ) && 'wtt_duplicate_relation' !== $rel->get_error_code() ) {
				return $rel;
			}
		}

		$validation = self::validate( $taxonomy, $table_id );

		return array(
			'created'    => array(
				array(
					'id'   => $field_id,
					'name' => (string) ( $node['name'] ?? $field_name ),
					'band' => self::BAND_ZEILE,
				),
			),
			'bands'      => $validation['bands'],
			'validation' => $validation,
		);
	}

	/**
	 * Create missing field children under Kopf/Fuss so counts match Zeile.
	 * Names (and for Fuss, types) follow Zeile columns by index.
	 * Existing fields are kept; extras are not deleted.
	 *
	 * @param string      $band_key Empty = all fixable bands; else kopf|fuss.
	 * @return array{created:list<array{id:int,name:string,band:string}>,bands:array,validation:array}|\WP_Error
	 */
	public static function create_missing_band_fields( string $taxonomy, int $table_id, string $band_key = '' ) {
		if ( $table_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_table', __( 'Invalid table node.', 'wp-taxonomy-tree' ) );
		}
		if ( ! Node_Type::has_type_named( $taxonomy, $table_id, 'table' ) ) {
			return new \WP_Error( 'wtt_not_table', __( 'Node is not a table.', 'wp-taxonomy-tree' ) );
		}

		$band_key = strtolower( sanitize_key( $band_key ) );
		if ( '' !== $band_key && self::BAND_KOPF !== $band_key && self::BAND_FUSS !== $band_key ) {
			return new \WP_Error( 'wtt_bad_band', __( 'Invalid band. Use kopf or fuss.', 'wp-taxonomy-tree' ) );
		}

		$bands = self::resolve_bands( $taxonomy, $table_id );
		$zeile = $bands[ self::BAND_ZEILE ];
		if ( null === $zeile || empty( $zeile['fields'] ) ) {
			return new \WP_Error(
				'wtt_no_zeile',
				__( 'Zeile must be bound with at least one field before creating band fields.', 'wp-taxonomy-tree' )
			);
		}

		$targets = array();
		if ( '' === $band_key ) {
			foreach ( self::collect_fixes( $bands, false ) as $fix ) {
				if ( 'create_fields' !== (string) ( $fix['action'] ?? '' ) ) {
					continue;
				}
				$targets[] = (string) $fix['band'];
			}
		} else {
			$targets[] = $band_key;
		}

		if ( empty( $targets ) ) {
			return new \WP_Error(
				'wtt_nothing_to_fix',
				__( 'No missing band fields to create.', 'wp-taxonomy-tree' )
			);
		}

		$created = array();
		foreach ( $targets as $key ) {
			$result = self::pad_band_from_zeile( $taxonomy, $bands, $key, $zeile );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( $result as $row ) {
				$created[] = $row;
			}
			/* Refresh band field list for subsequent targets. */
			$bands = self::resolve_bands( $taxonomy, $table_id );
			$zeile = $bands[ self::BAND_ZEILE ];
			if ( null === $zeile ) {
				break;
			}
		}

		$validation = self::validate( $taxonomy, $table_id );

		return array(
			'created'    => $created,
			'bands'      => $validation['bands'],
			'validation' => $validation,
		);
	}

	/**
	 * @param array{id:int,name:string,fieldCount:int,fields:list<array<string,mixed>>} $zeile
	 * @return list<array{id:int,name:string,band:string}>|\WP_Error
	 */
	private static function pad_band_from_zeile( string $taxonomy, array $bands, string $band_key, array $zeile ) {
		$band = $bands[ $band_key ] ?? null;
		if ( ! is_array( $band ) || (int) ( $band['id'] ?? 0 ) <= 0 ) {
			return new \WP_Error(
				'wtt_band_unbound',
				sprintf(
					/* translators: %s: kopf or fuss */
					__( 'Band “%s” is not bound to a child node.', 'wp-taxonomy-tree' ),
					$band_key
				)
			);
		}

		$band_id     = (int) $band['id'];
		$zeile_fields = array_values( $zeile['fields'] );
		$have         = array_values( $band['fields'] ?? array() );
		$need         = count( $zeile_fields );
		$have_count   = count( $have );
		if ( $have_count >= $need ) {
			return array();
		}

		$text_type_id = Node_Type::find_type_by_name( $taxonomy, $band_id, 'text' );
		$comp_type_id = Relation::find_type_id_by_name( $taxonomy, Relation::TYPE_COMPOSITION );
		$created      = array();

		for ( $i = $have_count; $i < $need; $i++ ) {
			$src      = $zeile_fields[ $i ];
			$src_name = trim( (string) ( $src['name'] ?? '' ) );
			if ( '' === $src_name ) {
				$src_name = sprintf(
					/* translators: %d: 1-based column index */
					__( 'Column %d', 'wp-taxonomy-tree' ),
					$i + 1
				);
			}

			$node = Tree_Model::create_term( $taxonomy, $src_name, $band_id );
			if ( is_wp_error( $node ) ) {
				return $node;
			}
			$new_id = (int) ( $node['id'] ?? 0 );
			if ( $new_id <= 0 ) {
				return new \WP_Error( 'wtt_create_failed', __( 'Could not create band field.', 'wp-taxonomy-tree' ) );
			}

			$type_id = 0;
			if ( self::BAND_FUSS === $band_key ) {
				$type_id = (int) ( $src['typeId'] ?? 0 );
				if ( $type_id <= 0 ) {
					$src_field_id = (int) ( $src['id'] ?? 0 );
					$type_id      = $src_field_id > 0 ? Node_Type::get_type_id( $src_field_id ) : 0;
				}
			}
			if ( $type_id <= 0 ) {
				$type_id = $text_type_id;
			}
			if ( $type_id > 0 ) {
				$typed = Node_Type::set_type_id( $taxonomy, $new_id, $type_id );
				if ( is_wp_error( $typed ) ) {
					return $typed;
				}
			}

			if ( self::BAND_FUSS === $band_key ) {
				$zeile_type = (string) ( $src['typeKey'] ?? $src['typeName'] ?? 'text' );
				$default_op = Footer_Ops::is_numeric_type( $zeile_type )
					? Footer_Ops::SUM
					: Footer_Ops::TEXT;
				$op_set     = Node_Type::set_footer_op( $taxonomy, $new_id, $default_op );
				if ( is_wp_error( $op_set ) ) {
					return $op_set;
				}
			}

			if ( $comp_type_id > 0 ) {
				$rel = Relation::add(
					$taxonomy,
					$band_id,
					$comp_type_id,
					$new_id,
					Relation::MULTIPLICITY_DEFAULT
				);
				if ( is_wp_error( $rel ) && 'wtt_duplicate_relation' !== $rel->get_error_code() ) {
					return $rel;
				}
			}

			$created[] = array(
				'id'   => $new_id,
				'name' => (string) ( $node['name'] ?? $src_name ),
				'band' => $band_key,
			);
		}

		return $created;
	}

	/**
	 * Resolve Kopf / Zeile / Fuss from type-prop bindings (child term ids).
	 * Display names of those children are irrelevant.
	 *
	 * @return array{
	 *   zeile:?array{id:int,name:string,fieldCount:int,fields:list<array{id:int,name:string}>},
	 *   kopf:?array{id:int,name:string,fieldCount:int,fields:list<array{id:int,name:string}>},
	 *   fuss:?array{id:int,name:string,fieldCount:int,fields:list<array{id:int,name:string}>}
	 * }
	 */
	public static function resolve_bands( string $taxonomy, int $table_id ): array {
		$out = array(
			self::BAND_ZEILE => null,
			self::BAND_KOPF  => null,
			self::BAND_FUSS  => null,
		);

		$bindings = Node_Type::get_prop_bindings( $table_id );
		$props    = Node_Type::get_effective_type_props( $taxonomy, $table_id );
		if ( empty( $props ) && Node_Type::is_table_type_catalog( $taxonomy, $table_id ) ) {
			$props = Node_Type::get_type_props( $table_id );
		}

		foreach ( $props as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$band_key = self::band_key_from_prop( $prop );
			if ( null === $band_key || null !== $out[ $band_key ] ) {
				continue;
			}
			$child_id = self::bound_child_id( $bindings, $prop );
			if ( $child_id <= 0 || ! self::is_direct_child( $taxonomy, $table_id, $child_id ) ) {
				continue;
			}
			$term = get_term( $child_id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$fields          = self::band_fields( $taxonomy, $child_id );
			$out[ $band_key ] = array(
				'id'         => $child_id,
				'name'       => $term->name,
				'fieldCount' => count( $fields ),
				'fields'     => $fields,
			);
		}

		return $out;
	}

	/**
	 * Column schema = Zeile fields when the band is bound; else empty.
	 *
	 * @return list<array{id:int,name:string,slug:string,description:string,shortDescription:string,required:bool,typeId:int,typeName:string,typePath:string}>
	 */
	public static function get_zeile_columns( string $taxonomy, int $table_id ): array {
		$bands = self::resolve_bands( $taxonomy, $table_id );
		$zeile = $bands[ self::BAND_ZEILE ];
		if ( null === $zeile || empty( $zeile['fields'] ) ) {
			return array();
		}

		$columns = array();
		foreach ( $zeile['fields'] as $field ) {
			$field_id = (int) ( $field['id'] ?? 0 );
			if ( $field_id <= 0 ) {
				continue;
			}
			$term = get_term( $field_id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$enriched = self::enrich_band_field(
				$taxonomy,
				array(
					'id'   => $field_id,
					'name' => $term->name,
				)
			);
			$col = array(
				'id'               => $field_id,
				'name'             => $term->name,
				'slug'             => $term->slug,
				'description'      => Tree_Model::decode_term_description( (string) $term->description ),
				'shortDescription' => Tree_Model::get_short_description( $field_id ),
				'required'         => Node_Type::is_required( $field_id ),
				'typeId'           => (int) ( $enriched['typeId'] ?? 0 ),
				'typeName'         => (string) ( $enriched['typeName'] ?? '' ),
				'typeKey'          => (string) ( $enriched['typeKey'] ?? '' ),
				'typePath'         => '',
			);
			$type = Node_Type::get_assignment( $taxonomy, $field_id );
			if ( is_array( $type ) ) {
				$col['typePath'] = (string) ( $type['path'] ?? '' );
			}
			foreach ( array( 'enumOptions', 'refScopeId', 'allowedRefIds', 'fieldMultiplicity', 'nodeRefOptions', 'nodeRefCreateFields' ) as $extra ) {
				if ( array_key_exists( $extra, $enriched ) ) {
					$col[ $extra ] = $enriched[ $extra ];
				}
			}
			$columns[] = $col;
		}
		return $columns;
	}

	/**
	 * @param array<string, int>       $bindings
	 * @param array<string, mixed>     $prop
	 */
	private static function bound_child_id( array $bindings, array $prop ): int {
		foreach ( array( 'id', 'key' ) as $field ) {
			$key = sanitize_key( (string) ( $prop[ $field ] ?? '' ) );
			if ( '' !== $key && isset( $bindings[ $key ] ) ) {
				return (int) $bindings[ $key ];
			}
		}
		return 0;
	}

	/**
	 * @param array<string, mixed> $prop
	 */
	private static function band_key_from_prop( array $prop ): ?string {
		foreach ( array( 'key', 'id' ) as $field ) {
			$key = strtolower( sanitize_key( (string) ( $prop[ $field ] ?? '' ) ) );
			if ( self::BAND_ZEILE === $key || self::BAND_KOPF === $key || self::BAND_FUSS === $key ) {
				return $key;
			}
		}
		return null;
	}

	private static function is_direct_child( string $taxonomy, int $parent_id, int $child_id ): bool {
		$term = get_term( $child_id, $taxonomy );
		return $term instanceof \WP_Term && (int) $term->parent === $parent_id;
	}

	/**
	 * Fields of a band = composition members, else hierarchy children.
	 *
	 * @return list<array{id:int,name:string,typeId:int,typeName:string,typeKey:string}>
	 */
	private static function band_fields( string $taxonomy, int $band_id ): array {
		$fields = self::composition_members( $taxonomy, $band_id );
		if ( empty( $fields ) ) {
			$fields = self::hierarchy_children( $taxonomy, $band_id );
		}
		return array_map(
			static function ( array $field ) use ( $taxonomy ): array {
				return self::enrich_band_field( $taxonomy, $field );
			},
			$fields
		);
	}

	/**
	 * @param array{id:int,name:string} $field
	 * @return array{id:int,name:string,typeId:int,typeName:string,typeKey:string}
	 */
	private static function enrich_band_field( string $taxonomy, array $field ): array {
		$field_id = (int) ( $field['id'] ?? 0 );
		$type     = $field_id > 0 ? Node_Type::get_assignment( $taxonomy, $field_id ) : null;
		$type_name = is_array( $type ) ? (string) ( $type['name'] ?? '' ) : '';
		$type_key  = strtolower( trim( $type_name ) );
		if ( 'integer' === $type_key ) {
			$type_key = 'int';
		}
		if ( 'boolean' === $type_key ) {
			$type_key = 'bool';
		}
		if ( '' === $type_key ) {
			$type_key = 'text';
		}
		$out = array(
			'id'       => $field_id,
			'name'     => (string) ( $field['name'] ?? '' ),
			'typeId'   => Node_Type::get_type_id( $field_id ),
			'typeName' => $type_name,
			'typeKey'  => $type_key,
			'footerOp' => Node_Type::get_footer_op( $field_id ),
		);
		if ( 'enum' === $type_key || Node_Type::is_enum_typed_field( $taxonomy, $field_id ) ) {
			$out['typeKey']     = 'enum';
			$out['enumOptions'] = Node_Type::get_enum_options( $taxonomy, $field_id );
		}
		if ( 'node_ref' === $type_key || 'node_embed' === $type_key || 'node_pick' === $type_key ) {
			$scope_id                 = Node_Type::get_ref_scope_id( $field_id );
			$out['refScopeId']        = $scope_id;
			$out['allowedRefIds']     = Node_Type::get_allowed_ref_ids( $field_id );
			$out['fieldMultiplicity'] = Node_Type::get_field_multiplicity( $field_id );
			$out['nodeRefOptions']    = Node_Type::get_node_ref_options_for_slot( $taxonomy, $field_id );
			if ( 'node_ref' === $type_key ) {
				$out['nodeRefCreateFields'] = Composition::get_node_ref_create_fields( $taxonomy, $scope_id );
			}
		}
		return $out;
	}

	/**
	 * @return list<array{id:int,name:string}>
	 */
	private static function composition_members( string $taxonomy, int $from_id ): array {
		$out = array();
		foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $from_id, Relation::TYPE_COMPOSITION ) as $edge ) {
			$to_id = (int) ( $edge['toId'] ?? 0 );
			if ( $to_id <= 0 ) {
				continue;
			}
			$term = get_term( $to_id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$out[] = array(
				'id'   => $to_id,
				'name' => $term->name,
			);
		}
		return $out;
	}

	/**
	 * @return list<array{id:int,name:string}>
	 */
	private static function hierarchy_children( string $taxonomy, int $parent_id ): array {
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
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
		$out = array();
		foreach ( $terms as $term ) {
			$out[] = array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
			);
		}
		return $out;
	}
}
