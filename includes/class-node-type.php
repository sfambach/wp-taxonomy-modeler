<?php
/**
 * Node data-type assignment (type_id / parent-as-type over term meta).
 *
 * Hierarchy (Q88): non-root datatype = WP parent; root type is seed-managed.
 * Attribute / catalog field types: chooser = nodes (Q92).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists and validates data-type bindings on taxonomy terms.
 */
final class Node_Type {

	public const META_KEY = '_wtt_type_id';

	/** Slot / set-member fill rule (proto Node.config.required). */
	public const META_KEY_REQUIRED = '_wtt_required';

	/** Table setting: show Fußzeile (proto Node.config.footer.enabled). */
	public const META_KEY_HAS_FOOTER = '_wtt_has_footer';

	/** Fuss slot: aggregate op key (sum|avg|… Q57). SoT on the field node. */
	public const META_KEY_FOOTER_OP = '_wtt_footer_op';

	/** Set member separator in labels/display (default `/`). */
	public const META_KEY_SET_SEPARATOR = '_wtt_set_separator';

	/** When all set members share a type, join unit once in display (default on). */
	public const META_KEY_SET_JOIN_UNITS = '_wtt_set_join_units';

	/** Include child member names in set field labels, e.g. Abmessung (L/B/H) (default on). */
	public const META_KEY_SET_LABEL_CHILDREN = '_wtt_set_label_children';

	/** Media type (Q65): allow WP Media Library upload/pick (default on). */
	public const META_KEY_MEDIA_ALLOW_UPLOAD = '_wtt_media_allow_upload';

	/** Media type (Q65): allow external URL input (default off / opt-in). */
	public const META_KEY_MEDIA_ALLOW_URL = '_wtt_media_allow_url';

	/** JSON list of allowed MIME display kinds (Q65). Default empty = none enabled. */
	public const META_KEY_MEDIA_ALLOWED_KINDS = '_wtt_media_allowed_kinds';

	/**
	 * Date type mode: `date` (calendar day) or `datetime` (date + time).
	 * Store SoT for instance values is always a Unix timestamp (int as decimal string).
	 */
	public const META_KEY_DATE_MODE = '_wtt_date_mode';

	/**
	 * Legacy int number display format (arabic|roman|binary|octal|hex).
	 * Prefer META_KEY_PREFERRED_CONVERTER; still read as fallback.
	 */
	public const META_KEY_INT_DISPLAY_FORMAT = '_wtt_int_display_format';

	/** Preferred value converter id (Registry; e.g. arabic for int). */
	public const META_KEY_PREFERRED_CONVERTER = '_wtt_preferred_converter';

	/**
	 * Value validators (0..n). JSON list of { id, errorText, expression?, isDefault?, fixes? }.
	 */
	public const META_KEY_VALIDATORS = '_wtt_validators';

	/** Preferred Object View / admin preview layout for this node. */
	public const META_KEY_PREFERRED_RENDER = '_wtt_preferred_render';

	/** Allowed preferred render keys (match Object View layout). */
	public const PREFERRED_RENDER_KEYS = array( 'form', 'table', 'compact', 'compact-vertical', 'embed' );

	/** Fixed constant value: points at a Typen-branch Node (e.g. Einheit → Ohm). */
	public const META_KEY_FIXED_NODE = '_wtt_fixed_node_id';

	/**
	 * Catalog root for `node_embed` / `node_ref` (ref_scope).
	 * node_embed: direct children selectable + embed fields; node_ref: descendants, id only.
	 * Alias: historical type name `subtree` → node_embed.
	 */
	public const META_KEY_REF_SCOPE = '_wtt_ref_scope_id';

	/**
	 * Field cardinality for node_ref / node_embed picks (runtime cell value).
	 * Values: 0..1 | 1 | 0..* | 1..* — distinct from Relation-edge multiplicity (Q78).
	 */
	public const META_KEY_FIELD_MULTIPLICITY = '_wtt_field_multiplicity';

	/**
	 * Q73: allowed direct children of ref_scope for node_embed / node_ref.
	 * Empty list = all children allowed (default).
	 */
	public const META_KEY_ALLOWED_REF_IDS = '_wtt_allowed_ref_ids';

	/** Whether a fixed value is active (radio: off / on). */
	public const META_KEY_FIXED_ENABLED = '_wtt_fixed_enabled';

	/** Literal fixed value for simple types (int/double/text/…). */
	public const META_KEY_FIXED_LITERAL = '_wtt_fixed_literal';

	/**
	 * Node-level read-only lock (attribute slots + typed fields).
	 * Replaces Fixed-as-lock for “user cannot edit”; Default value stays separate.
	 */
	public const META_KEY_READONLY = '_wtt_readonly';

	/** Disabled children of a branch-type (e.g. deactivate k under Praefixe). */
	public const META_KEY_DISABLED_BRANCH = '_wtt_disabled_branch_ids';

	/**
	 * Q51 scaffold interim: allowed Präfix term IDs on a Basiseinheit unit node.
	 * Empty list = L1 = no prefixes allowed (base unit only).
	 */
	public const META_KEY_ALLOWED_PREFIX_IDS = '_wtt_allowed_prefix_ids';

	/**
	 * Q51: SI prefix scale relative to the unit’s prefix root (e.g. milli → 1e-3).
	 * Stored on Praefix catalog nodes.
	 */
	public const META_KEY_MULTIPLIKATOR = '_wtt_multiplikator';

	/**
	 * Factor from prefix-root symbol to SI base of this Basiseinheit unit.
	 * Usually 1 (Meter: root = m = SI). Kilogramm: prefixes attach to gram → 1e-3 (1 g = 0.001 kg).
	 * to_si = magnitude * prefix_multiplikator * prefix_root_to_si.
	 */
	public const META_KEY_PREFIX_ROOT_TO_SI = '_wtt_prefix_root_to_si';

	/**
	 * Protected catalog / template node (seeded system leaves, RelationTypes, …).
	 * Values: '1' | absent/other = false. Local boolean — no inherit.
	 * Catalog deletable lock (#5) keys off this flag; editable in Development mode only.
	 */
	public const META_KEY_IS_TEMPLATE = '_wtt_is_template';

	/**
	 * Whether the term may be deleted in the admin tree.
	 * Values: '1' (yes) | '0' (no). Missing meta = deletable (user-created default).
	 * Seeded standard / complex catalog types and system RelationTypes use '0'.
	 */
	public const META_KEY_DELETABLE = '_wtt_deletable';

	/**
	 * Q76: When true, this node's effective type is inherited by descendants
	 * that do not override.
	 */
	public const META_KEY_TYPE_INHERITING = '_wtt_type_inheriting';

	/**
	 * Q76: When true, this node uses its own type_id instead of an ancestor's
	 * inheriting type.
	 */
	public const META_KEY_TYPE_OVERRIDE = '_wtt_type_override';

	/**
	 * Ordered property schema on a data-type node (prototype: table → Kopf/Zeile/Fuss).
	 * JSON list of { id, key, name, valueType, required }.
	 * valueType `subnode` = must bind a direct child of the instance.
	 */
	public const META_KEY_TYPE_PROPS = '_wtt_type_props';

	/**
	 * Instance bindings for type props: { propId: childTermId }.
	 */
	public const META_KEY_PROP_BINDINGS = '_wtt_prop_bindings';

	/**
	 * Default type props for Collection `table` (editable after seed).
	 *
	 * @return list<array{id:string,key:string,name:string,valueType:string,required:bool}>
	 */
	public static function default_table_type_props(): array {
		return array(
			array(
				'id'        => 'kopf',
				'key'       => 'kopf',
				'name'      => 'Kopf',
				'valueType' => 'subnode',
				'required'  => false,
			),
			array(
				'id'        => 'zeile',
				'key'       => 'zeile',
				'name'      => 'Zeile',
				'valueType' => 'subnode',
				'required'  => true,
			),
			array(
				'id'        => 'fuss',
				'key'       => 'fuss',
				'name'      => 'Fuss',
				'valueType' => 'subnode',
				'required'  => false,
			),
		);
	}

	/**
	 * @param mixed $raw Raw JSON / list.
	 * @return list<array{id:string,key:string,name:string,valueType:string,required:bool}>
	 */
	public static function normalize_type_props( $raw ): array {
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out  = array();
		$seen = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
			if ( '' === $id ) {
				$id = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
			}
			if ( '' === $id ) {
				$id = 'prop_' . substr( md5( wp_json_encode( $row ) . wp_rand() ), 0, 8 );
			}
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$key = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : $id;
			if ( '' === $key ) {
				$key = $id;
			}
			$name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : $key;
			if ( '' === $name ) {
				$name = $key;
			}
			$value_type_id = isset( $row['valueTypeId'] ) ? (int) $row['valueTypeId'] : 0;
			$value_type    = isset( $row['valueType'] ) ? sanitize_key( (string) $row['valueType'] ) : '';
			/* Prefer catalog type id when present. */
			if ( $value_type_id > 0 ) {
				$type_term = get_term( $value_type_id );
				if ( $type_term instanceof \WP_Term ) {
					$value_type = sanitize_key( (string) $type_term->name );
				}
			}
			if ( '' === $value_type ) {
				$value_type = 'subnode';
			}
			/* Legacy alias: node → subnode (direct child). */
			if ( 'node' === $value_type ) {
				$value_type = 'subnode';
			}
			if ( array_key_exists( 'required', $row ) ) {
				$required = ! empty( $row['required'] );
			} else {
				/* Legacy rows without flag: Zeile required, others optional. */
				$required = ( 'zeile' === $key );
			}
			$seen[ $id ] = true;
			$entry       = array(
				'id'        => $id,
				'key'       => $key,
				'name'      => $name,
				'valueType' => $value_type,
				'required'  => $required,
			);
			if ( $value_type_id > 0 ) {
				$entry['valueTypeId'] = $value_type_id;
			}
			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * @return list<array{id:string,key:string,name:string,valueType:string,valueTypeId?:int,valueTypeName?:string,required:bool}>
	 */
	public static function get_type_props( int $term_id ): array {
		if ( $term_id <= 0 ) {
			return array();
		}
		$term = get_term( $term_id );
		if ( ! metadata_exists( 'term', $term_id, self::META_KEY_TYPE_PROPS ) ) {
			if ( $term instanceof \WP_Term && 'table' === strtolower( $term->name ) ) {
				$props = self::default_table_type_props();
				return self::enrich_type_props_with_type_nodes(
					$term instanceof \WP_Term ? (string) $term->taxonomy : '',
					$term_id,
					$props
				);
			}
			return array();
		}
		$props = self::normalize_type_props( get_term_meta( $term_id, self::META_KEY_TYPE_PROPS, true ) );
		$taxonomy = $term instanceof \WP_Term ? (string) $term->taxonomy : '';
		return self::enrich_type_props_with_type_nodes( $taxonomy, $term_id, $props );
	}

	/**
	 * Resolve valueType name → catalog type node (e.g. subnode under Typen/Complex).
	 *
	 * @param list<array<string,mixed>> $props
	 * @return list<array<string,mixed>>
	 */
	public static function enrich_type_props_with_type_nodes( string $taxonomy, int $context_term_id, array $props ): array {
		if ( '' === $taxonomy || empty( $props ) ) {
			return $props;
		}
		$out = array();
		foreach ( $props as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$type_id   = isset( $prop['valueTypeId'] ) ? (int) $prop['valueTypeId'] : 0;
			$type_name = isset( $prop['valueType'] ) ? sanitize_key( (string) $prop['valueType'] ) : '';
			if ( 'node' === $type_name ) {
				$type_name = 'subnode';
			}
			if ( $type_id <= 0 && '' !== $type_name ) {
				$type_id = self::find_type_by_name( $taxonomy, $context_term_id, $type_name );
			}
			if ( $type_id <= 0 && '' === $type_name ) {
				$type_name = 'subnode';
				$type_id   = self::find_type_by_name( $taxonomy, $context_term_id, 'subnode' );
			}
			if ( $type_id > 0 ) {
				$type_term = get_term( $type_id, $taxonomy );
				if ( $type_term instanceof \WP_Term ) {
					$type_name                 = sanitize_key( (string) $type_term->name );
					$prop['valueTypeName']     = $type_term->name;
					$prop['valueTypeId']       = $type_id;
					$prop['valueType']         = '' !== $type_name ? $type_name : (string) $type_term->slug;
				}
			} else {
				$prop['valueType']     = '' !== $type_name ? $type_name : 'subnode';
				$prop['valueTypeId']   = 0;
				$prop['valueTypeName'] = $prop['valueType'];
			}
			$out[] = $prop;
		}
		return $out;
	}

	/**
	 * Props defined on the assigned data type (or on this node when it is the table catalog type).
	 *
	 * @return list<array{id:string,key:string,name:string,valueType:string,valueTypeId?:int,valueTypeName?:string,required:bool}>
	 */
	public static function get_effective_type_props( string $taxonomy, int $term_id ): array {
		$type_id = self::get_effective_type_id( $taxonomy, $term_id );
		if ( $type_id > 0 ) {
			return self::get_type_props( $type_id );
		}
		$term = get_term( $term_id, $taxonomy );
		if ( $term instanceof \WP_Term && 'table' === strtolower( $term->name ) ) {
			return self::get_type_props( $term_id );
		}
		return array();
	}

	/**
	 * @param list<array<string,mixed>> $props
	 * @return true|\WP_Error
	 */
	public static function set_type_props( string $taxonomy, int $term_id, array $props ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		$clean = self::normalize_type_props( $props );
		update_term_meta( $term_id, self::META_KEY_TYPE_PROPS, wp_json_encode( $clean ) );
		return true;
	}

	/**
	 * Ensure Collection `table` has the default Kopf/Zeile/Fuss prop list (idempotent write).
	 * Also clears illegal `type_inheriting` on table-typed nodes.
	 */
	public static function ensure_table_type_props( string $taxonomy ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		self::clear_table_type_inheriting( $taxonomy );
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => 'table',
				'hide_empty' => false,
				'number'     => 5,
			)
		);
		if ( ! is_array( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			if ( metadata_exists( 'term', (int) $term->term_id, self::META_KEY_TYPE_PROPS ) ) {
				continue;
			}
			self::set_type_props( $taxonomy, (int) $term->term_id, self::default_table_type_props() );
		}
	}

