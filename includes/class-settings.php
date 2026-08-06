<?php
/**
 * Plugin settings (scaffold).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and exposes plugin configuration options.
 *
 * Form edits stay local until Save; Undo restores the last saved option values.
 */
final class Settings {

	public const OPTION_TEST_MODE = 'wtt_test_mode';

	public const OPTION_SHOW_TYPE_IN_TREE = 'wtt_show_type_in_tree';

	/** Show set-member (child) properties under the selected set node’s detail panel. */
	public const OPTION_SHOW_SET_CHILD_PROPS = 'wtt_show_set_child_props';

	/**
	 * When ON: node detail edits need Save/Undo.
	 * When OFF (default): edits save immediately (autosave).
	 */
	public const OPTION_SAVE_VIA_BUTTON = 'wtt_save_via_button';

	/** Tree node picker presentation: `inline` or `popup` (default). */
	public const OPTION_TREE_PICKER_MODE = 'wtt_tree_picker_mode';

	/**
	 * Confirm before deleting nodes (leaf confirm + children promote/cascade dialog).
	 * Default follows Test mode: OFF while testing, ON when Test mode is off (release).
	 */
	public const OPTION_CONFIRM_NODE_DELETE = 'wtt_confirm_node_delete';

	/**
	 * Development mode — when ON, catalog/system nodes are deletable (Trash bin still protected).
	 * Default OFF (safe).
	 */
	public const OPTION_DEVELOPMENT_MODE = 'wtt_development_mode';

