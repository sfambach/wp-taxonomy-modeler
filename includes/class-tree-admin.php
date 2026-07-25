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

		// Scaffold: rename legacy Basiseinheit member "Wert" → "Typ" before tree payload.
		if ( current_user_can( Capabilities::edit_terms( $requested ) ) ) {
			Demo_Data::migrate_basiseinheit_wert_to_typ( $requested );
			Demo_Data::migrate_abmessung_t_to_h( $requested );
			Demo_Data::ensure_prefix_multiplikators( $requested );
			Demo_Data::ensure_short_descriptions( $requested );
		}

		return array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( Tree_Ajax::NONCE_ACTION ),
			'taxonomy'   => $requested,
			'taxonomies' => $taxonomies,
			'tree'       => Tree_Model::get_tree( $requested ),
			'version'    => WTT_VERSION,
			'testMode'          => Settings::is_test_mode(),
			'showTypeInTree'    => Settings::show_type_in_tree(),
			'showSetChildProps' => Settings::show_set_child_props(),
			'saveViaButton'     => Settings::save_via_button(),
			'i18n'       => array(
				'empty'           => __( 'No terms yet. Create a root node to start the tree.', 'wp-taxonomy-tree' ),
				'selectHint'      => __( 'Select a node to inspect it. Domain model (Project / Node / Parameter) is still in planning - this screen is the taxonomy-tree scaffold.', 'wp-taxonomy-tree' ),
				'loading'         => __( 'Loading...', 'wp-taxonomy-tree' ),
				'addRoot'         => __( 'Add root', 'wp-taxonomy-tree' ),
				'addChild'        => __( 'Add child', 'wp-taxonomy-tree' ),
				'moveUp'          => __( 'Move up', 'wp-taxonomy-tree' ),
				'moveDown'        => __( 'Move down', 'wp-taxonomy-tree' ),
				'delete'          => __( 'Delete', 'wp-taxonomy-tree' ),
				'name'            => __( 'Name', 'wp-taxonomy-tree' ),
				'nameHint'        => __( 'Display name — identity is the term ID (stable when renaming or copying).', 'wp-taxonomy-tree' ),
				'slug'            => __( 'Slug', 'wp-taxonomy-tree' ),
				'goToParent'      => __( 'Open parent in tree and settings', 'wp-taxonomy-tree' ),
				'parent'          => __( 'Parent', 'wp-taxonomy-tree' ),
				'description'     => __( 'Description', 'wp-taxonomy-tree' ),
				'descriptionHint' => __( 'Optional longer notes for this node.', 'wp-taxonomy-tree' ),
				'shortDescription'=> __( 'Short description', 'wp-taxonomy-tree' ),
				'shortDescriptionHint' => __( 'Compact expansion of the name (e.g. L → Länge, m → Milli). Shown in dropdowns as “name — short”, labels, help, and tooltips.', 'wp-taxonomy-tree' ),
				'count'           => __( 'Assigned posts', 'wp-taxonomy-tree' ),
				'none'            => __( 'None', 'wp-taxonomy-tree' ),
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
				'resetDemo'       => __( 'Reset test tree', 'wp-taxonomy-tree' ),
				'confirmReset'    => __( 'Delete BOM Testprojekt (and old Passive Components / Semiconductors stubs), then reinstall the full demo tree?', 'wp-taxonomy-tree' ),
				'demoReset'       => __( 'Test tree reset and reinstalled.', 'wp-taxonomy-tree' ),
				'dataType'        => __( 'Data type', 'wp-taxonomy-tree' ),
				'dataTypeNone'    => __( 'No type', 'wp-taxonomy-tree' ),
				'dataTypeHint'    => __( 'Typen branches: Datentypen, Praefixe, Basiseinheit, and custom ones (e.g. Bauformen). Set members are typed child nodes.', 'wp-taxonomy-tree' ),
				'dataTypeSaving'  => __( 'Saving…', 'wp-taxonomy-tree' ),
				'saveSettings'    => __( 'Save settings', 'wp-taxonomy-tree' ),
				'undoSettings'    => __( 'Undo', 'wp-taxonomy-tree' ),
				'settingsUnsavedHint' => __( 'Unsaved changes', 'wp-taxonomy-tree' ),
				'settingsSaving'  => __( 'Saving…', 'wp-taxonomy-tree' ),
				'settingsSaved'   => __( 'Saved', 'wp-taxonomy-tree' ),
				'copy'            => __( 'Copy', 'wp-taxonomy-tree' ),
				'reparent'        => __( 'Reparent', 'wp-taxonomy-tree' ),
				'reparentTitle'   => __( 'Change parent', 'wp-taxonomy-tree' ),
				'reparentText'    => __( 'Choose a new parent for this term. Children stay attached.', 'wp-taxonomy-tree' ),
				'reparentRoot'    => __( 'Root (no parent)', 'wp-taxonomy-tree' ),
				'reparentApply'   => __( 'Move', 'wp-taxonomy-tree' ),
				'inspecting'      => __( 'Inspecting:', 'wp-taxonomy-tree' ),
				'setMembers'      => __( 'Set members', 'wp-taxonomy-tree' ),
				'setChildProperties' => __( 'Child properties', 'wp-taxonomy-tree' ),
				'setChildPropertiesHint' => __( 'Properties of direct set members (read-only here — select a child to edit).', 'wp-taxonomy-tree' ),
				'setMemberType'   => __( 'Type', 'wp-taxonomy-tree' ),
				'setMemberUntyped'=> __( 'not typed', 'wp-taxonomy-tree' ),
				'setParent'       => __( 'Member of set', 'wp-taxonomy-tree' ),
				'required'        => __( 'Required', 'wp-taxonomy-tree' ),
				'optional'        => __( 'Optional', 'wp-taxonomy-tree' ),
				'requiredHint'    => __( 'Fill rule for this slot / set member (not part of the data type itself).', 'wp-taxonomy-tree' ),
				'tableSettings'   => __( 'Table settings', 'wp-taxonomy-tree' ),
				'hasFooter'       => __( 'Has footer (Fußzeile)', 'wp-taxonomy-tree' ),
				'hasFooterHint'   => __( 'When enabled, the table instance can show a Fußzeile with the same columns (sum/avg/… later).', 'wp-taxonomy-tree' ),
				'setSettings'     => __( 'Set settings', 'wp-taxonomy-tree' ),
				'setSeparator'    => __( 'Separator', 'wp-taxonomy-tree' ),
				'setSeparatorHint'=> __( 'Between member names in the label and between values in display (e.g. L/B/H or 10.5/20/5mm).', 'wp-taxonomy-tree' ),
				'setJoinUnits'    => __( 'Join units', 'wp-taxonomy-tree' ),
				'setJoinUnitsHint'=> __( 'When all members share the same type with Praefix, choose Praefix once for all and show values with the separator (e.g. 10.5/20/5mm).', 'wp-taxonomy-tree' ),
				'setJoinUnitsUnavailable' => __( 'Available when every set member has the same data type.', 'wp-taxonomy-tree' ),
				'setJoinUnitsNoPrefix' => __( 'Available when that shared type includes a Praefix.', 'wp-taxonomy-tree' ),
				'setLabelChildren'=> __( 'Include children in label', 'wp-taxonomy-tree' ),
				'setLabelChildrenHint' => __( 'When on, labels show member names after the set name (e.g. Abmessung (L/B/H)). Used in Form and Table previews.', 'wp-taxonomy-tree' ),
				'fixedValue'      => __( 'Fixed value', 'wp-taxonomy-tree' ),
				'fixedValueNone'  => __( 'Not fixed', 'wp-taxonomy-tree' ),
				'fixedValueOff'   => __( 'No fixed value', 'wp-taxonomy-tree' ),
				'fixedValueOn'    => __( 'Use fixed value', 'wp-taxonomy-tree' ),
				'fixedValueChoose'=> __( 'Choose node', 'wp-taxonomy-tree' ),
				'fixedValueHint'  => __( 'Off by default. When on, this slot is constant (not filled by the user).', 'wp-taxonomy-tree' ),
				'fixedLiteralHint'=> __( 'Simple types: enter the constant (e.g. 10 for double). Empty is not allowed while “Use fixed value” is selected.', 'wp-taxonomy-tree' ),
				'fixedLiteralPlaceholder' => __( 'Constant value…', 'wp-taxonomy-tree' ),
				'fixedCatalogHint'=> __( 'Catalog types: pick a Typen node (e.g. Einheit → Ohm).', 'wp-taxonomy-tree' ),
				'fixedValueUnavailable' => __( 'Fixed value is available for simple and catalog types after you choose a data type.', 'wp-taxonomy-tree' ),
				'boolTrue'        => __( 'true', 'wp-taxonomy-tree' ),
				'boolFalse'       => __( 'false', 'wp-taxonomy-tree' ),
				'displayNodeNameHint' => __( 'This type always shows the node name — no fixed value and no user input.', 'wp-taxonomy-tree' ),
				'helpShowDescription' => __( 'Show description', 'wp-taxonomy-tree' ),
				'helpChildProperties' => __( 'Child properties', 'wp-taxonomy-tree' ),
				'typeBranch'      => __( 'Type branch', 'wp-taxonomy-tree' ),
				'typeBranchHint'  => __( 'Direct children of the selected type. Uncheck values that do not apply (e.g. kilo-Farad).', 'wp-taxonomy-tree' ),
				'typeBranchEnabled' => __( 'Enabled', 'wp-taxonomy-tree' ),
				'prefixFilteredByUnit' => __( 'Filtered by Basiseinheit allowlist', 'wp-taxonomy-tree' ),
				'praefixChildSettings' => __( 'Praefix (allowed + conversion)', 'wp-taxonomy-tree' ),
				'praefixChildSettingsHint' => __( 'Enable prefixes for this unit and enter each factor vs the prefix root. to_si = Typ × factor × unit root factor. Factor is stored on the Praefix catalog node (shared). Empty allowlist = no prefixes.', 'wp-taxonomy-tree' ),
				'childExtras' => __( 'Child extras', 'wp-taxonomy-tree' ),
				'childExtrasHint' => __( 'Extras for set members (type, required, fixed, prefix conversion). Name and description stay on the child node.', 'wp-taxonomy-tree' ),
				'childExtrasOnParent' => __( 'Same prefix allowlist and conversion as on this Praefix node — also shown under the parent unit (Child extras).', 'wp-taxonomy-tree' ),
				'multiplikatorPlaceholder' => __( 'e.g. 0.001', 'wp-taxonomy-tree' ),
				'multiplikatorHint' => __( 'Factor vs prefix root (milli = 0.001, kilo = 1000).', 'wp-taxonomy-tree' ),
				'prefixRootToSi' => __( 'Unit: prefix root → SI base', 'wp-taxonomy-tree' ),
				'prefixRootToSiHint' => __( 'Usually 1. Kilogramm uses 0.001 (gram → kilogram).', 'wp-taxonomy-tree' ),
				'unitDisplayLabel' => __( 'Unit label', 'wp-taxonomy-tree' ),
				'unitConversions' => __( 'Conversions', 'wp-taxonomy-tree' ),
				'unitConversionsHint' => __( 'to_si = Typ × multiplikator × prefix_root_to_si. Factors come from the Praefix catalog; prefix_root_to_si is on this unit.', 'wp-taxonomy-tree' ),
				'unitConvPrefix'  => __( 'Praefix', 'wp-taxonomy-tree' ),
				'unitConvSymbol'  => __( 'Symbol', 'wp-taxonomy-tree' ),
				'unitConvFactor'  => __( '× factor', 'wp-taxonomy-tree' ),
				'unitConvToSi'    => __( '1 → SI', 'wp-taxonomy-tree' ),
				'unitConvSample'  => __( '10.5 → SI', 'wp-taxonomy-tree' ),
				'unitConvNone'    => __( '(none)', 'wp-taxonomy-tree' ),
				'setPreview'      => __( 'Preview', 'wp-taxonomy-tree' ),
				'unifiedPreviewHint' => __( 'Form and table layouts — each as editable input and display-only.', 'wp-taxonomy-tree' ),
				'previewSchema'   => __( 'Definition', 'wp-taxonomy-tree' ),
				'unitSchemaHint'  => __( 'Unit schema only — not an instance. Kuerzel is the unit symbol (Meter → m). Praefix catalog “m” is Milli — same letter, different node.', 'wp-taxonomy-tree' ),
				'unitUsageHint'   => __( 'Usage sample when a field uses this unit (value + prefix + symbol). Sample often picks milli → e.g. 10.5mm.', 'wp-taxonomy-tree' ),
				'previewAsForm'   => __( 'Form', 'wp-taxonomy-tree' ),
				'previewAsTable'  => __( 'Table', 'wp-taxonomy-tree' ),
				'previewEditable' => __( 'Editable', 'wp-taxonomy-tree' ),
				'previewDisplayOnly' => __( 'Display only', 'wp-taxonomy-tree' ),
				'setTableCellHint'=> __( 'Compact set as one table field', 'wp-taxonomy-tree' ),
				'previewUnavailable'=> __( 'Preview nicht möglich', 'wp-taxonomy-tree' ),
				'previewColIndex' => __( '#', 'wp-taxonomy-tree' ),
				'previewColOther' => __( 'Column A', 'wp-taxonomy-tree' ),
				'previewColField' => __( 'Field', 'wp-taxonomy-tree' ),
				'previewColNote'  => __( 'Column B', 'wp-taxonomy-tree' ),
				'previewColGeneric' => __( 'Column', 'wp-taxonomy-tree' ),
				'previewColType'  => __( 'Type', 'wp-taxonomy-tree' ),
				'previewColConstraint' => __( 'Constraint', 'wp-taxonomy-tree' ),
				'previewOptionalPrefix' => __( 'optional (allowlist)', 'wp-taxonomy-tree' ),
				'previewSampleText' => __( 'Sample', 'wp-taxonomy-tree' ),
				'previewFooter'   => __( 'Footer', 'wp-taxonomy-tree' ),
				'previewFixed'    => __( 'fixed', 'wp-taxonomy-tree' ),
				'previewFixedSymbol' => __( 'fixed symbol', 'wp-taxonomy-tree' ),
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