	/**
	 * Drop `type_inheriting` on every node whose effective type is `table`.
	 */
	public static function clear_table_type_inheriting( string $taxonomy ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
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
			$term_id = (int) $term->term_id;
			if ( ! self::is_type_inheriting( $term_id ) ) {
				continue;
			}
			$own = self::get_type_id( $term_id );
			if ( $own > 0 && self::type_id_is_named( $taxonomy, $own, 'table' ) ) {
				delete_term_meta( $term_id, self::META_KEY_TYPE_INHERITING );
			}
		}
	}

	/**
	 * @return array<string, int> propId => child term id
	 */
	public static function get_prop_bindings( int $term_id ): array {
		$raw = get_term_meta( $term_id, self::META_KEY_PROP_BINDINGS, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $prop_id => $child_id ) {
			$key = sanitize_key( (string) $prop_id );
			$id  = (int) $child_id;
			if ( '' === $key || $id <= 0 ) {
				continue;
			}
			$out[ $key ] = $id;
		}
		return $out;
	}

	/**
	 * @param array<string, int|string> $bindings
	 * @return true|\WP_Error
	 */
	public static function set_prop_bindings( string $taxonomy, int $term_id, array $bindings ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		$allowed_children = array();
		$children         = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 0,
			)
		);
		if ( is_array( $children ) ) {
			foreach ( $children as $cid ) {
				$allowed_children[ (int) $cid ] = true;
			}
		}

		/*
		 * Q87: composition / attribute members may live at parent=0 after detach.
		 * Table band bindings (zeile/kopf/fuss) must still accept those targets.
		 */
		foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $term_id, Relation::TYPE_COMPOSITION ) as $edge ) {
			$to_id = (int) ( $edge['toId'] ?? 0 );
			if ( $to_id > 0 ) {
				$allowed_children[ $to_id ] = true;
			}
		}
		foreach ( array( Relation::TYPE_AGGREGATION, 'aggregation' ) as $agg_key ) {
			foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $term_id, $agg_key ) as $edge ) {
				$to_id = (int) ( $edge['toId'] ?? 0 );
				if ( $to_id > 0 ) {
					$allowed_children[ $to_id ] = true;
				}
			}
		}

		$clean = array();
		foreach ( $bindings as $prop_id => $child_id ) {
			$key = sanitize_key( (string) $prop_id );
			$id  = (int) $child_id;
			if ( '' === $key || $id <= 0 ) {
				continue;
			}
			if ( ! isset( $allowed_children[ $id ] ) ) {
				continue;
			}
			$clean[ $key ] = $id;
		}

		if ( empty( $clean ) ) {
			delete_term_meta( $term_id, self::META_KEY_PROP_BINDINGS );
		} else {
			update_term_meta( $term_id, self::META_KEY_PROP_BINDINGS, wp_json_encode( $clean ) );
		}
		return true;
	}

	/**
	 * Direct children for table prop node pickers.
	 *
	 * @return list<array{id:int,name:string}>
	 */
	public static function get_direct_child_options( string $taxonomy, int $parent_id ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$sorted = array();
		foreach ( $terms as $child ) {
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			if ( class_exists( Trash::class ) && Trash::is_trashed( (int) $child->term_id ) ) {
				continue;
			}
			$sorted[] = $child;
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

		$out = array();
		foreach ( $sorted as $child ) {
			$out[] = array(
				'id'   => (int) $child->term_id,
				'name' => $child->name,
			);
		}
		return $out;
	}

	/**
	 * Closed option leaves for an enum-typed field (Q52).
	 *
	 * Preferred shape: field → Option (column) → Empfänger, Lieferant, …
	 * Fallback: options as direct children of the field (common scaffold mistake).
	 * When the field’s type is a concrete enum catalog node (e.g. Bauart), options
	 * are read from that type node instead.
	 *
	 * @return list<array{id:int,name:string}>
	 */
	public static function get_enum_options( string $taxonomy, int $field_id ): array {
		if ( $field_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$options_root = 0;
		$type_id      = self::get_effective_type_id( $taxonomy, $field_id );

		if ( $type_id > 0 && self::type_id_is_named( $taxonomy, $type_id, 'enum' ) ) {
			/* Field typed as catalog `enum` — options hang under the field. */
			$options_root = $field_id;
		} elseif ( $type_id > 0 && self::is_concrete_enum_type( $taxonomy, $type_id ) ) {
			/* Field typed as Bauart-style concrete enum — options under the type. */
			$options_root = $type_id;
		} elseif ( self::is_concrete_enum_type( $taxonomy, $field_id ) ) {
			/* The selected node itself is a concrete enum type. */
			$options_root = $field_id;
		}

		if ( $options_root <= 0 ) {
			return array();
		}

		foreach ( self::enum_option_column_names() as $column_name ) {
			$column_id = self::find_direct_child_by_name( $taxonomy, $options_root, $column_name );
			if ( $column_id > 0 ) {
				return self::get_direct_child_options( $taxonomy, $column_id );
			}
		}

		/* Fallback: treat direct children as closed options. */
		return self::get_direct_child_options( $taxonomy, $options_root );
	}

	/**
	 * Preferred enum column names (Q52): Option | Spalte | Column | Wert.
	 *
	 * @return list<string>
	 */
	public static function enum_option_column_names(): array {
		return array( 'Option', 'Spalte', 'Column', 'Wert' );
	}

	/**
	 * Whether $name is the structural enum option column (not a closed value).
	 */
	public static function is_enum_option_column_name( string $name ): bool {
		$key = strtolower( trim( $name ) );
		foreach ( self::enum_option_column_names() as $col ) {
			if ( $key === strtolower( $col ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when $term_id is a concrete enum definition (specialization under catalog `enum`),
	 * not the catalog leaf, not the Option column, and not an option value leaf.
	 */
	public static function is_concrete_enum_type( string $taxonomy, int $term_id ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return false;
		}
		if ( 0 === strcasecmp( $term->name, 'enum' ) ) {
			return false;
		}
		if ( self::is_enum_option_column_name( $term->name ) ) {
			return false;
		}
		$parent_id = (int) $term->parent;
		if ( $parent_id <= 0 ) {
			return false;
		}
		$parent = get_term( $parent_id, $taxonomy );
		if ( ! $parent instanceof \WP_Term ) {
			return false;
		}
		if ( self::is_enum_option_column_name( $parent->name ) ) {
			/* Closed option leaf under Option / Spalte / … */
			return false;
		}
		if ( 0 === strcasecmp( $parent->name, 'enum' ) ) {
			return true;
		}
		/*
		 * Nested folder under a concrete enum (e.g. enum → Group → Bauart):
		 * parent must itself be a concrete enum, not merely typed as enum (Option).
		 */
		if ( ! self::has_type_named( $taxonomy, $parent_id, 'enum' ) ) {
			return false;
		}
		return self::is_concrete_enum_type( $taxonomy, $parent_id );
	}

	/**
	 * Whether this field should expose enumOptions in API payloads.
	 */
	public static function is_enum_typed_field( string $taxonomy, int $term_id ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		$type_id = self::get_effective_type_id( $taxonomy, $term_id );
		if ( $type_id > 0 && self::type_id_is_named( $taxonomy, $type_id, 'enum' ) ) {
			return true;
		}
		if ( $type_id > 0 && self::is_concrete_enum_type( $taxonomy, $type_id ) ) {
			return true;
		}
		return self::is_concrete_enum_type( $taxonomy, $term_id );
	}

	/**
	 * Resolve the Option/Spalte/Column/Wert child under a concrete enum (0 if missing).
	 */
	public static function find_enum_option_column_id( string $taxonomy, int $enum_id ): int {
		if ( $enum_id <= 0 ) {
			return 0;
		}
		foreach ( self::enum_option_column_names() as $column_name ) {
			$column_id = self::find_direct_child_by_name( $taxonomy, $enum_id, $column_name );
			if ( $column_id > 0 ) {
				return $column_id;
			}
		}
		return 0;
	}

	/**
	 * Ensure the preferred Option column exists under a concrete enum; migrate loose option leaves.
	 *
	 * @return int|\WP_Error Column term id.
	 */
	public static function ensure_enum_option_column( string $taxonomy, int $enum_id ) {
		if ( ! self::is_concrete_enum_type( $taxonomy, $enum_id ) ) {
			return new \WP_Error(
				'wtt_not_enum',
				__( 'Festwerte can only be defined on a concrete enum (child of catalog enum).', 'wp-taxonomy-tree' )
			);
		}

		$column_id = self::find_enum_option_column_id( $taxonomy, $enum_id );
		if ( $column_id <= 0 ) {
			$created = Tree_Model::create_term( $taxonomy, 'Option', $enum_id );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$column_id = (int) ( $created['id'] ?? 0 );
			if ( $column_id <= 0 ) {
				return new \WP_Error( 'wtt_enum_column', __( 'Could not create Option column.', 'wp-taxonomy-tree' ) );
			}
		}

		/* Move former direct option leaves (fallback shape) under the column. */
		foreach ( self::get_direct_child_terms( $taxonomy, $enum_id ) as $child ) {
			$cid = (int) $child->term_id;
			if ( $cid === $column_id || self::is_enum_option_column_name( $child->name ) ) {
				continue;
			}
			/* Skip nested concrete-enum folders. */
			if ( self::is_concrete_enum_type( $taxonomy, $cid ) ) {
				continue;
			}
			$result = wp_update_term(
				$cid,
				$taxonomy,
				array(
					'parent' => $column_id,
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $column_id;
	}

	/**
	 * Replace closed option leaves under a concrete enum (Q52 Option column).
	 *
	 * Values are term names under Option; order is stored via `_wtt_position`.
	 *
	 * @param list<string>|mixed $values Ordered value labels.
	 * @return list<array{id:int,name:string}>|\WP_Error
	 */
	public static function set_enum_values( string $taxonomy, int $enum_id, $values ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Taxonomy not found.', 'wp-taxonomy-tree' ) );
		}
		if ( ! self::is_concrete_enum_type( $taxonomy, $enum_id ) ) {
			return new \WP_Error(
				'wtt_not_enum',
				__( 'Festwerte can only be defined on a concrete enum (child of catalog enum).', 'wp-taxonomy-tree' )
			);
		}

		$normalized = array();
		$seen       = array();
		if ( is_string( $values ) ) {
			$decoded = json_decode( $values, true );
			$values  = is_array( $decoded ) ? $decoded : array( $values );
		}
		if ( ! is_array( $values ) ) {
			$values = array();
		}
		foreach ( $values as $raw ) {
			if ( ! is_scalar( $raw ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) $raw );
			$name = trim( $name );
			if ( '' === $name ) {
				continue;
			}
			if ( self::is_enum_option_column_name( $name ) || 0 === strcasecmp( $name, 'enum' ) ) {
				return new \WP_Error(
					'wtt_bad_enum_value',
					__( 'That name is reserved for the enum structure.', 'wp-taxonomy-tree' )
				);
			}
			$key = strtolower( $name );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$normalized[] = $name;
		}

		$column_id = self::ensure_enum_option_column( $taxonomy, $enum_id );
		if ( is_wp_error( $column_id ) ) {
			return $column_id;
		}

		$existing = self::get_direct_child_terms( $taxonomy, $column_id );
		$by_key   = array();
		foreach ( $existing as $child ) {
			$by_key[ strtolower( $child->name ) ] = $child;
		}

		$kept_ids = array();
		$pos      = 0;
		foreach ( $normalized as $name ) {
			$key = strtolower( $name );
			if ( isset( $by_key[ $key ] ) ) {
				$term = $by_key[ $key ];
				$tid  = (int) $term->term_id;
				if ( $term->name !== $name ) {
					$renamed = wp_update_term(
						$tid,
						$taxonomy,
						array(
							'name' => $name,
						)
					);
					if ( is_wp_error( $renamed ) ) {
						return $renamed;
					}
				}
				Tree_Model::set_position( $tid, $pos );
				Tree_Model::touch_modified( $tid );
				$kept_ids[ $tid ] = true;
				unset( $by_key[ $key ] );
			} else {
				$created = Tree_Model::create_term( $taxonomy, $name, $column_id );
				if ( is_wp_error( $created ) ) {
					return $created;
				}
				$tid = (int) ( $created['id'] ?? 0 );
				if ( $tid <= 0 ) {
					return new \WP_Error( 'wtt_enum_value', __( 'Could not create enum value.', 'wp-taxonomy-tree' ) );
				}
				Tree_Model::set_position( $tid, $pos );
				$kept_ids[ $tid ] = true;
			}
			++$pos;
		}

		foreach ( $existing as $child ) {
			$tid = (int) $child->term_id;
			if ( isset( $kept_ids[ $tid ] ) ) {
				continue;
			}
			/* Permanently remove unused option leaves (Festwert labels, not domain folders). */
			$grand = self::get_direct_child_terms( $taxonomy, $tid );
			if ( ! empty( $grand ) ) {
				return new \WP_Error(
					'wtt_enum_value_busy',
					sprintf(
						/* translators: %s: option leaf name */
						__( 'Cannot remove “%s” because it has child nodes.', 'wp-taxonomy-tree' ),
						$child->name
					)
				);
			}
			$deleted = wp_delete_term( $tid, $taxonomy );
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
		}

		Tree_Model::touch_modified( $enum_id );

		return self::get_enum_options( $taxonomy, $enum_id );
	}

	/**
	 * True when this term is the Collection `table` datatype catalog node.
	 */
	public static function is_table_type_catalog( string $taxonomy, int $term_id ): bool {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return false;
		}
		return 'table' === strtolower( $term->name );
	}
	/**
	 * @return array{id:int,name:string,path:string}|null
	 */
	public static function get_assignment( string $taxonomy, int $term_id ): ?array {
		$type_id = self::get_effective_type_id( $taxonomy, $term_id );
		if ( $type_id <= 0 ) {
			return null;
		}

		$type = get_term( $type_id, $taxonomy );
		if ( ! $type instanceof \WP_Term ) {
			return null;
		}

		return array(
			'id'   => (int) $type->term_id,
			'name' => $type->name,
			'path' => self::term_path_from_typen( $taxonomy, (int) $type->term_id ),
			'shortDescription' => Tree_Model::get_short_description( (int) $type->term_id ),
		);
	}

	/**
	 * Stored own type_id (may be unused when inheriting without override).
	 */
	public static function get_type_id( int $term_id ): int {
		$value = get_term_meta( $term_id, self::META_KEY, true );
		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		return max( 0, (int) $value );
	}

	/**
	 * Effective type for behavior/display.
	 * Q88: hierarchy children derive datatype from WP parent (SoT = hierarchy).
	 * Attribute members / roots: own type_id, with Q76 catalog inherit+override interim.
	 */
	public static function get_effective_type_id( string $taxonomy, int $term_id ): int {
		if ( $term_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}
		/* Q88: datatype = father — derive from parent; one source of truth. */
		if ( self::is_hierarchy_datatype_subject( $taxonomy, $term_id ) ) {
			$term = get_term( $term_id, $taxonomy );
			return ( $term instanceof \WP_Term ) ? max( 0, (int) $term->parent ) : 0;
		}
		if ( self::is_type_override( $term_id ) ) {
			return self::get_type_id( $term_id );
		}

		$inherited = self::find_inherited_type_id( $taxonomy, $term_id );
		if ( $inherited > 0 ) {
			return $inherited;
		}

		return self::get_type_id( $term_id );
	}

	/**
	 * True when an ancestor offers an inheriting type (for Override UI).
	 */
	public static function can_inherit_type( string $taxonomy, int $term_id ): bool {
		return self::find_inherited_type_id( $taxonomy, $term_id ) > 0;
	}

	/**
	 * Walk parents: first ancestor with type_inheriting contributes its
	 * effective type (which may itself be inherited further up).
	 * Skips ancestors whose effective type is `table` (structural container only).
	 */
	public static function find_inherited_type_id( string $taxonomy, int $term_id ): int {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return 0;
		}

		$guard     = 0;
		$parent_id = (int) $term->parent;
		while ( $parent_id > 0 && $guard < 64 ) {
			++$guard;
			if ( self::is_type_inheriting( $parent_id ) ) {
				$candidate = self::get_effective_type_id( $taxonomy, $parent_id );
				if ( $candidate > 0 && ! self::type_id_is_named( $taxonomy, $candidate, 'table' ) ) {
					return $candidate;
				}
				/* Table + inheriting: do not push `table` onto bands/fields; keep walking. */
			}
			$parent = get_term( $parent_id, $taxonomy );
			if ( ! $parent instanceof \WP_Term ) {
				break;
			}
			$parent_id = (int) $parent->parent;
		}

		return 0;
	}

	/**
	 * Whether a type term id has the given datatype name (no inheritance walk).
	 */
	public static function type_id_is_named( string $taxonomy, int $type_id, string $type_name ): bool {
		if ( $type_id <= 0 || '' === $type_name || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		$type = get_term( $type_id, $taxonomy );
		if ( ! $type instanceof \WP_Term ) {
			return false;
		}
		return strtolower( (string) $type->name ) === strtolower( $type_name );
	}

	/**
	 * True when this node’s own (or effective) type is Collection `table`.
	 */
	public static function node_type_is_table( string $taxonomy, int $term_id ): bool {
		$own = self::get_type_id( $term_id );
		if ( $own > 0 && self::type_id_is_named( $taxonomy, $own, 'table' ) ) {
			return true;
		}
		$eff = self::get_effective_type_id( $taxonomy, $term_id );
		return $eff > 0 && self::type_id_is_named( $taxonomy, $eff, 'table' );
	}

	/**
	 * Q88: Hierarchy node whose datatype is the father (not an attribute member).
	 * Root is excluded (parent=0). Trash excluded.
	 */
	public static function is_hierarchy_datatype_subject( string $taxonomy, int $term_id ): bool {
		if ( $term_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		if ( class_exists( Trash::class ) && ( Trash::is_trash_node( $term_id ) || Trash::is_trashed( $term_id ) ) ) {
			return false;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return false;
		}
		/* Attribute slots keep their own catalog field type (Q87) — never hierarchy datatype. */
		if ( class_exists( Attribute::class ) && Attribute::is_slot( $term_id ) ) {
			return false;
		}
		$parent_id = (int) $term->parent;
		if ( $parent_id <= 0 ) {
			return false;
		}
		if ( class_exists( Trash::class ) && Trash::is_trash_node( $parent_id ) ) {
			return false;
		}
		/* Legacy: edge-linked members under a host (pre-slot parent=0) still excluded. */
		if ( Attribute::is_own_member( $taxonomy, $parent_id, $term_id ) ) {
			return false;
		}
		return true;
	}

	/**
	 * True when the term is a taxonomy root (WP parent = 0).
	 */
	public static function is_root_level_term( string $taxonomy, int $term_id ): bool {
		if ( $term_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		$term = get_term( $term_id, $taxonomy );
		return $term instanceof \WP_Term && 0 === (int) $term->parent;
	}

	/**
	 * Product model: free set_type assign is not editable in admin.
	 * Hierarchy children → father (Q88). Root → seed-only type_id (no admin picker).
	 * Attribute slot field types remain freely assignable (slots are parent=0 but
	 * are not taxonomy roots — Q87 / Q92; do not confuse with hierarchy set_type).
	 */
	public static function is_free_type_assignment_locked( string $taxonomy, int $term_id ): bool {
		/* Attribute slots live at WP parent=0; still editable field types. */
		if ( class_exists( Attribute::class ) && Attribute::is_slot( $term_id ) ) {
			return false;
		}
		if ( self::is_hierarchy_datatype_subject( $taxonomy, $term_id ) ) {
			return true;
		}
		if ( class_exists( Trash::class ) && ( Trash::is_trash_node( $term_id ) || Trash::is_trashed( $term_id ) ) ) {
			return false;
		}
		return self::is_root_level_term( $taxonomy, $term_id );
	}

	/**
	 * Q88: True when this hierarchy child’s datatype is its parent (derived or stored).
	 * Attribute members are excluded — they keep an own catalog type.
	 */
	public static function is_typed_as_parent( string $taxonomy, int $term_id ): bool {
		return self::is_hierarchy_datatype_subject( $taxonomy, $term_id );
	}

	/**
	 * Q88: Persist datatype of a hierarchy child as the parent.
	 * Does not promote parent via flags (parent-as-type needs no flag).
	 * Attribute members excluded. Does not copy type presets from the parent.
	 *
	 * @return true|\WP_Error
	 */
	public static function apply_parent_as_type( string $taxonomy, int $child_id ) {
		if ( ! taxonomy_exists( $taxonomy ) || $child_id <= 0 ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		if ( ! self::is_hierarchy_datatype_subject( $taxonomy, $child_id ) ) {
			return true;
		}
		$child = get_term( $child_id, $taxonomy );
		if ( ! $child instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		$parent_id = (int) $child->parent;

		return self::assign_hierarchy_parent_type_id( $taxonomy, $child_id, $parent_id );
	}

	/**
	 * Write type_id = parent without type presets (hierarchy class, not field type).
	 *
	 * @return true|\WP_Error
	 */
	private static function assign_hierarchy_parent_type_id( string $taxonomy, int $child_id, int $parent_id ) {
		if ( $child_id <= 0 || $parent_id <= 0 || $child_id === $parent_id ) {
			return true;
		}
		$current = self::get_type_id( $child_id );
		if ( $current !== $parent_id ) {
			update_term_meta( $child_id, self::META_KEY, $parent_id );
			delete_term_meta( $child_id, self::META_KEY_DISABLED_BRANCH );
		}
		/* Own type_id = parent must win over Q76 inheriting ancestors when meta is read raw. */
		self::set_type_override( $taxonomy, $child_id, true );
		return true;
	}

	/**
	 * Sync hierarchy children’s datatype to this parent (Q88).
	 * Parent-as-type needs no catalog flag.
	 *
	 * @return true|\WP_Error
	 */
	public static function promote_class_datatype( string $taxonomy, int $host_id ) {
		if ( ! taxonomy_exists( $taxonomy ) || $host_id <= 0 ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$host = get_term( $host_id, $taxonomy );
		if ( ! $host instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		return self::sync_children_parent_as_type( $taxonomy, $host_id );
	}

	/**
	 * Set type_id = parent for all non-attribute hierarchy children.
	 *
	 * @return true|\WP_Error
	 */
	public static function sync_children_parent_as_type( string $taxonomy, int $parent_id ) {
		if ( ! taxonomy_exists( $taxonomy ) || $parent_id <= 0 ) {
			return true;
		}
		$kids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $kids ) ) {
			return true;
		}
		foreach ( $kids as $kid ) {
			if ( ! $kid instanceof \WP_Term ) {
				continue;
			}
			$result = self::apply_parent_as_type( $taxonomy, (int) $kid->term_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/**
	 * Q88 repair: walk taxonomy and set hierarchy type_id = WP parent (skip attrs / trash).
	 * Root typing (Knoten) is left to Case_Data / Demo_Data ensure_root_typed_knoten.
	 *
	 * @return array{updated:int,skipped:int,errors:int}
	 */
	public static function ensure_hierarchy_datatype_inheritance( string $taxonomy ): array {
		$stats = array(
			'updated' => 0,
			'skipped' => 0,
			'errors'  => 0,
		);
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $stats;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $terms ) ) {
			return $stats;
		}
		$list = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$list[] = $term;
		}
		usort(
			$list,
			static function ( \WP_Term $a, \WP_Term $b ) use ( $taxonomy ): int {
				$da = count( get_ancestors( (int) $a->term_id, $taxonomy, 'taxonomy' ) );
				$db = count( get_ancestors( (int) $b->term_id, $taxonomy, 'taxonomy' ) );
				return $da <=> $db;
			}
		);
		foreach ( $list as $term ) {
			$tid = (int) $term->term_id;
			if ( ! self::is_hierarchy_datatype_subject( $taxonomy, $tid ) ) {
				++$stats['skipped'];
				continue;
			}
			$before = self::get_type_id( $tid );
			$result = self::apply_parent_as_type( $taxonomy, $tid );
			if ( is_wp_error( $result ) ) {
				++$stats['errors'];
				continue;
			}
			$after = self::get_type_id( $tid );
			if ( $after === (int) $term->parent && $before !== $after ) {
				++$stats['updated'];
			} else {
				++$stats['skipped'];
			}
		}
		return $stats;
	}

	public static function is_type_inheriting( int $term_id ): bool {
		return (string) get_term_meta( $term_id, self::META_KEY_TYPE_INHERITING, true ) === '1';
	}

	public static function is_type_override( int $term_id ): bool {
		return (string) get_term_meta( $term_id, self::META_KEY_TYPE_OVERRIDE, true ) === '1';
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_type_inheriting( string $taxonomy, int $term_id, bool $inheriting ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		/*
		 * Q76: `table` is a structural container (Kopf/Zeile/Fuss). Marking it
		 * inheriting would make every band/field look like a table — refuse.
		 */
		if ( $inheriting && self::node_type_is_table( $taxonomy, $term_id ) ) {
			delete_term_meta( $term_id, self::META_KEY_TYPE_INHERITING );
			return true;
		}
		if ( $inheriting ) {
			update_term_meta( $term_id, self::META_KEY_TYPE_INHERITING, '1' );
		} else {
			delete_term_meta( $term_id, self::META_KEY_TYPE_INHERITING );
		}
		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_type_override( string $taxonomy, int $term_id, bool $override ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		if ( $override ) {
			update_term_meta( $term_id, self::META_KEY_TYPE_OVERRIDE, '1' );
		} else {
			delete_term_meta( $term_id, self::META_KEY_TYPE_OVERRIDE );
		}
		return true;
	}

	/**
	 * Copy type-node settings onto a slot when the type is assigned (Q71 snapshot presets).
	 * Does not copy type_id / identity fields.
	 */
	public static function apply_type_presets( string $taxonomy, int $slot_id, int $type_id ): void {
		if ( $slot_id <= 0 || $type_id <= 0 || $slot_id === $type_id ) {
			return;
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$keys = array(
			self::META_KEY_REQUIRED,
			self::META_KEY_HAS_FOOTER,
			self::META_KEY_FOOTER_OP,
			self::META_KEY_SET_SEPARATOR,
			self::META_KEY_SET_JOIN_UNITS,
			self::META_KEY_SET_LABEL_CHILDREN,
			self::META_KEY_MEDIA_ALLOW_UPLOAD,
			self::META_KEY_MEDIA_ALLOW_URL,
			self::META_KEY_MEDIA_ALLOWED_KINDS,
			self::META_KEY_DATE_MODE,
			self::META_KEY_INT_DISPLAY_FORMAT,
			self::META_KEY_FIXED_NODE,
			self::META_KEY_REF_SCOPE,
			self::META_KEY_ALLOWED_REF_IDS,
			self::META_KEY_FIXED_ENABLED,
			self::META_KEY_FIXED_LITERAL,
			self::META_KEY_READONLY,
			self::META_KEY_DISABLED_BRANCH,
			self::META_KEY_ALLOWED_PREFIX_IDS,
		);

		foreach ( $keys as $key ) {
			delete_term_meta( $slot_id, $key );
			if ( ! metadata_exists( 'term', $type_id, $key ) ) {
				continue;
			}
			$value = get_term_meta( $type_id, $key, true );
			if ( '' === $value || null === $value || false === $value ) {
				continue;
			}
			update_term_meta( $slot_id, $key, $value );
		}
	}

	/**
	 * Copy scaffold settings meta from one term to another (type, required, fixed, footer, branch filters).
	 */
	public static function copy_settings( string $taxonomy, int $source_id, int $target_id ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$keys = array(
			self::META_KEY,
			self::META_KEY_REQUIRED,
			self::META_KEY_HAS_FOOTER,
			self::META_KEY_FOOTER_OP,
			self::META_KEY_SET_SEPARATOR,
			self::META_KEY_SET_JOIN_UNITS,
			self::META_KEY_SET_LABEL_CHILDREN,
			self::META_KEY_MEDIA_ALLOW_UPLOAD,
			self::META_KEY_MEDIA_ALLOW_URL,
			self::META_KEY_DATE_MODE,
			self::META_KEY_INT_DISPLAY_FORMAT,
			self::META_KEY_FIXED_NODE,
			self::META_KEY_REF_SCOPE,
			self::META_KEY_ALLOWED_REF_IDS,
			self::META_KEY_FIXED_ENABLED,
			self::META_KEY_FIXED_LITERAL,
			self::META_KEY_READONLY,
			self::META_KEY_DISABLED_BRANCH,
			self::META_KEY_ALLOWED_PREFIX_IDS,
			self::META_KEY_MULTIPLIKATOR,
			self::META_KEY_PREFIX_ROOT_TO_SI,
		);

		foreach ( $keys as $key ) {
			delete_term_meta( $target_id, $key );
			$value = get_term_meta( $source_id, $key, true );
			if ( '' === $value || null === $value || false === $value ) {
				continue;
			}
			update_term_meta( $target_id, $key, $value );
		}
	}

	/**
	 * Assign catalog / field type_id (or hierarchy parent / seed root type).
	 *
	 * @param bool $allow_seed When true, allow writing type_id on root-level terms
	 *                         (seed / repair). Admin and Relations must leave this false.
	 * @return true|\WP_Error
	 */
	public static function set_type_id( string $taxonomy, int $term_id, int $type_id, bool $allow_seed = false ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		if ( $type_id <= 0 ) {
			if ( self::is_typed_as_parent( $taxonomy, $term_id ) ) {
				return new \WP_Error(
					'wtt_type_locked',
					__( 'Specialization type is the parent node and cannot be cleared yet.', 'wp-taxonomy-tree' )
				);
			}
			if ( self::is_free_type_assignment_locked( $taxonomy, $term_id ) && ! $allow_seed ) {
				return new \WP_Error(
					'wtt_type_locked',
					__( 'Root type is seed-managed and cannot be cleared from the admin UI.', 'wp-taxonomy-tree' )
				);
			}
			delete_term_meta( $term_id, self::META_KEY );
			delete_term_meta( $term_id, self::META_KEY_DISABLED_BRANCH );
			self::clear_fixed_value( $term_id );
			self::clear_ref_scope( $term_id );
			return true;
		}

		if ( $type_id === $term_id ) {
			return new \WP_Error(
				'wtt_bad_type',
				__( 'A node cannot use itself as its data type.', 'wp-taxonomy-tree' )
			);
		}

		/*
		 * Q88: hierarchy datatype = parent only (no free pick).
		 * Attribute members are excluded (own catalog type remains editable).
		 */
		$parent_id = (int) $term->parent;
		if ( self::is_hierarchy_datatype_subject( $taxonomy, $term_id ) ) {
			if ( $type_id !== $parent_id ) {
				return new \WP_Error(
					'wtt_type_locked',
					__( 'Hierarchy datatype is the parent node and is not editable.', 'wp-taxonomy-tree' )
				);
			}
			return self::assign_hierarchy_parent_type_id( $taxonomy, $term_id, $parent_id );
		}

		/*
		 * Root: free set_type dropped — seed may write type_id by id ($allow_seed).
		 * Idempotent re-save of the current type is allowed without the seed flag.
		 */
		if ( self::is_free_type_assignment_locked( $taxonomy, $term_id ) && ! $allow_seed ) {
			$current = self::get_type_id( $term_id );
			if ( $current === $type_id ) {
				return true;
			}
			return new \WP_Error(
				'wtt_type_locked',
				__( 'Root type is seed-managed and is not editable in the admin UI.', 'wp-taxonomy-tree' )
			);
		}

		$type = get_term( $type_id, $taxonomy );
		if ( ! $type instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_bad_type', __( 'Data type not found.', 'wp-taxonomy-tree' ) );
		}

		if ( ! self::is_assignable_type( $taxonomy, $term_id, $type_id ) ) {
			return new \WP_Error(
				'wtt_bad_type',
				__( 'Choose an existing type node.', 'wp-taxonomy-tree' )
			);
		}

		$previous = self::get_type_id( $term_id );
		update_term_meta( $term_id, self::META_KEY, $type_id );
		if ( $previous !== $type_id ) {
			delete_term_meta( $term_id, self::META_KEY_DISABLED_BRANCH );
			self::clear_fixed_value( $term_id );
			if ( 'subtree' !== strtolower( $type->name ) && 'node_embed' !== strtolower( $type->name ) && 'node_ref' !== strtolower( $type->name ) ) {
				self::clear_ref_scope( $term_id );
			}
			if ( $previous !== $type_id && $type_id > 0 ) {
				self::apply_type_presets( $taxonomy, $term_id, $type_id );
			}
		}
		/* Table is never inheriting (bands/fields must not become tables). */
		if ( self::type_id_is_named( $taxonomy, $type_id, 'table' ) ) {
			delete_term_meta( $term_id, self::META_KEY_TYPE_INHERITING );
		}
		return true;
	}

	/**
	 * @return array<int, int>
	 */
	public static function get_disabled_branch_ids( int $term_id ): array {
		$raw = get_term_meta( $term_id, self::META_KEY_DISABLED_BRANCH, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw as $id ) {
			if ( is_numeric( $id ) ) {
				$ids[] = (int) $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param array<int, int> $ids Disabled child term IDs.
	 * @return true|\WP_Error
	 */
	public static function set_disabled_branch_ids( string $taxonomy, int $term_id, array $ids ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		$clean = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}
		$clean = array_values( array_unique( $clean ) );

		if ( empty( $clean ) ) {
			delete_term_meta( $term_id, self::META_KEY_DISABLED_BRANCH );
		} else {
			update_term_meta( $term_id, self::META_KEY_DISABLED_BRANCH, wp_json_encode( $clean ) );
		}

		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_branch_child_enabled( string $taxonomy, int $term_id, int $child_id, bool $enabled ) {
		$type_id = self::get_type_id( $term_id );
		if ( $type_id <= 0 ) {
			return new \WP_Error( 'wtt_no_type', __( 'Assign a branch type first.', 'wp-taxonomy-tree' ) );
		}

		if ( ! self::is_direct_child_of( $taxonomy, $child_id, $type_id ) ) {
			return new \WP_Error( 'wtt_bad_branch_child', __( 'Node is not a direct child of the selected type branch.', 'wp-taxonomy-tree' ) );
		}

		$disabled = self::get_disabled_branch_ids( $term_id );
		if ( $enabled ) {
			$disabled = array_values(
				array_filter(
					$disabled,
					static function ( int $id ) use ( $child_id ): bool {
						return $id !== $child_id;
					}
				)
			);
		} elseif ( ! in_array( $child_id, $disabled, true ) ) {
			$disabled[] = $child_id;
		}

		return self::set_disabled_branch_ids( $taxonomy, $term_id, $disabled );
	}

	/**
	 * Children of the assigned type when it is a branch (has children).
	 *
	 * @return array{typeId:int,typeName:string,children:array<int, array{id:int,name:string,enabled:bool}>}|null
	 */
	public static function get_type_branch( string $taxonomy, int $term_id ): ?array {
		$type_id = self::get_effective_type_id( $taxonomy, $term_id );
		if ( $type_id <= 0 ) {
			return null;
		}

		$branch = self::build_type_branch( $taxonomy, $type_id, self::get_disabled_branch_ids( $term_id ) );
		if ( null === $branch ) {
			return null;
		}

		return self::apply_unit_prefix_filter_to_branch( $taxonomy, $term_id, $branch );
	}

	/**
	 * @return array<int, int>
	 */
	public static function get_allowed_prefix_ids( int $unit_term_id ): array {
		$raw = get_term_meta( $unit_term_id, self::META_KEY_ALLOWED_PREFIX_IDS, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw as $id ) {
			if ( is_numeric( $id ) ) {
				$ids[] = (int) $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Persist Q51 allowlist on a Basiseinheit unit (L1: empty = no prefixes).
	 *
	 * @param array<int, int> $ids Allowed Präfix term IDs.
	 * @return true|\WP_Error
	 */
	public static function set_allowed_prefix_ids( string $taxonomy, int $unit_term_id, array $ids ) {
		if ( ! self::is_basiseinheit_unit_node( $taxonomy, $unit_term_id ) ) {
			return new \WP_Error(
				'wtt_bad_unit',
				__( 'Allowed prefixes can only be set on a Basiseinheit unit node.', 'wp-taxonomy-tree' )
			);
		}

		$prefixes_root = self::find_prefixes_root( $taxonomy, $unit_term_id );
		$clean         = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 || $prefixes_root <= 0 ) {
				continue;
			}
			if ( self::is_direct_child_of( $taxonomy, $id, $prefixes_root ) ) {
				$clean[] = $id;
			}
		}
		$clean = array_values( array_unique( $clean ) );

		if ( empty( $clean ) ) {
			// Persist empty JSON so L1 is explicit (missing meta also means empty).
			update_term_meta( $unit_term_id, self::META_KEY_ALLOWED_PREFIX_IDS, '[]' );
		} else {
			update_term_meta( $unit_term_id, self::META_KEY_ALLOWED_PREFIX_IDS, wp_json_encode( $clean ) );
		}

		return true;
	}

	public static function get_multiplikator( int $term_id ): ?float {
		$raw = get_term_meta( $term_id, self::META_KEY_MULTIPLIKATOR, true );
		if ( ! is_numeric( $raw ) ) {
			return null;
		}

		return (float) $raw;
	}

	public static function set_multiplikator( int $term_id, float $factor ): void {
		if ( $factor <= 0.0 ) {
			delete_term_meta( $term_id, self::META_KEY_MULTIPLIKATOR );
			return;
		}
		update_term_meta( $term_id, self::META_KEY_MULTIPLIKATOR, (string) $factor );
	}

	public static function get_prefix_root_to_si( int $unit_term_id ): float {
		$raw = get_term_meta( $unit_term_id, self::META_KEY_PREFIX_ROOT_TO_SI, true );
		if ( ! is_numeric( $raw ) ) {
			return 1.0;
		}
		$factor = (float) $raw;
		return $factor > 0.0 ? $factor : 1.0;
	}

	public static function set_prefix_root_to_si( int $unit_term_id, float $factor ): void {
		if ( $factor <= 0.0 || 1.0 === $factor ) {
			delete_term_meta( $unit_term_id, self::META_KEY_PREFIX_ROOT_TO_SI );
			return;
		}
		update_term_meta( $unit_term_id, self::META_KEY_PREFIX_ROOT_TO_SI, (string) $factor );
	}

	/**
	 * Convert a reading to the unit’s SI base: magnitude × prefix_multiplikator × prefix_root_to_si.
	 */
	public static function to_si_base( float $magnitude, ?float $prefix_multiplikator, int $unit_term_id ): float {
		$prefix = null !== $prefix_multiplikator && $prefix_multiplikator > 0.0 ? $prefix_multiplikator : 1.0;
		return $magnitude * $prefix * self::get_prefix_root_to_si( $unit_term_id );
	}

	/**
	 * Direct child of Typen/Basiseinheit (e.g. Meter, Ohm, Farad).
	 */
	public static function is_basiseinheit_unit_node( string $taxonomy, int $term_id ): bool {
		$base_root = self::find_base_units_root( $taxonomy, $term_id );
		if ( $base_root <= 0 ) {
			return false;
		}

		$term = get_term( $term_id, $taxonomy );
		return $term instanceof \WP_Term && (int) $term->parent === $base_root;
	}

	/**
	 * Prefix catalog with enabled flags from this unit's allowlist (L1 empty = none enabled).
	 *
	 * @return array{unitId:int,allowedPrefixIds:array<int,int>,prefixes:array<int, array{id:int,name:string,enabled:bool}>}|null
	 */
	public static function get_prefix_allowlist( string $taxonomy, int $unit_term_id ): ?array {
		if ( ! self::is_basiseinheit_unit_node( $taxonomy, $unit_term_id ) ) {
			return null;
		}

		$allowed = self::get_allowed_prefix_ids( $unit_term_id );
		$allowed_map = array_fill_keys( $allowed, true );
		$prefixes_root = self::find_prefixes_root( $taxonomy, $unit_term_id );
		$children      = $prefixes_root > 0 ? self::get_direct_child_terms( $taxonomy, $prefixes_root ) : array();

		$prefixes = array();
		foreach ( $children as $child ) {
			$child_id   = (int) $child->term_id;
			$prefixes[] = array(
				'id'      => $child_id,
				'name'    => $child->name,
				'enabled' => isset( $allowed_map[ $child_id ] ),
			);
		}

		return array(
			'unitId'           => $unit_term_id,
			'allowedPrefixIds' => $allowed,
			'prefixes'         => $prefixes,
		);
	}

	/**
	 * When this node is a direct child of a Basiseinheit unit (e.g. Meter/Praefix), return that unit id.
	 */
	public static function resolve_parent_basiseinheit_unit( string $taxonomy, int $term_id ): int {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || ! $term->parent ) {
			return 0;
		}

		$parent_id = (int) $term->parent;
		return self::is_basiseinheit_unit_node( $taxonomy, $parent_id ) ? $parent_id : 0;
	}

	/**
	 * Sibling Basiseinheit member with a fixed unit (set context), else 0.
	 */
	public static function resolve_sibling_fixed_basiseinheit( string $taxonomy, int $term_id ): int {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || ! $term->parent ) {
			return 0;
		}

		$parent_id = (int) $term->parent;
		if ( ! self::is_set_typed( $taxonomy, $parent_id ) ) {
			return 0;
		}

		$siblings = self::get_direct_child_terms( $taxonomy, $parent_id );
		foreach ( $siblings as $sibling ) {
			$sibling_id = (int) $sibling->term_id;
			if ( $sibling_id === $term_id ) {
				continue;
			}
			$type = self::get_assignment( $taxonomy, $sibling_id );
			if ( null === $type || 'basiseinheit' !== strtolower( $type['name'] ) ) {
				continue;
			}
			if ( ! self::is_fixed_enabled( $sibling_id ) ) {
				continue;
			}
			$fixed_id = self::get_fixed_node_id( $sibling_id );
			if ( $fixed_id > 0 && self::is_basiseinheit_unit_node( $taxonomy, $fixed_id ) ) {
				return $fixed_id;
			}
		}

		return 0;
	}

	/**
	 * @param array{typeId:int,typeName:string,children:array<int, array{id:int,name:string,enabled:bool}>} $branch
	 * @return array{typeId:int,typeName:string,children:array<int, array{id:int,name:string,enabled:bool}>,unitFilter?:bool,unitAllowlistEdit?:bool,unitId?:int,unitName?:string}
	 */
	private static function apply_unit_prefix_filter_to_branch( string $taxonomy, int $term_id, array $branch ): array {
		$type_name = strtolower( (string) ( $branch['typeName'] ?? '' ) );
		if ( 'praefixe' !== $type_name ) {
			return $branch;
		}

		// Praefix child under Meter/Ohm/… — edit the unit allowlist via this underknot’s type branch.
		$parent_unit = self::resolve_parent_basiseinheit_unit( $taxonomy, $term_id );
		if ( $parent_unit > 0 ) {
			$allowed     = self::get_allowed_prefix_ids( $parent_unit );
			$allowed_map = array_fill_keys( $allowed, true );
			$unit        = get_term( $parent_unit, $taxonomy );
			$children    = array();
			foreach ( $branch['children'] as $child ) {
				$id               = (int) ( $child['id'] ?? 0 );
				$child['enabled'] = isset( $allowed_map[ $id ] );
				$children[]       = $child;
			}
			$branch['children']            = $children;
			$branch['unitAllowlistEdit']   = true;
			$branch['unitId']              = $parent_unit;
			$branch['unitName']            = $unit instanceof \WP_Term ? $unit->name : '';
			$branch['unitPrefixRootToSi']  = self::get_prefix_root_to_si( $parent_unit );
			return $branch;
		}

		// Praefix member next to fixed Einheit (e.g. Kondensator) — read-only filter from unit allowlist.
		$unit_id = self::resolve_sibling_fixed_basiseinheit( $taxonomy, $term_id );
		if ( $unit_id <= 0 ) {
			return $branch;
		}

		$allowed     = self::get_allowed_prefix_ids( $unit_id );
		$allowed_map = array_fill_keys( $allowed, true );
		$unit        = get_term( $unit_id, $taxonomy );

		$children = array();
		foreach ( $branch['children'] as $child ) {
			$id = (int) ( $child['id'] ?? 0 );
			// L1: empty allowlist ⇒ nothing enabled; also respect local disabled_branch.
			$locally_on       = ! empty( $child['enabled'] );
			$child['enabled'] = $locally_on && isset( $allowed_map[ $id ] );
			$children[]       = $child;
		}
		$branch['children']   = $children;
		$branch['unitFilter'] = true;
		$branch['unitId']     = $unit_id;
		$branch['unitName']   = $unit instanceof \WP_Term ? $unit->name : '';

		return $branch;
	}

	/**
	 * Branch children for a type id (draft UI / preview before save).
	 *
	 * @param array<int, int> $disabled_ids
	 * @return array{typeId:int,typeName:string,children:array<int, array{id:int,name:string,enabled:bool}>}|null
	 */
	public static function build_type_branch( string $taxonomy, int $type_id, array $disabled_ids = array() ): ?array {
		if ( $type_id <= 0 ) {
			return null;
		}

		$type = get_term( $type_id, $taxonomy );
		if ( ! $type instanceof \WP_Term ) {
			return null;
		}

		$children = self::get_direct_child_terms( $taxonomy, $type_id );
		if ( empty( $children ) ) {
			return null;
		}

		$disabled = array();
		foreach ( $disabled_ids as $id ) {
			if ( is_numeric( $id ) ) {
				$disabled[] = (int) $id;
			}
		}
		$disabled = array_values( array_unique( $disabled ) );

		$list = array();
		foreach ( $children as $child ) {
			$child_id = (int) $child->term_id;
			$list[]   = array(
				'id'            => $child_id,
				'name'          => $child->name,
				'shortDescription' => Tree_Model::get_short_description( $child_id ),
				'enabled'       => ! in_array( $child_id, $disabled, true ),
				'multiplikator' => self::get_multiplikator( $child_id ),
			);
		}

		return array(
			'typeId'   => $type_id,
			'typeName' => $type->name,
			'children' => $list,
		);
	}

	/**
	 * Persist editable node settings in one step (name, type, required, fixed, footer, branch filters).
	 *
	 * @param array<string, mixed> $settings
	 * @return true|\WP_Error
	 */
	public static function save_node_settings( string $taxonomy, int $term_id, array $settings ) {
		$name        = array_key_exists( 'name', $settings ) ? (string) $settings['name'] : null;
		$description = array_key_exists( 'description', $settings ) ? (string) $settings['description'] : null;
		if ( null !== $name || null !== $description ) {
			$result = Tree_Model::update_term_fields( $taxonomy, $term_id, $name, $description );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( array_key_exists( 'short_description', $settings ) ) {
			$result = Tree_Model::set_short_description( $taxonomy, $term_id, (string) $settings['short_description'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( array_key_exists( 'icon', $settings ) ) {
			$result = Tree_Icons::set( $taxonomy, $term_id, (string) $settings['icon'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$type_id = isset( $settings['type_id'] ) ? (int) $settings['type_id'] : 0;
		/*
		 * is_template: Development mode only. Non-dev callers must omit the key;
		 * if present without Development mode, reject (do not silently apply).
		 */
		if ( array_key_exists( 'is_template', $settings ) ) {
			if ( ! Settings::is_development_mode() ) {
				return new \WP_Error(
					'wtt_template_dev_only',
					__( 'The template flag can only be changed in Development mode.', 'wp-taxonomy-tree' ),
					array( 'status' => 403 )
				);
			}
			$tpl_val = is_bool( $settings['is_template'] )
				? $settings['is_template']
				: ! empty( $settings['is_template'] );
			$result = self::set_is_template( $taxonomy, $term_id, $tpl_val );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		/* Q77 revise: datatype nodes may also carry a type_id (own schema / base type). */

		/*
		 * Q88: hierarchy datatype = parent. Root type is seed-managed (no admin set_type).
		 * Attribute members / catalog field types still use posted type_id.
		 */
		if ( self::is_hierarchy_datatype_subject( $taxonomy, $term_id ) ) {
			$result = self::apply_parent_as_type( $taxonomy, $term_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} elseif ( self::is_free_type_assignment_locked( $taxonomy, $term_id ) ) {
			/* Root: ignore posted type chrome; seed owns type_id. */
		} else {
			if ( array_key_exists( 'type_override', $settings ) ) {
				$result = self::set_type_override( $taxonomy, $term_id, ! empty( $settings['type_override'] ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			if ( array_key_exists( 'type_inheriting', $settings ) ) {
				$result = self::set_type_inheriting( $taxonomy, $term_id, ! empty( $settings['type_inheriting'] ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			/*
			 * Q76: When inheriting from an ancestor without override, keep any stored
			 * own type_id but do not overwrite it from the (disabled) picker.
			 */
			if ( self::is_type_override( $term_id ) || ! self::can_inherit_type( $taxonomy, $term_id ) ) {
				$result = self::set_type_id( $taxonomy, $term_id, $type_id );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		$effective_type_id = self::get_effective_type_id( $taxonomy, $term_id );
		$type_term         = $effective_type_id > 0 ? get_term( $effective_type_id, $taxonomy ) : null;
		$type_name         = $type_term instanceof \WP_Term ? $type_term->name : '';

		$required = ! empty( $settings['required'] );
		if ( self::is_display_only_type_name( $type_name ) ) {
			$required = false;
		}
		$result = self::set_required( $taxonomy, $term_id, $required );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$is_attr_slot = class_exists( Attribute::class ) && Attribute::is_slot( $term_id );

		/*
		 * Read-only replaces Fixed-as-lock (attribute slots). Default value seeding
		 * stays on Attribute::_wtt_attribute_fixed_values — do not confuse with lock.
		 */
		$readonly_applied = null;
		if ( array_key_exists( 'readonly', $settings ) ) {
			$readonly_applied = ! empty( $settings['readonly'] );
			$result           = self::set_readonly( $taxonomy, $term_id, $readonly_applied );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$fixed_enabled = ! empty( $settings['fixed_enabled'] );
		if ( self::is_display_only_type_name( $type_name ) ) {
			$fixed_enabled = false;
		}
		if ( self::is_media_type_name( $type_name ) ) {
			$fixed_enabled = false;
		}
		$fixed_literal = isset( $settings['fixed_literal'] ) ? (string) $settings['fixed_literal'] : '';
		$fixed_node_id = isset( $settings['fixed_node_id'] ) ? (int) $settings['fixed_node_id'] : 0;

		if ( $is_attr_slot ) {
			/*
			 * Attribute slots: stop writing new fixedEnabled locks. Compat: if an old
			 * client still posts fixed_enabled on, map to readonly (leave old fixed meta).
			 */
			if ( null === $readonly_applied && $fixed_enabled ) {
				$readonly_applied = true;
				$result           = self::set_readonly( $taxonomy, $term_id, true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			if ( null !== $readonly_applied ) {
				Attribute::sync_hosts_readonly_from_slot( $taxonomy, $term_id, $readonly_applied );
			}
		} else {
			$result = self::set_fixed_value( $taxonomy, $term_id, $fixed_enabled, $fixed_literal, $fixed_node_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$ref_scope_id = isset( $settings['ref_scope_id'] ) ? (int) $settings['ref_scope_id'] : 0;
		$type_key     = strtolower( $type_name );
		if ( 'subtree' === $type_key || 'node_embed' === $type_key || 'node_ref' === $type_key || 'node_pick' === $type_key ) {
			$result = self::set_ref_scope_id( $taxonomy, $term_id, $ref_scope_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( array_key_exists( 'allowed_ref_ids', $settings ) ) {
				$allowed = is_array( $settings['allowed_ref_ids'] ) ? $settings['allowed_ref_ids'] : array();
				$result  = self::set_allowed_ref_ids( $taxonomy, $term_id, $allowed );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			if ( array_key_exists( 'field_multiplicity', $settings ) ) {
				$result = self::set_field_multiplicity(
					$taxonomy,
					$term_id,
					(string) $settings['field_multiplicity']
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		} else {
			self::clear_ref_scope( $term_id );
			self::clear_allowed_ref_ids( $term_id );
			delete_term_meta( $term_id, self::META_KEY_FIELD_MULTIPLICITY );
		}

		if (
			array_key_exists( 'media_allow_upload', $settings )
			|| array_key_exists( 'media_allow_url', $settings )
			|| array_key_exists( 'media_allowed_kinds', $settings )
		) {
			$media_term = self::resolve_media_config_term_id( $taxonomy, $term_id );
			if ( $media_term > 0 ) {
				$cfg          = self::get_media_type_config( $media_term );
				$allow_upload = array_key_exists( 'media_allow_upload', $settings )
					? ! empty( $settings['media_allow_upload'] )
					: $cfg['allowUpload'];
				$allow_url    = array_key_exists( 'media_allow_url', $settings )
					? ! empty( $settings['media_allow_url'] )
					: $cfg['allowUrl'];
				$result       = self::set_media_type_config( $taxonomy, $media_term, $allow_upload, $allow_url );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			if ( array_key_exists( 'media_allowed_kinds', $settings ) ) {
				$kinds  = is_array( $settings['media_allowed_kinds'] ) ? $settings['media_allowed_kinds'] : array();
				$result = self::set_media_allowed_kinds( $taxonomy, $term_id, $kinds );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		if ( array_key_exists( 'date_mode', $settings ) ) {
			$date_term = self::resolve_date_config_term_id( $taxonomy, $term_id );
			if ( $date_term > 0 ) {
				$result = self::set_date_mode( $taxonomy, $date_term, (string) $settings['date_mode'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		if ( array_key_exists( 'preferred_converter', $settings ) ) {
			$result = self::set_preferred_converter(
				$taxonomy,
				$term_id,
				(string) $settings['preferred_converter']
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} elseif ( array_key_exists( 'int_display_format', $settings ) ) {
			/* Legacy POST key → preferred converter. */
			$result = self::set_preferred_converter(
				$taxonomy,
				$term_id,
				(string) $settings['int_display_format']
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( array_key_exists( 'preferred_render', $settings ) ) {
			$result = self::set_preferred_render( $taxonomy, $term_id, (string) $settings['preferred_render'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( array_key_exists( 'validators', $settings ) ) {
			$result = self::set_validators( $taxonomy, $term_id, $settings['validators'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$has_footer = ! empty( $settings['has_footer'] );
		$result     = self::set_has_footer( $taxonomy, $term_id, $has_footer );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( array_key_exists( 'footer_op', $settings ) ) {
			$result = self::set_footer_op( $taxonomy, $term_id, (string) $settings['footer_op'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( array_key_exists( 'type_props', $settings ) ) {
			$props  = is_array( $settings['type_props'] ) ? $settings['type_props'] : array();
			$result = self::set_type_props( $taxonomy, $term_id, $props );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( array_key_exists( 'prop_bindings', $settings ) ) {
			$bindings = is_array( $settings['prop_bindings'] ) ? $settings['prop_bindings'] : array();
			$result   = self::set_prop_bindings( $taxonomy, $term_id, $bindings );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if (
			array_key_exists( 'set_separator', $settings )
			|| array_key_exists( 'set_join_units', $settings )
			|| array_key_exists( 'set_label_children', $settings )
		) {
			if ( self::is_set_typed( $taxonomy, $term_id ) ) {
				if ( array_key_exists( 'set_separator', $settings ) ) {
					$result = self::set_set_separator( $taxonomy, $term_id, (string) $settings['set_separator'] );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				if ( array_key_exists( 'set_join_units', $settings ) ) {
					$result = self::set_set_join_units( $taxonomy, $term_id, ! empty( $settings['set_join_units'] ) );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				if ( array_key_exists( 'set_label_children', $settings ) ) {
					$result = self::set_set_label_children( $taxonomy, $term_id, ! empty( $settings['set_label_children'] ) );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
			}
		}

		$disabled_input = array();
		if ( isset( $settings['disabled_branch_ids'] ) && is_array( $settings['disabled_branch_ids'] ) ) {
			foreach ( $settings['disabled_branch_ids'] as $id ) {
				if ( is_numeric( $id ) ) {
					$disabled_input[] = (int) $id;
				}
			}
		}

		// Preferred: Basiseinheit unit parent saves allowlist + multiplikators (child extras on parent).
		// Use raw disabled ids (Präfix catalog), not filtered against set-type children.
		if ( self::is_basiseinheit_unit_node( $taxonomy, $term_id ) ) {
			$prefixes_root = self::find_prefixes_root( $taxonomy, $term_id );
			if ( $prefixes_root > 0 ) {
				$result = self::save_unit_prefix_settings(
					$taxonomy,
					$term_id,
					$prefixes_root,
					$disabled_input,
					$settings
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		$parent_unit = self::resolve_parent_basiseinheit_unit( $taxonomy, $term_id );
		if ( $parent_unit > 0 && 'praefixe' === strtolower( $type_name ) && $type_id > 0 ) {
			// Legacy path: editing on Praefix underknot still works; prefer parent unit UI.
			$result = self::save_unit_prefix_settings(
				$taxonomy,
				$parent_unit,
				$type_id,
				$disabled_input,
				$settings
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return self::set_disabled_branch_ids( $taxonomy, $term_id, array() );
		}

		// Validate disabled ids are children of the (new) type when a branch type is set.
		$disabled = array();
		if ( $type_id > 0 && ! empty( $disabled_input ) ) {
			foreach ( $disabled_input as $child_id ) {
				if ( self::is_direct_child_of( $taxonomy, $child_id, $type_id ) ) {
					$disabled[] = $child_id;
				}
			}
		}

		$result = self::set_disabled_branch_ids( $taxonomy, $term_id, $disabled );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Tree_Model::touch_modified( $term_id );
		return true;
	}

	/**
	 * Persist Q51 allowlist + Praefix multiplikators + unit prefix_root_to_si.
	 *
	 * @param array<int, int>      $disabled_prefix_ids Unchecked Präfix ids.
	 * @param array<string, mixed> $settings            May include prefix_multiplikators, prefix_root_to_si.
	 * @return true|\WP_Error
	 */
	private static function save_unit_prefix_settings(
		string $taxonomy,
		int $unit_term_id,
		int $prefixes_root_id,
		array $disabled_prefix_ids,
		array $settings
	) {
		$disabled_map = array_fill_keys( $disabled_prefix_ids, true );
		$allowed      = array();
		foreach ( self::get_direct_child_terms( $taxonomy, $prefixes_root_id ) as $prefix_term ) {
			$pid = (int) $prefix_term->term_id;
			if ( ! isset( $disabled_map[ $pid ] ) ) {
				$allowed[] = $pid;
			}
		}
		$result = self::set_allowed_prefix_ids( $taxonomy, $unit_term_id, $allowed );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $settings['prefix_multiplikators'] ) && is_array( $settings['prefix_multiplikators'] ) ) {
			foreach ( $settings['prefix_multiplikators'] as $prefix_id => $factor ) {
				$prefix_id = (int) $prefix_id;
				if ( $prefix_id <= 0 || ! self::is_direct_child_of( $taxonomy, $prefix_id, $prefixes_root_id ) ) {
					continue;
				}
				if ( ! is_numeric( $factor ) ) {
					continue;
				}
				$factor = (float) $factor;
				if ( $factor > 0.0 ) {
					self::set_multiplikator( $prefix_id, $factor );
				}
			}
		}

		if ( array_key_exists( 'prefix_root_to_si', $settings ) && is_numeric( $settings['prefix_root_to_si'] ) ) {
			self::set_prefix_root_to_si( $unit_term_id, (float) $settings['prefix_root_to_si'] );
		}

		return true;
	}

	/**
	 * @return array<int, \WP_Term>
	 */
	private static function get_direct_child_terms( string $taxonomy, int $parent_id ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$children = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			if ( class_exists( Trash::class ) && Trash::is_trashed( (int) $term->term_id ) ) {
				continue;
			}
			$children[] = $term;
		}

		usort(
			$children,
			static function ( \WP_Term $a, \WP_Term $b ): int {
				$pa = Tree_Model::get_position( (int) $a->term_id );
				$pb = Tree_Model::get_position( (int) $b->term_id );
				if ( $pa !== $pb ) {
					return $pa <=> $pb;
				}
				return strcasecmp( $a->name, $b->name );
			}
		);

		return $children;
	}

	private static function is_direct_child_of( string $taxonomy, int $child_id, int $parent_id ): bool {
		$child = get_term( $child_id, $taxonomy );
		return $child instanceof \WP_Term && (int) $child->parent === $parent_id;
	}

	/**
	 * Resolve a direct child of $parent_id by name (demo seeding / branch filters).
	 */
	public static function find_direct_child_by_name( string $taxonomy, int $parent_id, string $name ): int {
		$name = trim( $name );
		if ( '' === $name || $parent_id <= 0 ) {
			return 0;
		}

		foreach ( self::get_direct_child_terms( $taxonomy, $parent_id ) as $child ) {
			if ( 0 === strcasecmp( $child->name, $name ) ) {
				return (int) $child->term_id;
			}
		}

		return 0;
	}

	public static function is_required( int $term_id ): bool {
		$value = get_term_meta( $term_id, self::META_KEY_REQUIRED, true );
		return '1' === (string) $value || 1 === $value || true === $value;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_required( string $taxonomy, int $term_id, bool $required ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		if ( $required ) {
			update_term_meta( $term_id, self::META_KEY_REQUIRED, '1' );
		} else {
			delete_term_meta( $term_id, self::META_KEY_REQUIRED );
		}

		return true;
	}

	public static function has_footer( int $term_id ): bool {
		$value = get_term_meta( $term_id, self::META_KEY_HAS_FOOTER, true );
		return '1' === (string) $value || 1 === $value || true === $value;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_has_footer( string $taxonomy, int $term_id, bool $has_footer ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		if ( $has_footer ) {
			update_term_meta( $term_id, self::META_KEY_HAS_FOOTER, '1' );
		} else {
			delete_term_meta( $term_id, self::META_KEY_HAS_FOOTER );
		}

		return true;
	}

	/**
	 * Aggregate op key on a Fuss field slot (empty = default by column type).
	 */
	public static function get_footer_op( int $term_id ): string {
		$key = strtolower( sanitize_key( (string) get_term_meta( $term_id, self::META_KEY_FOOTER_OP, true ) ) );
		return isset( Footer_Ops::catalog()[ $key ] ) ? $key : '';
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_footer_op( string $taxonomy, int $term_id, string $op_key ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		$op_key = strtolower( sanitize_key( $op_key ) );
		if ( '' === $op_key ) {
			delete_term_meta( $term_id, self::META_KEY_FOOTER_OP );
			return true;
		}
		if ( ! isset( Footer_Ops::catalog()[ $op_key ] ) ) {
			return new \WP_Error( 'wtt_bad_footer_op', __( 'Unknown aggregate operation.', 'wp-taxonomy-tree' ) );
		}
		update_term_meta( $term_id, self::META_KEY_FOOTER_OP, $op_key );
		return true;
	}

	/**
	 * When term is a field under a table’s bound Fuss band: column index + Zeile type key.
	 *
	 * @return array{
	 *   tableId:int,
	 *   bandId:int,
	 *   index:int,
	 *   zeileTypeKey:string,
	 *   zeileFieldId:int,
	 *   footerOp:string,
	 *   footerOpOptions:list<array<string,mixed>>
	 * }|null
	 */
	public static function get_fuss_field_context( string $taxonomy, int $term_id ): ?array {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || (int) $term->parent <= 0 ) {
			return null;
		}
		$band_id = (int) $term->parent;
		$band    = get_term( $band_id, $taxonomy );
		if ( ! $band instanceof \WP_Term || (int) $band->parent <= 0 ) {
			return null;
		}
		$table_id = (int) $band->parent;
		if ( ! self::has_type_named( $taxonomy, $table_id, 'table' ) ) {
			return null;
		}
		$bindings = self::get_prop_bindings( $table_id );
		$fuss_id  = (int) ( $bindings['fuss'] ?? 0 );
		if ( $fuss_id !== $band_id ) {
			return null;
		}

		$bands = Table_Validator::resolve_bands( $taxonomy, $table_id );
		$fuss  = $bands[ Table_Validator::BAND_FUSS ] ?? null;
		$zeile = $bands[ Table_Validator::BAND_ZEILE ] ?? null;
		if ( ! is_array( $fuss ) || empty( $fuss['fields'] ) ) {
			return null;
		}

		$index = -1;
		foreach ( array_values( $fuss['fields'] ) as $i => $field ) {
			if ( (int) ( $field['id'] ?? 0 ) === $term_id ) {
				$index = (int) $i;
				break;
			}
		}
		if ( $index < 0 ) {
			return null;
		}

		$zeile_fields   = is_array( $zeile ) && isset( $zeile['fields'] ) ? array_values( $zeile['fields'] ) : array();
		$zeile_field    = $zeile_fields[ $index ] ?? null;
		$zeile_type     = is_array( $zeile_field )
			? (string) ( $zeile_field['typeKey'] ?? $zeile_field['typeName'] ?? 'text' )
			: 'text';
		$zeile_field_id = is_array( $zeile_field ) ? (int) ( $zeile_field['id'] ?? 0 ) : 0;
		$stored         = self::get_footer_op( $term_id );
		$normalized     = Footer_Ops::normalize( '' !== $stored ? $stored : '', $zeile_type );

		return array(
			'tableId'         => $table_id,
			'bandId'          => $band_id,
			'index'           => $index,
			'zeileTypeKey'    => $zeile_type,
			'zeileFieldId'    => $zeile_field_id,
			'footerOp'        => $normalized['key'],
			'footerOpOptions' => Footer_Ops::picker_options( $taxonomy, $zeile_type ),
		);
	}

	/**
	 * Separator between set members in labels and display (default `/`).
	 * Empty string is allowed once the meta has been written.
	 */
	public static function get_set_separator( int $term_id ): string {
		if ( ! metadata_exists( 'term', $term_id, self::META_KEY_SET_SEPARATOR ) ) {
			return '/';
		}
		$value = get_term_meta( $term_id, self::META_KEY_SET_SEPARATOR, true );
		return is_string( $value ) ? $value : '/';
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_set_separator( string $taxonomy, int $term_id, string $separator ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		// Soft length cap — display glue, not prose.
		$separator = substr( $separator, 0, 16 );
		update_term_meta( $term_id, self::META_KEY_SET_SEPARATOR, $separator );
		return true;
	}

	/**
	 * Join shared unit once in set display when members share a type (default on).
	 */
	public static function get_set_join_units( int $term_id ): bool {
		if ( ! metadata_exists( 'term', $term_id, self::META_KEY_SET_JOIN_UNITS ) ) {
			return true;
		}
		$value = get_term_meta( $term_id, self::META_KEY_SET_JOIN_UNITS, true );
		return '1' === (string) $value || 1 === $value || true === $value;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_set_join_units( string $taxonomy, int $term_id, bool $join_units ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		update_term_meta( $term_id, self::META_KEY_SET_JOIN_UNITS, $join_units ? '1' : '0' );
		return true;
	}

	/**
	 * Include child names in set labels like Abmessung (L/B/H) (default on).
	 */
	public static function get_set_label_children( int $term_id ): bool {
		if ( ! metadata_exists( 'term', $term_id, self::META_KEY_SET_LABEL_CHILDREN ) ) {
			return true;
		}
		$value = get_term_meta( $term_id, self::META_KEY_SET_LABEL_CHILDREN, true );
		return '1' === (string) $value || 1 === $value || true === $value;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_set_label_children( string $taxonomy, int $term_id, bool $include_children ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		update_term_meta( $term_id, self::META_KEY_SET_LABEL_CHILDREN, $include_children ? '1' : '0' );
		return true;
	}

	/**
	 * Known media display kinds (Q65 MIME classification).
	 *
	 * @return list<string>
	 */
	public static function media_kind_keys(): array {
		return array( 'image', 'video', 'audio', 'pdf', 'archive', 'office', 'text', 'file', 'link' );
	}

	/**
	 * @param mixed $raw Raw kind list.
	 * @return list<string>
	 */
	public static function normalize_media_allowed_kinds( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$known  = array_fill_keys( self::media_kind_keys(), true );
		$out    = array();
		$seen   = array();
		foreach ( $raw as $kind ) {
			$key = strtolower( trim( (string) $kind ) );
			if ( '' === $key || ! isset( $known[ $key ] ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $key;
		}
		return $out;
	}

	/**
	 * Allowed kinds for this node (slot or media type). Default: none.
	 *
	 * @return list<string>
	 */
	public static function get_media_allowed_kinds( int $term_id ): array {
		if ( $term_id <= 0 || ! metadata_exists( 'term', $term_id, self::META_KEY_MEDIA_ALLOWED_KINDS ) ) {
			return array();
		}
		$raw = get_term_meta( $term_id, self::META_KEY_MEDIA_ALLOWED_KINDS, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		return self::normalize_media_allowed_kinds( $raw );
	}

	/**
	 * @param list<string>|array<int, mixed> $kinds Allowed kinds.
	 * @return true|\WP_Error
	 */
	public static function set_media_allowed_kinds( string $taxonomy, int $term_id, array $kinds ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		if ( self::resolve_media_config_term_id( $taxonomy, $term_id ) <= 0 ) {
			return new \WP_Error( 'wtt_not_media', __( 'Media MIME kinds apply only to media-typed nodes.', 'wp-taxonomy-tree' ) );
		}
		$normalized = self::normalize_media_allowed_kinds( $kinds );
		update_term_meta( $term_id, self::META_KEY_MEDIA_ALLOWED_KINDS, wp_json_encode( $normalized ) );
		return true;
	}

	/**
	 * Media type config (Q65). Source flags stored on the media type term.
	 *
	 * @return array{allowUpload:bool,allowUrl:bool}
	 */
	public static function get_media_type_config( int $type_term_id ): array {
		$allow_upload = true;
		if ( metadata_exists( 'term', $type_term_id, self::META_KEY_MEDIA_ALLOW_UPLOAD ) ) {
			$value        = get_term_meta( $type_term_id, self::META_KEY_MEDIA_ALLOW_UPLOAD, true );
			$allow_upload = in_array( (string) $value, array( '1', 'true', 'yes' ), true );
		}

		$allow_url = false;
		if ( metadata_exists( 'term', $type_term_id, self::META_KEY_MEDIA_ALLOW_URL ) ) {
			$value     = get_term_meta( $type_term_id, self::META_KEY_MEDIA_ALLOW_URL, true );
			$allow_url = in_array( (string) $value, array( '1', 'true', 'yes' ), true );
		}

		if ( ! $allow_upload && ! $allow_url ) {
			$allow_upload = true;
		}

		return array(
			'allowUpload' => $allow_upload,
			'allowUrl'    => $allow_url,
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_media_type_config( string $taxonomy, int $type_term_id, bool $allow_upload, bool $allow_url ) {
		$term = get_term( $type_term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		if ( ! self::is_media_type_name( $term->name ) ) {
			return new \WP_Error( 'wtt_not_media', __( 'Media settings apply only to the media type.', 'wp-taxonomy-tree' ) );
		}
		if ( ! $allow_upload && ! $allow_url ) {
			$allow_upload = true;
		}
		update_term_meta( $type_term_id, self::META_KEY_MEDIA_ALLOW_UPLOAD, $allow_upload ? '1' : '0' );
		update_term_meta( $type_term_id, self::META_KEY_MEDIA_ALLOW_URL, $allow_url ? '1' : '0' );

		return true;
	}

	/**
	 * Resolve which term holds media source config: the media type node itself, or the assigned type.
	 */
	public static function resolve_media_config_term_id( string $taxonomy, int $term_id ): int {
		$term = get_term( $term_id, $taxonomy );
		if ( $term instanceof \WP_Term && self::is_media_type_name( $term->name ) ) {
			return $term_id;
		}
		$type_id = self::get_effective_type_id( $taxonomy, $term_id );
		if ( $type_id <= 0 ) {
			return 0;
		}
		$type = get_term( $type_id, $taxonomy );
		if ( $type instanceof \WP_Term && self::is_media_type_name( $type->name ) ) {
			return $type_id;
		}

		return 0;
	}

	/**
	 * Allowed kinds for this node. Own meta wins; otherwise inherit from media type term.
	 * Default (no meta anywhere): none — a kind must be enabled to use media.
	 *
	 * @return list<string>
	 */
	public static function get_media_allowed_kinds_for_node( string $taxonomy, int $term_id ): array {
		if ( metadata_exists( 'term', $term_id, self::META_KEY_MEDIA_ALLOWED_KINDS ) ) {
			return self::get_media_allowed_kinds( $term_id );
		}
		$type_id = self::resolve_media_config_term_id( $taxonomy, $term_id );
		if ( $type_id > 0 && $type_id !== $term_id ) {
			return self::get_media_allowed_kinds( $type_id );
		}
		return array();
	}

	/**
	 * Full media config for a node: type source flags + allowed kinds (own or inherited).
	 *
	 * @return array{allowUpload:bool,allowUrl:bool,allowedKinds:list<string>}|null
	 */
	public static function get_media_config_for_node( string $taxonomy, int $term_id ): ?array {
		$config_id = self::resolve_media_config_term_id( $taxonomy, $term_id );
		if ( $config_id <= 0 ) {
			return null;
		}

		$cfg                 = self::get_media_type_config( $config_id );
		$cfg['allowedKinds'] = self::get_media_allowed_kinds_for_node( $taxonomy, $term_id );

		return $cfg;
	}

	/**
	 * Normalize date mode: `date` (default) or `datetime`.
	 */
	public static function normalize_date_mode( string $mode ): string {
		$mode = strtolower( trim( $mode ) );
		return 'datetime' === $mode ? 'datetime' : 'date';
	}

	/**
	 * Normalize preferred render key: object layouts or field renderer ids (int, bool, …).
	 */
	public static function normalize_preferred_render( string $layout ): string {
		$key = strtolower( trim( $layout ) );
		if ( 'compact-horizontal' === $key || 'compact-h' === $key ) {
			$key = 'compact';
		}
		if ( 'compact-v' === $key ) {
			$key = 'compact-vertical';
		}
		if ( 'list' === $key ) {
			$key = 'table';
		}
		if ( 'pick-fill' === $key || 'pick_fill' === $key || 'compact-embed' === $key ) {
			$key = 'embed';
		}
		if ( in_array( $key, self::PREFERRED_RENDER_KEYS, true ) ) {
			return $key;
		}
		/* Field renderer registry ids — keep well-formed keys. */
		if ( 1 === preg_match( '/^[a-z][a-z0-9_-]*$/', $key ) ) {
			return $key;
		}
		return 'form';
	}

	private static function is_scalar_or_known_renderer_key( string $key ): bool {
		return '' !== self::canonical_renderer_key( $key );
	}

	/**
	 * Map type name aliases to Registry renderer ids.
	 */
	public static function canonical_renderer_key( string $key ): string {
		$key = self::normalize_type_name( $key );
		if ( 'integer' === $key ) {
			$key = 'int';
		}
		if ( 'boolean' === $key ) {
			$key = 'bool';
		}
		if ( 'float' === $key ) {
			$key = 'double';
		}
		static $keys = array(
			'int',
			'char',
			'double',
			'text',
			'textarea',
			'bool',
			'email',
			'date',
			'quantity',
			'media',
			'display_node_name',
			'node_ref',
			'node_embed',
			'enum',
			'table',
		);
		return in_array( $key, $keys, true ) ? $key : '';
	}

	/**
	 * Resolve Registry id for a catalog type term (Q96).
	 * Prefer `builtin.*` binding reverse lookup; leaf name = debt fallback.
	 */
	public static function registry_id_for_type_term( string $taxonomy, int $term_id ): string {
		$term_id = absint( $term_id );
		if ( $term_id <= 0 ) {
			return '';
		}
		if ( class_exists( Catalog_Bindings::class ) ) {
			$from_binding = Catalog_Bindings::registry_id_for_term( $taxonomy, $term_id );
			if ( '' !== $from_binding ) {
				return $from_binding;
			}
		}
		/* Debt: leaf name ↔ Registry id until all installs have builtin.* bindings. */
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}
		$canon = self::canonical_renderer_key( $term->name );
		if ( '' !== $canon ) {
			return $canon;
		}
		return self::normalize_type_name( $term->name );
	}

	/**
	 * Default preferred render for a new node or attribute slot.
	 * Typed scalars → type key (e.g. int); otherwise form.
	 */
	public static function default_preferred_render_for_term( string $taxonomy, int $term_id ): string {
		$canon = self::registry_id_for_type_term( $taxonomy, $term_id );
		if ( '' !== $canon && self::is_scalar_or_known_renderer_key( $canon ) ) {
			return $canon;
		}
		$type_id = self::get_type_id( $term_id );
		if ( $type_id > 0 ) {
			$from_type_term = self::registry_id_for_type_term( $taxonomy, $type_id );
			if ( '' !== $from_type_term && self::is_scalar_or_known_renderer_key( $from_type_term ) ) {
				return $from_type_term;
			}
			$from_type = self::get_preferred_render( $type_id );
			if ( 'form' !== $from_type ) {
				return $from_type;
			}
		}
		return 'form';
	}

	/**
	 * Ensure term has a preferred render meta (default on create / repair).
	 */
	public static function ensure_preferred_render( string $taxonomy, int $term_id ): string {
		if ( $term_id <= 0 ) {
			return 'form';
		}
		if ( metadata_exists( 'term', $term_id, self::META_KEY_PREFERRED_RENDER ) ) {
			return self::get_preferred_render( $term_id );
		}
		$default = self::default_preferred_render_for_term( $taxonomy, $term_id );
		self::set_preferred_render( $taxonomy, $term_id, $default );
		return self::normalize_preferred_render( $default );
	}

	/**
	 * Preferred Object View / admin preview surface for this node.
	 */
	public static function get_preferred_render( int $term_id ): string {
		if ( $term_id <= 0 ) {
			return 'form';
		}
		return self::normalize_preferred_render(
			(string) get_term_meta( $term_id, self::META_KEY_PREFERRED_RENDER, true )
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_preferred_render( string $taxonomy, int $term_id, string $layout ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Node not found.', 'wp-taxonomy-tree' ) );
		}
		$layout = self::normalize_preferred_render( $layout );
		if ( 'form' === $layout ) {
			delete_term_meta( $term_id, self::META_KEY_PREFERRED_RENDER );
		} else {
			update_term_meta( $term_id, self::META_KEY_PREFERRED_RENDER, $layout );
		}
		Tree_Model::touch_modified( $term_id );
		return true;
	}

	public static function is_date_type_name( string $type_name ): bool {
		return 'date' === self::normalize_type_name( $type_name );
	}

	/**
	 * Resolve which term holds date mode: the date type node itself, or the assigned type.
	 */
	public static function resolve_date_config_term_id( string $taxonomy, int $term_id ): int {
		$term = get_term( $term_id, $taxonomy );
		if ( $term instanceof \WP_Term && self::is_date_type_name( $term->name ) ) {
			return $term_id;
		}
		$type_id = self::get_effective_type_id( $taxonomy, $term_id );
		if ( $type_id <= 0 ) {
			return 0;
		}
		$type = get_term( $type_id, $taxonomy );
		if ( $type instanceof \WP_Term && self::is_date_type_name( $type->name ) ) {
			return $type_id;
		}

		return 0;
	}

	/**
	 * Date mode stored on the date catalog term (default `date`).
	 */
	public static function get_date_mode( int $term_id ): string {
		if ( $term_id <= 0 || ! metadata_exists( 'term', $term_id, self::META_KEY_DATE_MODE ) ) {
			return 'date';
		}
		return self::normalize_date_mode( (string) get_term_meta( $term_id, self::META_KEY_DATE_MODE, true ) );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_date_mode( string $taxonomy, int $type_term_id, string $mode ) {
		$term = get_term( $type_term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		if ( ! self::is_date_type_name( $term->name ) ) {
			return new \WP_Error( 'wtt_not_date', __( 'Date settings apply only to the date type.', 'wp-taxonomy-tree' ) );
		}
		$mode = self::normalize_date_mode( $mode );
		update_term_meta( $type_term_id, self::META_KEY_DATE_MODE, $mode );

		return true;
	}

	/**
	 * Full date config for a node (own meta on catalog type, or inherited via type assignment).
	 *
	 * @return array{mode:string}|null
	 */
	public static function get_date_config_for_node( string $taxonomy, int $term_id ): ?array {
		$config_id = self::resolve_date_config_term_id( $taxonomy, $term_id );
		if ( $config_id <= 0 ) {
			return null;
		}
		/* Field-level override: own meta wins when present. */
		if ( metadata_exists( 'term', $term_id, self::META_KEY_DATE_MODE ) ) {
			return array( 'mode' => self::get_date_mode( $term_id ) );
		}

		return array( 'mode' => self::get_date_mode( $config_id ) );
	}

	public static function is_int_type_name( string $type_name ): bool {
		return 'int' === self::normalize_type_name( $type_name );
	}

	/**
	 * Resolve which term holds int format: the int type node itself, or the assigned type.
	 */
	public static function resolve_int_config_term_id( string $taxonomy, int $term_id ): int {
		if ( 'int' === self::registry_id_for_type_term( $taxonomy, $term_id ) ) {
			return $term_id;
		}
		$type_id = self::get_effective_type_id( $taxonomy, $term_id );
		if ( $type_id <= 0 ) {
			return 0;
		}
		if ( 'int' === self::registry_id_for_type_term( $taxonomy, $type_id ) ) {
			return $type_id;
		}

		return 0;
	}

	/**
	 * Normalize preferred converter id (empty when none / invalid).
	 */
	public static function normalize_preferred_converter( string $id ): string {
		return Converter::normalize_id( $id );
	}

	/**
	 * Default preferred converter for a new node (int → arabic; else empty).
	 */
	public static function default_preferred_converter_for_term( string $taxonomy, int $term_id ): string {
		/* Q96: registry_id_for_type_term prefers builtin.*; name = debt fallback. */
		$registry_id = self::registry_id_for_type_term( $taxonomy, $term_id );
		if ( '' !== $registry_id ) {
			$from = Converter::default_for_type( $registry_id );
			if ( '' !== $from ) {
				return $from;
			}
		}
		$type_id = self::get_type_id( $term_id );
		if ( $type_id > 0 && $type_id !== $term_id ) {
			$from_type = Converter::default_for_type(
				self::registry_id_for_type_term( $taxonomy, $type_id )
			);
			if ( '' !== $from_type ) {
				return $from_type;
			}
			$from_pref = self::get_preferred_converter( $type_id );
			if ( '' !== $from_pref ) {
				return $from_pref;
			}
		}
		return '';
	}

	/**
	 * Ensure term has preferred converter meta when a default applies (e.g. int → arabic).
	 */
	public static function ensure_preferred_converter( string $taxonomy, int $term_id ): string {
		if ( $term_id <= 0 ) {
			return '';
		}
		if (
			metadata_exists( 'term', $term_id, self::META_KEY_PREFERRED_CONVERTER )
			|| metadata_exists( 'term', $term_id, self::META_KEY_INT_DISPLAY_FORMAT )
		) {
			return self::get_preferred_converter( $term_id );
		}
		$default = self::default_preferred_converter_for_term( $taxonomy, $term_id );
		if ( '' === $default ) {
			return '';
		}
		self::set_preferred_converter( $taxonomy, $term_id, $default );
		return self::normalize_preferred_converter( $default );
	}

	/**
	 * Preferred value converter for this node (empty when none).
	 * Reads `_wtt_preferred_converter`, falls back to legacy `_wtt_int_display_format`.
	 */
	public static function get_preferred_converter( int $term_id ): string {
		if ( $term_id <= 0 ) {
			return '';
		}
		if ( metadata_exists( 'term', $term_id, self::META_KEY_PREFERRED_CONVERTER ) ) {
			return self::normalize_preferred_converter(
				(string) get_term_meta( $term_id, self::META_KEY_PREFERRED_CONVERTER, true )
			);
		}
		if ( metadata_exists( 'term', $term_id, self::META_KEY_INT_DISPLAY_FORMAT ) ) {
			return self::normalize_preferred_converter(
				(string) get_term_meta( $term_id, self::META_KEY_INT_DISPLAY_FORMAT, true )
			);
		}
		return '';
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_preferred_converter( string $taxonomy, int $term_id, string $converter_id ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Node not found.', 'wp-taxonomy-tree' ) );
		}
		$converter_id = self::normalize_preferred_converter( $converter_id );
		$default      = self::default_preferred_converter_for_term( $taxonomy, $term_id );

		if ( '' === $converter_id ) {
			$converter_id = $default;
		}
		if ( '' === $converter_id ) {
			delete_term_meta( $term_id, self::META_KEY_PREFERRED_CONVERTER );
			delete_term_meta( $term_id, self::META_KEY_INT_DISPLAY_FORMAT );
			Tree_Model::touch_modified( $term_id );
			return true;
		}

		$type_key = self::registry_id_for_type_term( $taxonomy, $term_id );
		if ( '' === $type_key || ! self::is_scalar_or_known_renderer_key( $type_key ) ) {
			$type_id = self::get_effective_type_id( $taxonomy, $term_id );
			if ( $type_id > 0 && $type_id !== $term_id ) {
				$type_key = self::registry_id_for_type_term( $taxonomy, $type_id );
			}
		}
		if ( '' !== $type_key && ! Converter::applies_to_type( $converter_id, $type_key ) ) {
			return new \WP_Error(
				'wtt_converter_mismatch',
				__( 'That converter does not apply to this node’s type.', 'wp-taxonomy-tree' )
			);
		}

		update_term_meta( $term_id, self::META_KEY_PREFERRED_CONVERTER, $converter_id );
		if ( Converter::applies_to_type( $converter_id, 'int' ) ) {
			update_term_meta( $term_id, self::META_KEY_INT_DISPLAY_FORMAT, $converter_id );
		} else {
			delete_term_meta( $term_id, self::META_KEY_INT_DISPLAY_FORMAT );
		}
		Tree_Model::touch_modified( $term_id );
		return true;
	}

	/**
	 * Int display format on the int catalog term (default arabic).
	 * Thin wrapper over preferred converter (legacy API).
	 */
	public static function get_int_display_format( int $term_id ): string {
		$pref = self::get_preferred_converter( $term_id );
		if ( '' !== $pref ) {
			return Int_Value::normalize_format_id( $pref );
		}
		return Int_Value::DEFAULT_FORMAT;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_int_display_format( string $taxonomy, int $type_term_id, string $format_id ) {
		return self::set_preferred_converter( $taxonomy, $type_term_id, $format_id );
	}

	/**
	 * Full int config for a node (own meta on catalog type, or via type assignment).
	 *
	 * @return array{displayFormat:string}|null
	 */
	public static function get_int_config_for_node( string $taxonomy, int $term_id ): ?array {
		$config_id = self::resolve_int_config_term_id( $taxonomy, $term_id );
		if ( $config_id <= 0 ) {
			return null;
		}
		if (
			metadata_exists( 'term', $term_id, self::META_KEY_PREFERRED_CONVERTER )
			|| metadata_exists( 'term', $term_id, self::META_KEY_INT_DISPLAY_FORMAT )
		) {
			return array( 'displayFormat' => self::get_int_display_format( $term_id ) );
		}

		return array( 'displayFormat' => self::get_int_display_format( $config_id ) );
	}

	/**
	 * Resolve canonical type key for validator defaults (catalog leaf or type).
	 * Q96: prefer builtin.* binding reverse lookup over leaf name.
	 */
	public static function resolve_validator_type_key( string $taxonomy, int $term_id ): string {
		$from_self = self::registry_id_for_type_term( $taxonomy, $term_id );
		if ( '' !== $from_self && self::is_scalar_or_known_renderer_key( $from_self ) ) {
			return $from_self;
		}
		$type_id = self::get_effective_type_id( $taxonomy, $term_id );
		if ( $type_id > 0 && $type_id !== $term_id ) {
			$from_type = self::registry_id_for_type_term( $taxonomy, $type_id );
			if ( '' !== $from_type ) {
				return $from_type;
			}
		}
		if ( '' !== $from_self ) {
			return $from_self;
		}
		return '';
	}

	/**
	 * Stored validators only (may be empty).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function get_validators( int $term_id ): array {
		if ( $term_id <= 0 ) {
			return array();
		}
		$raw = get_term_meta( $term_id, self::META_KEY_VALIDATORS, true );
		return Validator::normalize_list( $raw );
	}

	/**
	 * Effective validators for a node (own meta, else type, else type default).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function get_validators_for_node( string $taxonomy, int $term_id ): array {
		$type_key = self::resolve_validator_type_key( $taxonomy, $term_id );
		$own      = self::get_validators( $term_id );
		if ( $own ) {
			return Validator::effective_list( $own, $type_key );
		}
		$type_id = self::get_effective_type_id( $taxonomy, $term_id );
		if ( $type_id > 0 && $type_id !== $term_id ) {
			$from_type = self::get_validators( $type_id );
			if ( $from_type ) {
				return Validator::effective_list( $from_type, $type_key );
			}
		}
		return Validator::default_list_for_type( $type_key );
	}

	/**
	 * Persist validators list.
	 *
	 * @param mixed $raw List or JSON string.
	 * @return true|\WP_Error
	 */
	public static function set_validators( string $taxonomy, int $term_id, $raw ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Node not found.', 'wp-taxonomy-tree' ) );
		}
		$type_key = self::resolve_validator_type_key( $taxonomy, $term_id );
		$list     = Validator::normalize_list( $raw );
		$filtered = array();
		foreach ( $list as $entry ) {
			$id = (string) ( $entry['id'] ?? '' );
			if ( ! Validator::applies_to_type( $id, $type_key ) && 'expression' !== $id ) {
				continue;
			}
			$filtered[] = $entry;
		}
		$filtered = Validator::effective_list( $filtered, $type_key );

		if ( array() === $filtered ) {
			delete_term_meta( $term_id, self::META_KEY_VALIDATORS );
			Tree_Model::touch_modified( $term_id );
			return true;
		}

		$json = wp_json_encode( $filtered );
		if ( false === $json ) {
			return new \WP_Error( 'wtt_validators_encode', __( 'Could not save validators.', 'wp-taxonomy-tree' ) );
		}
		update_term_meta( $term_id, self::META_KEY_VALIDATORS, $json );
		Tree_Model::touch_modified( $term_id );
		return true;
	}

	/**
	 * Ensure default validators exist when meta is missing and a type default applies.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function ensure_validators( string $taxonomy, int $term_id ): array {
		if ( $term_id <= 0 ) {
			return array();
		}
		if ( metadata_exists( 'term', $term_id, self::META_KEY_VALIDATORS ) ) {
			return self::get_validators_for_node( $taxonomy, $term_id );
		}
		$type_key = self::resolve_validator_type_key( $taxonomy, $term_id );
		$defaults = Validator::default_list_for_type( $type_key );
		if ( array() === $defaults ) {
			return array();
		}
		self::set_validators( $taxonomy, $term_id, $defaults );
		return self::get_validators_for_node( $taxonomy, $term_id );
	}

	/**
	 * Preferred converter for a node: own meta, else type default, else empty.
	 */
	public static function get_preferred_converter_for_node( string $taxonomy, int $term_id ): string {
		$own = self::get_preferred_converter( $term_id );
		if ( '' !== $own ) {
			return $own;
		}
		$default = self::default_preferred_converter_for_term( $taxonomy, $term_id );
		if ( '' !== $default ) {
			return $default;
		}
		$type_id = self::get_effective_type_id( $taxonomy, $term_id );
		if ( $type_id > 0 && $type_id !== $term_id ) {
			$from_type = self::get_preferred_converter( $type_id );
			if ( '' !== $from_type ) {
				return $from_type;
			}
		}
		return '';
	}

	/**
	 * Parse a store value to a Unix timestamp (0 when empty/invalid).
	 * Accepts: 4-digit year → Jan 1 that year (site TZ); unix decimal (5+ digits);
	 * compact Ymd; MySQL Y-m-d / Y-m-d H:i:s; strtotime fallback.
	 */
	public static function parse_date_store_value( string $raw ): int {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return 0;
		}
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );

		/* Year-only (not a unix timestamp). */
		if ( preg_match( '/^\d{4}$/', $raw ) ) {
			$y = (int) $raw;
			if ( $y >= 1000 && $y <= 9999 ) {
				$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', sprintf( '%04d-01-01', $y ), $tz );
				if ( $dt instanceof \DateTimeImmutable ) {
					return $dt->getTimestamp();
				}
			}
			return 0;
		}

		/* Compact Ymd. */
		if ( preg_match( '/^\d{8}$/', $raw ) ) {
			$dt = \DateTimeImmutable::createFromFormat( '!Ymd', $raw, $tz );
			if ( $dt instanceof \DateTimeImmutable && $dt->format( 'Ymd' ) === $raw ) {
				return $dt->getTimestamp();
			}
			return 0;
		}

		if ( preg_match( '/^-?\d{5,}$/', $raw ) ) {
			return (int) $raw;
		}

		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $raw, $tz );
		if ( $dt instanceof \DateTimeImmutable ) {
			return $dt->getTimestamp();
		}
		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d', $raw, $tz );
		if ( $dt instanceof \DateTimeImmutable ) {
			return $dt->setTime( 0, 0, 0 )->getTimestamp();
		}
		$ts = strtotime( $raw );
		return false === $ts ? 0 : (int) $ts;
	}

	/**
	 * Format a Unix timestamp for display (site timezone).
	 */
	public static function format_date_store_value( int $timestamp, string $mode = 'date' ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}
		$mode = self::normalize_date_mode( $mode );
		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date(
				'datetime' === $mode ? 'Y-m-d H:i' : 'Y-m-d',
				$timestamp
			);
		}
		return 'datetime' === $mode
			? gmdate( 'Y-m-d H:i', $timestamp )
			: gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Normalize a user/literal value to store SoT (unix timestamp decimal string, or empty).
	 */
	public static function normalize_date_store_value( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		$ts = self::parse_date_store_value( $raw );
		return $ts > 0 ? (string) $ts : '';
	}

	public static function get_fixed_node_id( int $term_id ): int {
		$value = get_term_meta( $term_id, self::META_KEY_FIXED_NODE, true );
		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		return max( 0, (int) $value );
	}

	public static function get_ref_scope_id( int $term_id ): int {
		$value = get_term_meta( $term_id, self::META_KEY_REF_SCOPE, true );
		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		return max( 0, (int) $value );
	}

	/**
	 * @return array{id:int,name:string}|null
	 */
	public static function get_ref_scope_assignment( string $taxonomy, int $term_id ): ?array {
		$scope_id = self::get_ref_scope_id( $term_id );
		if ( $scope_id <= 0 ) {
			return null;
		}
		$term = get_term( $scope_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		return array(
			'id'   => (int) $term->term_id,
			'name' => $term->name,
		);
	}

	public static function clear_ref_scope( int $term_id ): void {
		delete_term_meta( $term_id, self::META_KEY_REF_SCOPE );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_ref_scope_id( string $taxonomy, int $term_id, int $scope_id ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		if ( $scope_id <= 0 ) {
			self::clear_ref_scope( $term_id );
			return true;
		}

		$scope = get_term( $scope_id, $taxonomy );
		if ( ! $scope instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_bad_ref_scope', __( 'Catalog root not found.', 'wp-taxonomy-tree' ) );
		}

		if ( $scope_id === $term_id ) {
			return new \WP_Error( 'wtt_bad_ref_scope', __( 'Catalog root cannot be the slot itself.', 'wp-taxonomy-tree' ) );
		}

		update_term_meta( $term_id, self::META_KEY_REF_SCOPE, $scope_id );
		return true;
	}

	/**
	 * How many targets a node_ref / node_embed field may pick (runtime cell value).
	 * Distinct from Relation-edge multiplicity (Q78) on composition / aggregation.
	 */
	public static function get_field_multiplicity( int $term_id ): string {
		$raw = (string) get_term_meta( $term_id, self::META_KEY_FIELD_MULTIPLICITY, true );
		return Relation::normalize_multiplicity( $raw !== '' ? $raw : '0..1' );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_field_multiplicity( string $taxonomy, int $term_id, string $multiplicity ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		$multiplicity = Relation::normalize_multiplicity( $multiplicity );
		update_term_meta( $term_id, self::META_KEY_FIELD_MULTIPLICITY, $multiplicity );
		return true;
	}

	/**
	 * Allowed direct children of ref_scope (Q73). Empty = all children.
	 *
	 * @return list<int>
	 */
	public static function get_allowed_ref_ids( int $term_id ): array {
		$raw = get_term_meta( $term_id, self::META_KEY_ALLOWED_REF_IDS, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ids = array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	public static function clear_allowed_ref_ids( int $term_id ): void {
		delete_term_meta( $term_id, self::META_KEY_ALLOWED_REF_IDS );
	}

	/**
	 * @param array<int|string> $ids Candidate child term IDs.
	 * @return true|\WP_Error
	 */
	public static function set_allowed_ref_ids( string $taxonomy, int $term_id, array $ids ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		$scope_id = self::get_ref_scope_id( $term_id );
		$clean    = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			$child = get_term( $id, $taxonomy );
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			if ( $scope_id > 0 && (int) $child->parent !== $scope_id ) {
				continue;
			}
			$clean[] = $id;
		}
		$clean = array_values( array_unique( $clean ) );

		if ( empty( $clean ) ) {
			self::clear_allowed_ref_ids( $term_id );
			return true;
		}

		update_term_meta( $term_id, self::META_KEY_ALLOWED_REF_IDS, $clean );
		return true;
	}

	/**
	 * Whether a candidate id is allowed for this slot (empty allowlist = all).
	 */
	public static function is_ref_id_allowed( int $slot_id, int $candidate_id ): bool {
		$allowed = self::get_allowed_ref_ids( $slot_id );
		if ( empty( $allowed ) ) {
			return true;
		}

		return in_array( $candidate_id, $allowed, true );
	}

	/**
	 * Direct children of a catalog root — selectable targets for `subtree`.
	 *
	 * @return array<int, array{id:int,name:string,path:string,shortDescription?:string}>
	 */
	public static function get_subtree_options( string $taxonomy, int $scope_id ): array {
		if ( $scope_id <= 0 ) {
			return array();
		}
		$children = self::get_direct_child_terms( $taxonomy, $scope_id );
		$options  = array();
		foreach ( $children as $child ) {
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			$options[] = array(
				'id'               => (int) $child->term_id,
				'name'             => $child->name,
				'path'             => $child->name,
				'shortDescription' => Tree_Model::get_short_description( (int) $child->term_id ),
			);
		}

		return $options;
	}

	/**
	 * Selectable targets for `node_ref` under a catalog root.
	 * Excludes table band schema (Kopf/Zeile/Fuss) and set composition slots — only records.
	 *
	 * @return array<int, array{id:int,name:string,path:string,shortDescription?:string}>
	 */
	public static function get_node_ref_options( string $taxonomy, int $scope_id ): array {
		if ( $scope_id <= 0 ) {
			return array();
		}
		$options = array();
		$skip    = self::node_ref_schema_skip_ids( $taxonomy, $scope_id );
		self::collect_descendant_options( $taxonomy, $scope_id, '', $options, $skip );
		return $options;
	}

	/**
	 * node_ref options for a slot: descendants under ref_scope, filtered by allowed catalog children (Q73).
	 *
	 * @return array<int, array{id:int,name:string,path:string,shortDescription?:string}>
	 */
	public static function get_node_ref_options_for_slot( string $taxonomy, int $slot_id ): array {
		$scope_id = self::get_ref_scope_id( $slot_id );
		$options  = self::get_node_ref_options( $taxonomy, $scope_id );
		$allowed  = self::get_allowed_ref_ids( $slot_id );
		if ( empty( $allowed ) || empty( $options ) ) {
			return $options;
		}
		$filtered = array();
		foreach ( $options as $opt ) {
			$id = (int) ( $opt['id'] ?? 0 );
			if ( $id > 0 && self::is_ref_candidate_under_allowlist( $taxonomy, $id, $scope_id, $allowed ) ) {
				$filtered[] = $opt;
			}
		}
		return $filtered;
	}

	/**
	 * Whether candidate is an allowlisted catalog child or a descendant thereof.
	 *
	 * @param list<int> $allowed Direct children of scope that are allowed roots.
	 */
	public static function is_ref_candidate_under_allowlist(
		string $taxonomy,
		int $candidate_id,
		int $scope_id,
		array $allowed
	): bool {
		if ( empty( $allowed ) ) {
			return true;
		}
		$cur   = $candidate_id;
		$guard = 0;
		while ( $cur > 0 && $guard++ < 64 ) {
			if ( in_array( $cur, $allowed, true ) ) {
				return true;
			}
			$term = get_term( $cur, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				return false;
			}
			$parent = (int) $term->parent;
			if ( $parent <= 0 || ( $scope_id > 0 && $parent === $scope_id ) ) {
				return false;
			}
			$cur = $parent;
		}
		return false;
	}

	/**
	 * Schema nodes under a catalog root that must not appear as node_ref picks
	 * (table bands + set composition members). Whole subtrees are skipped.
	 *
	 * @return array<int, true>
	 */
	private static function node_ref_schema_skip_ids( string $taxonomy, int $term_id ): array {
		$skip = array();
		if ( $term_id <= 0 ) {
			return $skip;
		}

		if ( self::has_type_named( $taxonomy, $term_id, 'table' )
			|| self::is_table_type_catalog( $taxonomy, $term_id ) ) {
			$bindings = self::get_prop_bindings( $term_id );
			foreach ( array( 'kopf', 'zeile', 'fuss' ) as $key ) {
				$bid = isset( $bindings[ $key ] ) ? (int) $bindings[ $key ] : 0;
				if ( $bid > 0 ) {
					$skip[ $bid ] = true;
				}
			}
		}

		if ( self::is_set_typed( $taxonomy, $term_id ) ) {
			foreach ( self::get_set_members( $taxonomy, $term_id ) as $member ) {
				$mid = (int) ( $member['id'] ?? 0 );
				if ( $mid > 0 ) {
					$skip[ $mid ] = true;
				}
			}
		}

		return $skip;
	}

	/**
	 * @param array<int, array{id:int,name:string,path:string,shortDescription?:string}> $options
	 * @param array<int, true>                                                             $skip_ids
	 */
	private static function collect_descendant_options(
		string $taxonomy,
		int $parent_id,
		string $prefix,
		array &$options,
		array $skip_ids = array()
	): void {
		$children = self::get_direct_child_terms( $taxonomy, $parent_id );
		foreach ( $children as $child ) {
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			$cid = (int) $child->term_id;
			if ( isset( $skip_ids[ $cid ] ) ) {
				continue;
			}
			/*
			 * Nested schema sets (e.g. Abmessung under SMD 0201) have composition
			 * members — not pickable records. Catalog example leaves stay pickable
			 * even when set-typed.
			 */
			if ( self::is_set_typed( $taxonomy, $cid )
				&& ! Demo_Data::is_catalog_example( $cid )
				&& array() !== self::get_set_members( $taxonomy, $cid ) ) {
				continue;
			}
			$path = '' === $prefix ? $child->name : $prefix . ' / ' . $child->name;
			$options[] = array(
				'id'               => $cid,
				'name'             => $child->name,
				'path'             => $path,
				'shortDescription' => Tree_Model::get_short_description( $cid ),
			);
			/* Nested table/set schema under a record kind must stay out of the pick list. */
			$child_skip = $skip_ids + self::node_ref_schema_skip_ids( $taxonomy, $cid );
			self::collect_descendant_options( $taxonomy, $cid, $path, $options, $child_skip );
		}
	}

	public static function is_fixed_enabled( int $term_id ): bool {
		$value = get_term_meta( $term_id, self::META_KEY_FIXED_ENABLED, true );
		if ( '0' === (string) $value || 0 === $value || false === $value ) {
			return false;
		}
		if ( '1' === (string) $value || 1 === $value || true === $value ) {
			return true;
		}

		// Backward compat: node id alone counted as fixed before the radio existed.
		return self::get_fixed_node_id( $term_id ) > 0 || '' !== self::get_fixed_literal( $term_id );
	}

	/**
	 * Whether `_wtt_readonly` is explicitly stored on the term.
	 */
	public static function has_readonly_meta( int $term_id ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		$value = get_term_meta( $term_id, self::META_KEY_READONLY, true );
		return '1' === (string) $value || 1 === $value || true === $value;
	}

	/**
	 * Effective node read-only lock for paint.
	 * Explicit meta wins; attribute slots also treat legacy fixedEnabled as RO (lean migration).
	 */
	public static function is_readonly( int $term_id ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		if ( self::has_readonly_meta( $term_id ) ) {
			return true;
		}
		if ( class_exists( Attribute::class ) && Attribute::is_slot( $term_id ) && self::is_fixed_enabled( $term_id ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Persist node-level read-only. Does not delete legacy fixed* meta.
	 *
	 * @return true|\WP_Error
	 */
	public static function set_readonly( string $taxonomy, int $term_id, bool $readonly ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		if ( $readonly ) {
			update_term_meta( $term_id, self::META_KEY_READONLY, '1' );
		} else {
			delete_term_meta( $term_id, self::META_KEY_READONLY );
		}
		Tree_Model::touch_modified( $term_id );
		return true;
	}

	/**
	 * One-time lean migrate: attribute slot with fixedEnabled → explicit `_wtt_readonly`.
	 * Leaves fixed* meta in place (no mass-delete).
	 */
	public static function maybe_migrate_fixed_lock_to_readonly( int $term_id ): void {
		if ( $term_id <= 0 || self::has_readonly_meta( $term_id ) ) {
			return;
		}
		if ( ! class_exists( Attribute::class ) || ! Attribute::is_slot( $term_id ) ) {
			return;
		}
		if ( ! self::is_fixed_enabled( $term_id ) ) {
			return;
		}
		update_term_meta( $term_id, self::META_KEY_READONLY, '1' );
	}

	public static function get_fixed_literal( int $term_id ): string {
		$value = get_term_meta( $term_id, self::META_KEY_FIXED_LITERAL, true );
		return is_string( $value ) ? $value : ( is_numeric( $value ) ? (string) $value : '' );
	}

	/**
	 * @return array{id:int,name:string,path:string}|null
	 */
	public static function get_fixed_assignment( string $taxonomy, int $term_id ): ?array {
		if ( ! self::is_fixed_enabled( $term_id ) ) {
			return null;
		}

		$literal = self::get_fixed_literal( $term_id );
		if ( '' !== $literal ) {
			return array(
				'id'   => 0,
				'name' => $literal,
				'path' => $literal,
			);
		}

		$fixed_id = self::get_fixed_node_id( $term_id );
		if ( $fixed_id <= 0 ) {
			return null;
		}

		$fixed = get_term( $fixed_id, $taxonomy );
		if ( ! $fixed instanceof \WP_Term ) {
			return null;
		}

		return array(
			'id'   => (int) $fixed->term_id,
			'name' => $fixed->name,
			'path' => self::term_path_from_typen( $taxonomy, (int) $fixed->term_id ),
			'shortDescription' => Tree_Model::get_short_description( (int) $fixed->term_id ),
		);
	}

	private static function clear_fixed_value( int $term_id ): void {
		delete_term_meta( $term_id, self::META_KEY_FIXED_ENABLED );
		delete_term_meta( $term_id, self::META_KEY_FIXED_LITERAL );
		delete_term_meta( $term_id, self::META_KEY_FIXED_NODE );
	}

	/**
	 * Activate fixed value via explicit flag; simple types use literal, catalog types use node id.
	 *
	 * @return true|\WP_Error
	 */
	public static function set_fixed_value( string $taxonomy, int $term_id, bool $enabled, string $literal, int $fixed_node_id ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}

		if ( ! $enabled ) {
			self::clear_fixed_value( $term_id );
			return true;
		}

		$type_id = self::get_effective_type_id( $taxonomy, $term_id );
		$type    = $type_id > 0 ? get_term( $type_id, $taxonomy ) : null;
		$type_name = $type instanceof \WP_Term ? strtolower( $type->name ) : '';

		if ( self::is_display_only_type_name( $type_name ) ) {
			return new \WP_Error(
				'wtt_bad_fixed',
				__( 'display_node_name always shows the node name — fixed value is not used.', 'wp-taxonomy-tree' )
			);
		}

		if ( self::is_simple_type_name( $type_name ) ) {
			$literal = self::normalize_simple_literal( $type_name, $literal );
			if ( '' === $literal ) {
				return new \WP_Error(
					'wtt_bad_fixed',
					__( 'Enter a fixed value, or choose “No fixed value”.', 'wp-taxonomy-tree' )
				);
			}
			if ( 'email' === self::normalize_type_name( $type_name ) && ! self::is_valid_email_value( $literal ) ) {
				return new \WP_Error(
					'wtt_bad_fixed',
					__( 'Enter a valid email address.', 'wp-taxonomy-tree' )
				);
			}
			update_term_meta( $term_id, self::META_KEY_FIXED_ENABLED, '1' );
			update_term_meta( $term_id, self::META_KEY_FIXED_LITERAL, $literal );
			delete_term_meta( $term_id, self::META_KEY_FIXED_NODE );
			return true;
		}

		if ( $fixed_node_id <= 0 ) {
			return new \WP_Error(
				'wtt_bad_fixed',
				__( 'Choose a fixed Typen node, or choose “No fixed value”.', 'wp-taxonomy-tree' )
			);
		}

		$fixed = get_term( $fixed_node_id, $taxonomy );
		if ( ! $fixed instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_bad_fixed', __( 'Fixed value node not found.', 'wp-taxonomy-tree' ) );
		}

		if ( ! self::is_fixed_value_candidate( $taxonomy, $term_id, $fixed_node_id ) ) {
			return new \WP_Error(
				'wtt_bad_fixed',
				__( 'Fixed value must be a valid catalog/Typen node for this field type.', 'wp-taxonomy-tree' )
			);
		}

		update_term_meta( $term_id, self::META_KEY_FIXED_ENABLED, '1' );
		update_term_meta( $term_id, self::META_KEY_FIXED_NODE, $fixed_node_id );
		delete_term_meta( $term_id, self::META_KEY_FIXED_LITERAL );
		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_fixed_node_id( string $taxonomy, int $term_id, int $fixed_node_id ) {
		if ( $fixed_node_id <= 0 ) {
			return self::set_fixed_value( $taxonomy, $term_id, false, '', 0 );
		}

		return self::set_fixed_value( $taxonomy, $term_id, true, '', $fixed_node_id );
	}

	public static function is_simple_type_name( string $type_name ): bool {
		$name = self::normalize_type_name( $type_name );

		return in_array(
			$name,
			array( 'int', 'double', 'text', 'textarea', 'char', 'bool', 'email', 'date', 'quantity', 'display_node_name', 'media' ),
			true
		);
	}

	/**
	 * Whether a string is an acceptable email (empty allowed; use required separately).
	 */
	public static function is_valid_email_value( string $value ): bool {
		$value = trim( $value );
		if ( '' === $value ) {
			return true;
		}
		if ( function_exists( 'is_email' ) ) {
			return false !== is_email( $value );
		}
		return (bool) filter_var( $value, FILTER_VALIDATE_EMAIL );
	}

	public static function is_media_type_name( string $type_name ): bool {
		return 'media' === self::normalize_type_name( $type_name );
	}

	public static function is_display_only_type_name( string $type_name ): bool {
		return 'display_node_name' === self::normalize_type_name( $type_name );
	}

	/**
	 * Canonical type key for pickers / schema (aliases → SoT leaf name).
	 * e.g. datetime→date, measure→quantity, integer→int.
	 */
	public static function normalize_type_key( string $type_name ): string {
		return self::normalize_type_name( $type_name );
	}

	/**
	 * Normalize a type/catalog name to a canonical key (int, bool, …).
	 */
	public static function normalize_type_name( string $type_name ): string {
		$name = strtolower( trim( $type_name ) );
		if ( 'integer' === $name ) {
			return 'int';
		}
		if ( in_array( $name, array( 'float', 'number' ), true ) ) {
			return 'double';
		}
		if ( 'boolean' === $name ) {
			return 'bool';
		}
		if ( in_array( $name, array( 'string', 'varchar' ), true ) ) {
			return 'text';
		}
		if ( in_array( $name, array( 'datetime', 'date_time', 'date-time', 'timestamp' ), true ) ) {
			return 'date';
		}
		if ( in_array( $name, array( 'display node name', 'displayname', 'node_name' ), true ) ) {
			return 'display_node_name';
		}
		/* Informal / DE aliases → quantity (Größe). Not Messung; not BOM Menge. */
		if ( in_array( $name, array( 'measure', 'groesse', 'größe', 'grose' ), true ) ) {
			return 'quantity';
		}

		return $name;
	}

	private static function normalize_simple_literal( string $type_name, string $literal ): string {
		$literal = trim( $literal );
		$name    = self::normalize_type_name( $type_name );
		if ( 'bool' === $name ) {
			return in_array( $literal, array( '1', 'true', 'yes', 'on' ), true ) ? '1' : '0';
		}
		if ( 'int' === $name ) {
			return '' === $literal ? '' : (string) (int) $literal;
		}
		if ( 'char' === $name && '' !== $literal ) {
			return function_exists( 'mb_substr' ) ? mb_substr( $literal, 0, 1 ) : substr( $literal, 0, 1 );
		}
		if ( 'email' === $name ) {
			return strtolower( $literal );
		}
		if ( 'date' === $name ) {
			return self::normalize_date_store_value( $literal );
		}

		return $literal;
	}

	/**
	 * Candidates for fixed-value picker (concrete Typen nodes — units, prefixes, Bauformen values, …).
	 *
	 * @return array<int, array{id:int,name:string,path:string}>
	 */
	public static function get_fixed_picker_options( string $taxonomy, int $context_term_id ): array {
		$options = self::get_picker_options( $taxonomy, $context_term_id );
		$typen_id = self::resolve_typen_root( $taxonomy, $context_term_id );
		if ( $typen_id <= 0 ) {
			return $options;
		}

		$seen = array();
		foreach ( $options as $opt ) {
			$seen[ (int) $opt['id'] ] = true;
		}

		$typen_children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $typen_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $typen_children ) ) {
			return self::filter_fixed_options_by_unit_allowlist( $taxonomy, $context_term_id, $options );
		}

		foreach ( $typen_children as $branch ) {
			if ( ! $branch instanceof \WP_Term ) {
				continue;
			}
			$name = strtolower( $branch->name );
			if ( 'datentypen' === $name || 'basiseinheit' === $name ) {
				// Basiseinheit children already in type picker; Datentypen values are types, not fixed catalogs.
				continue;
			}
			self::add_term_children_as_options( $taxonomy, (int) $branch->term_id, $typen_id, $options, $seen );
		}

		usort(
			$options,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['path'], $b['path'] );
			}
		);

		return self::filter_fixed_options_by_unit_allowlist( $taxonomy, $context_term_id, $options );
	}

	/**
	 * When the node is typed Praefixe and a sibling Einheit is fixed, only allowed prefixes.
	 *
	 * @param array<int, array{id:int,name:string,path:string}> $options
	 * @return array<int, array{id:int,name:string,path:string}>
	 */
	private static function filter_fixed_options_by_unit_allowlist( string $taxonomy, int $context_term_id, array $options ): array {
		$type = self::get_assignment( $taxonomy, $context_term_id );
		if ( null === $type || 'praefixe' !== strtolower( $type['name'] ) ) {
			return $options;
		}

		$unit_id = self::resolve_sibling_fixed_basiseinheit( $taxonomy, $context_term_id );
		if ( $unit_id <= 0 ) {
			return $options;
		}

		$allowed     = self::get_allowed_prefix_ids( $unit_id );
		$allowed_map = array_fill_keys( $allowed, true );
		$filtered    = array();
		foreach ( $options as $opt ) {
			$id = (int) ( $opt['id'] ?? 0 );
			if ( isset( $allowed_map[ $id ] ) ) {
				$filtered[] = $opt;
			}
		}

		return $filtered;
	}

	public static function is_fixed_value_candidate( string $taxonomy, int $context_term_id, int $node_id ): bool {
		$type = self::get_assignment( $taxonomy, $context_term_id );
		$type_name = is_array( $type ) ? strtolower( (string) ( $type['name'] ?? '' ) ) : '';
		if ( 'subtree' === $type_name ) {
			$type_name = 'node_embed';
		}

		/* node_embed / node_ref: fixed = catalog pick under ref_scope (or any node if unset). */
		if ( 'node_embed' === $type_name || 'node_ref' === $type_name ) {
			$scope_id = self::get_ref_scope_id( $context_term_id );
			if ( $scope_id <= 0 ) {
				$term = get_term( $node_id, $taxonomy );
				return $term instanceof \WP_Term;
			}
			if ( (int) $node_id === $scope_id ) {
				return 'node_ref' === $type_name;
			}
			if ( 'node_embed' === $type_name ) {
				$node = get_term( $node_id, $taxonomy );
				return $node instanceof \WP_Term && (int) $node->parent === $scope_id;
			}
			return self::is_descendant_of( $taxonomy, $node_id, $scope_id );
		}

		if ( self::is_typen_type_node( $taxonomy, $context_term_id, $node_id ) ) {
			return true;
		}

		// Values under catalog branches (Praefixe / Bauformen / …) may be fixed constants.
		$typen_id = self::resolve_typen_root( $taxonomy, $context_term_id );
		if ( $typen_id <= 0 || ! self::is_descendant_of( $taxonomy, $node_id, $typen_id ) ) {
			return false;
		}

		$datentypen_id = self::resolve_datentypen_root( $taxonomy, $context_term_id );
		if ( $datentypen_id > 0 && ( (int) $node_id === $datentypen_id || self::is_descendant_of( $taxonomy, $node_id, $datentypen_id ) ) ) {
			return false;
		}

		return true;
	}

	public static function is_assignable_type( string $taxonomy, int $context_term_id, int $type_id ): bool {
		if ( $type_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		if ( $context_term_id > 0 && $type_id === $context_term_id ) {
			return false;
		}
		$type = get_term( $type_id, $taxonomy );
		if ( ! $type instanceof \WP_Term ) {
			return false;
		}
		if ( class_exists( Attribute::class ) && Attribute::is_slot( $type_id ) ) {
			return false;
		}
		if ( class_exists( Trash::class ) && ( Trash::is_trash_node( $type_id ) || Trash::is_trashed( $type_id ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether this term may be deleted. Default true when meta is absent.
	 * Development mode forces true for every node except the Trash / Hidden bins.
	 */
	public static function is_deletable( int $term_id ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		/* Trash bin / Hidden bin are never deletable as terms. */
		if ( class_exists( Trash::class ) && Trash::is_trash_node( $term_id ) ) {
			return false;
		}
		if ( class_exists( Hidden_Nodes::class ) && Hidden_Nodes::is_bin( $term_id ) ) {
			return false;
		}
		if ( Settings::is_development_mode() ) {
			return true;
		}
		if ( ! metadata_exists( 'term', $term_id, self::META_KEY_DELETABLE ) ) {
			return true;
		}
		return '0' !== (string) get_term_meta( $term_id, self::META_KEY_DELETABLE, true );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_deletable( int $term_id, bool $deletable ) {
		if ( $term_id <= 0 ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		update_term_meta( $term_id, self::META_KEY_DELETABLE, $deletable ? '1' : '0' );
		return true;
	}

	/**
	 * Local template / protected-catalog flag (no inherit).
	 */
	public static function is_template( int $term_id ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		return '1' === (string) get_term_meta( $term_id, self::META_KEY_IS_TEMPLATE, true );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_is_template( string $taxonomy, int $term_id, bool $value ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ) );
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		if ( true === $value ) {
			update_term_meta( $term_id, self::META_KEY_IS_TEMPLATE, '1' );
		} else {
			delete_term_meta( $term_id, self::META_KEY_IS_TEMPLATE );
		}
		return true;
	}

	/**
	 * Lock seeded template catalog terms (not under “Eigene Datentypen”) and optional extra ids.
	 * Keys solely on is_template (no is_datatype migrate).
	 *
	 * @param list<int> $extra_ids
	 */
	public static function lock_seeded_catalog_deletable( string $taxonomy, array $extra_ids = array() ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$eigene_ids = array();
		$eigene     = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => 'Eigene Datentypen',
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_array( $eigene ) ) {
			foreach ( $eigene as $term ) {
				if ( $term instanceof \WP_Term ) {
					$eigene_ids[] = (int) $term->term_id;
				}
			}
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $terms ) ) {
			$terms = array();
		}

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$tid = (int) $term->term_id;
			if ( self::is_ancestor_or_self( $taxonomy, $tid, $eigene_ids ) ) {
				continue;
			}
			if ( ! self::is_template( $tid ) ) {
				continue;
			}
			self::set_deletable( $tid, false );
		}

		foreach ( $extra_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				self::set_is_template( $taxonomy, $id, true );
				self::set_deletable( $id, false );
			}
		}
	}

	/**
	 * @param list<int> $ancestor_ids
	 */
	private static function is_ancestor_or_self( string $taxonomy, int $term_id, array $ancestor_ids ): bool {
		if ( empty( $ancestor_ids ) ) {
			return false;
		}
		$current = $term_id;
		$guard   = 0;
		while ( $current > 0 && $guard < 64 ) {
			if ( in_array( $current, $ancestor_ids, true ) ) {
				return true;
			}
			$term = get_term( $current, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				return false;
			}
			$current = (int) $term->parent;
			++$guard;
		}
		return false;
	}

	/**
	 * Display names unique among siblings under the same parent (Q79).
	 * When $parent_id is 0 and $exclude_term_id is set, parent is taken from that term.
	 * Type-branch uniqueness: when under the catalog type anchor, also unique among
	 * non-slot siblings of that parent (same rule).
	 *
	 * @return true|\WP_Error
	 */
	public static function assert_unique_datatype_name(
		string $taxonomy,
		string $name,
		int $exclude_term_id = 0,
		int $parent_id = 0
	) {
		$name = trim( $name );
		if ( '' === $name || ! taxonomy_exists( $taxonomy ) ) {
			return true;
		}
		if ( $parent_id <= 0 && $exclude_term_id > 0 ) {
			$exclude = get_term( $exclude_term_id, $taxonomy );
			if ( $exclude instanceof \WP_Term ) {
				$parent_id = (int) $exclude->parent;
			}
		}
		$needle = strtolower( $name );
		$siblings = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => max( 0, $parent_id ),
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $siblings ) ) {
			return true;
		}
		foreach ( $siblings as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$tid = (int) $term->term_id;
			if ( $exclude_term_id > 0 && $tid === $exclude_term_id ) {
				continue;
			}
			if ( class_exists( Attribute::class ) && Attribute::is_slot( $tid ) ) {
				continue;
			}
			if ( class_exists( Trash::class ) && Trash::is_trashed( $tid ) ) {
				continue;
			}
			if ( strtolower( $term->name ) === $needle ) {
				return new \WP_Error(
					'wtt_duplicate_datatype_name',
					sprintf(
						/* translators: %s: proposed datatype name */
						__( 'Data type name “%s” is already used by another sibling node. Instance nodes may reuse names across parents; siblings should not.', 'wp-taxonomy-tree' ),
						$name
					)
				);
			}
		}
		return true;
	}

	/**
	 * Q92 catalog type-branch root: chooser_focus → data_types → Type/Datentypen name fallback.
	 */
	public static function resolve_type_catalog_root( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}
		if ( class_exists( Catalog_Bindings::class ) ) {
			foreach ( array( Catalog_Bindings::KEY_CHOOSER_FOCUS, Catalog_Bindings::KEY_DATA_TYPES ) as $key ) {
				$id = Catalog_Bindings::resolve( $taxonomy, $key );
				if ( $id > 0 ) {
					return $id;
				}
			}
		}
		$from_name = self::find_any_datentypen_root( $taxonomy );
		if ( $from_name > 0 ) {
			return $from_name;
		}
		return self::find_any_typen_root( $taxonomy );
	}

	/**
	 * True when term is under the type catalog branch (or is the branch root).
	 */
	public static function is_under_type_catalog( string $taxonomy, int $term_id ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		$root = self::resolve_type_catalog_root( $taxonomy );
		if ( $root <= 0 ) {
			return false;
		}
		return $term_id === $root || self::is_descendant_of( $taxonomy, $term_id, $root );
	}

	/**
	 * Forest for the type chooser (Q92): tree under catalog type binding.
	 * Includes non-slot, non-trashed terms — no is_datatype flag.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_datatype_tree( string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$root_id = self::resolve_type_catalog_root( $taxonomy );
		if ( $root_id > 0 ) {
			$root = get_term( $root_id, $taxonomy );
			if ( $root instanceof \WP_Term ) {
				$out = array();
				self::collect_datatype_forest( $taxonomy, $root, $out, true );
				return $out;
			}
		}

		/* Fallback: all non-slot roots (exclude trash/hidden bins). */
		$roots = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => 0,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $roots ) ) {
			return array();
		}

		$out = array();
		foreach ( $roots as $root ) {
			if ( ! $root instanceof \WP_Term ) {
				continue;
			}
			$rid = (int) $root->term_id;
			if ( class_exists( Trash::class ) && Trash::is_trash_node( $rid ) ) {
				continue;
			}
			if ( class_exists( Hidden_Nodes::class ) && Hidden_Nodes::is_bin( $rid ) ) {
				continue;
			}
			self::collect_datatype_forest( $taxonomy, $root, $out, true );
		}
		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $acc
	 * @param bool                             $include_self Include $term itself in $acc (catalog root).
	 */
	private static function collect_datatype_forest( string $taxonomy, \WP_Term $term, array &$acc, bool $include_self = true ): void {
		$term_id = (int) $term->term_id;
		if ( class_exists( Attribute::class ) && Attribute::is_slot( $term_id ) ) {
			return;
		}
		if ( class_exists( Trash::class ) && ( Trash::is_trash_node( $term_id ) || Trash::is_trashed( $term_id ) ) ) {
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
		$child_terms = array();
		if ( is_array( $kids ) ) {
			foreach ( $kids as $kid ) {
				if ( $kid instanceof \WP_Term ) {
					$child_terms[] = $kid;
				}
			}
			usort(
				$child_terms,
				static function ( \WP_Term $a, \WP_Term $b ): int {
					$pa = Tree_Model::get_position( (int) $a->term_id );
					$pb = Tree_Model::get_position( (int) $b->term_id );
					if ( $pa !== $pb ) {
						return $pa <=> $pb;
					}
					return strcasecmp( $a->name, $b->name );
				}
			);
		}

		$children = array();
		foreach ( $child_terms as $kid ) {
			self::collect_datatype_forest( $taxonomy, $kid, $children, true );
		}

		if ( ! $include_self ) {
			foreach ( $children as $child_node ) {
				$acc[] = $child_node;
			}
			return;
		}

		$acc[] = array(
			'id'               => $term_id,
			'name'             => $term->name,
			'description'      => Tree_Model::decode_term_description( (string) $term->description ),
			'shortDescription' => Tree_Model::get_short_description( $term_id ),
			'parent'           => (int) $term->parent,
			'children'         => $children,
			'hasChildren'      => count( $children ) > 0,
		);
	}

	/**
	 * @return bool|null
	 */
	private static function read_tri_state_meta( int $term_id, string $key ): ?bool {
		if ( ! metadata_exists( 'term', $term_id, $key ) ) {
			return null;
		}
		$raw = get_term_meta( $term_id, $key, true );
		if ( '' === $raw || null === $raw || false === $raw ) {
			return null;
		}
		if ( is_string( $raw ) && in_array( strtolower( $raw ), array( '0', 'false', 'no', 'off' ), true ) ) {
			return false;
		}
		return (bool) $raw;
	}

	private static function resolve_inherited_flag( string $taxonomy, int $term_id, string $key ): bool {
		$current = get_term( $term_id, $taxonomy );
		$guard   = 0;
		while ( $current instanceof \WP_Term && $guard++ < 64 ) {
			$local = self::read_tri_state_meta( (int) $current->term_id, $key );
			if ( null !== $local ) {
				return $local;
			}
			if ( ! $current->parent ) {
				break;
			}
			$current = get_term( (int) $current->parent, $taxonomy );
		}
		return false;
	}

	/**
	 * @param bool|null $value
	 * @return true|\WP_Error
	 */
	private static function write_tri_state_meta( string $taxonomy, int $term_id, string $key, ?bool $value ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wtt_not_found', __( 'Term not found.', 'wp-taxonomy-tree' ) );
		}
		if ( null === $value ) {
			delete_term_meta( $term_id, $key );
			return true;
		}
		update_term_meta( $term_id, $key, $value ? '1' : '0' );
		return true;
	}

	/**
	 * Members of a set-typed node (Q75: outgoing composition Relation targets).
	 * Falls back to hierarchy children when no composition edges exist yet.
	 *
	 * @return array<int, array{id:int,name:string,typeId:int,required:bool,type:array{id:int,name:string,path:string}|null}>
	 */
	public static function get_set_members( string $taxonomy, int $term_id ): array {
		if ( ! self::is_set_typed( $taxonomy, $term_id ) ) {
			return array();
		}

		$member_ids = array();
		$via_composition = false;
		foreach ( Relation::list_outgoing_by_type_key( $taxonomy, $term_id, Relation::TYPE_COMPOSITION ) as $edge ) {
			$to_id = (int) ( $edge['toId'] ?? 0 );
			if ( $to_id > 0 ) {
				$member_ids[]    = $to_id;
				$via_composition = true;
			}
		}

		if ( ! $via_composition ) {
			$children = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $term_id,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			$terms = array();
			if ( is_array( $children ) ) {
				foreach ( $children as $child ) {
					if ( $child instanceof \WP_Term ) {
						$terms[] = $child;
					}
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
			foreach ( $terms as $child ) {
				$cid = (int) $child->term_id;
				/* Catalog record leaves are not set composition members. */
				if ( Demo_Data::is_catalog_example( $cid ) ) {
					continue;
				}
				$member_ids[] = $cid;
			}
		}

		$members = array();
		foreach ( $member_ids as $child_id ) {
			$child = get_term( $child_id, $taxonomy );
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			$type_id  = self::get_effective_type_id( $taxonomy, $child_id );
			$unit_qty = self::is_basiseinheit_unit_node( $taxonomy, $type_id );
			$members[] = array(
				'id'             => $child_id,
				'name'           => $child->name,
				'description'    => Tree_Model::decode_term_description( (string) $child->description ),
				'shortDescription' => Tree_Model::get_short_description( $child_id ),
				'typeId'         => $type_id,
				'required'       => self::is_required( $child_id ),
				'type'           => self::get_assignment( $taxonomy, $child_id ),
				'fixedEnabled'   => self::is_fixed_enabled( $child_id ),
				'fixedLiteral'   => self::get_fixed_literal( $child_id ),
				'fixedNodeId'    => self::get_fixed_node_id( $child_id ),
				'fixed'          => self::get_fixed_assignment( $taxonomy, $child_id ),
				/* Unit quantity types use trinity preview — not a filterable type branch. */
				'typeBranch'     => $unit_qty ? null : self::get_type_branch( $taxonomy, $child_id ),
				'quantitySchema' => $unit_qty ? self::get_quantity_schema_for_type( $taxonomy, $type_id ) : null,
				'mediaConfig'    => self::get_media_config_for_node( $taxonomy, $child_id ),
				'dateConfig'     => self::get_date_config_for_node( $taxonomy, $child_id ),
				'refScopeId'     => self::get_ref_scope_id( $child_id ),
				'viaComposition' => $via_composition,
			);
		}

		return $members;
	}

	/**
	 * @var array<string, array{unitId:int,unitName:string,prefixRootToSi:float,members:array<int, array<string, mixed>}>|null>
	 */
	private static $quantity_schema_cache = array();

	/**
	 * When a field’s type is a Basiseinheit unit (Meter, Ohm, …), expose that unit’s
	 * Typ + Praefix + Kuerzel schema so the UI can render the quantity trinity.
	 *
	 * @return array{unitId:int,unitName:string,prefixRootToSi:float,members:array<int, array<string, mixed>}>|null
	 */
	public static function get_quantity_schema_for_type( string $taxonomy, int $type_id ): ?array {
		$cache_key = $taxonomy . ':' . $type_id;
		if ( array_key_exists( $cache_key, self::$quantity_schema_cache ) ) {
			return self::$quantity_schema_cache[ $cache_key ];
		}

		if ( $type_id <= 0 || ! self::is_basiseinheit_unit_node( $taxonomy, $type_id ) ) {
			self::$quantity_schema_cache[ $cache_key ] = null;
			return null;
		}

		$unit = get_term( $type_id, $taxonomy );
		if ( ! $unit instanceof \WP_Term ) {
			self::$quantity_schema_cache[ $cache_key ] = null;
			return null;
		}

		$members = self::get_unit_quantity_members( $taxonomy, $type_id );
		if ( empty( $members ) ) {
			self::$quantity_schema_cache[ $cache_key ] = null;
			return null;
		}

		$result = array(
			'unitId'         => $type_id,
			'unitName'       => $unit->name,
			/* Q51 / Q109 — JS rescale keeps to_si constant without a round-trip. */
			'prefixRootToSi' => self::get_prefix_root_to_si( $type_id ),
			'members'        => $members,
		);
		self::$quantity_schema_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * True when the term is the Complex catalog leaf `quantity` (Größe).
	 */
	public static function is_quantity_type_catalog_node( string $taxonomy, int $term_id ): bool {
		if ( $term_id <= 0 ) {
			return false;
		}
		return 'quantity' === self::registry_id_for_type_term( $taxonomy, $term_id );
	}

	/**
	 * Fake object attributes for quantity catalog preview (Preis-shaped: number + select).
	 * Prefers a real child named Preis; else first child with a scalar + a catalog attr;
	 * else a synthetic Wert + Praefix pair.
	 *
	 * @return array{hostId:int,hostName:string,attributes:list<array<string, mixed>>}
	 */
	public static function get_quantity_preview_example( string $taxonomy, int $quantity_id ): array {
		$empty = array(
			'hostId'     => 0,
			'hostName'   => 'Preis',
			'attributes' => array(),
		);
		if ( $quantity_id <= 0 || ! self::is_quantity_type_catalog_node( $taxonomy, $quantity_id ) ) {
			return $empty;
		}

		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $quantity_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $children ) ) {
			$children = array();
		}

		$prefer = null;
		$fallback = null;
		foreach ( $children as $child ) {
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			$attrs = Attribute::list( $taxonomy, (int) $child->term_id );
			if ( array() === $attrs ) {
				continue;
			}
			if ( 'preis' === strtolower( (string) $child->name ) ) {
				$prefer = array( $child, $attrs );
				break;
			}
			if ( null === $fallback && self::attrs_look_like_quantity_example( $attrs ) ) {
				$fallback = array( $child, $attrs );
			}
		}
		$pick = $prefer ?? $fallback;
		if ( null !== $pick ) {
			/** @var \WP_Term $host */
			$host = $pick[0];
			/** @var list<array<string, mixed>> $attrs */
			$attrs = $pick[1];
			return array(
				'hostId'     => (int) $host->term_id,
				'hostName'   => (string) $host->name,
				'attributes' => $attrs,
			);
		}

		return array(
			'hostId'     => 0,
			'hostName'   => 'Preis',
			'attributes' => self::synthesize_quantity_example_attributes( $taxonomy, $quantity_id ),
		);
	}

	/**
	 * @param list<array<string, mixed>> $attrs Attribute rows.
	 */
	private static function attrs_look_like_quantity_example( array $attrs ): bool {
		$has_number = false;
		$has_choice = false;
		foreach ( $attrs as $row ) {
			$key = strtolower( (string) ( $row['typeKey'] ?? '' ) );
			if ( in_array( $key, array( 'double', 'int', 'quantity' ), true ) ) {
				$has_number = true;
			}
			if ( 'catalog' === (string) ( $row['fixedMode'] ?? '' ) ) {
				$has_choice = true;
			}
		}
		return $has_number && $has_choice;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function synthesize_quantity_example_attributes( string $taxonomy, int $quantity_id ): array {
		$prefix_root = self::find_prefixes_root( $taxonomy, $quantity_id );
		$options     = array();
		if ( $prefix_root > 0 ) {
			$branch = self::build_type_branch( $taxonomy, $prefix_root, array() );
			if ( is_array( $branch ) && isset( $branch['children'] ) && is_array( $branch['children'] ) ) {
				foreach ( $branch['children'] as $child ) {
					if ( ! is_array( $child ) ) {
						continue;
					}
					$id   = (int) ( $child['id'] ?? 0 );
					$name = (string) ( $child['name'] ?? '' );
					if ( $id <= 0 || '' === $name ) {
						continue;
					}
					if ( array_key_exists( 'enabled', $child ) && ! $child['enabled'] ) {
						continue;
					}
					$options[] = array(
						'id'   => $id,
						'name' => $name,
						'path' => $name,
					);
				}
			}
		}

		$wert = array(
			'id'           => -1,
			'name'         => 'Wert',
			'typeId'       => 0,
			'typeKey'      => 'double',
			'typeName'     => 'double',
			'multiplicity' => '1',
			'fixedMode'    => 'literal',
			'fixedOptions' => array(),
			'quantitySchema' => null,
		);

		$praefix = array(
			'id'           => -2,
			'name'         => 'Praefix',
			'typeId'       => $prefix_root,
			'typeKey'      => 'praefixe',
			'typeName'     => 'Praefixe',
			'multiplicity' => '1',
			'fixedMode'    => array() !== $options ? 'catalog' : 'literal',
			'fixedOptions' => $options,
			'choiceDepth'  => Attribute::choice_depth_from_options( $options ),
			'quantitySchema' => null,
		);

		return array( $wert, $praefix );
	}

	/**
	 * Schema members of a Basiseinheit unit (Typ / Praefix / Kuerzel) — no nested quantitySchema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_unit_quantity_members( string $taxonomy, int $unit_term_id ): array {
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $unit_term_id,
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

		$members = array();
		foreach ( $terms as $child ) {
			$child_id = (int) $child->term_id;
			$members[] = array(
				'id'           => $child_id,
				'name'         => $child->name,
				'description'  => Tree_Model::decode_term_description( (string) $child->description ),
				'typeId'       => self::get_type_id( $child_id ),
				'required'     => self::is_required( $child_id ),
				'type'         => self::get_assignment( $taxonomy, $child_id ),
				'fixedEnabled' => self::is_fixed_enabled( $child_id ),
				'fixedLiteral' => self::get_fixed_literal( $child_id ),
				'fixedNodeId'  => self::get_fixed_node_id( $child_id ),
				'fixed'        => self::get_fixed_assignment( $taxonomy, $child_id ),
				'typeBranch'   => self::get_type_branch( $taxonomy, $child_id ),
			);
		}

		if ( array() === $members ) {
			/*
			 * Fallstudie gold: Basiseinheit units are leaves (symbol in shortDescription).
			 * Synthesize Typ + Praefix + Kuerzel so QuantityRenderer can paint the trinity.
			 */
			return self::synthesize_unit_quantity_members( $taxonomy, $unit_term_id );
		}

		return $members;
	}

	/**
	 * Synthetic Typ / Praefix / Kuerzel for leaf Basiseinheit units (no set children).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function synthesize_unit_quantity_members( string $taxonomy, int $unit_term_id ): array {
		$unit = get_term( $unit_term_id, $taxonomy );
		if ( ! $unit instanceof \WP_Term ) {
			return array();
		}

		$symbol = Tree_Model::get_short_description( $unit_term_id );
		$symbol = is_string( $symbol ) ? trim( $symbol ) : '';

		$double_id   = 0;
		$double_term = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => 'double',
				'hide_empty' => false,
				'number'     => 1,
			)
		);
		if ( is_array( $double_term ) && isset( $double_term[0] ) && $double_term[0] instanceof \WP_Term ) {
			$double_id = (int) $double_term[0]->term_id;
		}

		$prefix_root = self::find_prefixes_root( $taxonomy, $unit_term_id );
		$prefix_branch = null;
		if ( $prefix_root > 0 ) {
			$prefix_branch = self::build_type_branch( $taxonomy, $prefix_root, array() );
			if ( null !== $prefix_branch ) {
				$prefix_branch = self::apply_unit_prefix_filter_to_branch(
					$taxonomy,
					$unit_term_id,
					$prefix_branch
				);
			}
		}

		$members = array(
			array(
				'id'           => 0,
				'name'         => 'Typ',
				'description'  => '',
				'typeId'       => $double_id,
				'required'     => true,
				'type'         => array(
					'id'   => $double_id,
					'name' => 'double',
				),
				'fixedEnabled' => false,
				'fixedLiteral' => '',
				'fixedNodeId'  => 0,
				'fixed'        => null,
				'typeBranch'   => null,
			),
		);

		if ( null !== $prefix_branch ) {
			$members[] = array(
				'id'           => 0,
				'name'         => 'Praefix',
				'description'  => '',
				'typeId'       => $prefix_root,
				'required'     => false,
				'type'         => array(
					'id'   => $prefix_root,
					'name' => (string) ( $prefix_branch['typeName'] ?? 'Praefixe' ),
				),
				'fixedEnabled' => false,
				'fixedLiteral' => '',
				'fixedNodeId'  => 0,
				'fixed'        => null,
				'typeBranch'   => $prefix_branch,
			);
		}

		$members[] = array(
			'id'           => 0,
			'name'         => 'Kuerzel',
			'description'  => '',
			'typeId'       => 0,
			'required'     => true,
			'type'         => array(
				'id'   => 0,
				'name' => 'text',
			),
			'fixedEnabled' => '' !== $symbol,
			'fixedLiteral' => $symbol,
			'fixedNodeId'  => 0,
			'fixed'        => null,
			'typeBranch'   => null,
		);

		return $members;
	}

	/**
	 * @return array{id:int,name:string}|null
	 */
	public static function get_set_parent( string $taxonomy, int $term_id ): ?array {
		// Q75: prefer incoming composition Relation from a set-typed node.
		foreach ( Relation::list_incoming( $taxonomy, $term_id ) as $edge ) {
			$key = strtolower( (string) ( $edge['typeKey'] ?? $edge['typeName'] ?? '' ) );
			if ( ! Relation::type_keys_match( $key, Relation::TYPE_COMPOSITION ) ) {
				continue;
			}
			$parent_id = (int) ( $edge['fromId'] ?? 0 );
			if ( $parent_id <= 0 || ! self::is_set_typed( $taxonomy, $parent_id ) ) {
				continue;
			}
			$parent = get_term( $parent_id, $taxonomy );
			if ( $parent instanceof \WP_Term ) {
				return array(
					'id'   => $parent_id,
					'name' => $parent->name,
				);
			}
		}

		// Legacy fallback: hierarchy parent when it is set-typed.
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || ! $term->parent ) {
			return null;
		}

		$parent_id = (int) $term->parent;
		if ( ! self::is_set_typed( $taxonomy, $parent_id ) ) {
			return null;
		}

		$parent = get_term( $parent_id, $taxonomy );
		if ( ! $parent instanceof \WP_Term ) {
			return null;
		}

		return array(
			'id'   => $parent_id,
			'name' => $parent->name,
		);
	}

	public static function has_type_named( string $taxonomy, int $term_id, string $type_name ): bool {
		$type_id = self::get_effective_type_id( $taxonomy, $term_id );
		if ( $type_id <= 0 ) {
			return false;
		}

		$type = get_term( $type_id, $taxonomy );
		if ( ! $type instanceof \WP_Term ) {
			return false;
		}

		return strtolower( $type->name ) === strtolower( $type_name );
	}

	/**
	 * True when the node is typed as Collection `set` or a concrete set schema (e.g. Abmessung).
	 */
	public static function is_set_typed( string $taxonomy, int $term_id ): bool {
		if ( self::has_type_named( $taxonomy, $term_id, 'set' ) ) {
			return true;
		}

		$type_id = self::get_effective_type_id( $taxonomy, $term_id );
		if ( $type_id <= 0 ) {
			return false;
		}

		// Concrete set schemas live under Complex and are themselves typed as `set`.
		return self::has_type_named( $taxonomy, $type_id, 'set' );
	}

	/**
	 * Direct children for help popovers (name, type, fixed, required, description).
	 * Set-typed children include one nested level of their members.
	 *
	 * @return array<int, array{name:string,description:string,typeName:string,fixed:string,required:bool,children?:array<int, array<string, mixed>>}>
	 */
	public static function get_help_children( string $taxonomy, int $term_id, int $depth = 0 ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$sorted = array();
		foreach ( $terms as $child ) {
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

		$list = array();
		foreach ( $sorted as $child ) {
			$child_id = (int) $child->term_id;
			$type     = self::get_assignment( $taxonomy, $child_id );
			$fixed    = self::get_fixed_assignment( $taxonomy, $child_id );
			$entry    = array(
				'name'        => $child->name,
				'description' => Tree_Model::decode_term_description( (string) $child->description ),
				'shortDescription' => Tree_Model::get_short_description( $child_id ),
				'typeName'    => $type['name'] ?? '',
				'fixed'       => $fixed['name'] ?? '',
				'required'    => self::is_required( $child_id ),
			);
			if ( $depth < 1 && self::is_set_typed( $taxonomy, $child_id ) ) {
				$nested = self::get_help_children( $taxonomy, $child_id, $depth + 1 );
				if ( ! empty( $nested ) ) {
					$entry['children'] = $nested;
				}
			}
			$list[] = $entry;
		}

		return $list;
	}

	/**
	 * Flat list for the detail-panel (assignable type nodes in Q92 chooser scope).
	 *
	 * @return array<int, array{id:int,name:string,path:string}>
	 */
	public static function get_picker_options( string $taxonomy, int $context_term_id ): array {
		unset( $context_term_id );
		$options = array();
		$seen    = array();
		self::flatten_assignable_datatype_options( $taxonomy, self::get_datatype_tree( $taxonomy ), array(), $options, $seen );
		return $options;
	}

	/**
	 * @param array<int, array<string, mixed>>             $nodes
	 * @param array<int, string>                           $path_parts
	 * @param array<int, array{id:int,name:string,path:string}> $options
	 * @param array<int, true>                             $seen
	 */
	private static function flatten_assignable_datatype_options(
		string $taxonomy,
		array $nodes,
		array $path_parts,
		array &$options,
		array &$seen
	): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || empty( $node['id'] ) ) {
				continue;
			}
			$id   = (int) $node['id'];
			$name = isset( $node['name'] ) ? (string) $node['name'] : '';
			$next = array_merge( $path_parts, array( $name ) );
			if ( empty( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$options[]   = array(
					'id'   => $id,
					'name' => $name,
					'path' => implode( ' / ', $next ),
					'shortDescription' => Tree_Model::get_short_description( $id ),
				);
			}
			$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
			if ( $children ) {
				self::flatten_assignable_datatype_options( $taxonomy, $children, $next, $options, $seen );
			}
		}
	}

	/**
	 * Resolve a type term by name under the Typen branch (for demo seeding).
	 */
	public static function find_type_by_name( string $taxonomy, int $context_term_id, string $type_name ): int {
		$type_name = trim( $type_name );
		if ( '' === $type_name ) {
			return 0;
		}

		$aliases = array( $type_name );
		$key     = strtolower( $type_name );
		if ( 'node_embed' === $key ) {
			$aliases[] = 'subtree';
		} elseif ( 'subtree' === $key ) {
			$aliases[] = 'node_embed';
		} elseif ( 'praefixe' === $key || 'präfixe' === $key ) {
			$aliases[] = 'Praefixe';
			$aliases[] = 'Präfixe';
		} elseif ( 'basiseinheit' === $key || 'basiseinheiten' === $key ) {
			$aliases[] = 'Basiseinheit';
			$aliases[] = 'Basiseinheiten';
		} elseif ( in_array( $key, array( 'measure', 'groesse', 'größe', 'grose', 'quantity' ), true ) ) {
			$aliases[] = 'quantity';
			$aliases[] = 'measure';
		}

		foreach ( $aliases as $alias ) {
			$found = self::find_type_by_exact_name( $taxonomy, $context_term_id, $alias );
			if ( $found > 0 ) {
				return $found;
			}
		}

		return 0;
	}

	/**
	 * @internal Exact name match under Typen (no aliases).
	 */
	private static function find_type_by_exact_name( string $taxonomy, int $context_term_id, string $type_name ): int {
		$matches = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => $type_name,
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( ! is_array( $matches ) ) {
			return 0;
		}

		foreach ( $matches as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$type_id = (int) $term->term_id;
			if ( self::is_assignable_type( $taxonomy, $context_term_id, $type_id ) ) {
				return $type_id;
			}
			if ( self::is_typen_type_node( $taxonomy, $context_term_id, $type_id ) ) {
				return $type_id;
			}
		}

		return 0;
	}

	/**
	 * Resolve a fixed-value catalog node by name (e.g. Praefix "m"/"Milli", Basiseinheit "Ohm").
	 * Also matches Präfixe short_description (letter symbol) when display names were renamed.
	 */
	public static function find_fixed_by_name( string $taxonomy, int $context_term_id, string $name ): int {
		$name = trim( $name );
		if ( '' === $name ) {
			return 0;
		}

		$matches = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => $name,
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( is_array( $matches ) ) {
			foreach ( $matches as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$node_id = (int) $term->term_id;
				if ( self::is_fixed_value_candidate( $taxonomy, $context_term_id, $node_id ) ) {
					return $node_id;
				}
			}
		}

		$prefixes_root = self::find_prefixes_root( $taxonomy, $context_term_id );
		if ( $prefixes_root <= 0 ) {
			return 0;
		}

		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $prefixes_root,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( ! is_array( $children ) ) {
			return 0;
		}

		$needle_slug = sanitize_title( $name );
		foreach ( $children as $child ) {
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}
			$short = Tree_Model::get_short_description( (int) $child->term_id );
			if ( $name === $short || $needle_slug === (string) $child->slug ) {
				if ( self::is_fixed_value_candidate( $taxonomy, $context_term_id, (int) $child->term_id ) ) {
					return (int) $child->term_id;
				}
			}
		}

		return 0;
	}

	public static function term_path_from_typen( string $taxonomy, int $term_id ): string {
		$typen_id = self::resolve_typen_root( $taxonomy, $term_id );
		if ( $typen_id <= 0 ) {
			return self::term_breadcrumb( $taxonomy, $term_id );
		}

		$parts   = array();
		$current = get_term( $term_id, $taxonomy );
		$guard   = 0;

		while ( $current instanceof \WP_Term && $guard++ < 64 ) {
			array_unshift( $parts, $current->name );
			if ( (int) $current->term_id === $typen_id ) {
				break;
			}
			if ( ! $current->parent ) {
				break;
			}
			$current = get_term( (int) $current->parent, $taxonomy );
		}

		return implode( ' / ', $parts );
	}

	/** @deprecated Use term_path_from_typen(). */
	public static function term_path_from_datentypen( string $taxonomy, int $term_id ): string {
		return self::term_path_from_typen( $taxonomy, $term_id );
	}

	private static function is_typen_type_node( string $taxonomy, int $context_term_id, int $type_id ): bool {
		$typen_id = self::resolve_typen_root( $taxonomy, $context_term_id );
		if ( $typen_id <= 0 ) {
			return false;
		}

		if ( ! self::is_descendant_of( $taxonomy, $type_id, $typen_id ) && (int) $type_id !== $typen_id ) {
			return false;
		}

		if ( (int) $type_id === $typen_id ) {
			return false;
		}

		$datentypen_id = self::resolve_datentypen_root( $taxonomy, $context_term_id );
		if ( $datentypen_id > 0 && self::is_descendant_of( $taxonomy, $type_id, $datentypen_id ) ) {
			if ( (int) $type_id === $datentypen_id ) {
				return false;
			}
			$blocked = array( 'Simple', 'Complex', 'Collection' );
			$type    = get_term( $type_id, $taxonomy );
			if ( $type instanceof \WP_Term && in_array( $type->name, $blocked, true ) ) {
				return false;
			}
			return true;
		}

		$prefixes_id = self::find_prefixes_root( $taxonomy, $context_term_id );
		if ( $prefixes_id > 0 && (int) $type_id === $prefixes_id ) {
			return true;
		}

		$base_units_id = self::find_base_units_root( $taxonomy, $context_term_id );
		if ( $base_units_id > 0 ) {
			if ( (int) $type_id === $base_units_id ) {
				return true;
			}
			if ( self::is_descendant_of( $taxonomy, $type_id, $base_units_id ) ) {
				return true;
			}
		}

		// Custom branch under Typen (e.g. Bauformen) — assignable like Praefixe.
		$type = get_term( $type_id, $taxonomy );
		if ( $type instanceof \WP_Term && (int) $type->parent === $typen_id ) {
			if ( 'datentypen' !== strtolower( $type->name ) ) {
				return true;
			}
		}

		return false;
	}

	private static function term_breadcrumb( string $taxonomy, int $term_id ): string {
		$parts   = array();
		$current = get_term( $term_id, $taxonomy );
		$guard   = 0;

		while ( $current instanceof \WP_Term && $guard++ < 64 ) {
			array_unshift( $parts, $current->name );
			if ( ! $current->parent ) {
				break;
			}
			$current = get_term( (int) $current->parent, $taxonomy );
		}

		return implode( ' / ', $parts );
	}

	private static function resolve_datentypen_root( string $taxonomy, int $context_term_id ): int {
		$from_context = self::find_datentypen_from_term( $taxonomy, $context_term_id );
		if ( $from_context > 0 ) {
			return $from_context;
		}

		return self::find_any_datentypen_root( $taxonomy );
	}

	private static function resolve_typen_root( string $taxonomy, int $context_term_id ): int {
		$project_root = self::find_project_root( $taxonomy, $context_term_id );
		if ( $project_root <= 0 ) {
			return self::find_any_typen_root( $taxonomy );
		}

		$typen_id = self::find_named_child( $taxonomy, $project_root, 'Typen' );
		if ( $typen_id > 0 ) {
			return $typen_id;
		}
		/* Fallstudie uses Definition as the type/constant catalog root. */
		$definition_id = self::find_named_child( $taxonomy, $project_root, 'Definition' );
		if ( $definition_id > 0 ) {
			return $definition_id;
		}

		return self::find_any_typen_root( $taxonomy );
	}

	private static function find_prefixes_root( string $taxonomy, int $context_term_id ): int {
		$typen_id = self::resolve_typen_root( $taxonomy, $context_term_id );
		if ( $typen_id <= 0 ) {
			return 0;
		}

		/* Demo: Typen/Praefixe — Fallstudie: Definition/Konstanten/Präfixe. */
		$direct = self::find_named_child_any(
			$taxonomy,
			$typen_id,
			array( 'Praefixe', 'Präfixe' )
		);
		if ( $direct > 0 ) {
			return $direct;
		}

		$konstanten = self::find_named_child( $taxonomy, $typen_id, 'Konstanten' );
		if ( $konstanten <= 0 ) {
			return 0;
		}

		return self::find_named_child_any(
			$taxonomy,
			$konstanten,
			array( 'Präfixe', 'Praefixe' )
		);
	}

	private static function find_base_units_root( string $taxonomy, int $context_term_id ): int {
		$typen_id = self::resolve_typen_root( $taxonomy, $context_term_id );
		if ( $typen_id <= 0 ) {
			return 0;
		}

		/* Demo: Typen/Basiseinheit — Fallstudie: Definition/Konstanten/Basiseinheiten. */
		$direct = self::find_named_child_any(
			$taxonomy,
			$typen_id,
			array( 'Basiseinheit', 'Basiseinheiten' )
		);
		if ( $direct > 0 ) {
			return $direct;
		}

		$konstanten = self::find_named_child( $taxonomy, $typen_id, 'Konstanten' );
		if ( $konstanten <= 0 ) {
			return 0;
		}

		return self::find_named_child_any(
			$taxonomy,
			$konstanten,
			array( 'Basiseinheiten', 'Basiseinheit' )
		);
	}

	private static function find_any_typen_root( string $taxonomy ): int {
		$candidates = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => 'Typen',
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( ! is_array( $candidates ) || empty( $candidates ) ) {
			return 0;
		}

		$first = $candidates[0];
		return $first instanceof \WP_Term ? (int) $first->term_id : 0;
	}

	private static function find_datentypen_from_term( string $taxonomy, int $term_id ): int {
		$project_root = self::find_project_root( $taxonomy, $term_id );
		if ( $project_root <= 0 ) {
			return 0;
		}

		$typen_id = self::find_named_child( $taxonomy, $project_root, 'Typen' );
		if ( $typen_id <= 0 ) {
			return 0;
		}

		return self::find_named_child( $taxonomy, $typen_id, 'Datentypen' );
	}

	private static function find_any_datentypen_root( string $taxonomy ): int {
		$candidates = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => 'Datentypen',
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( ! is_array( $candidates ) ) {
			return 0;
		}

		foreach ( $candidates as $candidate ) {
			if ( ! $candidate instanceof \WP_Term ) {
				continue;
			}
			$parent = get_term( (int) $candidate->parent, $taxonomy );
			if ( $parent instanceof \WP_Term && 'Typen' === $parent->name ) {
				return (int) $candidate->term_id;
			}
		}

		return 0;
	}

	private static function find_project_root( string $taxonomy, int $term_id ): int {
		$current = get_term( $term_id, $taxonomy );
		$guard   = 0;

		while ( $current instanceof \WP_Term && $guard++ < 64 ) {
			if ( ! $current->parent ) {
				return (int) $current->term_id;
			}
			$current = get_term( (int) $current->parent, $taxonomy );
		}

		return 0;
	}

	private static function find_named_child( string $taxonomy, int $parent_id, string $name ): int {
		return self::find_named_child_any( $taxonomy, $parent_id, array( $name ) );
	}

	/**
	 * First matching direct child by exact name (any of $names).
	 *
	 * @param array<int, string> $names
	 */
	private static function find_named_child_any( string $taxonomy, int $parent_id, array $names ): int {
		$wanted = array();
		foreach ( $names as $name ) {
			$name = (string) $name;
			if ( '' !== $name ) {
				$wanted[ $name ] = true;
			}
		}
		if ( empty( $wanted ) ) {
			return 0;
		}

		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( ! is_array( $children ) ) {
			return 0;
		}

		foreach ( $children as $child ) {
			if ( $child instanceof \WP_Term && isset( $wanted[ $child->name ] ) ) {
				return (int) $child->term_id;
			}
		}

		return 0;
	}

	private static function is_descendant_of( string $taxonomy, int $term_id, int $ancestor_id ): bool {
		if ( $term_id === $ancestor_id ) {
			return true;
		}

		$current = get_term( $term_id, $taxonomy );
		$guard   = 0;

		while ( $current instanceof \WP_Term && $guard++ < 64 ) {
			if ( (int) $current->parent === $ancestor_id ) {
				return true;
			}
			if ( ! $current->parent ) {
				return false;
			}
			$current = get_term( (int) $current->parent, $taxonomy );
		}

		return false;
	}

	/**
	 * @param array<int, array{id:int,name:string,path:string}> $options
	 * @param array<int, true>                                  $seen
	 */
	private static function add_term_children_as_options(
		string $taxonomy,
		int $parent_id,
		int $typen_id,
		array &$options,
		array &$seen
	): void {
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( ! is_array( $children ) ) {
			return;
		}

		foreach ( $children as $child ) {
			if ( $child instanceof \WP_Term ) {
				self::push_option( $taxonomy, $child, $typen_id, $options, $seen );
			}
		}
	}

	/**
	 * Collection kinds (list/table/enum/set) and their concrete types.
	 *
	 * @param array<int, array{id:int,name:string,path:string}> $options
	 * @param array<int, true>                                  $seen
	 */
	private static function add_collection_kind_options(
		string $taxonomy,
		int $collection_id,
		int $typen_id,
		array &$options,
		array &$seen
	): void {
		$kinds = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $collection_id,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( ! is_array( $kinds ) ) {
			return;
		}

		foreach ( $kinds as $kind ) {
			if ( ! $kind instanceof \WP_Term ) {
				continue;
			}
			self::push_option( $taxonomy, $kind, $typen_id, $options, $seen );
			self::add_term_children_as_options( $taxonomy, (int) $kind->term_id, $typen_id, $options, $seen );
		}
	}

	/**
	 * @param array<int, array{id:int,name:string,path:string}> $options
	 * @param array<int, true>                                  $seen
	 */
	private static function push_option(
		string $taxonomy,
		\WP_Term $term,
		int $typen_id,
		array &$options,
		array &$seen
	): void {
		$id = (int) $term->term_id;
		if ( isset( $seen[ $id ] ) ) {
			return;
		}

		$seen[ $id ] = true;
		$options[]   = array(
			'id'   => $id,
			'name' => $term->name,
			'path' => self::term_path_from_typen( $taxonomy, $id ),
			'shortDescription' => Tree_Model::get_short_description( $id ),
		);
	}
}