	public const PAGE_SLUG = 'wp-taxonomy-tree-settings';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 20 );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * Testbetrieb — default ON until first explicit save turns it off.
	 */
	public static function is_test_mode(): bool {
		return self::option_is_truthy( get_option( self::OPTION_TEST_MODE, '1' ) );
	}

	/**
	 * Show [type] suffix on tree labels — default OFF (labels often truncate).
	 */
	public static function show_type_in_tree(): bool {
		return self::option_is_truthy( get_option( self::OPTION_SHOW_TYPE_IN_TREE, '0' ) );
	}

	/**
	 * Under a set node’s properties, also list each child’s type/fixed/required — default OFF.
	 * Only applies when the selected node is set-typed.
	 */
	public static function show_set_child_props(): bool {
		return self::option_is_truthy( get_option( self::OPTION_SHOW_SET_CHILD_PROPS, '0' ) );
	}

	/**
	 * Save node settings via toolbar button — default OFF (autosave on change).
	 */
	public static function save_via_button(): bool {
		return self::option_is_truthy( get_option( self::OPTION_SAVE_VIA_BUTTON, '0' ) );
	}

	/**
	 * Tree picker UI: popup (compact trigger + dialog) or inline (always-visible tree).
	 */
	public static function tree_picker_mode(): string {
		return self::sanitize_tree_picker_mode( get_option( self::OPTION_TREE_PICKER_MODE, 'popup' ) );
	}

	/**
	 * Ask before deleting nodes.
	 * Unset option: OFF in Test mode, ON when Test mode is off (release posture).
	 */
	public static function confirm_node_delete(): bool {
		$default = self::is_test_mode() ? '0' : '1';
		return self::option_is_truthy( get_option( self::OPTION_CONFIRM_NODE_DELETE, $default ) );
	}

	/**
	 * Development mode — default OFF.
	 * When on, all nodes are deletable except the Trash bin term,
	 * and protected relations (incl. child_of) may be removed.
	 */
	public static function is_development_mode(): bool {
		return self::option_is_truthy( get_option( self::OPTION_DEVELOPMENT_MODE, '0' ) );
	}

	/**
	 * @param mixed $value Option value.
	 */
	private static function option_is_truthy( $value ): bool {
		return '1' === (string) $value || 1 === $value || true === $value;
	}

	/**
	 * @param mixed $value Raw form value.
	 */
	public static function sanitize_flag( $value ): string {
		return ( '1' === (string) $value || true === $value || 'on' === (string) $value ) ? '1' : '0';
	}

	/**
	 * @param mixed $value Raw form value.
	 */
	public static function sanitize_tree_picker_mode( $value ): string {
		return 'inline' === (string) $value ? 'inline' : 'popup';
	}

	public static function register_menu(): void {
		add_submenu_page(
			Tree_Admin::PAGE_SLUG,
			__( 'Taxonomy Tree Settings', 'wp-taxonomy-tree' ),
			__( 'Settings', 'wp-taxonomy-tree' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page && false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'wtt-settings-admin',
			WTT_PLUGIN_URL . 'assets/css/settings-admin.css',
			array(),
			WTT_VERSION
		);

		wp_enqueue_script(
			'wtt-settings-admin',
			WTT_PLUGIN_URL . 'assets/js/settings-admin.js',
			array(),
			WTT_VERSION,
			true
		);

		wp_localize_script(
			'wtt-settings-admin',
			'wttSettings',
			array(
				'fields' => array(
					'testMode'          => self::OPTION_TEST_MODE,
					'showTypeInTree'    => self::OPTION_SHOW_TYPE_IN_TREE,
					'showSetChildProps' => self::OPTION_SHOW_SET_CHILD_PROPS,
					'saveViaButton'     => self::OPTION_SAVE_VIA_BUTTON,
					'treePickerMode'    => self::OPTION_TREE_PICKER_MODE,
					'confirmNodeDelete' => self::OPTION_CONFIRM_NODE_DELETE,
					'developmentMode'   => self::OPTION_DEVELOPMENT_MODE,
				),
				'saved'  => array(
					'testMode'          => self::is_test_mode(),
					'showTypeInTree'    => self::show_type_in_tree(),
					'showSetChildProps' => self::show_set_child_props(),
					'saveViaButton'     => self::save_via_button(),
					'treePickerMode'    => self::tree_picker_mode(),
					'confirmNodeDelete' => self::confirm_node_delete(),
					'developmentMode'   => self::is_development_mode(),
				),
			)
		);
	}

	public static function register_settings(): void {
		register_setting(
			'wtt_settings',
			self::OPTION_TEST_MODE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_flag' ),
				'default'           => '1',
			)
		);

		register_setting(
			'wtt_settings',
			self::OPTION_SHOW_TYPE_IN_TREE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_flag' ),
				'default'           => '0',
			)
		);

		register_setting(
			'wtt_settings',
			self::OPTION_SHOW_SET_CHILD_PROPS,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_flag' ),
				'default'           => '0',
			)
		);

		register_setting(
			'wtt_settings',
			self::OPTION_SAVE_VIA_BUTTON,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_flag' ),
				'default'           => '0',
			)
		);

		register_setting(
			'wtt_settings',
			self::OPTION_TREE_PICKER_MODE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_tree_picker_mode' ),
				'default'           => 'popup',
			)
		);

		register_setting(
			'wtt_settings',
			self::OPTION_CONFIRM_NODE_DELETE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_flag' ),
				'default'           => '0',
			)
		);

		register_setting(
			'wtt_settings',
			self::OPTION_DEVELOPMENT_MODE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_flag' ),
				'default'           => '0',
			)
		);

		add_settings_section(
			'wtt_settings_general',
			__( 'General', 'wp-taxonomy-tree' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Change settings below, then save. Undo restores the last saved values.', 'wp-taxonomy-tree' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_section(
			'wtt_settings_development',
			__( 'Development mode', 'wp-taxonomy-tree' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Options for local development only. Do not enable on production sites.', 'wp-taxonomy-tree' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			self::OPTION_TEST_MODE,
			__( 'Test mode', 'wp-taxonomy-tree' ),
			array( self::class, 'render_test_mode_field' ),
			self::PAGE_SLUG,
			'wtt_settings_general'
		);

		add_settings_field(
			self::OPTION_SHOW_TYPE_IN_TREE,
			__( 'Show type in tree', 'wp-taxonomy-tree' ),
			array( self::class, 'render_show_type_in_tree_field' ),
			self::PAGE_SLUG,
			'wtt_settings_general'
		);

		add_settings_field(
			self::OPTION_SHOW_SET_CHILD_PROPS,
			__( 'Show set child properties', 'wp-taxonomy-tree' ),
			array( self::class, 'render_show_set_child_props_field' ),
			self::PAGE_SLUG,
			'wtt_settings_general'
		);

		add_settings_field(
			self::OPTION_SAVE_VIA_BUTTON,
			__( 'Save via button', 'wp-taxonomy-tree' ),
			array( self::class, 'render_save_via_button_field' ),
			self::PAGE_SLUG,
			'wtt_settings_general'
		);

		add_settings_field(
			self::OPTION_TREE_PICKER_MODE,
			__( 'Tree picker', 'wp-taxonomy-tree' ),
			array( self::class, 'render_tree_picker_mode_field' ),
			self::PAGE_SLUG,
			'wtt_settings_general'
		);

		add_settings_field(
			self::OPTION_CONFIRM_NODE_DELETE,
			__( 'Confirm node delete', 'wp-taxonomy-tree' ),
			array( self::class, 'render_confirm_node_delete_field' ),
			self::PAGE_SLUG,
			'wtt_settings_general'
		);

		add_settings_field(
			self::OPTION_DEVELOPMENT_MODE,
			__( 'All nodes and relations deletable', 'wp-taxonomy-tree' ),
			array( self::class, 'render_development_mode_field' ),
			self::PAGE_SLUG,
			'wtt_settings_development'
		);

		add_settings_section(
			'wtt_settings_catalog',
			__( 'Catalog bindings', 'wp-taxonomy-tree' ),
			static function (): void {
				echo '<p>' . esc_html__(
					'Attribute type chooser uses two bindings: chooser_root (subtree shown, e.g. Fallstudie) and chooser_focus (initial expand, e.g. Data Types). Legacy data_types / simple / complex helpers remain. Bound by term id so renames stay safe; option wtt_catalog_bindings. Rebuilt when the tree screen loads if missing.',
					'wp-taxonomy-tree'
				) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			Catalog_Bindings::OPTION,
			__( 'Attribute type chooser', 'wp-taxonomy-tree' ),
			array( self::class, 'render_catalog_bindings_field' ),
			self::PAGE_SLUG,
			'wtt_settings_catalog'
		);
	}

	public static function render_catalog_bindings_field(): void {
		$labels = Catalog_Bindings::key_labels();
		foreach ( Taxonomy::scaffold_slugs() as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			Catalog_Bindings::ensure( $taxonomy );
			$map = Catalog_Bindings::for_client( $taxonomy );
			echo '<p><strong>' . esc_html( $taxonomy ) . '</strong></p>';
			echo '<ul class="ul-disc">';
			foreach ( Catalog_Bindings::keys() as $key ) {
				$id    = isset( $map[ $key ] ) ? (int) $map[ $key ] : 0;
				$label = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
				$name  = '';
				if ( $id > 0 ) {
					$term = get_term( $id, $taxonomy );
					if ( $term instanceof \WP_Term ) {
						$name = (string) $term->name;
					}
				}
				echo '<li>';
				echo esc_html( $label ) . ' <code>' . esc_html( $key ) . '</code>: ';
				if ( $id > 0 ) {
					echo '<code>' . esc_html( (string) $id ) . '</code>';
					if ( '' !== $name ) {
						echo ' — ' . esc_html( $name );
					}
				} else {
					echo esc_html__( '(unbound)', 'wp-taxonomy-tree' );
				}
				echo '</li>';
			}
			echo '</ul>';
		}
	}

	public static function render_test_mode_field(): void {
		self::render_checkbox_field(
			self::OPTION_TEST_MODE,
			self::is_test_mode(),
			__( 'Enable test mode (Testbetrieb)', 'wp-taxonomy-tree' ),
			__( 'When enabled, the Reset test tree button is available on the tree screen. Applies only after you save.', 'wp-taxonomy-tree' )
		);
	}

	public static function render_show_type_in_tree_field(): void {
		self::render_checkbox_field(
			self::OPTION_SHOW_TYPE_IN_TREE,
			self::show_type_in_tree(),
			__( 'Append data type to tree labels (e.g. Wert [double])', 'wp-taxonomy-tree' ),
			__( 'Off by default — long type paths often do not fit in the tree column. Full type is always in the detail panel.', 'wp-taxonomy-tree' )
		);
	}

	public static function render_show_set_child_props_field(): void {
		self::render_checkbox_field(
			self::OPTION_SHOW_SET_CHILD_PROPS,
			self::show_set_child_props(),
			__( 'Under a set node, also list child (member) properties', 'wp-taxonomy-tree' ),
			__( 'Only for set-typed nodes (e.g. Meter, Abmessung). Shows each child’s type, fixed value, and required flag under the parent’s properties. Off by default.', 'wp-taxonomy-tree' )
		);
	}

	public static function render_save_via_button_field(): void {
		self::render_checkbox_field(
			self::OPTION_SAVE_VIA_BUTTON,
			self::save_via_button(),
			__( 'Save node settings with Save / Undo buttons', 'wp-taxonomy-tree' ),
			__( 'Off by default: changes in the tree detail panel are saved immediately. When enabled, edits stay local until you click Save settings (Undo restores the last saved values).', 'wp-taxonomy-tree' )
		);
	}

	public static function render_confirm_node_delete_field(): void {
		self::render_checkbox_field(
			self::OPTION_CONFIRM_NODE_DELETE,
			self::confirm_node_delete(),
			__( 'Ask before deleting nodes', 'wp-taxonomy-tree' ),
			__( 'When off: delete immediately (no confirm). Trash = node only (children move up); networking icon = whole branch. Default follows Test mode — off while testing, on when Test mode is off (release).', 'wp-taxonomy-tree' )
		);
	}

	public static function render_development_mode_field(): void {
		self::render_checkbox_field(
			self::OPTION_DEVELOPMENT_MODE,
			self::is_development_mode(),
			__( 'Allow deleting all nodes and relations', 'wp-taxonomy-tree' ),
			__( 'Development only. When enabled, catalog/system nodes are deletable and protected relations (including child_of) can be removed in the Relations UI. The Trash bin itself stays non-deletable (use Empty trash). Off by default.', 'wp-taxonomy-tree' )
		);
	}

	public static function render_tree_picker_mode_field(): void {
		$mode = self::tree_picker_mode();
		?>
		<select id="<?php echo esc_attr( self::OPTION_TREE_PICKER_MODE ); ?>" name="<?php echo esc_attr( self::OPTION_TREE_PICKER_MODE ); ?>">
			<option value="popup" <?php selected( $mode, 'popup' ); ?>>
				<?php esc_html_e( 'Popup (compact button + dialog)', 'wp-taxonomy-tree' ); ?>
			</option>
			<option value="inline" <?php selected( $mode, 'inline' ); ?>>
				<?php esc_html_e( 'Inline (tree always visible)', 'wp-taxonomy-tree' ); ?>
			</option>
		</select>
		<p class="description">
			<?php esc_html_e( 'How node pickers and the node_ref catalog chooser appear in preview and settings (reparent dialog always keeps an inline tree). Default: popup.', 'wp-taxonomy-tree' ); ?>
		</p>
		<?php
	}

	private static function render_checkbox_field( string $option, bool $checked, string $label, string $description ): void {
		?>
		<input type="hidden" name="<?php echo esc_attr( $option ); ?>" value="0" />
		<label for="<?php echo esc_attr( $option ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( $option ); ?>"
				name="<?php echo esc_attr( $option ); ?>"
				value="1"
				<?php checked( $checked ); ?>
			/>
			<?php echo esc_html( $label ); ?>
		</label>
		<p class="description">
			<?php echo esc_html( $description ); ?>
		</p>
		<?php
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-taxonomy-tree' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Taxonomy Tree Settings', 'wp-taxonomy-tree' ); ?></h1>
			<form method="post" action="options.php" id="wtt-settings-form" class="wtt-settings-form">
				<?php
				settings_fields( 'wtt_settings' );
				do_settings_sections( self::PAGE_SLUG );
				?>
				<p class="submit wtt-settings-actions">
					<?php
					submit_button(
						__( 'Save settings', 'wp-taxonomy-tree' ),
						'primary',
						'submit',
						false,
						array( 'id' => 'wtt-settings-save' )
					);
					?>
					<button type="button" class="button" id="wtt-settings-undo" disabled>
						<?php esc_html_e( 'Undo', 'wp-taxonomy-tree' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}
}
