<?php
/**
 * Additive Relations between Nodes (Q74 / Q75 scaffold).
 *
 * Hierarchy child_of stays synthetic — reparent only (not Add relation).
 * has_type is managed via the Relations UI but persists as type_id meta (one SoT).
 * ref_scope is synthetic (meta); To target is editable in Relations (not via Add edge).
 * child_of To is never editable here — reparent only.
 * Stored edges support add / remove / reorder / change To via stable edge ids.
 * Identical From → RelationType → To edges are not allowed.
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

	/** Data-type binding (Relations UI → type_id meta; not stored in _wtt_relations). */
	public const TYPE_HAS_TYPE = 'has_type';

	/** Catalog-root binding for node_ref / node_embed (meta; Relations To editable). */
	public const TYPE_REF_SCOPE = 'ref_scope';

	/** Hierarchy parent (synthetic; To not editable in Relations — reparent only). Inheritance along this chain (Q66/Q86). */
	public const TYPE_CHILD_OF = 'child_of';

	/**
	 * Alternate RelationType names accepted when resolving a type key.
	 *
	 * @var array<string, list<string>>
	 */
	private const TYPE_NAME_ALIASES = array(
		'besteht_aus' => array( 'composition' ),
		'composition' => array( 'besteht_aus' ),
	);

	/** RelationType names that must not be created via Add relation (system / synthetic). */
	public const PROTECTED_TYPE_NAMES = array( 'child_of', 'ref_scope' );

	/**
	 * Definition cardinality on a Relation edge (Q78).
	 * Lower bound 0|1; upper bound 1|* (“bis enthalten” = unbounded).
	 */
	public const MULTIPLICITY_DEFAULT = '0..*';

	public const MULTIPLICITIES = array( '0..1', '1', '0..*', '1..*' );

	/**
	 * @return list<array{id:string,typeId:int,typeName:string,typeKey:string,toId:int,toName:string,multiplicity:string,index:int}>
	 */
	public static function list_outgoing( string $taxonomy, int $from_id ): array {
		$edges = self::read_edges( $from_id );
		$out   = array();
		foreach ( $edges as $index => $edge ) {
			$row = self::hydrate_edge( $taxonomy, $edge, $index );
			if ( null !== $row ) {
				$out[] = $row;
			}
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
	 * @return list<array{id:string,typeId:int,typeName:string,typeKey:string,fromId:int,fromName:string}>
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
				$type_id = (int) ( $edge['typeId'] ?? 0 );
				$type    = $type_id > 0 ? get_term( $type_id, $taxonomy ) : null;
				$type_name = $type instanceof \WP_Term ? $type->name : '';
				$out[]   = array(
					'id'           => (string) ( $edge['id'] ?? '' ),
					'typeId'       => $type_id,
					'typeName'     => $type_name,
					'typeKey'      => ! empty( $edge['typeKey'] )
						? (string) $edge['typeKey']
						: strtolower( $type_name ),
					'fromId'       => $from_id,
					'fromName'     => $term->name,
					'multiplicity' => self::resolve_edge_multiplicity(
						! empty( $edge['typeKey'] )
							? (string) $edge['typeKey']
							: strtolower( $type_name ),
						$edge['multiplicity'] ?? null
					),
				);
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
	 * Whether an identical From → Type → To edge already exists.
	 *
	 * @param string $exclude_edge_id Edge id to ignore (e.g. when changing type on that edge).
	 */
	public static function has_identical( int $from_id, int $type_id, int $to_id, string $exclude_edge_id = '' ): bool {
		if ( $from_id <= 0 || $type_id <= 0 || $to_id <= 0 ) {
			return false;
		}
		$exclude_edge_id = sanitize_key( $exclude_edge_id );
		foreach ( self::read_edges( $from_id ) as $edge ) {
			if (
				'' !== $exclude_edge_id
				&& sanitize_key( (string) ( $edge['id'] ?? '' ) ) === $exclude_edge_id
			) {
				continue;
			}
			if ( (int) ( $edge['typeId'] ?? 0 ) === $type_id && (int) ( $edge['toId'] ?? 0 ) === $to_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $multiplicity Definition cardinality (Q78); default 0..*.
	 * @return true|\WP_Error
	 */
	public static function add( string $taxonomy, int $from_id, int $type_id, int $to_id, string $multiplicity = self::MULTIPLICITY_DEFAULT ) {
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

		/* has_type: Relations UI writes type_id — do not store a parallel edge. */
		if ( self::is_has_type_name( $type->name ) ) {
			return self::bind_has_type( $taxonomy, $from_id, $to_id );
		}

		if ( self::has_identical( $from_id, $type_id, $to_id ) ) {
			return new \WP_Error(
				'wtt_duplicate_relation',
				__( 'This relation already exists (same From, Relation type, and To).', 'wp-taxonomy-tree' )
			);
		}

		$type_key = strtolower( $type->name );
		$edges    = self::read_edges( $from_id );
		$edges[]  = array(
			'id'           => self::new_edge_id(),
			'typeId'       => $type_id,
			'toId'         => $to_id,
			'typeKey'      => $type_key,
			'multiplicity' => self::resolve_edge_multiplicity( $type_key, $multiplicity ),
		);
		return self::write_edges( $from_id, $edges );
	}

	/**
	 * Bind or clear data type via has_type (single SoT: type_id meta).
	 * Hierarchy / root free assign is locked (Q88) — use Attributes for field types.
	 *
	 * @return true|\WP_Error
	 */
	public static function bind_has_type( string $taxonomy, int $from_id, int $to_id ) {
		if ( Node_Type::is_free_type_assignment_locked( $taxonomy, $from_id ) ) {
			return new \WP_Error(
				'wtt_type_locked',
				__( 'Free has_type is not editable here. Hierarchy type is the parent; root type is seed-managed.', 'wp-taxonomy-tree' )
			);
		}
		$result = Node_Type::set_type_id( $taxonomy, $from_id, $to_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $to_id > 0 && Node_Type::can_inherit_type( $taxonomy, $from_id ) ) {
			Node_Type::set_type_override( $taxonomy, $from_id, true );
		}
		return true;
	}

	public static function is_has_type_name( string $name ): bool {
		return self::TYPE_HAS_TYPE === strtolower( trim( $name ) );
	}

	/**
	 * Fixed multiplicity for a RelationType name, or null if free (Q78).
	 *
	 * child_of always exactly one parent (hierarchy invariant).
	 * has_type / ref_scope stay 0..1 (synthetic bindings).
	 */
	public static function fixed_multiplicity_for_type_name( string $name ): ?string {
		$key = strtolower( trim( $name ) );
		if ( self::TYPE_CHILD_OF === $key ) {
			return '1';
		}
		if ( self::TYPE_HAS_TYPE === $key || self::TYPE_REF_SCOPE === $key ) {
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

		/* has_type binding lives in type_id meta — clear when RelationType is has_type. */
		if ( $type_id > 0 ) {
			$type = get_term( $type_id, $taxonomy );
			if ( $type instanceof \WP_Term && self::is_has_type_name( $type->name ) ) {
				$current = Node_Type::get_type_id( $from_id );
				if ( $to_id <= 0 || $current === $to_id || $current <= 0 ) {
					return self::bind_has_type( $taxonomy, $from_id, 0 );
				}
				return true;
			}
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
		if ( self::is_has_type_name( $type->name ) ) {
			return new \WP_Error(
				'wtt_bad_relation_type',
				__( 'has_type is set via Add relation (target = data-type node), not by renaming an edge.', 'wp-taxonomy-tree' )
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
	 * - has_type: rebinds type_id meta
	 * - ref_scope: rebinds catalog-root meta
	 * - stored edges: updates toId on the edge
	 *
	 * @param string $type_key Optional type name when there is no edge id (has_type / ref_scope).
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
				if ( self::has_identical( $from_id, $edge_type_id, $new_to_id, $edge_id ) ) {
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
		if ( self::TYPE_HAS_TYPE === $type_key ) {
			return self::bind_has_type( $taxonomy, $from_id, $new_to_id );
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
		return $out;
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
	 * @return list<array{id:string,typeId:int,toId:int,typeKey?:string}>
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
		$out      = array();
		$dirty    = false;
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
	 * @param list<array{id?:string,typeId:int,toId:int,typeKey?:string,multiplicity?:string}> $edges
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
			$key = $type_id . ':' . $to_id;
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
			$normalized[] = $row;
		}
		if ( empty( $normalized ) ) {
			delete_term_meta( $from_id, self::META_KEY );
			Tree_Model::touch_modified( $from_id );
			return true;
		}
		update_term_meta( $from_id, self::META_KEY, wp_json_encode( array_values( $normalized ) ) );
		Tree_Model::touch_modified( $from_id );
		return true;
	}

	/**
	 * @param array{id?:string,typeId:int,toId:int,typeKey?:string,multiplicity?:string} $edge
	 * @return array{id:string,typeId:int,typeName:string,typeKey:string,toId:int,toName:string,multiplicity:string,index:int}|null
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
		return array(
			'id'           => (string) ( $edge['id'] ?? '' ),
			'typeId'       => $type_id,
			'typeName'     => $type->name,
			'typeKey'      => $type_key,
			'toId'         => $to_id,
			'toName'       => $to->name,
			'multiplicity' => self::resolve_edge_multiplicity( $type_key, $edge['multiplicity'] ?? null ),
			'index'        => $index,
		);
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
				'path'      => implode( ' / ', $next_path ),
				'protected' => $protected,
			);
			self::collect_type_options( $taxonomy, (int) $kid->term_id, $out, $next_path );
		}
	}
}
