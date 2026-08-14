<?php
/**
 * Node presentation admin list (Q117) — WP_List_Table before Settings.
 *
 * Deep-link: ?page=wp-taxonomy-tree-presentation&term_id={id}
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Presentation submenu + list UI.
 */
final class Node_Presentation_Admin {

	public const PAGE_SLUG = 'wp-taxonomy-tree-presentation';

	public const NONCE_ACTION = 'wtt_node_presentation';

	public static function register(): void {
		/* Priority 18: after Cleanup (17), before Settings (20). */
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 18 );
		add_action( 'admin_init', array( self::class, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wtt_get_node_presentation', array( self::class, 'ajax_get' ) );
		add_action( 'wp_ajax_wtt_save_node_presentation', array( self::class, 'ajax_save' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			Tree_Admin::PAGE_SLUG,
			__( 'Node presentation', 'wp-taxonomy-tree' ),
			__( 'Presentation', 'wp-taxonomy-tree' ),
			'manage_categories',
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Admin URL, optionally focused on a term.
	 *
	 * @param array<string, string|int> $extra Extra query args (e.g. return=tree).
	 */
	public static function page_url( int $term_id = 0, string $taxonomy = '', array $extra = array() ): string {
		$args = array( 'page' => self::PAGE_SLUG );
		if ( $term_id > 0 ) {
			$args['term_id'] = $term_id;
		}
		if ( '' !== $taxonomy ) {
			$args['taxonomy'] = $taxonomy;
		}
		foreach ( $extra as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$args[ $key ] = is_int( $value ) ? $value : sanitize_text_field( (string) $value );
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page && false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		$ver = defined( 'WTT_VERSION' ) ? WTT_VERSION : '0.0.1';
		wp_enqueue_style(
			'wtt-presentation-admin',
			WTT_PLUGIN_URL . 'assets/css/presentation-admin.css',
			array(),
			$ver
		);
	}

	public static function handle_save(): void {
		if ( isset( $_POST['wtt_presentation_fill'] ) ) {
			self::handle_fill();
			return;
		}
		if ( ! isset( $_POST['wtt_presentation_save'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit presentation.', 'wp-taxonomy-tree' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$term_id  = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$locale   = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : Node_Presentation::site_locale();

		if ( $term_id <= 0 || ! Taxonomy::is_scaffold( $taxonomy ) ) {
			wp_safe_redirect( self::page_url( 0, $taxonomy ) );
			exit;
		}

		$values = isset( $_POST['wtt_pres'] ) && is_array( $_POST['wtt_pres'] )
			? wp_unslash( $_POST['wtt_pres'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		foreach ( Node_Presentation::TEXT_CONTEXTS as $ctx ) {
			$raw = isset( $values[ $ctx ] ) ? (string) $values[ $ctx ] : '';
			Node_Presentation::set( $term_id, $ctx, $locale, $raw );
		}
		$icon = isset( $values[ Node_Presentation::CONTEXT_ICON ] )
			? (string) $values[ Node_Presentation::CONTEXT_ICON ]
			: '';
		Node_Presentation::set( $term_id, Node_Presentation::CONTEXT_ICON, Node_Presentation::LOCALE_INVARIANT, $icon );

		wp_safe_redirect(
			add_query_arg(
				array_filter(
					array(
						'page'     => self::PAGE_SLUG,
						'taxonomy' => $taxonomy,
						'term_id'  => $term_id,
						'updated'  => '1',
						'return'   => isset( $_POST['wtt_return'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
							? sanitize_key( wp_unslash( $_POST['wtt_return'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
							: '',
					),
					static function ( $v ) {
						return '' !== $v && null !== $v;
					}
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Bulk fill empty presentation slots from name / short / description / icon.
	 */
	private static function handle_fill(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit presentation.', 'wp-taxonomy-tree' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : Taxonomy::default_slug();
		if ( ! Taxonomy::is_scaffold( $taxonomy ) ) {
			$taxonomy = Taxonomy::default_slug();
		}
		$n = Node_Presentation::fill_taxonomy_from_legacy( $taxonomy );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'taxonomy'   => $taxonomy,
					'wtt_filled' => (string) $n,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function ajax_get(): void {
		check_ajax_referer( Tree_Ajax::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		$term_id  = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		if ( $term_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'bad_term' ), 400 );
		}
		if ( '' !== $taxonomy ) {
			Node_Presentation::maybe_migrate_taxonomy( $taxonomy );
		}
		$locale = Node_Presentation::site_locale();
		$term   = get_term( $term_id );
		$raw    = array();
		foreach ( Node_Presentation::TEXT_CONTEXTS as $ctx ) {
			$raw[ $ctx ] = Node_Presentation::get_raw( $term_id, $ctx, $locale );
		}
		$raw[ Node_Presentation::CONTEXT_ICON ] = Node_Presentation::get_raw(
			$term_id,
			Node_Presentation::CONTEXT_ICON,
			Node_Presentation::LOCALE_INVARIANT
		);
		wp_send_json_success(
			array(
				'termId'   => $term_id,
				'locale'   => $locale,
				'nodeName' => ( $term instanceof \WP_Term ) ? (string) $term->name : '',
				'raw'      => $raw,
				'values'   => Node_Presentation::map_for_term_ui( $term_id, $locale ),
				'listUrl'  => self::page_url( $term_id, $taxonomy ),
			)
		);
	}

	public static function ajax_save(): void {
		check_ajax_referer( Tree_Ajax::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
		$locale  = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : Node_Presentation::site_locale();
		if ( $term_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'bad_term' ), 400 );
		}
		$raw = isset( $_POST['values'] ) ? wp_unslash( $_POST['values'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		foreach ( Node_Presentation::TEXT_CONTEXTS as $ctx ) {
			if ( array_key_exists( $ctx, $raw ) ) {
				Node_Presentation::set( $term_id, $ctx, $locale, (string) $raw[ $ctx ] );
			}
		}
		if ( array_key_exists( Node_Presentation::CONTEXT_ICON, $raw ) ) {
			Node_Presentation::set(
				$term_id,
				Node_Presentation::CONTEXT_ICON,
				Node_Presentation::LOCALE_INVARIANT,
				(string) $raw[ Node_Presentation::CONTEXT_ICON ]
			);
		}
		wp_send_json_success(
			array(
				'termId' => $term_id,
				'values' => Node_Presentation::map_for_term_ui( $term_id, $locale ),
			)
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-taxonomy-tree' ) );
		}

		$taxonomy = Taxonomy::default_slug();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['taxonomy'] ) ) {
			$requested = sanitize_key( wp_unslash( $_GET['taxonomy'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( Taxonomy::is_scaffold( $requested ) ) {
				$taxonomy = $requested;
			}
		}

		Node_Presentation::maybe_migrate_taxonomy( $taxonomy );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$focus_id = isset( $_GET['term_id'] ) ? absint( wp_unslash( $_GET['term_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$incomplete = isset( $_GET['incomplete'] ) && '1' === (string) wp_unslash( $_GET['incomplete'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$context_filter = isset( $_GET['context'] ) ? sanitize_key( wp_unslash( $_GET['context'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		if ( $focus_id > 0 ) {
			self::render_edit_form( $taxonomy, $focus_id );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once WTT_PLUGIN_DIR . 'includes/class-node-presentation-list-table.php';
		$table = new Node_Presentation_List_Table(
			array(
				'taxonomy'   => $taxonomy,
				'incomplete' => $incomplete,
				'context'    => $context_filter,
				'search'     => $search,
			)
		);
		$table->prepare_items();

		echo '<div class="wrap wtt-presentation-admin">';
		echo '<h1>' . esc_html__( 'Node presentation', 'wp-taxonomy-tree' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Locale-aware labels (form, table, select, symbol, help) and locale-invariant icon. Same store for tree Display foldable and this list.', 'wp-taxonomy-tree' ) . '</p>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Presentation saved.', 'wp-taxonomy-tree' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['wtt_filled'] ) ) {
			$n = absint( wp_unslash( $_GET['wtt_filled'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of presentation values written */
					__( 'Filled %d empty presentation value(s) from existing name / short description / description / icon.', 'wp-taxonomy-tree' ),
					$n
				)
			) . '</p></div>';
		}

		echo '<form method="post" style="margin: 1em 0;">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="wtt_presentation_fill" value="1" />';
		echo '<input type="hidden" name="taxonomy" value="' . esc_attr( $taxonomy ) . '" />';
		submit_button(
			__( 'Fill empty from existing data', 'wp-taxonomy-tree' ),
			'secondary',
			'submit',
			false,
			array(
				'title' => __( 'Writes form/select/table/symbol/help/icon only where still empty.', 'wp-taxonomy-tree' ),
			)
		);
		echo '</form>';

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="taxonomy" value="' . esc_attr( $taxonomy ) . '" />';
		$table->search_box( __( 'Search nodes', 'wp-taxonomy-tree' ), 'wtt-pres' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	private static function render_edit_form( string $taxonomy, int $term_id ): void {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Node not found.', 'wp-taxonomy-tree' ) . '</p></div>';
			return;
		}

		$locale = Node_Presentation::site_locale();
		$map    = Node_Presentation::map_for_term_ui( $term_id, $locale );
		$icons  = Tree_Icons::enabled_keys();
		$catalog = Tree_Icons::catalog();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$return = isset( $_GET['return'] ) ? sanitize_key( wp_unslash( $_GET['return'] ) ) : '';
		if ( '' === $return && isset( $_POST['wtt_return'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$return = sanitize_key( wp_unslash( $_POST['wtt_return'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		echo '<div class="wrap wtt-presentation-admin">';
		echo '<h1>' . esc_html(
			sprintf(
				/* translators: %s: node name */
				__( 'Presentation: %s', 'wp-taxonomy-tree' ),
				$term->name
			)
		) . '</h1>';
		echo '<p class="wtt-presentation-admin__nav">';
		echo '<a href="' . esc_url( self::page_url( 0, $taxonomy ) ) . '">&larr; ' . esc_html__( 'Back to list', 'wp-taxonomy-tree' ) . '</a>';
		if ( 'tree' === $return ) {
			$tree_url = Tree_Admin::page_url( $term_id, $taxonomy );
			echo ' <span class="wtt-presentation-admin__nav-sep" aria-hidden="true">·</span> ';
			echo '<a href="' . esc_url( $tree_url ) . '">&larr; ' . esc_html__( 'Back to node', 'wp-taxonomy-tree' ) . '</a>';
		}
		echo '</p>';

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="wtt_presentation_save" value="1" />';
		echo '<input type="hidden" name="term_id" value="' . esc_attr( (string) $term_id ) . '" />';
		echo '<input type="hidden" name="taxonomy" value="' . esc_attr( $taxonomy ) . '" />';
		echo '<input type="hidden" name="locale" value="' . esc_attr( $locale ) . '" />';
		if ( 'tree' === $return ) {
			echo '<input type="hidden" name="wtt_return" value="tree" />';
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		$labels = array(
			'form'   => __( 'Form', 'wp-taxonomy-tree' ),
			'table'  => __( 'Table', 'wp-taxonomy-tree' ),
			'select' => __( 'Select list', 'wp-taxonomy-tree' ),
			'symbol' => __( 'Symbol / shortcut', 'wp-taxonomy-tree' ),
			'help'   => __( 'Help', 'wp-taxonomy-tree' ),
		);
		foreach ( $labels as $ctx => $label ) {
			$id = 'wtt-pres-' . $ctx;
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			$val = $map[ $ctx ] ?? '';
			if ( in_array( $ctx, array( 'help', 'form' ), true ) ) {
				echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="wtt_pres[' . esc_attr( $ctx ) . ']">' . esc_textarea( $val ) . '</textarea>';
			} else {
				echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="wtt_pres[' . esc_attr( $ctx ) . ']" value="' . esc_attr( $val ) . '" />';
			}
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: locale code */
					__( 'Locale: %s', 'wp-taxonomy-tree' ),
					$locale
				)
			) . '</p>';
			echo '</td></tr>';
		}

		echo '<tr><th scope="row"><label for="wtt-pres-icon">' . esc_html__( 'Icon', 'wp-taxonomy-tree' ) . '</label></th><td>';
		echo '<select id="wtt-pres-icon" name="wtt_pres[icon]">';
		echo '<option value="">' . esc_html__( 'None', 'wp-taxonomy-tree' ) . '</option>';
		$current_icon = $map[ Node_Presentation::CONTEXT_ICON ] ?? '';
		foreach ( $icons as $key ) {
			$label = $catalog[ $key ] ?? $key;
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $current_icon, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Not translated — same icon for all locales.', 'wp-taxonomy-tree' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save presentation', 'wp-taxonomy-tree' ) );
		echo '</form></div>';
	}
}
