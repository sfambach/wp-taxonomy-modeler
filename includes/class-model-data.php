<?php
/**
 * Instance data store for Fill Model Data (scaffold).
 *
 * Taxonomy terms define structures (hosts + attributes). This service stores
 * filled instances separately — attributes are not the instances.
 *
 * Persistence: one WP option keyed by taxonomy + structure term id.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for structure-bound instance rows (JSON option).
 */
final class Model_Data {

	/** Option holding all instance bags. */
	public const OPTION_KEY = 'wtt_model_instances';

	/**
	 * Bag key for a structure host.
	 */
	public static function bag_key( string $taxonomy, int $structure_id ): string {
		return sanitize_key( $taxonomy ) . ':' . absint( $structure_id );
	}

	/**
	 * List instances for a structure node (newest first).
	 *
	 * @return list<array{id:string,name:string,values:array<string,string>,updatedAt:string}>
	 */
	public static function list( string $taxonomy, int $structure_id ): array {
		if ( $structure_id <= 0 || ! Taxonomy::is_scaffold( $taxonomy ) ) {
			return array();
		}

		$all = self::load_all();
		$key = self::bag_key( $taxonomy, $structure_id );
		$bag = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();

		$out = array();
		foreach ( $bag as $row ) {
			$normalized = self::normalize_row( $row );
			if ( null !== $normalized ) {
				$out[] = $normalized;
			}
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				return strcmp( (string) ( $b['updatedAt'] ?? '' ), (string) ( $a['updatedAt'] ?? '' ) );
			}
		);

