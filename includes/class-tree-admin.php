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
 *
 * Assets are printed inline from disk so Laragon junctions cannot break
 * static file URLs under wp-content/plugins/.
 */
final class Tree_Admin {

	public const PAGE_SLUG = 'wp-taxonomy-tree';

	/** @var array<string, mixed>|null */
	private static ?array $boot_config = null;

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'prepare_screen' ) );
		add_action( 'admin_head', array( self::class, 'print_inline_css' ) );
		add_action( 'admin_footer', array( self::class, 'print_inline_js' ) );
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

	private static function is_plugin_screen( string $hook_suffix = '' ): bool {
		if ( '' !== $hook_suffix && 'toplevel_page_' . self::PAGE_SLUG === $hook_suffix ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return self::PAGE_SLUG === $page;
	}

	public static function prepare_screen( string $hook_suffix ): void {
		if ( ! self::is_plugin_screen( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );
		self::$boot_config = self::build_config();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_config(): array {
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

		return array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( Tree_Ajax::NONCE_ACTION ),
			'taxonomy'   => $requested,
			'taxonomies' => $taxonomies,
			'tree'       => Tree_Model::get_tree( $requested ),
			'version'    => WTT_VERSION,
			'i18n'       => array(
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
				'installDemo'     => __( 'Install BOM test tree', 'wp-taxonomy-tree' ),
				'resetDemo'       => __( 'Reset test tree', 'wp-taxonomy-tree' ),
				'confirmReset'    => __( 'Delete BOM Testprojekt (and old Passive Components / Semiconductors stubs), then reinstall the full demo tree?', 'wp-taxonomy-tree' ),
				'demoDone'        => __( 'BOM test tree installed (from planning + tree-split proto).', 'wp-taxonomy-tree' ),
				'demoReset'       => __( 'Test tree reset and reinstalled.', 'wp-taxonomy-tree' ),
				'typeBinding'     => __( 'Type binding (has_type)', 'wp-taxonomy-tree' ),
				'typeHint'        => __( 'Type only from Typ-Ast (Q26). Catalog pick = subtree (+ ref_scope). Free jump = node_ref.', 'wp-taxonomy-tree' ),
				'type'            => __( 'Type', 'wp-taxonomy-tree' ),
				'noType'          => __( '- no type -', 'wp-taxonomy-tree' ),
				'refScope'        => __( 'Catalog root (ref_scope)', 'wp-taxonomy-tree' ),
				'noScope'         => __( '- choose catalog root -', 'wp-taxonomy-tree' ),
				'templateFlag'    => __( 'Template node', 'wp-taxonomy-tree' ),
				'required'        => __( 'Required field', 'wp-taxonomy-tree' ),
				'requiredHint'    => __( 'On the slot Node (config.required), not on has_type.', 'wp-taxonomy-tree' ),
				'footerOp'        => __( 'Footer op', 'wp-taxonomy-tree' ),
				'parameters'      => __( 'Parameters (Q64)', 'wp-taxonomy-tree' ),
				'paramName'       => __( 'Name', 'wp-taxonomy-tree' ),
				'paramType'       => __( 'Type', 'wp-taxonomy-tree' ),
				'addParameter'    => __( 'Add parameter', 'wp-taxonomy-tree' ),
				'remove'          => __( 'Remove', 'wp-taxonomy-tree' ),
				'save'            => __( 'Save', 'wp-taxonomy-tree' ),
				'saved'           => __( 'Saved.', 'wp-taxonomy-tree' ),
				'relations'       => __( 'Relations', 'wp-taxonomy-tree' ),
				'attributes'      => __( 'Node attributes', 'wp-taxonomy-tree' ),
				'scaffoldBadge'   => sprintf(
					/* translators: %s: plugin version */
					__( 'Scaffold %s', 'wp-taxonomy-tree' ),
					WTT_VERSION
				),
			),
		);
	}

	public static function print_inline_css(): void {
		if ( ! self::is_plugin_screen() ) {
			return;
		}
		if ( null === self::$boot_config ) {
			self::$boot_config = self::build_config();
		}

		$css_abs = WTT_PLUGIN_DIR . 'assets/css/tree-admin.css';
		if ( ! is_readable( $css_abs ) ) {
			return;
		}

		$css = file_get_contents( $css_abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $css || '' === $css ) {
			return;
		}

		echo "<style id=\"wtt-tree-admin-css\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function print_inline_js(): void {
		if ( ! self::is_plugin_screen() ) {
			return;
		}
		if ( null === self::$boot_config ) {
			self::$boot_config = self::build_config();
		}

		$js_abs = WTT_PLUGIN_DIR . 'assets/js/tree-admin.js';
		$js     = is_readable( $js_abs ) ? file_get_contents( $js_abs ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$json = wp_json_encode( self::$boot_config );
		if ( false === $json ) {
			$json = '{}';
		}

		echo "<script id=\"wtt-tree-boot\">\n";
		echo 'window.wttTree = ' . $json . ";\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "</script>\n";

		if ( false !== $js && '' !== $js ) {
			echo "<script id=\"wtt-tree-admin-js\">\n" . $js . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo "<script>document.getElementById('wtt-app') && (document.getElementById('wtt-app').innerHTML = '<p class=\"wtt-error\">JS file missing on disk.</p>');</script>\n";
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
				<span class="wtt-badge" id="wtt-badge"><?php echo esc_html( sprintf( __( 'Scaffold %s', 'wp-taxonomy-tree' ), WTT_VERSION ) ); ?></span>
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
								__( 'Plugin assets missing on disk. CSS: %1$s / JS: %2$s', 'wp-taxonomy-tree' ),
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
