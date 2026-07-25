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
					'testMode'           => self::OPTION_TEST_MODE,
					'showTypeInTree'     => self::OPTION_SHOW_TYPE_IN_TREE,
					'showSetChildProps'  => self::OPTION_SHOW_SET_CHILD_PROPS,
					'saveViaButton'      => self::OPTION_SAVE_VIA_BUTTON,
				),
				'saved'  => array(
					'testMode'          => self::is_test_mode(),
					'showTypeInTree'    => self::show_type_in_tree(),
					'showSetChildProps' => self::show_set_child_props(),
					'saveViaButton'     => self::save_via_button(),
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

		add_settings_section(
			'wtt_settings_general',
			__( 'General', 'wp-taxonomy-tree' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Change settings below, then save. Undo restores the last saved values.', 'wp-taxonomy-tree' ) . '</p>';
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
