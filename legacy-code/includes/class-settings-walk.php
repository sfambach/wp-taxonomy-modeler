<?php
/**
 * Q123 Settings / Render walk — gather Settings.data + Settings.view
 * along besteht_aus / aggregation with hybrid live + override deltas.
 *
 * Product: DEVELOPER-ATTRIBUTE-MODEL.md (OQ-W2/W3/W6/W8/W16).
 *
 * Nested Walk-Wizard deltas live on the **attribute Relation** under
 * `settings.nested[<path>]` where `<path>` is `/`-joined child Relation edge
 * UUIDs from the attribute target (not node ids, not display names). Depth 0
 * keeps top-level `settings.data` / `settings.view`. Writes never push into
 * nested type nodes.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recursive Settings walk for attribute / type target trees.
 */
final class Settings_Walk {

	/** Safety cap (OQ-W8: walk to leaf; cycles stop first). */
	public const SAFETY_DEPTH = 32;

	/** Max rows in decorate_row `settingsWalk` summary (names + preferred + nodeId). */
	public const SUMMARY_MAX_NODES = 24;

	/**
	 * Request-scoped walk() memo (type + deltas fingerprint).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $walk_cache = array();

	/**
	 * Request-scoped live_for_node memo.
	 *
	 * @var array<string, array{data:array<string,mixed>,view:array<string,mixed>}>
	 */
	private static array $live_cache = array();

	/** Drop request memos after mutations (see Tree_Model::touch_modified). */
	public static function bust_request_caches(): void {
		self::$walk_cache = array();
		self::$live_cache = array();
	}

	/**
	 * Live Settings.data / Settings.view for a node from scaffold meta.
	 * Maps `_wtt_preferred_*` / validators into the product namespaces (migrate debt).
	 *
	 * @return array{data:array<string,mixed>,view:array<string,mixed>}
	 */
	public static function live_for_node( string $taxonomy, int $node_id ): array {
		$data = array();
		$view = array();
		if ( $node_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return array(
				'data' => $data,
				'view' => $view,
			);
		}

		$live_key = $taxonomy . ':' . $node_id;
		if ( isset( self::$live_cache[ $live_key ] ) ) {
			return self::$live_cache[ $live_key ];
		}

		$term = get_term( $node_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return array(
				'data' => $data,
				'view' => $view,
			);
		}

		$pref = Node_Type::get_preferred_render( $node_id );
		if ( '' !== $pref ) {
			$view['preferredRenderer'] = $pref;
		}

		$conv = Node_Type::get_preferred_converter_for_node( $taxonomy, $node_id );
		if ( '' !== $conv ) {
			$view['preferredConverter'] = $conv;
		}

		$validators = Node_Type::get_validators_for_node( $taxonomy, $node_id );
		if ( ! empty( $validators ) ) {
			$data['validators'] = $validators;
		}

		$type_key = Node_Type::registry_id_for_type_term( $taxonomy, $node_id );
		if ( 'date' === $type_key ) {
			$cfg = Node_Type::get_date_config_for_node( $taxonomy, $node_id );
			if ( is_array( $cfg ) && isset( $cfg['mode'] ) ) {
				$data['dateMode'] = Node_Type::normalize_date_mode( (string) $cfg['mode'] );
			}
		}

		if ( 'textarea' === $type_key ) {
			$ta = Node_Type::get_textarea_config_for_node( $taxonomy, $node_id );
			if ( is_array( $ta ) ) {
				$data['textareaCols'] = Node_Type::normalize_textarea_cols( $ta['cols'] ?? null );
				$data['textareaRows'] = Node_Type::normalize_textarea_rows( $ta['rows'] ?? null );
			}
		}

		/*
		 * Q51 / Q120: unit leaf allowlist lives in term meta; expose as Settings.data
		 * so Walk hybrid + attribute Relation deltas can override without mutating catalog.
		 * With-prefix folder has no unit meta — live omits the key (unit marriage only).
		 */
		if ( Node_Type::is_basiseinheit_unit_node( $taxonomy, $node_id ) ) {
			$data['allowedPrefixIds'] = Node_Type::get_allowed_prefix_ids( $node_id );
		}

		$choice_filter = Node_Type::get_choice_filter( $node_id );
		if ( is_array( $choice_filter ) ) {
			$data['choiceFilter'] = $choice_filter;
		}

		/*
		 * Type Default seed (Q106 / node Festwert) — live for Walk hybrid.
		 * Attribute Relation overrides: depth 0 → edge.default; nested → settings.nested[path].data.default.
		 */
		$type_default = self::live_default_seed_for_node( $taxonomy, $node_id );
		if ( array() !== $type_default ) {
			$data['default'] = $type_default;
		}

		$bag = array(
			'data' => $data,
			'view' => $view,
		);
		self::$live_cache[ $live_key ] = $bag;
		return $bag;
	}

