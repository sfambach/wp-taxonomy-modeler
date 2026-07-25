<?php
/**
 * Demo / test taxonomy tree seeder.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs the shared test category tree (idempotent).
 */
final class Demo_Data {

	/**
	 * Default demo tree used in local/cloud testing.
	 *
	 * @return array<int, array{name:string, children?:array<int, array<string, mixed>>}>
	 */
	public static function blueprint(): array {
		return array(
			array(
				'name'     => 'Passive Components',
				'children' => array(
					array(
						'name'     => 'Resistors',
						'children' => array(
							array( 'name' => 'SMD 0805' ),
						),
					),
					array( 'name' => 'Capacitors' ),
				),
			),
			array(
				'name'     => 'Semiconductors',
				'children' => array(
					array( 'name' => 'Transistors' ),
				),
			),
		);
	}

	/**
	 * Ensure demo terms exist under a hierarchical taxonomy.
	 *
	 * @return array{created:int,existing:int,taxonomy:string}|\WP_Error
	 */
	public static function install( string $taxonomy = 'category' ) {
		if ( ! Tree_Model::is_hierarchical_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Not a hierarchical taxonomy.', 'wp-taxonomy-tree' ) );
		}

		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			return new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) );
		}

		$created  = 0;
		$existing = 0;
		self::install_nodes( $taxonomy, self::blueprint(), 0, $created, $existing );

		return array(
			'created'  => $created,
			'existing' => $existing,
			'taxonomy' => $taxonomy,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $nodes Nodes to ensure.
	 */
	private static function install_nodes( string $taxonomy, array $nodes, int $parent_id, int &$created, int &$existing ): void {
		foreach ( $nodes as $node ) {
			$name = isset( $node['name'] ) ? (string) $node['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			$term_id = self::ensure_term( $taxonomy, $name, $parent_id, $created, $existing );
			if ( $term_id <= 0 ) {
				continue;
			}

			$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
			if ( ! empty( $children ) ) {
				self::install_nodes( $taxonomy, $children, $term_id, $created, $existing );
			}
		}
	}

	private static function ensure_term( string $taxonomy, string $name, int $parent_id, int &$created, int &$existing ): int {
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
			return (int) $found[0]->term_id;
		}

		$result = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'parent' => max( 0, $parent_id ),
			)
		);

		if ( is_wp_error( $result ) ) {
			// Race / duplicate slug under same parent.
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
