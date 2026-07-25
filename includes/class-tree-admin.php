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

	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wtt-tree-admin',
			WTT_PLUGIN_URL . 'assets/css/tree-admin.css',
			array( 'dashicons' ),
			WTT_VERSION
		);

		wp_enqueue_script(
			'wtt-tree-admin',
			WTT_PLUGIN_URL . 'assets/js/tree-admin.js',
			array(),
			WTT_VERSION,
			true
		);

		$taxonomies = Tree_Model::hierarchical_taxonomies();
		$default    = 'category';
		if ( ! empty( $taxonomies ) ) {
			$slugs = array_column( $taxonomies, 'slug' );
			if ( ! in_array( $default, $slugs, true ) ) {
				$default = $slugs[0];
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : $default;
		if ( ! Tree_Model::is_hierarchical_taxonomy( $requested ) ) {
			$requested = $default;
		}

		wp_localize_script(
			'wtt-tree-admin',
			'wttTree',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( Tree_Ajax::NONCE_ACTION ),
				'taxonomy'   => $requested,
				'taxonomies' => $taxonomies,
				'tree'       => Tree_Model::get_tree( $requested ),
				'version'    => WTT_VERSION,
				'i18n'       => array(
					'empty'           => __( 'No terms yet. Create a root node to start the tree.', 'wp-taxonomy-tree' ),
					'selectHint'      => __( 'Select a node to inspect it. Domain model (Project / Node / Parameter) is still in planning — this screen is the taxonomy-tree scaffold.', 'wp-taxonomy-tree' ),
					'loading'         => __( 'Loading…', 'wp-taxonomy-tree' ),
					'addRoot'         => __( 'Add root', 'wp-taxonomy-tree' ),
					'addChild'        => __( 'Add child', 'wp-taxonomy-tree' ),
					'delete'          => __( 'Delete', 'wp-taxonomy-tree' ),
					'name'            => __( 'Name', 'wp-taxonomy-tree' ),
					'slug'            => __( 'Slug', 'wp-taxonomy-tree' ),
					'parent'          => __( 'Parent', 'wp-taxonomy-tree' ),
					'description'     => __( 'Description', 'wp-taxonomy-tree' ),
					'count'           => __( 'Assigned posts', 'wp-taxonomy-tree' ),
					'none'            => __( '— None —', 'wp-taxonomy-tree' ),
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
					'scaffoldBadge'   => __( 'Scaffold 0.0.1', 'wp-taxonomy-tree' ),
				),
			)
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-taxonomy-tree' ) );
		}
		?>
		<div class="wrap wtt-wrap">
			<h1>
				<?php esc_html_e( 'Taxonomy Tree', 'wp-taxonomy-tree' ); ?>
				<span class="wtt-badge" id="wtt-badge"></span>
			</h1>
			<p class="description" id="wtt-intro"></p>
			<div id="wtt-app" class="wtt-app" aria-live="polite"></div>
		</div>
		<?php
	}
}