	/**
	 * Live type-node Default seed (literal or catalog id) as Q106 list.
	 *
	 * @return list<string|array<string,string>>
	 */
	public static function live_default_seed_for_node( string $taxonomy, int $node_id ): array {
		if ( $node_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$fixed = Node_Type::get_fixed_assignment( $taxonomy, $node_id );
		if ( ! is_array( $fixed ) ) {
			return array();
		}
		$fixed_id = isset( $fixed['id'] ) ? (int) $fixed['id'] : 0;
		if ( $fixed_id > 0 ) {
			return array( (string) $fixed_id );
		}
		$name = isset( $fixed['name'] ) ? trim( (string) $fixed['name'] ) : '';
		if ( '' !== $name ) {
			return array( $name );
		}
		return array();
	}

	/**
	 * Normalize Settings.data.default (Q106 seed list).
	 *
	 * @param mixed $raw
	 * @return list<string|array<string,string>>
	 */
	public static function normalize_default_seed( $raw ): array {
		if ( class_exists( __NAMESPACE__ . '\\Attribute' ) ) {
			return Attribute::normalize_default_seed( $raw );
		}
		if ( null === $raw || false === $raw || '' === $raw ) {
			return array();
		}
		if ( is_array( $raw ) ) {
			$out = array();
			foreach ( $raw as $v ) {
				if ( is_string( $v ) || is_numeric( $v ) ) {
					$s = trim( (string) $v );
					if ( '' !== $s ) {
						$out[] = $s;
					}
				}
			}
			return $out;
		}
		if ( is_string( $raw ) || is_numeric( $raw ) ) {
			$s = trim( (string) $raw );
			return '' !== $s ? array( $s ) : array();
		}
		return array();
	}

	/**
	 * Normalize Settings.data.allowedPrefixIds (prefix term ids).
	 *
	 * @param mixed $raw
	 * @return array<int, int>
	 */
	public static function normalize_allowed_prefix_ids( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ids = array();
		foreach ( $raw as $id ) {
			if ( is_numeric( $id ) ) {
				$n = (int) $id;
				if ( $n > 0 ) {
					$ids[] = $n;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Canonicalize allowedPrefixIds key casing inside a data bag.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function normalize_data_bag( array $data ): array {
		$out = array();
		foreach ( $data as $key => $value ) {
			$k = is_string( $key ) ? Relation::sanitize_settings_key( $key ) : '';
			if ( '' === $k ) {
				continue;
			}
			if ( 'allowedprefixids' === strtolower( $k ) ) {
				$k     = 'allowedPrefixIds';
				$value = self::normalize_allowed_prefix_ids( $value );
			} elseif ( 'default' === strtolower( $k ) ) {
				$k     = 'default';
				$value = self::normalize_default_seed( $value );
			} elseif ( 'readonly' === strtolower( $k ) ) {
				/* Nested walk override (depth ≥ 1). Host edge RO stays on Relation.edge. */
				$k     = 'readOnly';
				$value = self::normalize_bool_flag( $value );
			} elseif ( 'hidden' === strtolower( $k ) ) {
				/* Nested walk override (depth ≥ 1). Host edge Hide stays on Relation.edge. */
				$k     = 'hidden';
				$value = self::normalize_bool_flag( $value );
			} elseif ( 'choicefilter' === strtolower( $k ) ) {
				$k = 'choiceFilter';
				if ( is_array( $value ) ) {
					$mode = isset( $value['mode'] ) ? (string) $value['mode'] : 'exclude';
					$ids  = array();
					if ( isset( $value['ids'] ) && is_array( $value['ids'] ) ) {
						foreach ( $value['ids'] as $id ) {
							$id = (int) $id;
							if ( $id > 0 ) {
								$ids[] = $id;
							}
						}
					}
					$value = array(
						'mode' => ( 'include' === $mode ) ? 'include' : 'exclude',
						'ids'  => array_values( array_unique( $ids ) ),
					);
				} else {
					continue;
				}
			}
			$out[ $k ] = $value;
		}
		return $out;
	}

	/**
	 * Coerce Settings.data bool flags (readOnly / hidden path overrides).
	 *
	 * @param mixed $value Raw value.
	 */
	private static function normalize_bool_flag( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (int) $value !== 0;
		}
		if ( is_string( $value ) ) {
			$trim = strtolower( trim( $value ) );
			if ( '' === $trim || '0' === $trim || 'false' === $trim || 'no' === $trim || 'off' === $trim ) {
				return false;
			}
			return true;
		}
		return ! empty( $value );
	}

	/**
	 * Hybrid merge: live below, deltas win when a key is present (OQ-W2/W3).
	 *
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>}      $live
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>}|null $deltas
	 * @return array{data:array<string,mixed>,view:array<string,mixed>}
	 */
	public static function merge_hybrid( array $live, ?array $deltas ): array {
		$live_data = isset( $live['data'] ) && is_array( $live['data'] )
			? self::normalize_data_bag( $live['data'] )
			: array();
		$live_view = isset( $live['view'] ) && is_array( $live['view'] ) ? $live['view'] : array();

		$delta_data = array();
		$delta_view = array();
		if ( is_array( $deltas ) ) {
			if ( isset( $deltas['data'] ) && is_array( $deltas['data'] ) ) {
				$delta_data = self::normalize_data_bag( $deltas['data'] );
			}
			if ( isset( $deltas['view'] ) && is_array( $deltas['view'] ) ) {
				$delta_view = self::normalize_view_bag( $deltas['view'] );
			}
		}

		return array(
			'data' => array_merge( $live_data, $delta_data ),
			'view' => array_merge( $live_view, $delta_view ),
		);
	}

	/**
	 * Canonicalize view bag keys (preferredRenderer camelCase; accept legacy lower).
	 *
	 * @param array<string, mixed> $view
	 * @return array<string, mixed>
	 */
	public static function normalize_view_bag( array $view ): array {
		$out = array();
		foreach ( $view as $key => $value ) {
			$k = is_string( $key ) ? Relation::sanitize_settings_key( $key ) : '';
			if ( '' === $k ) {
				continue;
			}
			if ( 'preferredrenderer' === strtolower( $k ) ) {
				$k = 'preferredRenderer';
			} elseif ( 'preferredconverter' === strtolower( $k ) ) {
				$k = 'preferredConverter';
			}
			$out[ $k ] = $value;
		}
		return $out;
	}

	/**
	 * Whether a Settings bag has a key (presence = override), accepting legacy casing.
	 *
	 * @param array<string, mixed> $bag
	 */
	public static function bag_has_key( array $bag, string $key ): bool {
		if ( array_key_exists( $key, $bag ) ) {
			return true;
		}
		$lower = strtolower( $key );
		foreach ( $bag as $k => $_v ) {
			if ( is_string( $k ) && strtolower( $k ) === $lower ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read a string from a view bag (camelCase + legacy lower).
	 *
	 * @param array<string, mixed> $view
	 */
	public static function view_string( array $view, string $key ): string {
		if ( isset( $view[ $key ] ) && ( is_string( $view[ $key ] ) || is_numeric( $view[ $key ] ) ) ) {
			return trim( (string) $view[ $key ] );
		}
		$lower = strtolower( $key );
		foreach ( $view as $k => $v ) {
			if ( is_string( $k ) && strtolower( $k ) === $lower && ( is_string( $v ) || is_numeric( $v ) ) ) {
				return trim( (string) $v );
			}
		}
		return '';
	}

	/**
	 * Walk a type / attribute target tree. Root deltas = Relation settings on the
	 * attribute edge (or null when opening a node as itself). Nested path deltas
	 * under `settings.nested` apply when descending composition edges.
	 *
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}|null $root_deltas
	 * @return array{
	 *   rootId:int,
	 *   resolved:array{data:array<string,mixed>,view:array<string,mixed>},
	 *   tree:array<string,mixed>|null,
	 *   nodeCount:int,
	 *   cycleStops:int,
	 *   depth:int
	 * }
	 */
	public static function walk(
		string $taxonomy,
		int $root_node_id,
		?array $root_deltas = null,
		int $max_depth = self::SAFETY_DEPTH
	): array {
		$max_depth = max( 0, $max_depth );
		$cache_key = self::walk_cache_key( $taxonomy, $root_node_id, $root_deltas, $max_depth );
		if ( isset( self::$walk_cache[ $cache_key ] ) ) {
			return self::$walk_cache[ $cache_key ];
		}

		$stats = array(
			'nodeCount'       => 0,
			'cycleStops'      => 0,
			'maxDepthReached' => 0,
		);

		$root_full = null;
		$root_bag  = null;
		if ( is_array( $root_deltas ) ) {
			$root_full = Relation::normalize_settings_deltas( $root_deltas );
			/* Depth 0 incoming = top-level data/view only (nested applied on descend). */
			$root_bag = Relation::normalize_settings_bag(
				array(
					'data' => isset( $root_deltas['data'] ) && is_array( $root_deltas['data'] )
						? $root_deltas['data']
						: array(),
					'view' => isset( $root_deltas['view'] ) && is_array( $root_deltas['view'] )
						? $root_deltas['view']
						: array(),
				)
			);
		}

		$tree = null;
		if ( $root_node_id > 0 && taxonomy_exists( $taxonomy ) ) {
			$tree = self::walk_node(
				$taxonomy,
				$root_node_id,
				$root_bag,
				array(),
				0,
				$max_depth,
				$stats,
				'',
				'',
				'',
				$root_full
			);
		}

		$resolved = is_array( $tree ) && isset( $tree['resolved'] ) && is_array( $tree['resolved'] )
			? $tree['resolved']
			: array(
				'data' => array(),
				'view' => array(),
			);

		/* Root deltas with no live node still contribute (edge-only override). */
		if ( null === $tree && is_array( $root_bag ) ) {
			$resolved = self::merge_hybrid(
				array(
					'data' => array(),
					'view' => array(),
				),
				$root_bag
			);
		}

		$result = array(
			'rootId'     => $root_node_id,
			'resolved'   => $resolved,
			'tree'       => $tree,
			'nodeCount'  => (int) $stats['nodeCount'],
			'cycleStops' => (int) $stats['cycleStops'],
			'depth'      => (int) $stats['maxDepthReached'],
		);
		self::$walk_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}|null $root_deltas
	 */
	private static function walk_cache_key(
		string $taxonomy,
		int $root_node_id,
		?array $root_deltas,
		int $max_depth
	): string {
		$fp = '0';
		if ( is_array( $root_deltas ) ) {
			$encoded = wp_json_encode( Relation::normalize_settings_deltas( $root_deltas ) );
			$fp      = is_string( $encoded ) ? md5( $encoded ) : '0';
		}
		return $taxonomy . '|' . $root_node_id . '|' . $max_depth . '|' . $fp;
	}

	/**
	 * Whether edge settings carry nested walk-path deltas (need deep walk for Options UI).
	 *
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}|null $edge_settings
	 */
	public static function edge_has_nested_deltas( ?array $edge_settings ): bool {
		if ( ! is_array( $edge_settings ) ) {
			return false;
		}
		$nested = isset( $edge_settings['nested'] ) && is_array( $edge_settings['nested'] )
			? $edge_settings['nested']
			: array();
		return array() !== $nested;
	}

	/**
	 * Normalize a Walk path (`""` = depth 0; else `/`-joined edge UUIDs).
	 */
	public static function normalize_walk_path( string $path ): string {
		return Relation::normalize_nested_settings_path( $path );
	}

	/**
	 * Deltas for one walk path from attribute Relation settings.
	 * Empty path → top-level data/view; non-empty → settings.nested[path].
	 *
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}|null $root_settings
	 * @return array{data?:array<string,mixed>,view?:array<string,mixed>}|null
	 */
	public static function deltas_for_walk_path( ?array $root_settings, string $path ): ?array {
		$path = self::normalize_walk_path( $path );
		if ( ! is_array( $root_settings ) ) {
			return null;
		}
		if ( '' === $path ) {
			return Relation::normalize_settings_bag(
				array(
					'data' => isset( $root_settings['data'] ) && is_array( $root_settings['data'] )
						? $root_settings['data']
						: array(),
					'view' => isset( $root_settings['view'] ) && is_array( $root_settings['view'] )
						? $root_settings['view']
						: array(),
				)
			);
		}
		$nested = isset( $root_settings['nested'] ) && is_array( $root_settings['nested'] )
			? $root_settings['nested']
			: array();
		if ( ! isset( $nested[ $path ] ) || ! is_array( $nested[ $path ] ) ) {
			return null;
		}
		return Relation::normalize_settings_bag( $nested[ $path ] );
	}

	/**
	 * Merge two delta bags; upper wins on key presence (same as hybrid layers).
	 *
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>}|null $lower
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>}|null $upper
	 * @return array{data?:array<string,mixed>,view?:array<string,mixed>}|null
	 */
	public static function merge_delta_bags( ?array $lower, ?array $upper ): ?array {
		if ( null === $upper ) {
			return is_array( $lower ) ? Relation::normalize_settings_bag( $lower ) : null;
		}
		if ( null === $lower ) {
			return Relation::normalize_settings_bag( $upper );
		}
		$lower_data = isset( $lower['data'] ) && is_array( $lower['data'] ) ? $lower['data'] : array();
		$upper_data = isset( $upper['data'] ) && is_array( $upper['data'] ) ? $upper['data'] : array();
		$lower_view = isset( $lower['view'] ) && is_array( $lower['view'] )
			? self::normalize_view_bag( $lower['view'] )
			: array();
		$upper_view = isset( $upper['view'] ) && is_array( $upper['view'] )
			? self::normalize_view_bag( $upper['view'] )
			: array();
		return Relation::normalize_settings_bag(
			array(
				'data' => array_merge( $lower_data, $upper_data ),
				'view' => array_merge( $lower_view, $upper_view ),
			)
		);
	}

	/**
	 * Whether $path appears on the walk from $root_node_id (empty path always ok).
	 *
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}|null $root_settings
	 */
	public static function walk_path_exists(
		string $taxonomy,
		int $root_node_id,
		?array $root_settings,
		string $path
	): bool {
		$path = self::normalize_walk_path( $path );
		if ( '' === $path ) {
			return true;
		}
		$walk    = self::walk( $taxonomy, $root_node_id, $root_settings );
		$summary = self::summary_from_walk( $walk );
		foreach ( $summary as $level ) {
			if ( ! is_array( $level ) ) {
				continue;
			}
			if ( self::normalize_walk_path( (string) ( $level['path'] ?? '' ) ) === $path ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Apply / clear one Settings key on a walk path inside attribute edge settings.
	 * Empty path writes top-level data/view; nested paths write settings.nested[path].
	 * Null $value deletes the key. Does not touch type nodes.
	 *
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}|null $settings
	 * @param mixed                                                                                         $value
	 * @return array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}|null
	 */
	public static function apply_walk_settings_key(
		?array $settings,
		string $path,
		string $namespace,
		string $key,
		$value
	): ?array {
		$path = self::normalize_walk_path( $path );
		$ns   = strtolower( trim( $namespace ) );
		if ( 'view' !== $ns && 'data' !== $ns ) {
			return is_array( $settings ) ? Relation::normalize_settings_deltas( $settings ) : null;
		}
		$key = Relation::sanitize_settings_key( $key );
		if ( '' === $key ) {
			return is_array( $settings ) ? Relation::normalize_settings_deltas( $settings ) : null;
		}
		if ( 'view' === $ns ) {
			if ( 'preferredrenderer' === strtolower( $key ) ) {
				$key = 'preferredRenderer';
			} elseif ( 'preferredconverter' === strtolower( $key ) ) {
				$key = 'preferredConverter';
			}
		} elseif ( 'data' === $ns && 'allowedprefixids' === strtolower( $key ) ) {
			$key = 'allowedPrefixIds';
		} elseif ( 'data' === $ns && 'default' === strtolower( $key ) ) {
			$key = 'default';
		}

		$base = is_array( $settings ) ? $settings : array();
		if ( '' === $path ) {
			$bag = array(
				'data' => isset( $base['data'] ) && is_array( $base['data'] )
					? self::normalize_data_bag( $base['data'] )
					: array(),
				'view' => isset( $base['view'] ) && is_array( $base['view'] )
					? self::normalize_view_bag( $base['view'] )
					: array(),
			);
			if ( null === $value ) {
				unset( $bag[ $ns ][ $key ] );
			} else {
				if ( 'data' === $ns && 'allowedPrefixIds' === $key ) {
					$value = self::normalize_allowed_prefix_ids( $value );
				} elseif ( 'data' === $ns && 'default' === $key ) {
					$value = self::normalize_default_seed( $value );
				}
				$bag[ $ns ][ $key ] = $value;
			}
			$out = $base;
			foreach ( array( 'data', 'view' ) as $slot ) {
				if ( empty( $bag[ $slot ] ) ) {
					unset( $out[ $slot ] );
				} else {
					$out[ $slot ] = $bag[ $slot ];
				}
			}
			return Relation::normalize_settings_deltas( $out );
		}

		$nested = isset( $base['nested'] ) && is_array( $base['nested'] ) ? $base['nested'] : array();
		$bag    = isset( $nested[ $path ] ) && is_array( $nested[ $path ] )
			? array(
				'data' => isset( $nested[ $path ]['data'] ) && is_array( $nested[ $path ]['data'] )
					? self::normalize_data_bag( $nested[ $path ]['data'] )
					: array(),
				'view' => isset( $nested[ $path ]['view'] ) && is_array( $nested[ $path ]['view'] )
					? self::normalize_view_bag( $nested[ $path ]['view'] )
					: array(),
			)
			: array(
				'data' => array(),
				'view' => array(),
			);
		if ( null === $value ) {
			unset( $bag[ $ns ][ $key ] );
		} else {
			if ( 'data' === $ns && 'allowedPrefixIds' === $key ) {
				$value = self::normalize_allowed_prefix_ids( $value );
			} elseif ( 'data' === $ns && 'default' === $key ) {
				$value = self::normalize_default_seed( $value );
			}
			$bag[ $ns ][ $key ] = $value;
		}
		$norm_bag = Relation::normalize_settings_bag( $bag );
		if ( null === $norm_bag ) {
			unset( $nested[ $path ] );
		} else {
			$nested[ $path ] = $norm_bag;
		}
		$out = $base;
		if ( empty( $nested ) ) {
			unset( $out['nested'] );
		} else {
			$out['nested'] = $nested;
		}
		return Relation::normalize_settings_deltas( $out );
	}

	/**
	 * Compact meta for Attribute::decorate_row payloads (no full tree).
	 * Callers may add preferredSource / hasPreferredOverride after resolve.
	 *
	 * @param array<string, mixed> $walk Result of walk().
	 * @return array{nodeCount:int,cycleStops:int,depth:int}
	 */
	public static function meta_from_walk( array $walk ): array {
		return array(
			'nodeCount'  => (int) ( $walk['nodeCount'] ?? 0 ),
			'cycleStops' => (int) ( $walk['cycleStops'] ?? 0 ),
			'depth'      => (int) ( $walk['depth'] ?? 0 ),
		);
	}

	/**
	 * Bounded flatten of walk tree for Options Walk-Wizard UI.
	 * Reuses an existing walk() result — no second recursion.
	 *
	 * @param array<string, mixed> $walk Result of walk().
	 * @return list<array<string,mixed>>
	 */
	public static function summary_from_walk( array $walk ): array {
		$tree = isset( $walk['tree'] ) && is_array( $walk['tree'] ) ? $walk['tree'] : null;
		if ( null === $tree ) {
			return array();
		}
		$out = array();
		self::flatten_summary_node( $tree, 0, $out, self::SUMMARY_MAX_NODES );
		return $out;
	}

	/**
	 * Nested composition? (more than the root type node, or children reached).
	 *
	 * @param array<string, mixed> $walk_meta meta_from_walk() (+ optional keys).
	 */
	public static function walk_is_nested( array $walk_meta ): bool {
		$node_count = (int) ( $walk_meta['nodeCount'] ?? 0 );
		$depth      = (int) ( $walk_meta['depth'] ?? 0 );
		return $node_count > 1 || $depth > 0;
	}

	/**
	 * @param array<string, mixed>       $node
	 * @param list<array<string,mixed>> $out
	 */
	private static function flatten_summary_node( array $node, int $depth, array &$out, int $max ): void {
		if ( count( $out ) >= $max ) {
			return;
		}
		/*
		 * Cycle stubs duplicate a node already listed (e.g. Präfixe via Unit type
		 * Praefix and again via With-prefix composition). Father-level edges only.
		 */
		if ( ! empty( $node['cycleStopped'] ) ) {
			return;
		}

		$node_id = isset( $node['nodeId'] ) ? (int) $node['nodeId'] : 0;
		$path    = isset( $node['path'] ) ? self::normalize_walk_path( (string) $node['path'] ) : '';

		$live_view = array();
		if ( isset( $node['live']['view'] ) && is_array( $node['live']['view'] ) ) {
			$live_view = self::normalize_view_bag( $node['live']['view'] );
		}
		$type_preferred = self::view_string( $live_view, 'preferredRenderer' );
		if ( '' === $type_preferred && $node_id > 0 ) {
			$type_preferred = Node_Type::get_preferred_render( $node_id );
		}

		$resolved_view = array();
		if ( isset( $node['resolved']['view'] ) && is_array( $node['resolved']['view'] ) ) {
			$resolved_view = self::normalize_view_bag( $node['resolved']['view'] );
		}
		$preferred = self::view_string( $resolved_view, 'preferredRenderer' );
		if ( '' === $preferred ) {
			$preferred = $type_preferred;
		}

		$path_deltas = isset( $node['pathDeltas'] ) && is_array( $node['pathDeltas'] )
			? Relation::normalize_settings_bag( $node['pathDeltas'] )
			: null;
		$path_view   = ( is_array( $path_deltas ) && isset( $path_deltas['view'] ) && is_array( $path_deltas['view'] ) )
			? self::normalize_view_bag( $path_deltas['view'] )
			: array();
		$path_data   = ( is_array( $path_deltas ) && isset( $path_deltas['data'] ) && is_array( $path_deltas['data'] ) )
			? self::normalize_data_bag( $path_deltas['data'] )
			: array();

		$has_pref_key      = self::bag_has_key( $path_view, 'preferredRenderer' );
		$pref_delta        = self::view_string( $path_view, 'preferredRenderer' );
		$has_pref_override = $has_pref_key && '' !== $pref_delta;

		$live_conv = self::view_string( $live_view, 'preferredConverter' );
		$resolved_conv = self::view_string( $resolved_view, 'preferredConverter' );
		$has_conv_override = self::bag_has_key( $path_view, 'preferredConverter' )
			&& '' !== self::view_string( $path_view, 'preferredConverter' );

		$live_data = isset( $node['live']['data'] ) && is_array( $node['live']['data'] )
			? self::normalize_data_bag( $node['live']['data'] )
			: array();
		$resolved_data = isset( $node['resolved']['data'] ) && is_array( $node['resolved']['data'] )
			? self::normalize_data_bag( $node['resolved']['data'] )
			: array();

		$type_validators = isset( $live_data['validators'] ) && is_array( $live_data['validators'] )
			? $live_data['validators']
			: array();
		$validators      = isset( $resolved_data['validators'] ) && is_array( $resolved_data['validators'] )
			? $resolved_data['validators']
			: $type_validators;
		$has_validators_override = array_key_exists( 'validators', $path_data );

		$type_date_mode = isset( $live_data['dateMode'] ) ? (string) $live_data['dateMode'] : '';
		$date_mode      = isset( $resolved_data['dateMode'] ) ? (string) $resolved_data['dateMode'] : $type_date_mode;
		$has_date_override = array_key_exists( 'dateMode', $path_data );

		$type_textarea_cols = isset( $live_data['textareaCols'] )
			? Node_Type::normalize_textarea_cols( $live_data['textareaCols'] )
			: Node_Type::TEXTAREA_COLS_DEFAULT;
		$type_textarea_rows = isset( $live_data['textareaRows'] )
			? Node_Type::normalize_textarea_rows( $live_data['textareaRows'] )
			: Node_Type::TEXTAREA_ROWS_DEFAULT;
		$has_textarea_override = array_key_exists( 'textareaCols', $path_data )
			|| array_key_exists( 'textareaRows', $path_data );
		$textarea_cols = array_key_exists( 'textareaCols', $path_data )
			? Node_Type::normalize_textarea_cols( $path_data['textareaCols'] )
			: ( isset( $resolved_data['textareaCols'] )
				? Node_Type::normalize_textarea_cols( $resolved_data['textareaCols'] )
				: $type_textarea_cols );
		$textarea_rows = array_key_exists( 'textareaRows', $path_data )
			? Node_Type::normalize_textarea_rows( $path_data['textareaRows'] )
			: ( isset( $resolved_data['textareaRows'] )
				? Node_Type::normalize_textarea_rows( $resolved_data['textareaRows'] )
				: $type_textarea_rows );

		$type_allowed_prefix_ids = isset( $live_data['allowedPrefixIds'] ) && is_array( $live_data['allowedPrefixIds'] )
			? self::normalize_allowed_prefix_ids( $live_data['allowedPrefixIds'] )
			: array();
		$has_prefix_override     = array_key_exists( 'allowedPrefixIds', $path_data );
		$allowed_prefix_ids      = $has_prefix_override
			? self::normalize_allowed_prefix_ids( $path_data['allowedPrefixIds'] )
			: ( isset( $resolved_data['allowedPrefixIds'] ) && is_array( $resolved_data['allowedPrefixIds'] )
				? self::normalize_allowed_prefix_ids( $resolved_data['allowedPrefixIds'] )
				: $type_allowed_prefix_ids );

		$type_default = isset( $live_data['default'] )
			? self::normalize_default_seed( $live_data['default'] )
			: array();
		$has_default_override = array_key_exists( 'default', $path_data );
		$default_seed         = $has_default_override
			? self::normalize_default_seed( $path_data['default'] )
			: ( isset( $resolved_data['default'] )
				? self::normalize_default_seed( $resolved_data['default'] )
				: $type_default );

		/*
		 * Nested RO / Hide: type default from composition edge on the type tree;
		 * path Settings.data overrides on the attribute Relation (not host edge fields).
		 */
		$type_read_only = ! empty( $node['edgeReadOnly'] );
		$has_ro_override = array_key_exists( 'readOnly', $path_data );
		$read_only       = $has_ro_override
			? self::normalize_bool_flag( $path_data['readOnly'] )
			: ( array_key_exists( 'readOnly', $resolved_data )
				? self::normalize_bool_flag( $resolved_data['readOnly'] )
				: $type_read_only );

		$type_hidden     = ! empty( $node['edgeHidden'] );
		$has_hide_override = array_key_exists( 'hidden', $path_data );
		$hidden          = $has_hide_override
			? self::normalize_bool_flag( $path_data['hidden'] )
			: ( array_key_exists( 'hidden', $resolved_data )
				? self::normalize_bool_flag( $resolved_data['hidden'] )
				: $type_hidden );

		$has_path_override = null !== $path_deltas;
		$has_delta         = $has_path_override;
		if ( ! $has_delta && isset( $node['deltas'] ) && is_array( $node['deltas'] ) ) {
			$has_delta = null !== Relation::normalize_settings_bag( $node['deltas'] );
		}

		$type_key = '';
		$taxonomy = isset( $node['taxonomy'] ) && is_string( $node['taxonomy'] ) ? (string) $node['taxonomy'] : '';
		if ( $node_id > 0 && '' !== $taxonomy ) {
			$type_key = Node_Type::registry_id_for_type_term( $taxonomy, $node_id );
		}

		$supports_prefix_allowlist = false;
		$prefix_allowlist_source   = '';
		$prefix_catalog            = array();
		$supports_choice_filter    = false;
		$choice_options            = array();
		$type_choice_filter        = null;
		$choice_filter             = null;
		$has_choice_filter_override = false;
		if ( $node_id > 0 && '' !== $taxonomy ) {
			if ( Node_Type::is_basiseinheit_unit_node( $taxonomy, $node_id ) ) {
				$supports_prefix_allowlist = true;
				$prefix_allowlist_source   = 'unit';
			} elseif ( Node_Type::is_unit_prefix_bucket( $taxonomy, $node_id ) ) {
				$supports_prefix_allowlist = true;
				$prefix_allowlist_source   = 'bucket';
			}
			if ( $supports_prefix_allowlist ) {
				$prefix_catalog = Node_Type::list_prefix_catalog( $taxonomy, $node_id );
			}

			$choice_options = array();
			if ( Node_Type::is_unit_prefix_bucket( $taxonomy, $node_id ) ) {
				$choice_options         = Attribute::catalog_choice_options_for_type( $taxonomy, $node_id );
				$supports_choice_filter = array() !== $choice_options;
			} else {
				/*
				 * Structure hosts (Unit type, Kontakt, quantity, …) inherit via
				 * child_of — heirs are NOT CatalogChoice leaves. Only ship Choices
				 * when the type is a real catalog specialization tree.
				 */
				if ( ! Attribute::prefers_structure_over_catalog( $taxonomy, $node_id ) ) {
					$choice_options         = Attribute::catalog_choice_options_for_type( $taxonomy, $node_id );
					$supports_choice_filter = array() !== $choice_options;
				}
			}

			/*
			 * Choice filter lives on the composition edge (incoming deltas) / path
			 * override — not on the catalog type node itself.
			 */
			$edge_data = array();
			if ( isset( $node['deltas']['data'] ) && is_array( $node['deltas']['data'] ) ) {
				$edge_data = self::normalize_data_bag( $node['deltas']['data'] );
			}
			if ( isset( $edge_data['choiceFilter'] ) && is_array( $edge_data['choiceFilter'] ) ) {
				$type_choice_filter = $edge_data['choiceFilter'];
			}
			$has_choice_filter_override = array_key_exists( 'choiceFilter', $path_data );
			if ( $has_choice_filter_override && isset( $path_data['choiceFilter'] ) && is_array( $path_data['choiceFilter'] ) ) {
				$choice_filter = $path_data['choiceFilter'];
			} elseif ( isset( $resolved_data['choiceFilter'] ) && is_array( $resolved_data['choiceFilter'] ) ) {
				$choice_filter = $resolved_data['choiceFilter'];
			} else {
				$choice_filter = $type_choice_filter;
			}
		}

		$out[] = array(
			'depth'                    => $depth,
			'path'                     => $path,
			'nodeId'                   => $node_id,
			'edgeId'                   => isset( $node['edgeId'] ) ? Attribute::normalize_attr_id( $node['edgeId'] ?? '' ) : '',
			'name'                     => isset( $node['name'] ) ? (string) $node['name'] : '',
			'edgeName'                 => isset( $node['edgeName'] ) ? (string) $node['edgeName'] : '',
			'typeKey'                  => $type_key,
			/* Settings.view */
			'preferred'                => $preferred,
			'typePreferred'            => $type_preferred,
			'hasPreferredOverride'     => $has_pref_override,
			'preferredConverter'       => $resolved_conv,
			'typePreferredConverter'   => $live_conv,
			'hasConverterOverride'     => $has_conv_override,
			/* Settings.data */
			'validators'               => $validators,
			'typeValidators'           => $type_validators,
			'hasValidatorsOverride'    => $has_validators_override,
			'dateMode'                 => $date_mode,
			'typeDateMode'             => $type_date_mode,
			'hasDateModeOverride'      => $has_date_override,
			'textareaCols'             => $textarea_cols,
			'typeTextareaCols'         => $type_textarea_cols,
			'textareaRows'             => $textarea_rows,
			'typeTextareaRows'         => $type_textarea_rows,
			'hasTextareaLayoutOverride'=> $has_textarea_override,
			'allowedPrefixIds'         => $allowed_prefix_ids,
			'typeAllowedPrefixIds'     => $type_allowed_prefix_ids,
			'hasAllowedPrefixIdsOverride' => $has_prefix_override,
			'supportsPrefixAllowlist'  => $supports_prefix_allowlist,
			'prefixAllowlistSource'    => $prefix_allowlist_source,
			'prefixCatalog'            => $prefix_catalog,
			'supportsChoiceFilter'     => $supports_choice_filter,
			'choiceOptions'            => $choice_options,
			'choiceFilter'             => $choice_filter,
			'typeChoiceFilter'         => $type_choice_filter,
			'hasChoiceFilterOverride'  => $has_choice_filter_override,
			/* Settings.data.default — nested path delta; depth 0 also hybrid with edge.default (decorate). */
			'default'                  => $default_seed,
			'typeDefault'              => $type_default,
			'hasDefaultOverride'       => $has_default_override,
			'defaultSource'            => $has_default_override ? 'settings' : 'type',
			/* Nested walk RO / Hide path overrides (depth ≥ 1). */
			'readOnly'                 => $read_only,
			'typeReadOnly'             => $type_read_only,
			'hasReadOnlyOverride'      => $has_ro_override,
			'hidden'                   => $hidden,
			'typeHidden'               => $type_hidden,
			'hasHiddenOverride'        => $has_hide_override,
			'hasPathOverride'          => $has_path_override,
			'hasDelta'                 => $has_delta,
			'cycleStopped'             => ! empty( $node['cycleStopped'] ),
		);

		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			self::flatten_summary_node( $child, $depth + 1, $out, $max );
		}
	}

	/** Settings.data keys owned by the typeExtras bridge (not preferredRenderer). */
	public const TYPE_EXTRAS_DATA_KEYS = array( 'dateMode', 'textareaCols', 'textareaRows', 'presentationContext', 'validators', 'choiceFilter', 'compute' );

	/** Settings.view keys owned by the typeExtras bridge. */
	public const TYPE_EXTRAS_VIEW_KEYS = array( 'preferredConverter' );

	/**
	 * Map scaffold typeExtras → Relation Settings.data / Settings.view deltas.
	 *
	 * @param array<string, mixed> $extras Normalized or raw typeExtras.
	 * @return array{data?:array<string,mixed>,view?:array<string,mixed>}
	 */
	public static function deltas_from_type_extras( array $extras ): array {
		$extras = Attribute::normalize_type_extras( $extras );
		$data   = array();
		$view   = array();

		if ( isset( $extras['dateMode'] ) ) {
			$data['dateMode'] = $extras['dateMode'];
		}
		if ( isset( $extras['textareaCols'] ) ) {
			$data['textareaCols'] = $extras['textareaCols'];
		}
		if ( isset( $extras['textareaRows'] ) ) {
			$data['textareaRows'] = $extras['textareaRows'];
		}
		if ( isset( $extras['presentationContext'] ) ) {
			$data['presentationContext'] = $extras['presentationContext'];
		}
		if ( isset( $extras['validators'] ) && is_array( $extras['validators'] ) ) {
			$data['validators'] = $extras['validators'];
		}
		if ( isset( $extras['choiceFilter'] ) && is_array( $extras['choiceFilter'] ) ) {
			$data['choiceFilter'] = $extras['choiceFilter'];
		}
		if ( isset( $extras['compute'] ) && is_array( $extras['compute'] ) ) {
			$data['compute'] = $extras['compute'];
		}
		if ( isset( $extras['preferredConverter'] ) && is_string( $extras['preferredConverter'] ) && '' !== $extras['preferredConverter'] ) {
			$view['preferredConverter'] = $extras['preferredConverter'];
		} elseif ( isset( $extras['displayFormat'] ) && is_string( $extras['displayFormat'] ) && '' !== $extras['displayFormat'] ) {
			$view['preferredConverter'] = $extras['displayFormat'];
		}

		$out = array();
		if ( ! empty( $data ) ) {
			$out['data'] = $data;
		}
		if ( ! empty( $view ) ) {
			$out['view'] = $view;
		}
		return $out;
	}

	/**
	 * Extract typeExtras-shaped bag from Relation Settings deltas (edge preferred).
	 *
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>}|null $settings
	 * @return array<string, mixed>
	 */
	public static function type_extras_from_deltas( ?array $settings ): array {
		if ( ! is_array( $settings ) ) {
			return array();
		}
		$deltas = Relation::normalize_settings_deltas( $settings );
		if ( null === $deltas ) {
			return array();
		}
		$raw  = array();
		$data = isset( $deltas['data'] ) && is_array( $deltas['data'] ) ? $deltas['data'] : array();
		$view = isset( $deltas['view'] ) && is_array( $deltas['view'] )
			? self::normalize_view_bag( $deltas['view'] )
			: array();

		if ( array_key_exists( 'dateMode', $data ) ) {
			$raw['dateMode'] = $data['dateMode'];
		}
		if ( array_key_exists( 'textareaCols', $data ) ) {
			$raw['textareaCols'] = $data['textareaCols'];
		}
		if ( array_key_exists( 'textareaRows', $data ) ) {
			$raw['textareaRows'] = $data['textareaRows'];
		}
		if ( array_key_exists( 'presentationContext', $data ) ) {
			$raw['presentationContext'] = $data['presentationContext'];
		}
		if ( array_key_exists( 'validators', $data ) ) {
			$raw['validators'] = $data['validators'];
		}
		if ( array_key_exists( 'choiceFilter', $data ) ) {
			$raw['choiceFilter'] = $data['choiceFilter'];
		}
		if ( array_key_exists( 'compute', $data ) ) {
			$raw['compute'] = $data['compute'];
		}
		if ( self::bag_has_key( $view, 'preferredConverter' ) ) {
			$raw['preferredConverter'] = self::view_string( $view, 'preferredConverter' );
		}

		return Attribute::normalize_type_extras( $raw );
	}

	/**
	 * Inherited / hybrid read: edge deltas win; host `_wtt_attribute_type_extras` fills gaps.
	 * Own attrs use {@see type_extras_from_deltas()} only (≈ 0.0.431).
	 *
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>}|null $edge_settings
	 * @param array<string, mixed>                                              $host_extras
	 * @return array<string, mixed>
	 */
	public static function merge_type_extras_hybrid( ?array $edge_settings, array $host_extras ): array {
		$host = Attribute::normalize_type_extras( $host_extras );
		$edge = self::type_extras_from_deltas( $edge_settings );
		if ( empty( $edge ) ) {
			return $host;
		}
		return Attribute::normalize_type_extras( array_merge( $host, $edge ) );
	}

	/**
	 * Hybrid Settings deltas: host/heir override wins over father edge (Q66 / OQ-W5).
	 * Merges top-level data/view keys and nested[path] bags; never mutates inputs.
	 *
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}|null $base
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}|null $override
	 * @return array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}|null
	 */
	public static function merge_settings_deltas_hybrid( ?array $base, ?array $override ): ?array {
		$base_n = Relation::normalize_settings_deltas( $base );
		$over_n = Relation::normalize_settings_deltas( $override );
		if ( null === $over_n || array() === $over_n ) {
			return $base_n;
		}
		if ( null === $base_n || array() === $base_n ) {
			return $over_n;
		}

		$out = $base_n;
		foreach ( array( 'data', 'view' ) as $ns ) {
			if ( empty( $over_n[ $ns ] ) || ! is_array( $over_n[ $ns ] ) ) {
				continue;
			}
			$bag = isset( $out[ $ns ] ) && is_array( $out[ $ns ] ) ? $out[ $ns ] : array();
			$out[ $ns ] = array_merge( $bag, $over_n[ $ns ] );
		}
		if ( ! empty( $over_n['nested'] ) && is_array( $over_n['nested'] ) ) {
			$nested = isset( $out['nested'] ) && is_array( $out['nested'] ) ? $out['nested'] : array();
			foreach ( $over_n['nested'] as $path => $bag ) {
				if ( ! is_string( $path ) || ! is_array( $bag ) ) {
					continue;
				}
				$existing = isset( $nested[ $path ] ) && is_array( $nested[ $path ] )
					? $nested[ $path ]
					: array();
				$merged   = $existing;
				foreach ( array( 'data', 'view' ) as $ns ) {
					if ( empty( $bag[ $ns ] ) || ! is_array( $bag[ $ns ] ) ) {
						continue;
					}
					$ns_bag = isset( $merged[ $ns ] ) && is_array( $merged[ $ns ] )
						? $merged[ $ns ]
						: array();
					$merged[ $ns ] = array_merge( $ns_bag, $bag[ $ns ] );
				}
				$norm = Relation::normalize_settings_bag( $merged );
				if ( null !== $norm ) {
					$nested[ $path ] = $norm;
				} else {
					unset( $nested[ $path ] );
				}
			}
			if ( ! empty( $nested ) ) {
				$out['nested'] = $nested;
			} else {
				unset( $out['nested'] );
			}
		}

		return Relation::normalize_settings_deltas( $out );
	}

	/**
	 * Whether every host typeExtras key is already present on the edge with the same value.
	 * Used by one-shot prune after dual-write stop (redundant host debt).
	 *
	 * @param array<string, mixed> $host_norm   Normalized host bag.
	 * @param array<string, mixed> $edge_extras Normalized edge bag.
	 */
	public static function host_type_extras_covered_by_edge( array $host_norm, array $edge_extras ): bool {
		$host_norm   = Attribute::normalize_type_extras( $host_norm );
		$edge_extras = Attribute::normalize_type_extras( $edge_extras );
		if ( empty( $host_norm ) ) {
			return true;
		}
		if ( empty( $edge_extras ) ) {
			return false;
		}
		foreach ( $host_norm as $key => $value ) {
			if ( ! array_key_exists( $key, $edge_extras ) ) {
				return false;
			}
			if ( $edge_extras[ $key ] !== $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Remove typeExtras-owned keys from Settings; keep preferredRenderer and other view keys.
	 *
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>}|null $settings
	 * @return array{data?:array<string,mixed>,view?:array<string,mixed>}|null
	 */
	public static function strip_type_extras_from_settings( ?array $settings ): ?array {
		if ( ! is_array( $settings ) ) {
			return null;
		}
		$out = $settings;
		if ( isset( $out['data'] ) && is_array( $out['data'] ) ) {
			foreach ( self::TYPE_EXTRAS_DATA_KEYS as $key ) {
				unset( $out['data'][ $key ] );
			}
			if ( empty( $out['data'] ) ) {
				unset( $out['data'] );
			}
		}
		if ( isset( $out['view'] ) && is_array( $out['view'] ) ) {
			$view = self::normalize_view_bag( $out['view'] );
			foreach ( self::TYPE_EXTRAS_VIEW_KEYS as $key ) {
				unset( $view[ $key ] );
			}
			/* Drop legacy lower-case preferredconverter if present. */
			foreach ( array_keys( $view ) as $vk ) {
				if ( is_string( $vk ) && 'preferredconverter' === strtolower( $vk ) && 'preferredConverter' !== $vk ) {
					unset( $view[ $vk ] );
				}
			}
			if ( empty( $view ) ) {
				unset( $out['view'] );
			} else {
				$out['view'] = $view;
			}
		}
		return Relation::normalize_settings_deltas( $out );
	}

	/**
	 * Apply typeExtras onto existing Settings (preserves preferredRenderer).
	 *
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>}|null $settings
	 * @param array<string, mixed>|null                                         $extras Null/empty clears typeExtras keys only.
	 * @return array{data?:array<string,mixed>,view?:array<string,mixed>}|null
	 */
	public static function apply_type_extras_to_settings( ?array $settings, ?array $extras ): ?array {
		$base = self::strip_type_extras_from_settings( $settings );
		if ( null === $extras || array() === $extras ) {
			return $base;
		}
		$deltas = self::deltas_from_type_extras( $extras );
		if ( empty( $deltas ) ) {
			return $base;
		}
		$merged = is_array( $base ) ? $base : array();
		if ( isset( $deltas['data'] ) && is_array( $deltas['data'] ) ) {
			$merged['data'] = isset( $merged['data'] ) && is_array( $merged['data'] )
				? array_merge( $merged['data'], $deltas['data'] )
				: $deltas['data'];
		}
		if ( isset( $deltas['view'] ) && is_array( $deltas['view'] ) ) {
			$merged_view = isset( $merged['view'] ) && is_array( $merged['view'] )
				? self::normalize_view_bag( $merged['view'] )
				: array();
			$merged['view'] = array_merge( $merged_view, self::normalize_view_bag( $deltas['view'] ) );
		}
		return Relation::normalize_settings_deltas( $merged );
	}

	/**
	 * Resolve preferred render for an attribute: hybrid live type + Relation view deltas,
	 * with legacy slot meta as synthetic override when present.
	 *
	 * Clear semantics: presence of a non-empty `preferredRenderer` delta = override.
	 * Empty-string delta (stale) does not count as override — delete the key on save.
	 *
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>}|null $edge_settings
	 * @return array{value:string,hasOverride:bool,typePreferred:string,preferredSource:string,resolved:array{data:array<string,mixed>,view:array<string,mixed>},walkMeta:array{nodeCount:int,cycleStops:int,depth:int,preferredSource:string,hasPreferredOverride:bool},walkSummary:list<array{depth:int,name:string,edgeName:string,preferred:string,hasDelta:bool,cycleStopped:bool}>}
	 */
	public static function resolve_preferred_render(
		string $taxonomy,
		int $type_id,
		?array $edge_settings,
		int $legacy_slot_id = 0,
		bool $include_walk_summary = true
	): array {
		$deltas = is_array( $edge_settings )
			? Relation::normalize_settings_deltas( $edge_settings )
			: null;
		$delta_view = ( is_array( $deltas ) && isset( $deltas['view'] ) && is_array( $deltas['view'] ) )
			? self::normalize_view_bag( $deltas['view'] )
			: array();

		/*
		 * Root Preferred / settingsResolved = depth-0 hybrid only (children do not
		 * change root resolved). Deep walk + summary is optional for Options UI.
		 */
		$walk     = self::walk( $taxonomy, $type_id, $deltas, 0 );
		$resolved = isset( $walk['resolved'] ) && is_array( $walk['resolved'] )
			? $walk['resolved']
			: array(
				'data' => array(),
				'view' => array(),
			);

		$type_preferred = $type_id > 0
			? Node_Type::get_preferred_render( $type_id )
			: Renderer::Form->value;

		$delta_pref    = self::view_string( $delta_view, 'preferredRenderer' );
		$has_delta_key = self::bag_has_key( $delta_view, 'preferredRenderer' );
		/* Non-empty edge delta only — empty string = cleared (delete key on save). */
		$edge_override = $has_delta_key && '' !== $delta_pref;

		$legacy_has = $legacy_slot_id > 0
			&& metadata_exists( 'term', $legacy_slot_id, Node_Type::META_KEY_PREFERRED_RENDER );

		if ( $edge_override ) {
			$value  = Node_Type::normalize_preferred_render( $delta_pref );
			$source = 'edge';
		} elseif ( $has_delta_key && '' === $delta_pref ) {
			/* Stale empty key — treat as cleared; prefer deleting the key on next save. */
			$value  = $type_preferred;
			$source = 'type';
		} elseif ( $legacy_has ) {
			$value  = Node_Type::get_preferred_render( $legacy_slot_id );
			$source = 'legacy';
		} else {
			$from_resolved = self::view_string(
				isset( $resolved['view'] ) && is_array( $resolved['view'] ) ? $resolved['view'] : array(),
				'preferredRenderer'
			);
			if ( '' !== $from_resolved ) {
				$value  = Node_Type::normalize_preferred_render( $from_resolved );
				$source = 'walk';
			} else {
				$value  = $type_preferred;
				$source = 'type';
			}
		}

		$has_override = $edge_override || $legacy_has;
		$walk_meta    = self::meta_from_walk( $walk );
		$walk_meta['preferredSource']      = $source;
		$walk_meta['hasPreferredOverride'] = $has_override;

		$walk_summary  = array();
		$may_be_nested = $type_id > 0
			&& (
				self::edge_has_nested_deltas( $edge_settings )
				|| (
					class_exists( Attribute::class )
					&& Attribute::type_has_attributes( $taxonomy, $type_id )
				)
			);

		if ( $include_walk_summary && $may_be_nested ) {
			$deep                             = self::walk( $taxonomy, $type_id, $deltas, self::SAFETY_DEPTH );
			$walk_meta                        = self::meta_from_walk( $deep );
			$walk_meta['preferredSource']     = $source;
			$walk_meta['hasPreferredOverride'] = $has_override;
			/*
			 * Always emit at least the depth-0 type row. Gating on walk_is_nested
			 * left Options with nodeCount=1 + empty levels → perpetual “Loading…”.
			 */
			$walk_summary = self::summary_from_walk( $deep );
		} elseif ( $include_walk_summary ) {
			/*
			 * Depth-0 only (no nested attributes): still one Settings surface
			 * (parity — Knoten-/Attribut-Walk even when the type has no children).
			 */
			$walk_summary = self::summary_from_walk( $walk );
		} elseif ( ! $include_walk_summary && $may_be_nested ) {
			/* Options fold loads summary via wtt_get_attribute_settings_walk. */
			$walk_meta['lazy'] = true;
		}

		return array(
			'value'           => $value,
			'hasOverride'     => $has_override,
			'typePreferred'   => $type_preferred,
			'preferredSource' => $source,
			'resolved'        => $resolved,
			'walkMeta'        => $walk_meta,
			'walkSummary'     => $walk_summary,
		);
	}

	/**
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>}|null                               $incoming_deltas
	 * @param list<int>                                                                                     $seen Node ids already on the walk.
	 * @param array{nodeCount:int,cycleStops:int,maxDepthReached:int}                                       $stats
	 * @param array{data?:array<string,mixed>,view?:array<string,mixed>,nested?:array<string,mixed>}|null $root_full Attribute edge settings (incl. nested).
	 * @return array<string, mixed>
	 */
	private static function walk_node(
		string $taxonomy,
		int $node_id,
		?array $incoming_deltas,
		array $seen,
		int $depth,
		int $max_depth,
		array &$stats,
		string $edge_id,
		string $edge_name,
		string $walk_path,
		?array $root_full
	): array {
		$term = get_term( $node_id, $taxonomy );
		$name = $term instanceof \WP_Term ? $term->name : '';

		$path_deltas = self::deltas_for_walk_path( $root_full, $walk_path );
		/* Attribute path deltas win over type-tree child-edge bags. */
		$effective_deltas = self::merge_delta_bags( $incoming_deltas, $path_deltas );

		$live     = self::live_for_node( $taxonomy, $node_id );
		$resolved = self::merge_hybrid( $live, $effective_deltas );

		++$stats['nodeCount'];
		if ( $depth > $stats['maxDepthReached'] ) {
			$stats['maxDepthReached'] = $depth;
		}

		$node = array(
			'taxonomy'     => $taxonomy,
			'nodeId'       => $node_id,
			'name'         => $name,
			'edgeId'       => $edge_id,
			'edgeName'     => $edge_name,
			'path'         => $walk_path,
			'live'         => $live,
			'deltas'       => $effective_deltas,
			'pathDeltas'   => $path_deltas,
			'resolved'     => $resolved,
			'children'     => array(),
			'cycleStopped' => false,
			'depthStopped' => false,
		);

		if ( in_array( $node_id, $seen, true ) ) {
			$node['cycleStopped'] = true;
			++$stats['cycleStops'];
			return $node;
		}

		if ( $depth >= $max_depth ) {
			$node['depthStopped'] = true;
			return $node;
		}

		$next_seen   = $seen;
		$next_seen[] = $node_id;
		$children    = array();

		/*
		 * CatalogChoice roots (Unit With/Without prefix buckets): paint picks leaves
		 * under the bucket — do not walk Praefix/Kuerzel composition under them, or
		 * Options on structure attrs (e.g. Wert→Unit type) explode into cryptic paths.
		 */
		$skip_structure_walk = $node_id > 0
			&& Node_Type::is_unit_prefix_bucket( $taxonomy, $node_id );

		if ( ! $skip_structure_walk ) {
		/*
		 * Effective attributes along child_of (Organisation inherits Kontakt).
		 * Own-only outgoing edges left specializations empty in the Options walk.
		 */
		foreach ( Attribute::effective_edges_for_settings_walk( $taxonomy, $node_id ) as $edge ) {
				$to_id = (int) ( $edge['typeId'] ?? $edge['toId'] ?? 0 );
				if ( $to_id <= 0 ) {
					continue;
				}

				/*
				 * Target is the type node for Q123 edges; legacy slots use _wtt_type_id.
				 * Walk the effective type so Settings follow the product target tree.
				 */
				$child_node_id = $to_id;
				if ( Attribute::is_slot( $to_id ) ) {
					$slot_type = Node_Type::get_type_id( $to_id );
					if ( $slot_type <= 0 ) {
						continue;
					}
					$child_node_id = $slot_type;
				}

				$child_edge_id   = Attribute::normalize_attr_id( $edge['id'] ?? $edge['edgeId'] ?? '' );
				if ( '' === $child_edge_id ) {
					continue;
				}
				$child_edge_name = Relation::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
				if ( '' === $child_edge_name && $term instanceof \WP_Term ) {
					$to = get_term( $to_id, $taxonomy );
					$child_edge_name = $to instanceof \WP_Term ? $to->name : '';
				}

				$child_path = '' === $walk_path
					? $child_edge_id
					: $walk_path . '/' . $child_edge_id;

				/* Type-tree composition edge bag only (data/view); nested lives on attribute edge. */
				$child_edge_bag = null;
				if ( isset( $edge['settings'] ) && is_array( $edge['settings'] ) ) {
					$child_edge_bag = Relation::normalize_settings_bag( $edge['settings'] );
				}

				$child_node = self::walk_node(
					$taxonomy,
					$child_node_id,
					$child_edge_bag,
					$next_seen,
					$depth + 1,
					$max_depth,
					$stats,
					$child_edge_id,
					$child_edge_name,
					$child_path,
					$root_full
				);
				/* Composition-edge RO / Hide = type defaults for nested walk overrides. */
				$child_node['edgeReadOnly'] = ! empty( $edge['readOnly'] ) || ! empty( $edge['readonly'] );
				$child_node['edgeHidden']   = ! empty( $edge['hidden'] );
				$children[]                 = $child_node;
		}
		} // end !skip_structure_walk

		$node['children'] = $children;
		return $node;
	}
}
