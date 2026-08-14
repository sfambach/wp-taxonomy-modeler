<?php
/**
 * Additive Relations between Nodes (Q74 / Q75 scaffold).
 *
 * Hierarchy child_of stays synthetic — reparent only (not Add relation).
 * Field / catalog type_id is set via Node_Type (not a RelationType).
 * ref_scope is synthetic (meta); To target is editable in Relations (not via Add edge).
 * child_of To is never editable here — reparent only.
 * Stored edges support add / remove / reorder / change To via stable edge ids.
 * Identical From → RelationType → To edges are not allowed when unnamed.
 * Named attribute edges (besteht_aus / aggregation) may share the same To when names differ (Q123).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Term-meta backed Relation edges (from → to, typed by RelationType Node).
 */
final class Relation {

	public const META_KEY = '_wtt_relations';

	public const ROOT_NAME = 'Relationstypen';

	/**
	 * Request-scoped hydrated outgoing edges (from_id → rows).
	 *
	 * @var array<int, list<array<string, mixed>>>
	 */
	private static array $outgoing_cache = array();

	/** Drop request memos after mutations. */
	public static function bust_request_caches(): void {
		self::$outgoing_cache = array();
	}

	/**
	 * Additive RelationType: domain composition / „besteht aus“ (Q75/Q85).
	 * Legacy term name `composition` still resolved via aliases.
	 */
	public const TYPE_COMPOSITION = 'besteht_aus';

	/** @deprecated Use TYPE_COMPOSITION; kept for reading old edges/seeds. */
	public const TYPE_COMPOSITION_LEGACY = 'composition';

	/**
	 * Aggregation: member lives on when the host object is gone (attribute Bindung).
	 */
	public const TYPE_AGGREGATION = 'aggregation';

	/** Catalog-root binding for node_ref / node_embed (meta; Relations To editable). */
	public const TYPE_REF_SCOPE = 'ref_scope';

	/** Hierarchy parent (synthetic; To not editable in Relations — reparent only). Inheritance along this chain (Q66/Q86). */
	public const TYPE_CHILD_OF = 'child_of';

	/**
	 * Calculation Relation (Q125): op + optional props on settings.data.
	 * First op default_from (= Q124 seed). Later: scale_factor / scale_ref / contains.
	 */
	public const TYPE_CALC = 'calc';

	/**
	 * Legacy Q124 type name — migrate alias for TYPE_CALC + op=default_from.
	 */
	public const TYPE_DEFAULTVALUE_FROM = 'defaultvalue_from';

	/** calc settings.data.op — seed empty slots from provider (Q124 behaviour). */
	public const CALC_OP_DEFAULT_FROM = 'default_from';

	/** calc settings.data.op — static scale (peer units; later). */
	public const CALC_OP_SCALE_FACTOR = 'scale_factor';

	/** calc settings.data.op — factor from external ref (FX; later). */
	public const CALC_OP_SCALE_REF = 'scale_ref';

	/** calc settings.data.op — integer containment (packaging; later). */
	public const CALC_OP_CONTAINS = 'contains';

	/**
	 * Alternate RelationType names accepted when resolving a type key.
	 * Product has only `besteht_aus` — `composition` is read/migrate legacy only (0.7.94).
	 * `defaultvalue_from` ↔ `calc` (Q125).
	 *
	 * @var array<string, list<string>>
	 */
	private const TYPE_NAME_ALIASES = array(
		'besteht_aus'         => array( 'composition' ),
		'composition'         => array( 'besteht_aus' ),
		'calc'                => array( 'defaultvalue_from' ),
		'defaultvalue_from'   => array( 'calc' ),
	);

	/** RelationType names that must not be created via Add relation (system / synthetic). */
	public const PROTECTED_TYPE_NAMES = array( 'child_of', 'ref_scope' );

	/**
	 * Legacy type still readable; not offered in Add relation when `calc` exists.
	 *
	 * @var list<string>
	 */
	public const DEPRECATED_ASSIGNABLE_TYPE_NAMES = array( 'defaultvalue_from' );

	/**
	 * Definition cardinality on a Relation edge (Q78).
	 * Lower bound 0|1; upper bound 1|* (“bis enthalten” = unbounded).
	 */
	public const MULTIPLICITY_DEFAULT = '0..*';

	public const MULTIPLICITIES = array( '0..1', '1', '0..*', '1..*' );

	/**
	 * Whether a RelationType key is an attribute Bindung (Q123: name required).
	 */
	public static function is_attribute_binding_type_key( string $type_key ): bool {
		$key = strtolower( trim( $type_key ) );
		if ( '' === $key ) {
			return false;
		}
		return self::type_keys_match( $key, self::TYPE_COMPOSITION )
			|| self::type_keys_match( $key, self::TYPE_AGGREGATION )
			|| self::type_keys_match( $key, self::TYPE_COMPOSITION_LEGACY );
	}

	/**
	 * Whether a RelationType key is calc or legacy defaultvalue_from (Q125 / Q124).
	 */
	public static function is_calc_type_key( string $type_key ): bool {
		$key = strtolower( trim( $type_key ) );
		if ( '' === $key ) {
			return false;
		}
		return self::type_keys_match( $key, self::TYPE_CALC )
			|| self::type_keys_match( $key, self::TYPE_DEFAULTVALUE_FROM );
	}

	/**
	 * Whether a RelationType key is defaultvalue_from / calc default_from family (Q124).
	 */
	public static function is_defaultvalue_from_type_key( string $type_key ): bool {
		return self::is_calc_type_key( $type_key );
	}

	/**
	 * Resolve calc op from edge/row settings.data.op.
	 * Legacy defaultvalue_from edges (no op) → default_from.
	 *
	 * @param array<string, mixed> $edge_or_row Stored edge or hydrated row.
	 */
	public static function calc_op_from_edge( array $edge_or_row ): string {
		$settings = isset( $edge_or_row['settings'] ) && is_array( $edge_or_row['settings'] )
			? $edge_or_row['settings']
			: array();
		$data = isset( $settings['data'] ) && is_array( $settings['data'] )
			? $settings['data']
			: array();
		$op   = isset( $data['op'] ) ? strtolower( trim( (string) $data['op'] ) ) : '';
		if ( '' !== $op ) {
			return $op;
		}
		$type_key = strtolower( trim( (string) ( $edge_or_row['typeKey'] ?? '' ) ) );
		if ( '' === $type_key && isset( $edge_or_row['typeName'] ) ) {
			$type_key = strtolower( trim( (string) $edge_or_row['typeName'] ) );
		}
		/* Legacy type or bare calc seed → default_from. */
		if ( self::is_calc_type_key( $type_key ) ) {
			return self::CALC_OP_DEFAULT_FROM;
		}
		return '';
	}

	/**
	 * Edge participates in Q124 create/empty seed (calc op=default_from or legacy type).
	 *
	 * @param array<string, mixed> $edge_or_row
	 */
	public static function is_default_from_calc_edge( array $edge_or_row ): bool {
		$type_key = strtolower( trim( (string) ( $edge_or_row['typeKey'] ?? '' ) ) );
		if ( '' === $type_key && isset( $edge_or_row['typeName'] ) ) {
			$type_key = strtolower( trim( (string) $edge_or_row['typeName'] ) );
		}
		if ( ! self::is_calc_type_key( $type_key ) ) {
			return false;
		}
		return self::CALC_OP_DEFAULT_FROM === self::calc_op_from_edge( $edge_or_row );
	}

	/**
	 * UI label for RelationType key (i18n). Falls back to key.
	 */
	public static function type_key_label( string $type_key ): string {
		$key = strtolower( trim( $type_key ) );
		if ( self::TYPE_CALC === $key || self::TYPE_DEFAULTVALUE_FROM === $key ) {
			return __( 'Calculation', 'wp-taxonomy-tree' );
		}
		if ( self::type_keys_match( $key, self::TYPE_COMPOSITION ) ) {
			return __( 'besteht_aus', 'wp-taxonomy-tree' );
		}
		if ( self::TYPE_AGGREGATION === $key ) {
			return __( 'aggregation', 'wp-taxonomy-tree' );
		}
		return $type_key;
	}

