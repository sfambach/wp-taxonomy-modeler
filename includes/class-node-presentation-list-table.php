<?php
/**
 * Node presentation WP_List_Table (admin-only; requires WP_List_Table).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List table: one row per taxonomy term with completeness + quick edit link.
 */
final class Node_Presentation_List_Table extends \WP_List_Table {

	private string $taxonomy;

	private bool $incomplete_only;

	private string $search;

	/**
	 * @param array{taxonomy?:string,incomplete?:bool,search?:string} $args
	 */
	public function __construct( array $args = array() ) {
		parent::__construct(
			array(
				'singular' => 'wtt_presentation_node',
				'plural'   => 'wtt_presentation_nodes',
				'ajax'     => false,
			)
		);
		$this->taxonomy        = (string) ( $args['taxonomy'] ?? Taxonomy::default_slug() );
		$this->incomplete_only = ! empty( $args['incomplete'] );
		$this->search          = (string) ( $args['search'] ?? '' );
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'name'   => __( 'Node', 'wp-taxonomy-tree' ),
			'form'   => __( 'Form', 'wp-taxonomy-tree' ),
			'table'  => __( 'Table', 'wp-taxonomy-tree' ),
			'select' => __( 'Select', 'wp-taxonomy-tree' ),
			'symbol' => __( 'Symbol', 'wp-taxonomy-tree' ),
			'help'   => __( 'Help', 'wp-taxonomy-tree' ),
			'icon'   => __( 'Icon', 'wp-taxonomy-tree' ),
			'status' => __( 'Status', 'wp-taxonomy-tree' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_sortable_columns() {
		return array(
			'name' => array( 'name', false ),
		);
	}

	public function prepare_items(): void {
		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		$terms = get_terms(
			array(
				'taxonomy'   => $this->taxonomy,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		$rows = array();
		if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
			$locale = Node_Presentation::site_locale();
			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				if ( '' !== $this->search ) {
					$hay = strtolower( $term->name . ' ' . $term->slug );
					if ( false === strpos( $hay, strtolower( $this->search ) ) ) {
						continue;
					}
				}
				$map      = Node_Presentation::map_for_term_ui( (int) $term->term_id, $locale );
				$complete = Node_Presentation::is_complete( (int) $term->term_id, $locale );
				if ( $this->incomplete_only && $complete ) {
					continue;
				}
				$rows[] = array(
					'term'     => $term,
					'map'      => $map,
					'complete' => $complete,
				);
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['term']->name, (string) $b['term']->name );
			}
		);

		$per_page     = 40;
		$current_page = $this->get_pagenum();
		$total        = count( $rows );
		$this->items  = array_slice( $rows, ( $current_page - 1 ) * $per_page, $per_page );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * @param array{term:\WP_Term,map:array<string,string>,complete:bool} $item
	 */
	protected function column_name( $item ): string {
		$term    = $item['term'];
		$url     = Node_Presentation_Admin::page_url( (int) $term->term_id, $this->taxonomy );
		$name    = esc_html( $term->name );
		$edit    = '<a href="' . esc_url( $url ) . '"><strong>' . $name . '</strong></a>';
		$actions = array(
			'edit' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Edit', 'wp-taxonomy-tree' ) . '</a>',
		);
		return $edit . $this->row_actions( $actions );
	}

	/**
	 * @param array{term:\WP_Term,map:array<string,string>,complete:bool} $item
	 * @param string                                                         $column_name
	 */
	protected function column_default( $item, $column_name ) {
		$map = $item['map'];
		if ( 'status' === $column_name ) {
			return $item['complete']
				? '<span class="wtt-pres-ok">' . esc_html__( 'Complete', 'wp-taxonomy-tree' ) . '</span>'
				: '<span class="wtt-pres-incomplete">' . esc_html__( 'Incomplete', 'wp-taxonomy-tree' ) . '</span>';
		}
		if ( isset( $map[ $column_name ] ) ) {
			$val = (string) $map[ $column_name ];
			if ( '' === $val ) {
				return '<span class="wtt-pres-empty">—</span>';
			}
			if ( 'icon' === $column_name ) {
				return '<span class="dashicons dashicons-' . esc_attr( $val ) . '" title="' . esc_attr( $val ) . '"></span> '
					. '<code>' . esc_html( $val ) . '</code>';
			}
			$short = function_exists( 'mb_substr' ) ? mb_substr( $val, 0, 40 ) : substr( $val, 0, 40 );
			if ( strlen( $val ) > 40 ) {
				$short .= '…';
			}
			return esc_html( $short );
		}
		return '';
	}

	/**
	 * @param string $which top|bottom
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$base = admin_url( 'admin.php' );
		echo '<div class="alignleft actions">';
		printf(
			'<a class="button%s" href="%s">%s</a> ',
			$this->incomplete_only ? ' button-primary' : '',
			esc_url(
				add_query_arg(
					array(
						'page'       => Node_Presentation_Admin::PAGE_SLUG,
						'taxonomy'   => $this->taxonomy,
						'incomplete' => '1',
					),
					$base
				)
			),
			esc_html__( 'Incomplete only', 'wp-taxonomy-tree' )
		);
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url(
				add_query_arg(
					array(
						'page'     => Node_Presentation_Admin::PAGE_SLUG,
						'taxonomy' => $this->taxonomy,
					),
					$base
				)
			),
			esc_html__( 'All', 'wp-taxonomy-tree' )
		);
		echo '</div>';
	}
}