		return $out;
	}

	/**
	 * @return array{id:string,name:string,values:array<string,string>,updatedAt:string}|null
	 */
	public static function get( string $taxonomy, int $structure_id, string $instance_id ): ?array {
		$instance_id = sanitize_key( $instance_id );
		if ( '' === $instance_id ) {
			return null;
		}
		foreach ( self::list( $taxonomy, $structure_id ) as $row ) {
			if ( $row['id'] === $instance_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Create or update an instance.
	 *
	 * @param array<string, mixed> $payload id?, name, values (attr id → string).
	 * @return array{id:string,name:string,values:array<string,string>,updatedAt:string}|\WP_Error
	 */
	public static function save( string $taxonomy, int $structure_id, array $payload ) {
		if ( ! Taxonomy::is_scaffold( $taxonomy ) || $structure_id <= 0 ) {
			return new \WP_Error( 'wtt_bad_structure', __( 'Invalid structure node.', 'wp-taxonomy-tree' ) );
		}

		$term = get_term( $structure_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
			return new \WP_Error( 'wtt_bad_structure', __( 'Structure node not found.', 'wp-taxonomy-tree' ) );
		}

		$name = isset( $payload['name'] ) ? sanitize_text_field( (string) $payload['name'] ) : '';
		if ( '' === $name ) {
			return new \WP_Error( 'wtt_name_required', __( 'Instance name is required.', 'wp-taxonomy-tree' ) );
		}

		$allowed_attrs = self::allowed_attribute_ids( $taxonomy, $structure_id );
		$raw_values    = isset( $payload['values'] ) && is_array( $payload['values'] ) ? $payload['values'] : array();
		$values        = self::sanitize_values( $raw_values, $allowed_attrs );

		$id = isset( $payload['id'] ) ? sanitize_key( (string) $payload['id'] ) : '';
		if ( '' === $id ) {
			$id = self::new_id();
		}

		$row = array(
			'id'        => $id,
			'name'      => $name,
			'values'    => $values,
			'updatedAt' => gmdate( 'c' ),
		);

		$all = self::load_all();
		$key = self::bag_key( $taxonomy, $structure_id );
		$bag = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();

		$found = false;
		foreach ( $bag as $i => $existing ) {
			if ( ! is_array( $existing ) ) {
				continue;
			}
			$eid = isset( $existing['id'] ) ? sanitize_key( (string) $existing['id'] ) : '';
			if ( $eid === $id ) {
				$bag[ $i ] = $row;
				$found     = true;
				break;
			}
		}
		if ( ! $found ) {
			$bag[] = $row;
		}

		$all[ $key ] = array_values( $bag );
		self::persist_all( $all );

		return $row;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function delete( string $taxonomy, int $structure_id, string $instance_id ) {
		$instance_id = sanitize_key( $instance_id );
		if ( '' === $instance_id ) {
			return new \WP_Error( 'wtt_bad_id', __( 'Invalid instance id.', 'wp-taxonomy-tree' ) );
		}

		$all = self::load_all();
		$key = self::bag_key( $taxonomy, $structure_id );
		if ( ! isset( $all[ $key ] ) || ! is_array( $all[ $key ] ) ) {
			return new \WP_Error( 'wtt_not_found', __( 'Instance not found.', 'wp-taxonomy-tree' ) );
		}

		$next  = array();
		$found = false;
		foreach ( $all[ $key ] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$eid = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
			if ( $eid === $instance_id ) {
				$found = true;
				continue;
			}
			$next[] = $row;
		}

		if ( ! $found ) {
			return new \WP_Error( 'wtt_not_found', __( 'Instance not found.', 'wp-taxonomy-tree' ) );
		}

		if ( array() === $next ) {
			unset( $all[ $key ] );
		} else {
			$all[ $key ] = array_values( $next );
		}
		self::persist_all( $all );

		return true;
	}

	/**
	 * Fill empty attribute slots from Sample_Data (type → sample map).
	 *
	 * @param array<string, string> $values Current values (attr id → string).
	 * @return array<string, string>
	 */
	public static function apply_samples( string $taxonomy, int $structure_id, array $values ): array {
		$out = array();
		foreach ( $values as $attr_id => $val ) {
			$out[ (string) $attr_id ] = (string) $val;
		}

		foreach ( Attribute::list( $taxonomy, $structure_id ) as $row ) {
			if ( ! empty( $row['hidden'] ) ) {
				continue;
			}
			$attr_id = (string) (int) ( $row['id'] ?? 0 );
			if ( '' === $attr_id || '0' === $attr_id ) {
				continue;
			}
			$current = isset( $out[ $attr_id ] ) ? trim( (string) $out[ $attr_id ] ) : '';
			if ( '' !== $current ) {
				continue;
			}
			/* Fixed Festwerte stay as defined — do not invent samples over them. */
			if ( ! empty( $row['fixedValues'] ) && is_array( $row['fixedValues'] ) && array() !== $row['fixedValues'] ) {
				$first = $row['fixedValues'][0] ?? '';
				$out[ $attr_id ] = (string) $first;
				continue;
			}
			$type_key = (string) ( $row['typeKey'] ?? '' );
			$sample   = Sample_Data::for_type( '' !== $type_key ? $type_key : (int) ( $row['typeId'] ?? 0 ) );
			if ( '' !== $sample ) {
				$out[ $attr_id ] = $sample;
			}
		}

		return $out;
	}

	/**
	 * Structure DTO for the admin form (host + fillable attributes).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function structure_dto( string $taxonomy, int $structure_id ): ?array {
		$view = Object_Render::get_view( $taxonomy, $structure_id );
		if ( null === $view ) {
			return null;
		}

		$fields = array();
		foreach ( Attribute::list( $taxonomy, $structure_id ) as $row ) {
			if ( ! empty( $row['hidden'] ) ) {
				continue;
			}
			$attr_id = (int) ( $row['id'] ?? 0 );
			if ( $attr_id <= 0 ) {
				continue;
			}
			$fixed = array();
			if ( isset( $row['fixedValues'] ) && is_array( $row['fixedValues'] ) ) {
				foreach ( $row['fixedValues'] as $v ) {
					$fixed[] = (string) $v;
				}
			}
			$fields[] = array(
				'id'           => $attr_id,
				'name'         => (string) ( $row['name'] ?? '' ),
				'typeId'       => (int) ( $row['typeId'] ?? 0 ),
				'typeName'     => (string) ( $row['typeName'] ?? '' ),
				'typeKey'      => (string) ( $row['typeKey'] ?? '' ),
				'multiplicity' => (string) ( $row['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY ),
				'inherited'    => ! empty( $row['inherited'] ),
				'readonly'     => ! empty( $row['readonly'] ),
				'fixedValues'  => $fixed,
				'fixedLabel'   => (string) ( $row['fixedLabel'] ?? '' ),
			);
		}

		$view['fields']         = $fields;
		$view['attributeCount'] = count( $fields );
		return $view;
	}

	/**
	 * Pickable structure hosts (prefer nodes that already have attributes).
	 *
	 * @return list<array{id:int,name:string,path:string,taxonomy:string,attributeCount:int}>
	 */
	public static function list_structure_hosts( string $taxonomy = '' ): array {
		$nodes = Object_Render::list_pickable_nodes( $taxonomy );
		$out   = array();
		foreach ( $nodes as $node ) {
			$tax = (string) ( $node['taxonomy'] ?? '' );
			$id  = (int) ( $node['id'] ?? 0 );
			if ( '' === $tax || $id <= 0 ) {
				continue;
			}
			$count = 0;
			foreach ( Attribute::list( $tax, $id ) as $row ) {
				if ( empty( $row['hidden'] ) ) {
					++$count;
				}
			}
			$out[] = array(
				'id'             => $id,
				'name'           => (string) ( $node['name'] ?? '' ),
				'path'           => (string) ( $node['path'] ?? '' ),
				'taxonomy'       => $tax,
				'attributeCount' => $count,
			);
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				/* Hosts with attributes first, then path. */
				$ac = (int) ( $a['attributeCount'] ?? 0 );
				$bc = (int) ( $b['attributeCount'] ?? 0 );
				if ( $ac > 0 && 0 === $bc ) {
					return -1;
				}
				if ( $bc > 0 && 0 === $ac ) {
					return 1;
				}
				$tax = strcasecmp( (string) ( $a['taxonomy'] ?? '' ), (string) ( $b['taxonomy'] ?? '' ) );
				if ( 0 !== $tax ) {
					return $tax;
				}
				return strcasecmp( (string) ( $a['path'] ?? '' ), (string) ( $b['path'] ?? '' ) );
			}
		);

		return $out;
	}

	/**
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function load_all(): array {
		$raw = get_option( self::OPTION_KEY, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @param array<string, list<array<string, mixed>>> $all Full store.
	 */
	private static function persist_all( array $all ): void {
		update_option( self::OPTION_KEY, $all, false );
	}

	private static function new_id(): string {
		return 'md_' . strtolower( wp_generate_password( 12, false, false ) );
	}

	/**
	 * @return array<int, true>
	 */
	private static function allowed_attribute_ids( string $taxonomy, int $structure_id ): array {
		$allowed = array();
		foreach ( Attribute::list( $taxonomy, $structure_id ) as $row ) {
			if ( ! empty( $row['hidden'] ) ) {
				continue;
			}
			$aid = (int) ( $row['id'] ?? 0 );
			if ( $aid > 0 ) {
				$allowed[ $aid ] = true;
			}
		}
		return $allowed;
	}

	/**
	 * @param array<mixed, mixed> $raw Raw values map.
	 * @param array<int, true>    $allowed Allowed attribute ids.
	 * @return array<string, string>
	 */
	private static function sanitize_values( array $raw, array $allowed ): array {
		$out = array();
		foreach ( $raw as $key => $value ) {
			$attr_id = absint( $key );
			if ( $attr_id <= 0 || ! isset( $allowed[ $attr_id ] ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$encoded = wp_json_encode( $value );
				$out[ (string) $attr_id ] = false === $encoded ? '' : $encoded;
				continue;
			}
			$out[ (string) $attr_id ] = sanitize_textarea_field( (string) $value );
		}
		return $out;
	}

	/**
	 * @param mixed $row Raw row.
	 * @return array{id:string,name:string,values:array<string,string>,updatedAt:string}|null
	 */
	private static function normalize_row( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$id = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
		if ( '' === $id ) {
			return null;
		}
		$values = array();
		if ( isset( $row['values'] ) && is_array( $row['values'] ) ) {
			foreach ( $row['values'] as $k => $v ) {
				$values[ (string) absint( $k ) ] = is_scalar( $v ) ? (string) $v : '';
			}
		}
		return array(
			'id'        => $id,
			'name'      => isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '',
			'values'    => $values,
			'updatedAt' => isset( $row['updatedAt'] ) ? sanitize_text_field( (string) $row['updatedAt'] ) : '',
		);
	}
}