	/**
	 * Default settings bag for a new calc edge.
	 *
	 * @return array{data:array{op:string}}
	 */
	public static function calc_settings_for_op( string $op ): array {
		$op = strtolower( trim( $op ) );
		if ( '' === $op ) {
			$op = self::CALC_OP_DEFAULT_FROM;
		}
		return array(
			'data' => array(
				'op' => $op,
			),
		);
	}

	/**
	 * Relation.name required: attribute Bindungen and calc/defaultvalue_from (consumer attr).
	 */
	public static function type_key_requires_name( string $type_key ): bool {
		return self::is_attribute_binding_type_key( $type_key )
			|| self::is_calc_type_key( $type_key );
	}

	/**
	 * @return list<array{id:string,typeId:int,typeName:string,typeKey:string,toId:int,toName:string,name?:string,multiplicity:string,index:int}>
	 */
	public static function list_outgoing( string $taxonomy, int $from_id ): array {
		if ( $from_id > 0 && isset( self::$outgoing_cache[ $from_id ] ) ) {
			return self::$outgoing_cache[ $from_id ];
		}
		$edges = self::read_edges( $from_id );
		$out   = array();
		foreach ( $edges as $index => $edge ) {
			$row = self::hydrate_edge( $taxonomy, $edge, $index );
			if ( null !== $row ) {
				$out[] = $row;
			}
		}
		if ( $from_id > 0 ) {
			self::$outgoing_cache[ $from_id ] = $out;
		}
		return $out;
	}

