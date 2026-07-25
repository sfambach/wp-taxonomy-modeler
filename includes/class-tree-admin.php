<?php
/**
 * Admin tree screen.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Taxonomy Tree admin page and assets.
 */
final class Tree_Admin {

	public const PAGE_SLUG = 'wp-taxonomy-tree';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'Taxonomy Tree', 'wp-taxonomy-tree' ),
			__( 'Taxonomy Tree', 'wp-taxonomy-tree' ),
			'manage_categories',
			self::PAGE_SLUG,
			array( self::class, 'render_page' ),
			'dashicons-networking',
			58
		);
	}

	/**
	 * Whether the current admin request is our screen.
	 */
	private static function is_plugin_screen( string $hook_suffix ): bool {
		if ( 'toplevel_page_' . self::PAGE_SLUG === $hook_suffix ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return self::PAGE_SLUG === $page;
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		if ( ! self::is_plugin_screen( $hook_suffix ) ) {
			return;
		}

		$css_rel = 'assets/css/tree-admin.css';
		$js_rel  = 'assets/js/tree-admin.js';
		$css_abs = WTT_PLUGIN_DIR . $css_rel;
		$js_abs  = WTT_PLUGIN_DIR . $js_rel;

		if ( is_readable( $css_abs ) ) {
			wp_enqueue_style(
				'wtt-tree-admin',
				plugins_url( $css_rel, WTT_PLUGIN_FILE ),
				array( 'dashicons' ),
				(string) filemtime( $css_abs )
			);
		}

		if ( is_readable( $js_abs ) ) {
			wp_enqueue_script(
				'wtt-tree-admin',
				plugins_url( $js_rel, WTT_PLUGIN_FILE ),
				array(),
				(string) filemtime( $js_abs ),
				true
			);
		}

		$taxonomies = Tree_Model::hierarchical_taxonomies();
		$default    = 'category';
		if ( ! empty( $taxonomies ) ) {
			$slugs = array_column( $taxonomies, 'slug' );
			if ( ! in_array( $default, $slugs, true ) ) {
				$default = (string) $slugs[0];
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : $default;
		if ( ! Tree_Model::is_hierarchical_taxonomy( $requested ) ) {
			$requested = $default;
		}

		$config = array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( Tree_Ajax::NONCE_ACTION ),
			'taxonomy'     => $requested,
			'taxonomies'   => $taxonomies,
			'tree'         => Tree_Model::get_tree( $requested ),
			'version'      => WTT_VERSION,
			'assetsOk'     => is_readable( $css_abs ) && is_readable( $js_abs ),
			'pluginUrl'    => plugins_url( '/', WTT_PLUGIN_FILE ),
			'i18n'         => array(
				'empty'           => __( 'No terms yet. Create a root node to start the tree.', 'wp-taxonomy-tree' ),
				'selectHint'      => __( 'Select a node to inspect it. Domain model (Project / Node / Parameter) is still in planning - this screen is the taxonomy-tree scaffold.', 'wp-taxonomy-tree' ),
				'loading'         => __( 'Loading...', 'wp-taxonomy-tree' ),
				'addRoot'         => __( 'Add root', 'wp-taxonomy-tree' ),
				'addChild'        => __( 'Add child', 'wp-taxonomy-tree' ),
				'delete'          => __( 'Delete', 'wp-taxonomy-tree' ),
				'name'            => __( 'Name', 'wp-taxonomy-tree' ),
				'slug'            => __( 'Slug', 'wp-taxonomy-tree' ),
				'parent'          => __( 'Parent', 'wp-taxonomy-tree' ),
				'description'     => __( 'Description', 'wp-taxonomy-tree' ),
				'count'           => __( 'Assigned posts', 'wp-taxonomy-tree' ),
				'none'            => __( '- None -', 'wp-taxonomy-tree' ),
				'promptRoot'      => __( 'Name for the new root term:', 'wp-taxonomy-tree' ),
				'promptChild'     => __( 'Name for the new child term:', 'wp-taxonomy-tree' ),
				'confirmLeaf'     => __( 'Delete this term?', 'wp-taxonomy-tree' ),
				'dialogTitle'     => __( 'Delete term with children', 'wp-taxonomy-tree' ),
				'dialogText'      => __( 'This term has children. What should happen to them?', 'wp-taxonomy-tree' ),
				'promoteChildren' => __( 'Move children up one level', 'wp-taxonomy-tree' ),
				'deleteChildren'  => __( 'Delete children as well', 'wp-taxonomy-tree' ),
				'cancel'          => __( 'Cancel', 'wp-taxonomy-tree' ),
				'error'           => __( 'Something went wrong.', 'wp-taxonomy-tree' ),
				'taxonomy'        => __( 'Taxonomy', 'wp-taxonomy-tree' ),
				'scaffoldBadge'   => __( 'Scaffold 0.0.2', 'wp-taxonomy-tree' ),
			),
		);

		if ( is_readable( $js_abs ) ) {
			wp_localize_script( 'wtt-tree-admin', 'wttTree', $config );
		} else {
			// Still expose config for diagnostics when JS file is missing.
			wp_add_inline_script(
				'jquery',
				'window.wttTree = ' . wp_json_encode( $config ) . ';',
				'before'
			);
		}
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-taxonomy-tree' ) );
		}

		$css_ok = is_readable( WTT_PLUGIN_DIR . 'assets/css/tree-admin.css' );
		$js_ok  = is_readable( WTT_PLUGIN_DIR . 'assets/js/tree-admin.js' );
		?>
		<div class="wrap wtt-wrap">
			<h1>
				<?php esc_html_e( 'Taxonomy Tree', 'wp-taxonomy-tree' ); ?>
				<span class="wtt-badge" id="wtt-badge"><?php esc_html_e( 'Scaffold 0.0.2', 'wp-taxonomy-tree' ); ?></span>
			</h1>
			<p class="description" id="wtt-intro">
				<?php esc_html_e( 'Select a node to inspect it. Domain model (Project / Node / Parameter) is still in planning - this screen is the taxonomy-tree scaffold.', 'wp-taxonomy-tree' ); ?>
			</p>
			<?php if ( ! $css_ok || ! $js_ok ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: CSS path status, 2: JS path status */
								__( 'Plugin assets missing. CSS: %1$s / JS: %2$s. Pull branch cursor/plugin-scaffold-f17e and ensure the assets/ folder exists next to wp-taxonomy-tree.php.', 'wp-taxonomy-tree' ),
								$css_ok ? 'OK' : 'MISSING',
								$js_ok ? 'OK' : 'MISSING'
							)
						);
						?>
					</p>
					<p><code><?php echo esc_html( WTT_PLUGIN_DIR ); ?></code></p>
				</div>
			<?php endif; ?>
			<div id="wtt-app" class="wtt-app" aria-live="polite">
				<p class="wtt-empty"><?php esc_html_e( 'Loading tree UI...', 'wp-taxonomy-tree' ); ?></p>
			</div>
		</div>
		<?php
	}
}
