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

	/**
	 * Show Model_Data instance counts on structure-host tree labels.
	 * Default OFF. Primary UX is the tree toolbar switch.
	 */
	public const OPTION_SHOW_MODEL_DATA_COUNTS = 'wtt_show_model_data_counts';

	/** Hide the taxonomy project root in the tree UI (show children at top level). Default ON. */
	public const OPTION_HIDE_ROOT_NODE = 'wtt_hide_root_node';

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
	 * Warn before structural attribute edits that bump the host model version (UR-S1).
	 * Default ON. Client skips the popup when the host has no Model_Data instances.
	 * Grouped under Settings → Confirm dialogs (Q107).
	 */
	public const OPTION_WARN_STRUCTURAL_MODEL_CHANGE = 'wtt_warn_structural_model_change';

	/**
	 * Show a confirm dialog when a validation result has warnings[] (Q107).
	 * Default OFF. Save with warnings is always allowed; this only gates the popup.
	 */
	public const OPTION_DIALOG_ON_VALIDATION_WARNINGS = 'wtt_dialog_on_validation_warnings';

	/**
	 * Default Object View / nested paint depth (0–5). Site-wide; blocks may override.
	 * 1 = this node + direct attributes (R1 standard).
	 */
	public const OPTION_DEFAULT_RENDER_DEPTH = 'wtt_default_render_depth';

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
	 * Show (N) Model_Data instance counts on structure-host tree labels — default OFF.
	 */
	public static function show_model_data_counts(): bool {
		return self::option_is_truthy( get_option( self::OPTION_SHOW_MODEL_DATA_COUNTS, '0' ) );
	}

	/**
	 * Hide taxonomy root in the tree column — default ON.
	 */
	public static function hide_root_node(): bool {
		return self::option_is_truthy( get_option( self::OPTION_HIDE_ROOT_NODE, '1' ) );
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
	 * Warn before structural model (attribute schema) changes — default ON.
	 * Popup is shown only when the host also has ≥1 Model_Data instance (client gate).
	 */
	public static function warn_structural_model_change(): bool {
		return self::option_is_truthy( get_option( self::OPTION_WARN_STRUCTURAL_MODEL_CHANGE, '1' ) );
	}

	/**
	 * Confirm dialog when validation warnings are present — default OFF (Q107).
	 */
	public static function dialog_on_validation_warnings(): bool {
		return self::option_is_truthy( get_option( self::OPTION_DIALOG_ON_VALIDATION_WARNINGS, '0' ) );
	}

	/**
	 * Default render depth for Object View (and nested paint) — 0..5, default 1.
	 */
	public static function default_render_depth(): int {
		$raw = get_option( self::OPTION_DEFAULT_RENDER_DEPTH, 1 );
		if ( class_exists( __NAMESPACE__ . '\\Object_Render' ) ) {
			return Object_Render::normalize_render_depth( $raw );
		}
		$n = is_numeric( $raw ) ? (int) $raw : 1;
		if ( $n < 0 ) {
			$n = 0;
		}
		if ( $n > 5 ) {
			$n = 5;
		}
		return $n;
	}

	/**
	 * @param mixed $value Raw form value.
	 */
	public static function sanitize_render_depth( $value ): int {
		if ( class_exists( __NAMESPACE__ . '\\Object_Render' ) ) {
			return Object_Render::normalize_render_depth( $value );
		}
		$n = is_numeric( $value ) ? (int) $value : 1;
		if ( $n < 0 ) {
			$n = 0;
		}
		if ( $n > 5 ) {
			$n = 5;
		}
		return $n;
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
					'hideRootNode'           => self::OPTION_HIDE_ROOT_NODE,
					'showTypeInTree'         => self::OPTION_SHOW_TYPE_IN_TREE,
					'showModelDataCounts'    => self::OPTION_SHOW_MODEL_DATA_COUNTS,
					'showSetChildProps'      => self::OPTION_SHOW_SET_CHILD_PROPS,
					'saveViaButton'     => self::OPTION_SAVE_VIA_BUTTON,
					'treePickerMode'    => self::OPTION_TREE_PICKER_MODE,
					'confirmNodeDelete'             => self::OPTION_CONFIRM_NODE_DELETE,
					'warnStructuralModelChange'     => self::OPTION_WARN_STRUCTURAL_MODEL_CHANGE,
					'dialogOnValidationWarnings'    => self::OPTION_DIALOG_ON_VALIDATION_WARNINGS,
					'defaultRenderDepth'            => self::OPTION_DEFAULT_RENDER_DEPTH,
					'developmentMode'               => self::OPTION_DEVELOPMENT_MODE,
					'treeIconKeys'                  => Tree_Icons::OPTION_ENABLED,
					'catalogBindings'               => Catalog_Bindings::OPTION,
				),
				'saved'  => array(
					'testMode'                      => self::is_test_mode(),
					'hideRootNode'                  => self::hide_root_node(),
					'showTypeInTree'                => self::show_type_in_tree(),
					'showModelDataCounts'           => self::show_model_data_counts(),
					'showSetChildProps'             => self::show_set_child_props(),
					'saveViaButton'                 => self::save_via_button(),
					'treePickerMode'                => self::tree_picker_mode(),
					'confirmNodeDelete'             => self::confirm_node_delete(),
					'warnStructuralModelChange'     => self::warn_structural_model_change(),
					'dialogOnValidationWarnings'    => self::dialog_on_validation_warnings(),
					'defaultRenderDepth'            => self::default_render_depth(),
					'developmentMode'               => self::is_development_mode(),
					'treeIconKeys'                  => Tree_Icons::enabled_keys(),
					'catalogBindings'               => self::catalog_bindings_saved_state(),
				),
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( Tree_Ajax::NONCE_ACTION ),
				'taxonomy'  => Taxonomy::FS,
				'i18n'      => array(
					'bindingsChange'      => __( 'Change', 'wp-taxonomy-tree' ),
					'bindingsDone'        => __( 'Done', 'wp-taxonomy-tree' ),
					'bindingsUnbound'     => __( '(unbound)', 'wp-taxonomy-tree' ),
					'resetCaseTree'       => __( 'Delete and reinstall case-study tree', 'wp-taxonomy-tree' ),
					'confirmResetCase'    => __( 'Delete all Fallstudie terms (including attribute slots), then reinstall the case-study tree? This cannot be undone.', 'wp-taxonomy-tree' ),
					'resetCaseNeedDev'    => __( 'Enable Development mode and save settings first.', 'wp-taxonomy-tree' ),
					'resetCaseWorking'    => __( 'Resetting…', 'wp-taxonomy-tree' ),
					'resetCaseDone'       => __( 'Case tree reset and reinstalled.', 'wp-taxonomy-tree' ),
					'error'               => __( 'Something went wrong.', 'wp-taxonomy-tree' ),
				),
			)
		);
	}

	/**
	 * Current binding selects for Undo (taxonomy → key → term id string).
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function catalog_bindings_saved_state(): array {
		$out = array();
		foreach ( Taxonomy::scaffold_slugs() as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$map = Catalog_Bindings::for_client( $taxonomy );
			$row = array();
			foreach ( Catalog_Bindings::keys() as $key ) {
				$row[ $key ] = isset( $map[ $key ] ) ? (string) (int) $map[ $key ] : '0';
			}
			$out[ $taxonomy ] = $row;
		}
		return $out;
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
			self::OPTION_HIDE_ROOT_NODE,
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
			self::OPTION_SHOW_MODEL_DATA_COUNTS,
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
			self::OPTION_WARN_STRUCTURAL_MODEL_CHANGE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_flag' ),
				'default'           => '1',
			)
		);

		register_setting(
			'wtt_settings',
			self::OPTION_DIALOG_ON_VALIDATION_WARNINGS,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_flag' ),
				'default'           => '0',
			)
		);

		register_setting(
			'wtt_settings',
			self::OPTION_DEFAULT_RENDER_DEPTH,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'sanitize_render_depth' ),
				'default'           => 1,
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

		register_setting(
			'wtt_settings',
			Catalog_Bindings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Catalog_Bindings::class, 'sanitize_option' ),
				'default'           => array(),
			)
		);

		register_setting(
			'wtt_settings',
			Tree_Icons::OPTION_ENABLED,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Tree_Icons::class, 'sanitize_enabled_keys' ),
				'default'           => array(),
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
			'wtt_settings_confirm_dialogs',
			__( 'Confirm dialogs', 'wp-taxonomy-tree' ),
			static function (): void {
				echo '<p>' . esc_html__(
					'Optional popups for risky or soft situations. Turn on only the friction you want.',
					'wp-taxonomy-tree'
				) . '</p>';
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
			self::OPTION_HIDE_ROOT_NODE,
			__( 'Hide root node', 'wp-taxonomy-tree' ),
			array( self::class, 'render_hide_root_node_field' ),
			self::PAGE_SLUG,
			'wtt_settings_general'
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
			self::OPTION_SHOW_MODEL_DATA_COUNTS,
			__( 'Show Model Data counts in tree', 'wp-taxonomy-tree' ),
			array( self::class, 'render_show_model_data_counts_field' ),
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
			'wtt_settings_confirm_dialogs'
		);

		add_settings_field(
			self::OPTION_WARN_STRUCTURAL_MODEL_CHANGE,
			__( 'Warn before structural model changes', 'wp-taxonomy-tree' ),
			array( self::class, 'render_warn_structural_model_change_field' ),
			self::PAGE_SLUG,
			'wtt_settings_confirm_dialogs'
		);

		add_settings_field(
			self::OPTION_DIALOG_ON_VALIDATION_WARNINGS,
			__( 'Dialog on validation warnings', 'wp-taxonomy-tree' ),
			array( self::class, 'render_dialog_on_validation_warnings_field' ),
			self::PAGE_SLUG,
			'wtt_settings_confirm_dialogs'
		);

		add_settings_field(
			self::OPTION_DEFAULT_RENDER_DEPTH,
			__( 'Default render depth', 'wp-taxonomy-tree' ),
			array( self::class, 'render_default_render_depth_field' ),
			self::PAGE_SLUG,
			'wtt_settings_general'
		);

		add_settings_field(
			Tree_Icons::OPTION_ENABLED,
			__( 'Tree icons', 'wp-taxonomy-tree' ),
			array( self::class, 'render_tree_icons_field' ),
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

		add_settings_field(
			'wtt_reset_case_tree',
			__( 'Reset case tree', 'wp-taxonomy-tree' ),
			array( self::class, 'render_reset_case_tree_field' ),
			self::PAGE_SLUG,
			'wtt_settings_development'
		);

		add_settings_section(
			'wtt_settings_catalog',
			__( 'Catalog bindings', 'wp-taxonomy-tree' ),
			static function (): void {
				echo '<p>' . esc_html__(
					'Stable term-id links for the shared catalog tree. Rarely changed — use Change to edit, then Save.',
					'wp-taxonomy-tree'
				) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			Catalog_Bindings::OPTION,
			__( 'Current bindings', 'wp-taxonomy-tree' ),
			array( self::class, 'render_catalog_bindings_field' ),
			self::PAGE_SLUG,
			'wtt_settings_catalog'
		);
	}

	public static function render_catalog_bindings_field(): void {
		$labels   = Catalog_Bindings::key_labels();
		$helps    = Catalog_Bindings::key_helps();
		$rendered = false;

		foreach ( Taxonomy::scaffold_slugs() as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$map        = Catalog_Bindings::for_client( $taxonomy );
			$candidates = Catalog_Bindings::list_candidate_terms( $taxonomy );
			$rendered   = true;

			echo '<div class="wtt-catalog-bindings" data-mode="view">';
			echo '<div class="wtt-catalog-bindings__toolbar">';
			echo '<p class="wtt-catalog-bindings__tax"><strong>' . esc_html( $taxonomy ) . '</strong></p>';
			echo '<button type="button" class="button wtt-catalog-bindings__toggle" data-wtt-bindings-toggle="1">';
			echo esc_html__( 'Change', 'wp-taxonomy-tree' );
			echo '</button>';
			echo '</div>';

			echo '<table class="widefat striped wtt-catalog-bindings__table">';
			echo '<thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Key', 'wp-taxonomy-tree' ) . '</th>';
			echo '<th scope="col" class="wtt-catalog-bindings__col-view">' . esc_html__( 'Term ID', 'wp-taxonomy-tree' ) . '</th>';
			echo '<th scope="col" class="wtt-catalog-bindings__col-view">' . esc_html__( 'Node', 'wp-taxonomy-tree' ) . '</th>';
			echo '<th scope="col" class="wtt-catalog-bindings__col-edit">' . esc_html__( 'Node', 'wp-taxonomy-tree' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Binding', 'wp-taxonomy-tree' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( Catalog_Bindings::keys() as $key ) {
				$id       = isset( $map[ $key ] ) ? (int) $map[ $key ] : 0;
				$label    = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
				$help     = isset( $helps[ $key ] ) ? $helps[ $key ] : '';
				$field_id = Catalog_Bindings::OPTION . '-' . $taxonomy . '-' . $key;
				$name     = Catalog_Bindings::OPTION . '[' . $taxonomy . '][' . $key . ']';
				$node     = '';
				if ( $id > 0 ) {
					$term = get_term( $id, $taxonomy );
					if ( $term instanceof \WP_Term ) {
						$node = (string) $term->name;
					}
				}

				echo '<tr>';
				echo '<td><code>' . esc_html( $key ) . '</code></td>';

				echo '<td class="wtt-catalog-bindings__col-view">';
				if ( $id > 0 ) {
					echo '<code class="wtt-catalog-bindings__view-id">' . esc_html( (string) $id ) . '</code>';
				} else {
					echo '<em class="wtt-catalog-bindings__view-id">' . esc_html__( '(unbound)', 'wp-taxonomy-tree' ) . '</em>';
				}
				echo '</td>';
				echo '<td class="wtt-catalog-bindings__col-view">';
				echo '<span class="wtt-catalog-bindings__view-name">' . esc_html( '' !== $node ? $node : '—' ) . '</span>';
				echo '</td>';

				echo '<td class="wtt-catalog-bindings__col-edit">';
				echo '<select class="wtt-catalog-bindings__select" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $name ) . '" data-wtt-binding="1" data-taxonomy="' . esc_attr( $taxonomy ) . '" data-key="' . esc_attr( $key ) . '">';
				echo '<option value="0"' . selected( $id, 0, false ) . '>' . esc_html__( '(unbound)', 'wp-taxonomy-tree' ) . '</option>';
				foreach ( $candidates as $candidate ) {
					$cid  = (int) $candidate['id'];
					$path = (string) $candidate['path'];
					echo '<option value="' . esc_attr( (string) $cid ) . '"' . selected( $id, $cid, false ) . '>';
					echo esc_html( $path . ' (#' . $cid . ')' );
					echo '</option>';
				}
				if ( $id > 0 ) {
					$found = false;
					foreach ( $candidates as $candidate ) {
						if ( (int) $candidate['id'] === $id ) {
							$found = true;
							break;
						}
					}
					if ( ! $found ) {
						$orph = '' !== $node ? $node : __( '(missing term)', 'wp-taxonomy-tree' );
						echo '<option value="' . esc_attr( (string) $id ) . '" selected="selected">';
						echo esc_html( $orph . ' (#' . $id . ')' );
						echo '</option>';
					}
				}
				echo '</select>';
				echo '</td>';

				echo '<td class="wtt-catalog-bindings__desc"><label for="' . esc_attr( $field_id ) . '">' . esc_html( $label ) . '</label>';
				if ( '' !== $help ) {
					echo '<p class="description wtt-catalog-bindings__help">' . esc_html( $help ) . '</p>';
				}
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
			echo '</div>';
		}

		if ( ! $rendered ) {
			echo '<p class="description"><em>' . esc_html__( 'No scaffold taxonomy registered — bindings cannot be shown yet.', 'wp-taxonomy-tree' ) . '</em></p>';
		}
	}

	public static function render_hide_root_node_field(): void {
		self::render_checkbox_field(
			self::OPTION_HIDE_ROOT_NODE,
			self::hide_root_node(),
			__( 'Hide the taxonomy root in the tree', 'wp-taxonomy-tree' ),
			__( 'When enabled (default), the project root is hidden and its children appear at the top level. The root is still stored as “Fallstudie” in the database; the tree label is “Taxonomy”.', 'wp-taxonomy-tree' )
		);
	}

	public static function render_test_mode_field(): void {
		self::render_checkbox_field(
			self::OPTION_TEST_MODE,
			self::is_test_mode(),
			__( 'Enable test mode (Testbetrieb)', 'wp-taxonomy-tree' ),
			__( 'Scaffold test posture (e.g. default confirm-delete off). Applies only after you save.', 'wp-taxonomy-tree' )
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

	public static function render_show_model_data_counts_field(): void {
		self::render_checkbox_field(
			self::OPTION_SHOW_MODEL_DATA_COUNTS,
			self::show_model_data_counts(),
			__( 'Show instance counts on structure hosts (e.g. Bauteilliste (23))', 'wp-taxonomy-tree' ),
			__( 'Off by default. Same toggle as the Taxonomy Tree toolbar switch. The count links to Fill Model Data for that host.', 'wp-taxonomy-tree' )
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
		self::render_switch_field(
			self::OPTION_CONFIRM_NODE_DELETE,
			self::confirm_node_delete(),
			__( 'Ask before deleting nodes', 'wp-taxonomy-tree' ),
			__( 'When off: delete immediately (no confirm). Trash = node only (children move up); networking icon = whole branch. Default follows Test mode — off while testing, on when Test mode is off (release).', 'wp-taxonomy-tree' )
		);
	}

	public static function render_warn_structural_model_change_field(): void {
		self::render_switch_field(
			self::OPTION_WARN_STRUCTURAL_MODEL_CHANGE,
			self::warn_structural_model_change(),
			__( 'Warn before structural model changes', 'wp-taxonomy-tree' ),
			__( 'When on (default), confirm before add/remove attribute or changing type/multiplicity if the host has Model Data instances — those edits bump the model version and may cause data conflicts. Off skips the confirmation. No popup when the host has no instances.', 'wp-taxonomy-tree' )
		);
	}

	public static function render_dialog_on_validation_warnings_field(): void {
		self::render_switch_field(
			self::OPTION_DIALOG_ON_VALIDATION_WARNINGS,
			self::dialog_on_validation_warnings(),
			__( 'Show dialog when validation warnings are present', 'wp-taxonomy-tree' ),
			__( 'Off by default. When on, admin UIs may ask for confirmation if a validation result includes warnings. Saving with warnings is always allowed; this only controls the optional popup. Schema errors in Taxonomy Tree definition remain blocked separately.', 'wp-taxonomy-tree' )
		);
	}

	/**
	 * Site-wide default for Object View renderDepth (blocks may override per instance).
	 */
	public static function render_default_render_depth_field(): void {
		$option = self::OPTION_DEFAULT_RENDER_DEPTH;
		$value  = self::default_render_depth();
		echo '<input type="number" class="small-text" id="' . esc_attr( $option ) . '" name="' . esc_attr( $option ) . '" value="' . esc_attr( (string) $value ) . '" min="0" max="5" step="1" />';
		echo '<p class="description">' . esc_html__(
			'Default nesting depth for Taxo Object view (0 = meta only; 1 = this node and its direct attributes — recommended; 2+ = nested related objects). Individual blocks may override. Change this when another project needs a deeper default.',
			'wp-taxonomy-tree'
		) . '</p>';
	}

	/**
	 * Allowlist of Dashicons usable as optional per-node tree icons.
	 */
	public static function render_tree_icons_field(): void {
		$catalog = Tree_Icons::catalog();
		$enabled = Tree_Icons::enabled_keys();
		$option  = Tree_Icons::OPTION_ENABLED;

		wp_enqueue_style( 'dashicons' );

		echo '<div class="wtt-tree-icons-settings">';
		echo '<p class="description">' . esc_html__(
			'Select which icons may be assigned on nodes (Properties → Icon). Nodes store their own icon; new children copy the parent icon once. Unchecking an icon here hides it from pickers; nodes that already use it show no icon until another allowed icon is chosen.',
			'wp-taxonomy-tree'
		) . '</p>';
		echo '<input type="hidden" name="' . esc_attr( $option ) . '" value="" />';
		echo '<ul class="wtt-tree-icons-settings__list">';
		foreach ( $catalog as $key => $label ) {
			$id = $option . '_' . $key;
			echo '<li class="wtt-tree-icons-settings__item">';
			echo '<label for="' . esc_attr( $id ) . '">';
			echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $option ) . '[]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $enabled, true ), true, false ) . ' />';
			echo '<span class="dashicons dashicons-' . esc_attr( $key ) . '" aria-hidden="true"></span>';
			echo '<span class="wtt-tree-icons-settings__label">' . esc_html( $label ) . '</span>';
			echo '</label>';
			echo '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	public static function render_development_mode_field(): void {
		self::render_checkbox_field(
			self::OPTION_DEVELOPMENT_MODE,
			self::is_development_mode(),
			__( 'Allow deleting all nodes and relations', 'wp-taxonomy-tree' ),
			__( 'Development only. When enabled, catalog/system nodes are deletable and protected relations (including child_of) can be removed in the Relations UI. The Trash bin itself stays non-deletable (use Empty trash). Off by default.', 'wp-taxonomy-tree' )
		);
	}

	public static function render_reset_case_tree_field(): void {
		$dev_on = self::is_development_mode();
		?>
		<button
			type="button"
			class="button button-secondary"
			id="wtt-settings-reset-case"
			<?php disabled( ! $dev_on ); ?>
		>
			<?php esc_html_e( 'Delete and reinstall case-study tree', 'wp-taxonomy-tree' ); ?>
		</button>
		<span id="wtt-settings-reset-case-status" class="wtt-settings-reset-status" role="status" aria-live="polite"></span>
		<p class="description">
			<?php
			esc_html_e(
				'Hard-wipes all Fallstudie terms (including attribute slots), clears catalog bindings and Model Data for wtt_fs, then reinstalls the blueprint. Requires Development mode (save first if you just turned it on).',
				'wp-taxonomy-tree'
			);
			?>
		</p>
		<?php
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

	/**
	 * Boolean setting as slide switch (Confirm dialogs and similar).
	 */
	private static function render_switch_field( string $option, bool $checked, string $label, string $description ): void {
		?>
		<input type="hidden" name="<?php echo esc_attr( $option ); ?>" value="0" />
		<label class="wtt-switch<?php echo $checked ? ' is-on' : ''; ?>" for="<?php echo esc_attr( $option ); ?>">
			<input
				type="checkbox"
				class="wtt-switch__input"
				id="<?php echo esc_attr( $option ); ?>"
				name="<?php echo esc_attr( $option ); ?>"
				value="1"
				<?php checked( $checked ); ?>
			/>
			<span class="wtt-switch__track" aria-hidden="true"><span class="wtt-switch__thumb"></span></span>
			<span class="wtt-switch__text"><?php echo esc_html( $label ); ?></span>
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