	/**
	 * Outgoing edges filtered by RelationType key (e.g. composition).
	 *
	 * @return list<array{id:string,typeId:int,typeName:string,typeKey:string,toId:int,toName:string,index:int}>
	 */
	public static function list_outgoing_by_type_key( string $taxonomy, int $from_id, string $type_key ): array {
		$key = strtolower( trim( $type_key ) );
		if ( '' === $key ) {
			return array();
		}
		$out = array();
		foreach ( self::list_outgoing( $taxonomy, $from_id ) as $row ) {
			$row_key  = strtolower( (string) ( $row['typeKey'] ?? '' ) );
			$row_name = strtolower( (string) ( $row['typeName'] ?? '' ) );
			if ( self::type_keys_match( $row_key, $key ) || self::type_keys_match( $row_name, $key )
				|| $row_key === $key || $row_name === $key ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Incoming stored edges (scan terms that reference $to_id).
	 *
	 * @return list<array{id:string,typeId:int,typeName:string,typeKey:string,fromId:int,fromName:string,name?:string,multiplicity:string}>
	 */
	public static function list_incoming( string $taxonomy, int $to_id ): array {
		if ( $to_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
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
			$from_id = (int) $term->term_id;
			if ( $from_id === $to_id ) {
				continue;
			}
			foreach ( self::read_edges( $from_id ) as $edge ) {
				if ( (int) ( $edge['toId'] ?? 0 ) !== $to_id ) {
					continue;
				}
				$type_id   = (int) ( $edge['typeId'] ?? 0 );
				$type      = $type_id > 0 ? get_term( $type_id, $taxonomy ) : null;
				$type_name = $type instanceof \WP_Term ? $type->name : '';
				$type_key  = ! empty( $edge['typeKey'] )
					? (string) $edge['typeKey']
					: strtolower( $type_name );
				$row       = array(
					'id'           => (string) ( $edge['id'] ?? '' ),
					'typeId'       => $type_id,
					'typeName'     => $type_name,
					'typeKey'      => $type_key,
					'fromId'       => $from_id,
					'fromName'     => $term->name,
					'multiplicity' => self::resolve_edge_multiplicity(
						$type_key,
						$edge['multiplicity'] ?? null
					),
				);
				$name = self::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
				if ( '' !== $name ) {
					$row['name'] = $name;
				}
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Term id of the RelationType named $name under Relationstypen, or 0.
	 * Resolves aliases (e.g. composition ↔ besteht_aus).
	 */
	public static function find_type_id_by_name( string $taxonomy, string $name ): int {
		$root_id = self::find_relation_types_root( $taxonomy );
		if ( $root_id <= 0 ) {
			return 0;
		}
		$want = strtolower( trim( $name ) );
		if ( '' === $want ) {
			return 0;
		}
		$aliases = self::TYPE_NAME_ALIASES[ $want ] ?? array();
		$wants   = array_merge( array( $want ), $aliases );

		$found = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $root_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $found ) ) {
			return 0;
		}
		foreach ( $wants as $candidate ) {
			foreach ( $found as $term ) {
				if ( $term instanceof \WP_Term && strtolower( $term->name ) === $candidate ) {
					return (int) $term->term_id;
				}
			}
		}
		// Nested RelationTypes (future folders under Relationstypen).
		foreach ( self::get_assignable_type_options( $taxonomy ) as $opt ) {
			$opt_name = strtolower( (string) ( $opt['name'] ?? '' ) );
			if ( in_array( $opt_name, $wants, true ) ) {
				return (int) ( $opt['id'] ?? 0 );
			}
		}
		return 0;
	}

	/**
	 * True when two type keys refer to the same RelationType (aliases).
	 */
	public static function type_keys_match( string $a, string $b ): bool {
		$a = strtolower( trim( $a ) );
		$b = strtolower( trim( $b ) );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b ) {
			return true;
		}
		$aliases = self::TYPE_NAME_ALIASES[ $a ] ?? array();
		return in_array( $b, $aliases, true );
	}

	/**
	 * Normalize definition multiplicity (Q78). Invalid → default.
	 */
	public static function normalize_multiplicity( $value ): string {
		$key = is_string( $value ) ? trim( $value ) : '';
		if ( in_array( $key, self::MULTIPLICITIES, true ) ) {
			return $key;
		}
		return self::MULTIPLICITY_DEFAULT;
	}

	/**
	 * @return list<array{value:string,label:string}>
	 */
	public static function multiplicity_options(): array {
		return array(
			array(
				'value' => '0..1',
				'label' => __( '0..1 (optional, at most one)', 'wp-taxonomy-tree' ),
			),
			array(
				'value' => '1',
				'label' => __( '1 (exactly one)', 'wp-taxonomy-tree' ),
			),
			array(
				'value' => '0..*',
				'label' => __( '0..* (optional, many)', 'wp-taxonomy-tree' ),
			),
			array(
				'value' => '1..*',
				'label' => __( '1..* (required, many)', 'wp-taxonomy-tree' ),
			),
		);
	}

	/**
	 * Whether an identical From → Type → To (+ name when set) edge already exists.
	 *
	 * Unnamed edges: same typeId+toId = duplicate.
	 * Named edges (Q123 attributes): same typeId+toId+name = duplicate; different names OK.
	 *
	 * @param string $exclude_edge_id Edge id to ignore (e.g. when changing type on that edge).
	 * @param string $name            Optional Relation name (attribute label).
	 */
	public static function has_identical(
		int $from_id,
		int $type_id,
		int $to_id,
		string $exclude_edge_id = '',
		string $name = ''
	): bool {
		if ( $from_id <= 0 || $type_id <= 0 || $to_id <= 0 ) {
			return false;
		}
		$exclude_edge_id = sanitize_key( $exclude_edge_id );
		$name_key        = self::normalize_edge_name( $name );
		foreach ( self::read_edges( $from_id ) as $edge ) {
			if (
				'' !== $exclude_edge_id
				&& sanitize_key( (string) ( $edge['id'] ?? '' ) ) === $exclude_edge_id
			) {
				continue;
			}
			if ( (int) ( $edge['typeId'] ?? 0 ) !== $type_id || (int) ( $edge['toId'] ?? 0 ) !== $to_id ) {
				continue;
			}
			$edge_name = self::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
			if ( '' === $name_key && '' === $edge_name ) {
				return true;
			}
			if ( '' !== $name_key && $edge_name === $name_key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize Relation.name for storage / compare (Q123 attribute label).
	 */
	public static function normalize_edge_name( string $name ): string {
		/* Repair stripslashes damage on JSON `\uXXXX` escapes (ä → u00e4). */
		$name = Json_Meta::repair_stripped_unicode_escapes( $name );
		return trim( sanitize_text_field( $name ) );
	}

	/**
	 * @param string                    $multiplicity Definition cardinality (Q78); default 0..*.
	 * @param string                    $name         Optional Relation name (required for attribute Bindungen).
	 * @param array<string, mixed>|null $settings     Settings.data / Settings.view deltas.
	 * @param array<string, mixed>      $edge_fields  Optional OQ-W4 fields: readOnly, hidden, default.
	 * @return string|\WP_Error New edge id.
	 */
	public static function add(
		string $taxonomy,
		int $from_id,
		int $type_id,
		int $to_id,
		string $multiplicity = self::MULTIPLICITY_DEFAULT,
		string $name = '',
		?array $settings = null,
		array $edge_fields = array()
	) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$from = get_term( $from_id, $taxonomy );
		$to   = get_term( $to_id, $taxonomy );
		$type = get_term( $type_id, $taxonomy );
		if ( ! $from instanceof \WP_Term || ! $to instanceof \WP_Term || ! $type instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		if ( $from_id === $to_id ) {
			return new \WP_Error( 'wtt_bad_relation', __( 'A node cannot relate to itself.', 'wp-taxonomy-tree' ) );
		}
		if ( self::is_protected_type_name( $type->name ) ) {
			return new \WP_Error(
				'wtt_protected_relation',
				__( 'This relation type cannot be added here (use Reparent or the matching setting).', 'wp-taxonomy-tree' )
			);
		}
		if ( ! self::is_under_relation_types( $taxonomy, $type_id ) ) {
			return new \WP_Error(
				'wtt_bad_relation_type',
				__( 'Choose a RelationType under Relationstypen.', 'wp-taxonomy-tree' )
			);
		}

		$name     = self::normalize_edge_name( $name );
		$type_key = strtolower( $type->name );
		if ( self::type_key_requires_name( $type_key ) && '' === $name ) {
			return new \WP_Error(
				'wtt_bad_relation',
				self::is_calc_type_key( $type_key )
					? __( 'Calculation (calc) requires a name (consumer attribute for default_from).', 'wp-taxonomy-tree' )
					: __( 'Attribute relations (besteht_aus / aggregation) require a name.', 'wp-taxonomy-tree' )
			);
		}
		if ( self::has_identical( $from_id, $type_id, $to_id, '', $name ) ) {
			return new \WP_Error(
				'wtt_duplicate_relation',
				'' !== $name
					? __( 'This relation already exists (same From, Relation type, To, and name).', 'wp-taxonomy-tree' )
					: __( 'This relation already exists (same From, Relation type, and To).', 'wp-taxonomy-tree' )
			);
		}
		$edge_id  = self::new_edge_id();
		$edges    = self::read_edges( $from_id );
		$row      = array(
			'id'           => $edge_id,
			'typeId'       => $type_id,
			'toId'         => $to_id,
			'typeKey'      => $type_key,
			'multiplicity' => self::resolve_edge_multiplicity( $type_key, $multiplicity ),
		);
		if ( '' !== $name ) {
			$row['name'] = $name;
		}
		$normalized_settings = self::normalize_settings_deltas( $settings );
		if ( null !== $normalized_settings ) {
			$row['settings'] = $normalized_settings;
		}
		/* OQ-W4 edge fields (move / restore paths). */
		$row = self::with_edge_bool_fields( $edge_fields, $row );
		$row = self::with_edge_default_field( $edge_fields, $row );
		$edges[] = $row;
		$written = self::write_edges( $from_id, $edges );
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		return $edge_id;
	}

	/**
	 * Update Relation.name on a stored edge (Q123 attribute label).
	 *
	 * @return true|\WP_Error
	 */
	public static function update_name( string $taxonomy, int $from_id, string $edge_id, string $name ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$from = get_term( $from_id, $taxonomy );
		if ( ! $from instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		$edge_id = sanitize_key( $edge_id );
		$name    = self::normalize_edge_name( $name );
		if ( '' === $edge_id ) {
			return new \WP_Error( 'wtt_not_found', __( 'Relation not found.', 'wp-taxonomy-tree' ) );
		}
		$edges = self::read_edges( $from_id );
		$found = false;
		foreach ( $edges as $i => $edge ) {
			if ( sanitize_key( (string) ( $edge['id'] ?? '' ) ) !== $edge_id ) {
				continue;
			}
			$type_id = (int) ( $edge['typeId'] ?? 0 );
			$to_id   = (int) ( $edge['toId'] ?? 0 );
			if ( self::has_identical( $from_id, $type_id, $to_id, $edge_id, $name ) ) {
				return new \WP_Error(
					'wtt_duplicate_relation',
					__( 'This relation already exists (same From, Relation type, To, and name).', 'wp-taxonomy-tree' )
				);
			}
			if ( '' === $name ) {
				unset( $edges[ $i ]['name'] );
			} else {
				$edges[ $i ]['name'] = $name;
			}
			$found = true;
			break;
		}
		if ( ! $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Relation not found.', 'wp-taxonomy-tree' ) );
		}
		return self::write_edges( $from_id, $edges );
	}

	/**
	 * Replace Settings override deltas on an edge (`data` / `view` maps). Empty clears.
	 *
	 * @param array<string, mixed>|null $settings
	 * @return true|\WP_Error
	 */
	public static function update_settings( string $taxonomy, int $from_id, string $edge_id, ?array $settings ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$from = get_term( $from_id, $taxonomy );
		if ( ! $from instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		$edge_id = class_exists( __NAMESPACE__ . '\\Attribute' )
			? Attribute::normalize_attr_id( $edge_id )
			: sanitize_key( $edge_id );
		if ( '' === $edge_id ) {
			return new \WP_Error( 'wtt_not_found', __( 'Relation not found.', 'wp-taxonomy-tree' ) );
		}
		$edges = self::read_edges( $from_id );
		$found = false;
		foreach ( $edges as $i => $edge ) {
			$candidate = class_exists( __NAMESPACE__ . '\\Attribute' )
				? Attribute::normalize_attr_id( $edge['id'] ?? '' )
				: sanitize_key( (string) ( $edge['id'] ?? '' ) );
			if ( $candidate !== $edge_id ) {
				continue;
			}
			$normalized = self::normalize_settings_deltas( $settings );
			if ( null === $normalized ) {
				unset( $edges[ $i ]['settings'] );
			} else {
				$edges[ $i ]['settings'] = $normalized;
			}
			$found = true;
			break;
		}
		if ( ! $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Relation not found.', 'wp-taxonomy-tree' ) );
		}
		return self::write_edges( $from_id, $edges );
	}

	/**
	 * Normalize Relation Settings deltas.
	 *
	 * Top-level `data` / `view` = depth-0 (direct attribute target).
	 * Optional `nested` = flat map of path → `{data?,view?}` for Walk-Wizard
	 * overrides along composition (Q123 / OQ-W6). Path keys are `/`-joined
	 * Relation edge UUIDs from the attribute target (see Settings_Walk).
	 *
	 * @param array<string, mixed>|null $settings
	 * @return array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,array{data?:array<string,mixed>,view?:array<string,mixed>}>}|null
	 */
	public static function normalize_settings_deltas( ?array $settings ): ?array {
		if ( null === $settings || ! is_array( $settings ) ) {
			return null;
		}
		$out = array();
		foreach ( array( 'data', 'view' ) as $ns ) {
			if ( empty( $settings[ $ns ] ) || ! is_array( $settings[ $ns ] ) ) {
				continue;
			}
			$bag = array();
			foreach ( $settings[ $ns ] as $key => $value ) {
				$k = is_string( $key ) ? self::sanitize_settings_key( $key ) : '';
				if ( '' === $k ) {
					continue;
				}
				$bag[ $k ] = $value;
			}
			if ( ! empty( $bag ) ) {
				$out[ $ns ] = $bag;
			}
		}

		/* Nested path deltas — flat map; values are data/view bags only (no nested-in-nested). */
		if ( isset( $settings['nested'] ) && is_array( $settings['nested'] ) ) {
			$nested_out = array();
			foreach ( $settings['nested'] as $path => $bag ) {
				if ( ! is_string( $path ) || ! is_array( $bag ) ) {
					continue;
				}
				$path_key = self::normalize_nested_settings_path( $path );
				if ( '' === $path_key ) {
					continue;
				}
				$norm_bag = self::normalize_settings_bag( $bag );
				if ( null !== $norm_bag ) {
					$nested_out[ $path_key ] = $norm_bag;
				}
			}
			if ( ! empty( $nested_out ) ) {
				$out['nested'] = $nested_out;
			}
		}

		return empty( $out ) ? null : $out;
	}

	/**
	 * Normalize a single Settings.data / Settings.view bag (no nested map).
	 *
	 * @param array<string, mixed> $bag
	 * @return array{data?:array<string,mixed>,view?:array<string,mixed>}|null
	 */
	public static function normalize_settings_bag( array $bag ): ?array {
		$out = array();
		foreach ( array( 'data', 'view' ) as $ns ) {
			if ( empty( $bag[ $ns ] ) || ! is_array( $bag[ $ns ] ) ) {
				continue;
			}
			$ns_bag = array();
			foreach ( $bag[ $ns ] as $key => $value ) {
				$k = is_string( $key ) ? self::sanitize_settings_key( $key ) : '';
				if ( '' === $k ) {
					continue;
				}
				$ns_bag[ $k ] = $value;
			}
			if ( ! empty( $ns_bag ) ) {
				$out[ $ns ] = $ns_bag;
			}
		}
		return empty( $out ) ? null : $out;
	}

	/**
	 * Walk path = `/`-joined Relation edge UUIDs (sanitize_key segments).
	 * Empty string = depth 0 (top-level data/view).
	 */
	public static function normalize_nested_settings_path( string $path ): string {
		$path = trim( $path );
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return '';
		}
		$parts = array();
		foreach ( explode( '/', $path ) as $seg ) {
			$seg = class_exists( __NAMESPACE__ . '\\Attribute' )
				? Attribute::normalize_attr_id( $seg )
				: sanitize_key( (string) $seg );
			if ( '' === $seg ) {
				return '';
			}
			$parts[] = $seg;
		}
		return implode( '/', $parts );
	}

	/**
	 * Settings.data / Settings.view wire keys (camelCase). Do not use sanitize_key —
	 * it lowercases and would turn preferredRenderer into preferredrenderer.
	 */
	public static function sanitize_settings_key( string $key ): string {
		$key = trim( $key );
		if ( '' === $key ) {
			return '';
		}
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $key );
		return is_string( $clean ) ? $clean : '';
	}

	/**
	 * Fixed multiplicity for a RelationType name, or null if free (Q78).
	 *
	 * child_of always exactly one parent (hierarchy invariant).
	 * ref_scope stays 0..1 (synthetic binding).
	 */
	public static function fixed_multiplicity_for_type_name( string $name ): ?string {
		$key = strtolower( trim( $name ) );
		if ( self::TYPE_CHILD_OF === $key ) {
			return '1';
		}
		if ( self::TYPE_REF_SCOPE === $key ) {
			return '0..1';
		}
		/* One provider link per edge; multiple named consumer attrs = multiple edges. */
		if ( self::is_calc_type_key( $key ) ) {
			return '0..1';
		}
		return null;
	}

	/**
	 * Resolve multiplicity for an edge, applying RelationType locks (child_of → 1).
	 *
	 * @param string|null $type_key Edge typeKey or RelationType name.
	 * @param mixed       $value    Raw multiplicity from storage / caller.
	 */
	public static function resolve_edge_multiplicity( ?string $type_key, $value ): string {
		$fixed = null !== $type_key && '' !== $type_key
			? self::fixed_multiplicity_for_type_name( $type_key )
			: null;
		if ( null !== $fixed ) {
			return $fixed;
		}
		return self::normalize_multiplicity( $value );
	}

	/**
	 * Remove one stored edge by id (preferred) or first matching type+to.
	 *
	 * @return true|\WP_Error
	 */
	public static function remove( string $taxonomy, int $from_id, int $type_id = 0, int $to_id = 0, string $edge_id = '' ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$from = get_term( $from_id, $taxonomy );
		if ( ! $from instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		$edges   = self::read_edges( $from_id );
		$changed = false;
		$next    = array();
		$edge_id = sanitize_key( $edge_id );

		foreach ( $edges as $edge ) {
			$match = false;
			if ( '' !== $edge_id && sanitize_key( (string) ( $edge['id'] ?? '' ) ) === $edge_id ) {
				$match = true;
			} elseif (
				'' === $edge_id
				&& $type_id > 0
				&& $to_id > 0
				&& ! $changed
				&& (int) ( $edge['typeId'] ?? 0 ) === $type_id
				&& (int) ( $edge['toId'] ?? 0 ) === $to_id
			) {
				$match = true;
			}
			if ( $match ) {
				$changed = true;
				continue;
			}
			$next[] = $edge;
		}
		if ( ! $changed ) {
			return true;
		}
		return self::write_edges( $from_id, $next );
	}

	/**
	 * Change RelationType of a stored edge (additive types only).
	 *
	 * @return true|\WP_Error
	 */
	public static function update_type( string $taxonomy, int $from_id, string $edge_id, int $new_type_id ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$from = get_term( $from_id, $taxonomy );
		if ( ! $from instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		$edge_id = sanitize_key( $edge_id );
		if ( '' === $edge_id || $new_type_id <= 0 ) {
			return new \WP_Error( 'wtt_bad_relation', __( 'Invalid relation type change.', 'wp-taxonomy-tree' ) );
		}

		$type = get_term( $new_type_id, $taxonomy );
		if ( ! $type instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Relation type not found.', 'wp-taxonomy-tree' ) );
		}
		if ( self::is_protected_type_name( $type->name ) ) {
			return new \WP_Error(
				'wtt_protected_relation',
				__( 'Cannot assign a protected relation type here.', 'wp-taxonomy-tree' )
			);
		}
		if ( ! self::is_under_relation_types( $taxonomy, $new_type_id ) ) {
			return new \WP_Error(
				'wtt_bad_relation_type',
				__( 'Choose a RelationType under Relationstypen.', 'wp-taxonomy-tree' )
			);
		}

		$edges  = self::read_edges( $from_id );
		$found  = false;
		$to_id  = 0;
		foreach ( $edges as $i => $edge ) {
			if ( sanitize_key( (string) ( $edge['id'] ?? '' ) ) !== $edge_id ) {
				continue;
			}
			$to_id = (int) ( $edge['toId'] ?? 0 );
			if ( self::has_identical( $from_id, $new_type_id, $to_id, $edge_id ) ) {
				return new \WP_Error(
					'wtt_duplicate_relation',
					__( 'This relation already exists (same From, Relation type, and To).', 'wp-taxonomy-tree' )
				);
			}
			$edges[ $i ]['typeId']  = $new_type_id;
			$edges[ $i ]['typeKey'] = strtolower( $type->name );
			$found                  = true;
			break;
		}
		if ( ! $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Relation not found.', 'wp-taxonomy-tree' ) );
		}
		return self::write_edges( $from_id, $edges );
	}

	/**
	 * Set / clear OQ-W4 edge readOnly (omit key when false).
	 *
	 * @return true|\WP_Error
	 */
	public static function update_read_only( string $taxonomy, int $from_id, string $edge_id, bool $read_only ) {
		return self::update_edge_bool_flag( $taxonomy, $from_id, $edge_id, 'readOnly', $read_only );
	}

	/**
	 * Set / clear OQ-W4 / Q105 edge hidden (background-only). Omit key when false.
	 *
	 * @return true|\WP_Error
	 */
	public static function update_hidden( string $taxonomy, int $from_id, string $edge_id, bool $hidden ) {
		return self::update_edge_bool_flag( $taxonomy, $from_id, $edge_id, 'hidden', $hidden );
	}

	/**
	 * Set / clear OQ-W4 edge default seed (Q106 templates). Empty / null omits the key.
	 *
	 * Storage key is `default` (not Settings). Always persisted as a list when present.
	 *
	 * @param list<string|array<string,string>>|string|null $values
	 * @return true|\WP_Error
	 */
	public static function update_default( string $taxonomy, int $from_id, string $edge_id, $values ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$from = get_term( $from_id, $taxonomy );
		if ( ! $from instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		$edge_id = class_exists( __NAMESPACE__ . '\\Attribute' )
			? Attribute::normalize_attr_id( $edge_id )
			: sanitize_key( $edge_id );
		if ( '' === $edge_id ) {
			return new \WP_Error( 'wtt_bad_relation', __( 'Missing relation id.', 'wp-taxonomy-tree' ) );
		}

		$normalized = class_exists( __NAMESPACE__ . '\\Attribute' )
			? Attribute::normalize_default_seed( $values )
			: array();

		$edges = self::read_edges( $from_id );
		$found = false;
		foreach ( $edges as $i => $edge ) {
			$candidate = class_exists( __NAMESPACE__ . '\\Attribute' )
				? Attribute::normalize_attr_id( $edge['id'] ?? '' )
				: sanitize_key( (string) ( $edge['id'] ?? '' ) );
			if ( $candidate !== $edge_id ) {
				continue;
			}
			unset( $edges[ $i ]['defaultSeed'] );
			if ( empty( $normalized ) ) {
				unset( $edges[ $i ]['default'] );
			} else {
				$edges[ $i ]['default'] = $normalized;
			}
			$found = true;
			break;
		}
		if ( ! $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Relation not found.', 'wp-taxonomy-tree' ) );
		}
		return self::write_edges( $from_id, $edges );
	}

	/**
	 * @param 'readOnly'|'hidden' $flag
	 * @return true|\WP_Error
	 */
	private static function update_edge_bool_flag(
		string $taxonomy,
		int $from_id,
		string $edge_id,
		string $flag,
		bool $on
	) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$from = get_term( $from_id, $taxonomy );
		if ( ! $from instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		$edge_id = class_exists( __NAMESPACE__ . '\\Attribute' )
			? Attribute::normalize_attr_id( $edge_id )
			: sanitize_key( $edge_id );
		if ( '' === $edge_id || ( 'readOnly' !== $flag && 'hidden' !== $flag ) ) {
			return new \WP_Error( 'wtt_bad_relation', __( 'Missing relation id.', 'wp-taxonomy-tree' ) );
		}

		$edges = self::read_edges( $from_id );
		$found = false;
		foreach ( $edges as $i => $edge ) {
			$candidate = class_exists( __NAMESPACE__ . '\\Attribute' )
				? Attribute::normalize_attr_id( $edge['id'] ?? '' )
				: sanitize_key( (string) ( $edge['id'] ?? '' ) );
			if ( $candidate !== $edge_id ) {
				continue;
			}
			if ( $on ) {
				$edges[ $i ][ $flag ] = true;
				if ( 'readOnly' === $flag ) {
					unset( $edges[ $i ]['readonly'] );
				}
			} else {
				unset( $edges[ $i ][ $flag ] );
				if ( 'readOnly' === $flag ) {
					unset( $edges[ $i ]['readonly'] );
				}
			}
			$found = true;
			break;
		}
		if ( ! $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Relation not found.', 'wp-taxonomy-tree' ) );
		}
		return self::write_edges( $from_id, $edges );
	}

	/**
	 * Copy OQ-W4 edge bool fields onto a normalized row (true keys only).
	 *
	 * @param array<string, mixed> $source Raw edge.
	 * @param array<string, mixed> $row    Normalized row.
	 * @return array<string, mixed>
	 */
	private static function with_edge_bool_fields( array $source, array $row ): array {
		if ( ! empty( $source['readOnly'] ) || ! empty( $source['readonly'] ) ) {
			$row['readOnly'] = true;
		}
		if ( ! empty( $source['hidden'] ) ) {
			$row['hidden'] = true;
		}
		return $row;
	}

	/**
	 * Copy OQ-W4 edge default seed onto a normalized row (omit when empty).
	 *
	 * Canonical key: `default`. Legacy alias `defaultSeed` accepted on read.
	 *
	 * @param array<string, mixed> $source Raw edge.
	 * @param array<string, mixed> $row    Normalized row.
	 * @return array<string, mixed>
	 */
	private static function with_edge_default_field( array $source, array $row ): array {
		$raw = null;
		if ( array_key_exists( 'default', $source ) ) {
			$raw = $source['default'];
		} elseif ( array_key_exists( 'defaultSeed', $source ) ) {
			$raw = $source['defaultSeed'];
		}
		if ( null === $raw ) {
			return $row;
		}
		$normalized = class_exists( __NAMESPACE__ . '\\Attribute' )
			? Attribute::normalize_default_seed( $raw )
			: array();
		if ( empty( $normalized ) ) {
			return $row;
		}
		$row['default'] = $normalized;
		return $row;
	}

	/**
	 * Update definition multiplicity on a stored edge (Q78).
	 *
	 * @return true|\WP_Error
	 */
	public static function update_multiplicity( string $taxonomy, int $from_id, string $edge_id, string $multiplicity ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$from = get_term( $from_id, $taxonomy );
		if ( ! $from instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		$edge_id = sanitize_key( $edge_id );
		if ( '' === $edge_id ) {
			return new \WP_Error( 'wtt_bad_relation', __( 'Missing relation id.', 'wp-taxonomy-tree' ) );
		}

		$edges = self::read_edges( $from_id );
		$found = false;
		foreach ( $edges as $i => $edge ) {
			if ( sanitize_key( (string) ( $edge['id'] ?? '' ) ) !== $edge_id ) {
				continue;
			}
			$type_key = strtolower( (string) ( $edge['typeKey'] ?? '' ) );
			if ( '' === $type_key && ! empty( $edge['typeId'] ) ) {
				$t = get_term( (int) $edge['typeId'], $taxonomy );
				if ( $t instanceof \WP_Term ) {
					$type_key = strtolower( $t->name );
				}
			}
			$fixed     = self::fixed_multiplicity_for_type_name( $type_key );
			$requested = self::normalize_multiplicity( $multiplicity );
			if ( null !== $fixed && $requested !== $fixed ) {
				return new \WP_Error(
					'wtt_fixed_multiplicity',
					sprintf(
						/* translators: 1: relation type key, 2: fixed multiplicity */
						__( 'Multiplicity for %1$s is fixed to %2$s and cannot be changed.', 'wp-taxonomy-tree' ),
						$type_key,
						$fixed
					)
				);
			}
			$edges[ $i ]['multiplicity'] = self::resolve_edge_multiplicity( $type_key, $multiplicity );
			$found                       = true;
			break;
		}
		if ( ! $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Relation not found.', 'wp-taxonomy-tree' ) );
		}
		return self::write_edges( $from_id, $edges );
	}

	/**
	 * Change the To node of a Relation (outgoing from $from_id).
	 *
	 * - child_of: rejected (reparent only)
	 * - ref_scope: rebinds catalog-root meta
	 * - stored edges: updates toId on the edge
	 *
	 * @param string $type_key Optional type name when there is no edge id (ref_scope).
	 * @return true|\WP_Error
	 */
	public static function update_to(
		string $taxonomy,
		int $from_id,
		int $new_to_id,
		string $edge_id = '',
		int $type_id = 0,
		string $type_key = ''
	) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$from = get_term( $from_id, $taxonomy );
		if ( ! $from instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		if ( $new_to_id <= 0 ) {
			return new \WP_Error( 'wtt_bad_relation', __( 'Choose a target node.', 'wp-taxonomy-tree' ) );
		}
		if ( $new_to_id === $from_id ) {
			return new \WP_Error(
				'wtt_bad_relation',
				__( 'A relation cannot target the same node as From.', 'wp-taxonomy-tree' )
			);
		}
		$to = get_term( $new_to_id, $taxonomy );
		if ( ! $to instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Target node not found.', 'wp-taxonomy-tree' ) );
		}

		$edge_id  = sanitize_key( $edge_id );
		$type_key = strtolower( trim( $type_key ) );

		if ( '' !== $edge_id ) {
			$edges = self::read_edges( $from_id );
			$found = false;
			foreach ( $edges as $i => $edge ) {
				if ( sanitize_key( (string) ( $edge['id'] ?? '' ) ) !== $edge_id ) {
					continue;
				}
				$edge_type_id = (int) ( $edge['typeId'] ?? 0 );
				$edge_name    = (string) ( $edge['name'] ?? '' );
				if ( self::has_identical( $from_id, $edge_type_id, $new_to_id, $edge_id, $edge_name ) ) {
					return new \WP_Error(
						'wtt_duplicate_relation',
						__( 'This relation already exists (same From, Relation type, and To).', 'wp-taxonomy-tree' )
					);
				}
				$edges[ $i ]['toId'] = $new_to_id;
				$found               = true;
				break;
			}
			if ( ! $found ) {
				return new \WP_Error( 'wtt_not_found', __( 'Relation not found.', 'wp-taxonomy-tree' ) );
			}
			return self::write_edges( $from_id, $edges );
		}

		if ( $type_id > 0 ) {
			$type = get_term( $type_id, $taxonomy );
			if ( $type instanceof \WP_Term ) {
				$type_key = strtolower( $type->name );
			}
		}

		if ( self::TYPE_CHILD_OF === $type_key ) {
			return new \WP_Error(
				'wtt_protected_relation',
				__( 'child_of target is fixed here — use Reparent.', 'wp-taxonomy-tree' )
			);
		}
		if ( self::TYPE_REF_SCOPE === $type_key ) {
			return Node_Type::set_ref_scope_id( $taxonomy, $from_id, $new_to_id );
		}

		return new \WP_Error(
			'wtt_bad_relation',
			__( 'Cannot change target for this relation.', 'wp-taxonomy-tree' )
		);
	}

	/**
	 * Identical From → Type → To copies are not allowed.
	 * Use Add relation / “other relation type” to create a different type on the same endpoints.
	 *
	 * @return true|\WP_Error
	 */
	public static function duplicate( string $taxonomy, int $from_id, string $edge_id ) {
		unset( $taxonomy, $from_id, $edge_id );
		return new \WP_Error(
			'wtt_duplicate_relation',
			__( 'Identical relations are not allowed. Pick a different relation type for the same connection.', 'wp-taxonomy-tree' )
		);
	}

	/**
	 * Move a stored edge up (delta -1) or down (delta +1) in the list.
	 *
	 * @return true|\WP_Error
	 */
	public static function move( string $taxonomy, int $from_id, string $edge_id, int $delta ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$from = get_term( $from_id, $taxonomy );
		if ( ! $from instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		$edge_id = sanitize_key( $edge_id );
		if ( '' === $edge_id || 0 === $delta ) {
			return new \WP_Error( 'wtt_bad_relation', __( 'Invalid relation move.', 'wp-taxonomy-tree' ) );
		}

		$edges = self::read_edges( $from_id );
		$index = -1;
		foreach ( $edges as $i => $edge ) {
			if ( sanitize_key( (string) ( $edge['id'] ?? '' ) ) === $edge_id ) {
				$index = (int) $i;
				break;
			}
		}
		if ( $index < 0 ) {
			return new \WP_Error( 'wtt_not_found', __( 'Relation not found.', 'wp-taxonomy-tree' ) );
		}

		$target = $index + $delta;
		if ( $target < 0 || $target >= count( $edges ) ) {
			return true;
		}

		$tmp            = $edges[ $index ];
		$edges[ $index ]  = $edges[ $target ];
		$edges[ $target ] = $tmp;

		return self::write_edges( $from_id, array_values( $edges ) );
	}

	/**
	 * Force multiplicity = 1 on any stored child_of edges (idempotent).
	 * Hierarchy is normally synthetic (WP parent); repair leftover stored edges.
	 *
	 * @return int Number of edges rewritten.
	 */
	public static function repair_child_of_multiplicity( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$child_of_id = self::find_type_id_by_name( $taxonomy, self::TYPE_CHILD_OF );
		$terms       = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $terms ) ) {
			return 0;
		}

		$fixed   = '1';
		$repaired = 0;
		foreach ( $terms as $tid ) {
			$tid   = (int) $tid;
			$edges = self::read_edges( $tid );
			if ( empty( $edges ) ) {
				continue;
			}
			$changed = false;
			foreach ( $edges as $i => $edge ) {
				$key = strtolower( (string) ( $edge['typeKey'] ?? '' ) );
				$eid = (int) ( $edge['typeId'] ?? 0 );
				$is_child_of = self::TYPE_CHILD_OF === $key
					|| ( $child_of_id > 0 && $eid === $child_of_id );
				if ( ! $is_child_of ) {
					continue;
				}
				$current = self::normalize_multiplicity( $edge['multiplicity'] ?? null );
				if ( $fixed !== $current || self::TYPE_CHILD_OF !== $key ) {
					$edges[ $i ]['multiplicity'] = $fixed;
					$edges[ $i ]['typeKey']      = self::TYPE_CHILD_OF;
					$changed                     = true;
					++$repaired;
				}
			}
			if ( $changed ) {
				self::write_edges( $tid, $edges );
			}
		}
		return $repaired;
	}

	/**
	 * Q86: drop obsolete RelationType `erbt_von` (inheritance = child_of only).
	 * Removes edges of that type, then every `erbt_von` type term under any Relationstypen folder.
	 */
	public static function migrate_drop_erbt_von( string $taxonomy ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$found = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => 'erbt_von',
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $found ) || empty( $found ) ) {
			return;
		}

		$type_ids = array();
		foreach ( $found as $term ) {
			if ( $term instanceof \WP_Term ) {
				$type_ids[] = (int) $term->term_id;
			}
		}
		$type_ids = array_values( array_unique( array_filter( $type_ids ) ) );
		if ( empty( $type_ids ) ) {
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
		if ( is_array( $terms ) ) {
			foreach ( $terms as $tid ) {
				$tid   = (int) $tid;
				$edges = self::read_edges( $tid );
				if ( empty( $edges ) ) {
					continue;
				}
				$kept    = array();
				$changed = false;
				foreach ( $edges as $edge ) {
					$key = strtolower( (string) ( $edge['typeKey'] ?? '' ) );
					$eid = (int) ( $edge['typeId'] ?? 0 );
					if ( in_array( $eid, $type_ids, true ) || 'erbt_von' === $key ) {
						$changed = true;
						continue;
					}
					$kept[] = $edge;
				}
				if ( $changed ) {
					self::write_edges( $tid, $kept );
				}
			}
		}

		foreach ( $type_ids as $type_id ) {
			Node_Type::set_deletable( $type_id, true );
			wp_delete_term( $type_id, $taxonomy );
		}
	}

	/**
	 * Rename legacy RelationType `composition` → `besteht_aus` (idempotent).
	 */
	public static function migrate_composition_type_name( string $taxonomy ): void {
		$folder_id = self::find_relation_types_root( $taxonomy );
		if ( $folder_id <= 0 ) {
			return;
		}

		$exact = static function ( string $name ) use ( $taxonomy, $folder_id ): int {
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $folder_id,
					'hide_empty' => false,
					'name'       => $name,
					'number'     => 1,
				)
			);
			if ( is_array( $found ) && isset( $found[0] ) && $found[0] instanceof \WP_Term ) {
				return (int) $found[0]->term_id;
			}
			return 0;
		};

		$legacy = $exact( self::TYPE_COMPOSITION_LEGACY );
		$modern = $exact( self::TYPE_COMPOSITION );

		if ( $legacy > 0 && $modern <= 0 ) {
			$updated = wp_update_term(
				$legacy,
				$taxonomy,
				array(
					'name' => self::TYPE_COMPOSITION,
					'slug' => self::TYPE_COMPOSITION,
				)
			);
			if ( ! is_wp_error( $updated ) ) {
				self::rewrite_edge_type_keys( $taxonomy, $legacy, self::TYPE_COMPOSITION );
			}
			return;
		}

		if ( $legacy > 0 && $modern > 0 && $legacy !== $modern ) {
			self::retarget_edges_type_id( $taxonomy, $legacy, $modern );
			Node_Type::set_deletable( $legacy, true );
			wp_delete_term( $legacy, $taxonomy );
			self::rewrite_edge_type_keys( $taxonomy, $modern, self::TYPE_COMPOSITION );
			return;
		}

		if ( $modern > 0 ) {
			self::rewrite_edge_type_keys( $taxonomy, $modern, self::TYPE_COMPOSITION );
		}
	}

	/**
	 * Rewrite stored edge typeKey for a RelationType id.
	 */
	public static function rewrite_edge_type_keys( string $taxonomy, int $type_id, string $type_key ): void {
		if ( $type_id <= 0 || '' === $type_key || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$type_key = strtolower( trim( $type_key ) );
		$terms    = get_terms(
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
		foreach ( $terms as $tid ) {
			$tid   = (int) $tid;
			$edges = self::read_edges( $tid );
			if ( empty( $edges ) ) {
				continue;
			}
			$changed = false;
			foreach ( $edges as $i => $edge ) {
				if ( (int) ( $edge['typeId'] ?? 0 ) !== $type_id ) {
					continue;
				}
				if ( strtolower( (string) ( $edge['typeKey'] ?? '' ) ) !== $type_key ) {
					$edges[ $i ]['typeKey'] = $type_key;
					$changed                = true;
				}
			}
			if ( $changed ) {
				self::write_edges( $tid, $edges );
			}
		}
	}

	/**
	 * Retarget all edges from one RelationType id to another.
	 */
	public static function retarget_edges_type_id( string $taxonomy, int $from_type_id, int $to_type_id ): void {
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
		foreach ( $terms as $tid ) {
			$tid   = (int) $tid;
			$edges = self::read_edges( $tid );
			if ( empty( $edges ) ) {
				continue;
			}
			$changed = false;
			foreach ( $edges as $i => $edge ) {
				if ( (int) ( $edge['typeId'] ?? 0 ) !== $from_type_id ) {
					continue;
				}
				$edges[ $i ]['typeId']  = $to_type_id;
				$edges[ $i ]['typeKey'] = self::TYPE_COMPOSITION;
				$changed                = true;
			}
			if ( $changed ) {
				self::write_edges( $tid, $edges );
			}
		}
	}

	public static function find_relation_types_root( string $taxonomy ): int {
		$candidates = array(
			array( Demo_Data::ROOT_NAME, self::ROOT_NAME ),
			array( Case_Data::ROOT_NAME, self::ROOT_NAME ),
		);
		foreach ( $candidates as $path ) {
			$found = Demo_Data::find_term_by_path( $taxonomy, $path );
			if ( $found > 0 ) {
				return $found;
			}
		}
		$found = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => self::ROOT_NAME,
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
	 * Tree of RelationType nodes for the picker (under Relationstypen).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_relation_type_tree( string $taxonomy ): array {
		$root_id = self::find_relation_types_root( $taxonomy );
		if ( $root_id <= 0 ) {
			return array();
		}
		$root = get_term( $root_id, $taxonomy );
		if ( ! $root instanceof \WP_Term ) {
			return array();
		}
		$node = self::build_type_tree_node( $taxonomy, $root );
		return $node ? array( $node ) : array();
	}

	/**
	 * Assignable RelationType options (excludes protected names like child_of).
	 *
	 * @return list<array{id:int,name:string,path:string,protected:bool}>
	 */
	public static function get_assignable_type_options( string $taxonomy ): array {
		$root_id = self::find_relation_types_root( $taxonomy );
		if ( $root_id <= 0 ) {
			return array();
		}
		$out = array();
		self::collect_type_options( $taxonomy, $root_id, $out, array( self::ROOT_NAME ) );
		$has_calc = false;
		foreach ( $out as $opt ) {
			if ( self::TYPE_CALC === strtolower( (string) ( $opt['name'] ?? '' ) ) ) {
				$has_calc = true;
				break;
			}
		}
		if ( ! $has_calc ) {
			return $out;
		}
		/* Hide legacy defaultvalue_from from Add picker when calc is seeded. */
		$filtered = array();
		foreach ( $out as $opt ) {
			$name = strtolower( (string) ( $opt['name'] ?? '' ) );
			if ( in_array( $name, self::DEPRECATED_ASSIGNABLE_TYPE_NAMES, true ) ) {
				continue;
			}
			$filtered[] = $opt;
		}
		return $filtered;
	}

	public static function is_protected_type_name( string $name ): bool {
		return in_array( strtolower( trim( $name ) ), self::PROTECTED_TYPE_NAMES, true );
	}

	public static function is_under_relation_types( string $taxonomy, int $term_id ): bool {
		$root_id = self::find_relation_types_root( $taxonomy );
		if ( $root_id <= 0 || $term_id <= 0 ) {
			return false;
		}
		if ( $term_id === $root_id ) {
			return false;
		}
		$term  = get_term( $term_id, $taxonomy );
		$guard = 0;
		while ( $term instanceof \WP_Term && $guard < 64 ) {
			++$guard;
			$parent = (int) $term->parent;
			if ( $parent === $root_id ) {
				return true;
			}
			if ( $parent <= 0 ) {
				break;
			}
			$term = get_term( $parent, $taxonomy );
		}
		return false;
	}

	private static function new_edge_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return sanitize_key( str_replace( '-', '', wp_generate_uuid4() ) );
		}
		return sanitize_key( uniqid( 'rel', true ) );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function read_edges( int $from_id ): array {
		$raw = get_term_meta( $from_id, self::META_KEY, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out   = array();
		$dirty = false;
		foreach ( $raw as $edge ) {
			if ( ! is_array( $edge ) ) {
				continue;
			}
			$type_id = isset( $edge['typeId'] ) ? (int) $edge['typeId'] : 0;
			$to_id   = isset( $edge['toId'] ) ? (int) $edge['toId'] : 0;
			if ( $type_id <= 0 || $to_id <= 0 ) {
				continue;
			}
			$id = isset( $edge['id'] ) ? sanitize_key( (string) $edge['id'] ) : '';
			if ( '' === $id ) {
				$id    = self::new_edge_id();
				$dirty = true;
			}
			$type_key = ! empty( $edge['typeKey'] )
				? (string) $edge['typeKey']
				: self::type_key_for_type_id( $type_id );
			$resolved = self::resolve_edge_multiplicity( $type_key, $edge['multiplicity'] ?? null );
			$stored   = array_key_exists( 'multiplicity', $edge )
				? trim( (string) $edge['multiplicity'] )
				: null;
			if ( null === $stored || $stored !== $resolved ) {
				$dirty = true;
			}
			$row = array(
				'id'           => $id,
				'typeId'       => $type_id,
				'toId'         => $to_id,
				'multiplicity' => $resolved,
			);
			if ( ! empty( $edge['typeKey'] ) ) {
				$row['typeKey'] = sanitize_key( (string) $edge['typeKey'] );
			} elseif ( '' !== $type_key ) {
				$row['typeKey'] = sanitize_key( $type_key );
			}
			$raw_name = (string) ( $edge['name'] ?? '' );
			$name     = self::normalize_edge_name( $raw_name );
			if ( '' !== $name ) {
				$row['name'] = $name;
			}
			if ( '' !== $raw_name && Json_Meta::has_stripped_unicode_escapes( $raw_name ) && $name !== $raw_name ) {
				$dirty = true;
			}
			$settings = self::normalize_settings_deltas(
				isset( $edge['settings'] ) && is_array( $edge['settings'] ) ? $edge['settings'] : null
			);
			if ( null !== $settings ) {
				$row['settings'] = $settings;
			}
			$row   = self::with_edge_bool_fields( $edge, $row );
			$row   = self::with_edge_default_field( $edge, $row );
			$out[] = $row;
		}
		if ( $dirty && $from_id > 0 ) {
			self::write_edges( $from_id, $out );
		}
		return $out;
	}

	/**
	 * RelationType key for a type term id (empty when unknown).
	 */
	private static function type_key_for_type_id( int $type_id ): string {
		if ( $type_id <= 0 ) {
			return '';
		}
		$type = get_term( $type_id );
		if ( $type instanceof \WP_Term ) {
			return strtolower( $type->name );
		}
		return '';
	}

	/**
	 * @param list<array<string, mixed>> $edges
	 * @return true|\WP_Error
	 */
	private static function write_edges( int $from_id, array $edges ) {
		if ( empty( $edges ) ) {
			delete_term_meta( $from_id, self::META_KEY );
			return true;
		}
		$normalized = array();
		$seen       = array();
		foreach ( $edges as $edge ) {
			if ( ! is_array( $edge ) ) {
				continue;
			}
			$type_id = (int) ( $edge['typeId'] ?? 0 );
			$to_id   = (int) ( $edge['toId'] ?? 0 );
			if ( $type_id <= 0 || $to_id <= 0 ) {
				continue;
			}
			$name = self::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
			/*
			 * Q123: named attribute edges may share the same To (e.g. two text fields).
			 * Unnamed edges still unique on typeId:toId.
			 */
			$key = '' !== $name
				? $type_id . ':' . $to_id . ':' . strtolower( $name )
				: $type_id . ':' . $to_id;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$id           = isset( $edge['id'] ) ? sanitize_key( (string) $edge['id'] ) : '';
			if ( '' === $id ) {
				$id = self::new_edge_id();
			}
			$type_key = ! empty( $edge['typeKey'] )
				? sanitize_key( (string) $edge['typeKey'] )
				: self::type_key_for_type_id( $type_id );
			$row      = array(
				'id'           => $id,
				'typeId'       => $type_id,
				'toId'         => $to_id,
				'multiplicity' => self::resolve_edge_multiplicity( $type_key, $edge['multiplicity'] ?? null ),
			);
			if ( '' !== $type_key ) {
				$row['typeKey'] = $type_key;
			}
			if ( '' !== $name ) {
				$row['name'] = $name;
			}
			$settings = self::normalize_settings_deltas(
				isset( $edge['settings'] ) && is_array( $edge['settings'] ) ? $edge['settings'] : null
			);
			if ( null !== $settings ) {
				$row['settings'] = $settings;
			}
			$row          = self::with_edge_bool_fields( $edge, $row );
			$row          = self::with_edge_default_field( $edge, $row );
			$normalized[] = $row;
		}
		if ( empty( $normalized ) ) {
			delete_term_meta( $from_id, self::META_KEY );
			Tree_Model::touch_modified( $from_id );
			return true;
		}
		$ok = Json_Meta::update_term_meta( $from_id, self::META_KEY, array_values( $normalized ) );
		if ( false === $ok ) {
			return new \WP_Error( 'wtt_relation_encode', __( 'Could not save relations.', 'wp-taxonomy-tree' ) );
		}
		Tree_Model::touch_modified( $from_id );
		return true;
	}

	/**
	 * @param array<string, mixed> $edge
	 * @return array<string, mixed>|null
	 */
	private static function hydrate_edge( string $taxonomy, array $edge, int $index ): ?array {
		$type_id = (int) ( $edge['typeId'] ?? 0 );
		$to_id   = (int) ( $edge['toId'] ?? 0 );
		$type    = $type_id > 0 ? get_term( $type_id, $taxonomy ) : null;
		$to      = $to_id > 0 ? get_term( $to_id, $taxonomy ) : null;
		if ( ! $type instanceof \WP_Term || ! $to instanceof \WP_Term ) {
			return null;
		}
		$type_key = ! empty( $edge['typeKey'] )
			? (string) $edge['typeKey']
			: strtolower( $type->name );
		$row      = array(
			'id'           => (string) ( $edge['id'] ?? '' ),
			'typeId'       => $type_id,
			'typeName'     => $type->name,
			'typeKey'      => $type_key,
			'toId'         => $to_id,
			'toName'       => $to->name,
			'multiplicity' => self::resolve_edge_multiplicity( $type_key, $edge['multiplicity'] ?? null ),
			'index'        => $index,
		);
		$name = self::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
		if ( '' !== $name ) {
			$row['name'] = $name;
		}
		$settings = self::normalize_settings_deltas(
			isset( $edge['settings'] ) && is_array( $edge['settings'] ) ? $edge['settings'] : null
		);
		if ( null !== $settings ) {
			$row['settings'] = $settings;
		}
		if ( self::is_calc_type_key( $type_key ) ) {
			$row['calcOp'] = self::calc_op_from_edge( array_merge( $edge, array( 'typeKey' => $type_key ) ) );
			$row['typeLabel'] = self::type_key_label( $type_key );
		}
		$row = self::with_edge_bool_fields( $edge, $row );
		return self::with_edge_default_field( $edge, $row );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function build_type_tree_node( string $taxonomy, \WP_Term $term ): ?array {
		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => (int) $term->term_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		$children = array();
		if ( is_array( $kids ) ) {
			foreach ( $kids as $kid ) {
				if ( $kid instanceof \WP_Term ) {
					$child = self::build_type_tree_node( $taxonomy, $kid );
					if ( $child ) {
						$children[] = $child;
					}
				}
			}
		}
		return array(
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'description' => Tree_Model::decode_term_description( (string) $term->description ),
			'parent'      => (int) $term->parent,
			'protected'   => self::is_protected_type_name( $term->name ),
			'children'    => $children,
			'hasChildren' => count( $children ) > 0,
		);
	}

	/**
	 * @param list<array{id:int,name:string,path:string,protected:bool}> $out
	 * @param list<string>                                                 $path
	 */
	private static function collect_type_options( string $taxonomy, int $parent_id, array &$out, array $path ): void {
		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
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
			$next_path = array_merge( $path, array( $kid->name ) );
			$protected = self::is_protected_type_name( $kid->name );
			$out[]     = array(
				'id'        => (int) $kid->term_id,
				'name'      => $kid->name,
				'label'     => self::type_key_label( $kid->name ),
				'path'      => implode( ' / ', $next_path ),
				'protected' => $protected,
			);
			self::collect_type_options( $taxonomy, (int) $kid->term_id, $out, $next_path );
		}
	}
}
