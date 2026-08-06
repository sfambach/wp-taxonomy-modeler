<?php
/**
 * Fill Model Data admin working page.
 *
 * Taxonomy Tree menu item (before Settings): manage instance data against
 * structure hosts (attributes). Persistence via Model_Data.
 *
 * Layout: sidebar = taxonomy + structure pickers; main = form editor for one
 * instance, with automatic **list view** of instances below (identity columns).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Fill Model Data submenu + AJAX CRUD.
 */
final class Model_Data_Admin {

	public const PAGE_SLUG = 'wp-taxonomy-tree-model-data';

	public const NONCE_ACTION = 'wtt_model_data';

	public static function register(): void {
		/* Priority 15: after Tree_Admin (10), before Settings (20) so Settings stays last. */
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 15 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wtt_model_data_get', array( self::class, 'ajax_get' ) );
		add_action( 'wp_ajax_wtt_model_data_save', array( self::class, 'ajax_save' ) );
		add_action( 'wp_ajax_wtt_model_data_delete', array( self::class, 'ajax_delete' ) );
		add_action( 'wp_ajax_wtt_model_data_samples', array( self::class, 'ajax_samples' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			Tree_Admin::PAGE_SLUG,
			__( 'Fill Model Data', 'wp-taxonomy-tree' ),
			__( 'Fill Model Data', 'wp-taxonomy-tree' ),
			'manage_categories',
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

		$ver = defined( 'WTT_VERSION' ) ? WTT_VERSION : '0.0.1';

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_media();

		wp_enqueue_style(
			'wtt-object-render',
			WTT_PLUGIN_URL . 'assets/css/wtt-object-render.css',
			array(),
			$ver
		);
		wp_enqueue_style(
			'wtt-media-render',
			WTT_PLUGIN_URL . 'assets/css/wtt-media-render.css',
			array(),
			$ver
		);
		wp_enqueue_style(
			'wtt-model-data-admin',
			WTT_PLUGIN_URL . 'assets/css/model-data-admin.css',
			array( 'wtt-object-render' ),
			$ver
		);

		wp_register_script(
			'wtt-sample-data',
			WTT_PLUGIN_URL . 'assets/js/wtt-sample-data.js',
			array(),
			$ver,
			true
		);
		wp_register_script(
			'wtt-media-render',
			WTT_PLUGIN_URL . 'assets/js/wtt-media-render.js',
			array(),
			$ver,
			true
		);
		wp_register_script(
			'wtt-node-render',
			WTT_PLUGIN_URL . 'assets/js/wtt-node-render.js',
			array( 'wtt-sample-data', 'wtt-media-render' ),
			$ver,
			true
		);

		wp_enqueue_script(
			'wtt-model-data-admin',
			WTT_PLUGIN_URL . 'assets/js/model-data-admin.js',
			array( 'wtt-node-render', 'wtt-sample-data' ),
			$ver,
			true
		);

		Taxonomy::register_taxonomies();
		$default = Taxonomy::default_slug();
		if ( Taxonomy::is_scaffold( Taxonomy::FS ) && taxonomy_exists( Taxonomy::FS ) ) {
			/* Prefer Fallstudie / Model area when present. */
			$default = Taxonomy::FS;
		}

		$taxonomies = array();
		foreach ( Taxonomy::scaffold_taxonomies() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$taxonomies[] = array(
				'slug'  => (string) ( $row['slug'] ?? '' ),
				'label' => (string) ( $row['label'] ?? $row['slug'] ?? '' ),
			);
		}

		wp_localize_script(
			'wtt-model-data-admin',
			'wttModelData',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
				'taxonomy'    => $default,
				'taxonomies'  => $taxonomies,
				'hosts'       => Model_Data::list_structure_hosts(),
				'treePickerMode' => Settings::tree_picker_mode(),
				'i18n'        => self::i18n(),
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function i18n(): array {
		return array(
			'pageTitle'       => __( 'Fill Model Data', 'wp-taxonomy-tree' ),
			'intro'           => __( 'The taxonomy tree defines structures (types, attributes, hosts). This page manages instance data filled against a selected structure.', 'wp-taxonomy-tree' ),
			'taxonomy'        => __( 'Taxonomy', 'wp-taxonomy-tree' ),
			'structure'       => __( 'Structure node', 'wp-taxonomy-tree' ),
			'structureHint'   => __( 'Pick a host that has attributes. Hosts with attributes are listed first.', 'wp-taxonomy-tree' ),
			'chooseStructure' => __( 'Choose a structure…', 'wp-taxonomy-tree' ),
			'noAttributes'    => __( 'This node has no attributes yet. Add attributes on the Taxonomy Tree screen first.', 'wp-taxonomy-tree' ),
			'instances'       => __( 'Instances', 'wp-taxonomy-tree' ),
			'noInstances'     => __( 'No instances yet. Create one to fill attribute values.', 'wp-taxonomy-tree' ),
			'newInstance'     => __( 'New instance', 'wp-taxonomy-tree' ),
			'editInstance'    => __( 'Edit instance', 'wp-taxonomy-tree' ),
			'identity'        => __( 'Identity', 'wp-taxonomy-tree' ),
			'runningNumber'   => __( 'Number', 'wp-taxonomy-tree' ),
			'instanceId'      => __( 'Id', 'wp-taxonomy-tree' ),
			'createdAt'       => __( 'Created', 'wp-taxonomy-tree' ),
			'version'         => __( 'Version', 'wp-taxonomy-tree' ),
			'modifiedAt'      => __( 'Last modified', 'wp-taxonomy-tree' ),
			'modifiedBy'      => __( 'Modified by', 'wp-taxonomy-tree' ),
			'assignedOnSave'  => __( 'Assigned on save', 'wp-taxonomy-tree' ),
			'attributes'      => __( 'Attributes', 'wp-taxonomy-tree' ),
			'save'            => __( 'Save', 'wp-taxonomy-tree' ),
			'delete'          => __( 'Delete', 'wp-taxonomy-tree' ),
			'confirmDelete'   => __( 'Delete this instance? This cannot be undone.', 'wp-taxonomy-tree' ),
			'fillSamples'     => __( 'Fill samples', 'wp-taxonomy-tree' ),
			'fillSamplesHint' => __( 'Fill empty fields from the central type → sample map.', 'wp-taxonomy-tree' ),
			'saved'           => __( 'Instance saved.', 'wp-taxonomy-tree' ),
			'deleted'         => __( 'Instance deleted.', 'wp-taxonomy-tree' ),
			'loading'         => __( 'Loading…', 'wp-taxonomy-tree' ),
			'error'           => __( 'Something went wrong.', 'wp-taxonomy-tree' ),
			'attrsLabel'      => __( 'attrs', 'wp-taxonomy-tree' ),
			'fixed'           => __( 'Fixed', 'wp-taxonomy-tree' ),
			'inherited'       => __( 'Inherited', 'wp-taxonomy-tree' ),
			'selectStructure' => __( 'Select a structure node to list and edit instances.', 'wp-taxonomy-tree' ),
			'versionShort'    => __( 'v', 'wp-taxonomy-tree' ),
			'colNumber'       => __( 'Number', 'wp-taxonomy-tree' ),
			'colId'           => __( 'Id', 'wp-taxonomy-tree' ),
			'colCreated'      => __( 'Created', 'wp-taxonomy-tree' ),
			'colVersion'      => __( 'Version', 'wp-taxonomy-tree' ),
			'colModified'     => __( 'Last modified', 'wp-taxonomy-tree' ),
			'openInstance'    => __( 'Open instance', 'wp-taxonomy-tree' ),
			'activeInstance'  => __( 'Editing', 'wp-taxonomy-tree' ),
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-taxonomy-tree' ) );
		}
		?>
		<div class="wrap wtt-model-data" id="wtt-model-data-app">
			<h1><?php echo esc_html__( 'Fill Model Data', 'wp-taxonomy-tree' ); ?></h1>
			<p class="wtt-model-data__intro description">
				<?php echo esc_html__( 'The taxonomy tree defines structures (types, attributes, hosts). This page manages instance data filled against a selected structure.', 'wp-taxonomy-tree' ); ?>
			</p>
			<div class="wtt-model-data__layout">
				<aside class="wtt-model-data__sidebar" aria-label="<?php echo esc_attr__( 'Structure', 'wp-taxonomy-tree' ); ?>">
					<div class="wtt-model-data__field">
						<label for="wtt-md-taxonomy"><?php echo esc_html__( 'Taxonomy', 'wp-taxonomy-tree' ); ?></label>
						<select id="wtt-md-taxonomy" class="wtt-model-data__select"></select>
					</div>
					<div class="wtt-model-data__field">
						<label for="wtt-md-structure"><?php echo esc_html__( 'Structure node', 'wp-taxonomy-tree' ); ?></label>
						<select id="wtt-md-structure" class="wtt-model-data__select"></select>
						<p class="description"><?php echo esc_html__( 'Pick a host that has attributes. Hosts with attributes are listed first.', 'wp-taxonomy-tree' ); ?></p>
					</div>
				</aside>
				<main class="wtt-model-data__main" aria-live="polite">
					<p id="wtt-md-placeholder" class="wtt-model-data__placeholder description">
						<?php echo esc_html__( 'Select a structure node to list and edit instances.', 'wp-taxonomy-tree' ); ?>
					</p>
					<div id="wtt-md-editor" class="wtt-model-data__editor" hidden>
						<div class="wtt-model-data__editor-toolbar">
							<h2 id="wtt-md-editor-title"><?php echo esc_html__( 'Edit instance', 'wp-taxonomy-tree' ); ?></h2>
							<span id="wtt-md-status" class="wtt-model-data__status" role="status"></span>
						</div>
						<section class="wtt-model-data__identity" aria-labelledby="wtt-md-identity-title">
							<h3 id="wtt-md-identity-title" class="screen-reader-text"><?php echo esc_html__( 'Identity', 'wp-taxonomy-tree' ); ?></h3>
							<dl id="wtt-md-identity" class="wtt-model-data__identity-grid"></dl>
						</section>
						<section class="wtt-model-data__attrs" aria-labelledby="wtt-md-attrs-title">
							<h3 id="wtt-md-attrs-title"><?php echo esc_html__( 'Attributes', 'wp-taxonomy-tree' ); ?></h3>
							<div id="wtt-md-fields" class="wtt-object-view__form wtt-model-data__fields" role="list"></div>
						</section>
						<p class="wtt-model-data__actions">
							<button type="button" class="button button-primary" id="wtt-md-save">
								<?php echo esc_html__( 'Save', 'wp-taxonomy-tree' ); ?>
							</button>
							<button type="button" class="button" id="wtt-md-samples" title="<?php echo esc_attr__( 'Fill empty fields from the central type → sample map.', 'wp-taxonomy-tree' ); ?>">
								<?php echo esc_html__( 'Fill samples', 'wp-taxonomy-tree' ); ?>
							</button>
							<button type="button" class="button-link-delete wtt-model-data__trash" id="wtt-md-delete" title="<?php echo esc_attr__( 'Delete', 'wp-taxonomy-tree' ); ?>" aria-label="<?php echo esc_attr__( 'Delete', 'wp-taxonomy-tree' ); ?>">
								<span class="dashicons dashicons-trash" aria-hidden="true"></span>
							</button>
						</p>
					</div>
					<section id="wtt-md-instances" class="wtt-model-data__list-view" hidden aria-labelledby="wtt-md-instances-title">
						<div class="wtt-model-data__list-toolbar">
							<h2 id="wtt-md-instances-title"><?php echo esc_html__( 'Instances', 'wp-taxonomy-tree' ); ?></h2>
							<button type="button" class="button" id="wtt-md-new" disabled>
								<?php echo esc_html__( 'New instance', 'wp-taxonomy-tree' ); ?>
							</button>
						</div>
						<div class="wtt-model-data__list-wrap">
							<table class="widefat striped wtt-model-data__list" id="wtt-md-instance-list">
								<thead>
									<tr>
										<th scope="col" class="wtt-model-data__col-active"><span class="screen-reader-text"><?php echo esc_html__( 'Editing', 'wp-taxonomy-tree' ); ?></span></th>
										<th scope="col"><?php echo esc_html__( 'Number', 'wp-taxonomy-tree' ); ?></th>
										<th scope="col"><?php echo esc_html__( 'Id', 'wp-taxonomy-tree' ); ?></th>
										<th scope="col"><?php echo esc_html__( 'Created', 'wp-taxonomy-tree' ); ?></th>
										<th scope="col"><?php echo esc_html__( 'Version', 'wp-taxonomy-tree' ); ?></th>
										<th scope="col"><?php echo esc_html__( 'Last modified', 'wp-taxonomy-tree' ); ?></th>
									</tr>
								</thead>
								<tbody id="wtt-md-list-tbody"></tbody>
							</table>
						</div>
					</section>
				</main>
			</div>
		</div>
		<?php
	}

	public static function ajax_get(): void {
		self::verify_request();
		$taxonomy     = self::request_taxonomy();
		$structure_id = self::request_structure_id();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! Capabilities::user_can_manage( $taxonomy ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$structure = Model_Data::structure_dto( $taxonomy, $structure_id );
		if ( null === $structure ) {
			self::send_error( new \WP_Error( 'wtt_not_found', __( 'Structure node not found.', 'wp-taxonomy-tree' ) ) );
		}

		wp_send_json_success(
			array(
				'structure' => $structure,
				'instances' => Model_Data::list( $taxonomy, $structure_id ),
			)
		);
	}

	public static function ajax_save(): void {
		self::verify_request();
		$taxonomy     = self::request_taxonomy();
		$structure_id = self::request_structure_id();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$payload = array(
			'id'     => isset( $_POST['id'] ) ? sanitize_key( wp_unslash( (string) $_POST['id'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'values' => self::request_values_json(),
		);

		$result = Model_Data::save( $taxonomy, $structure_id, $payload );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success(
			array(
				'instance'  => $result,
				'instances' => Model_Data::list( $taxonomy, $structure_id ),
			)
		);
	}

	public static function ajax_delete(): void {
		self::verify_request();
		$taxonomy     = self::request_taxonomy();
		$structure_id = self::request_structure_id();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( (string) $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = Model_Data::delete( $taxonomy, $structure_id, $id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success(
			array(
				'instances' => Model_Data::list( $taxonomy, $structure_id ),
			)
		);
	}

	public static function ajax_samples(): void {
		self::verify_request();
		$taxonomy     = self::request_taxonomy();
		$structure_id = self::request_structure_id();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! Capabilities::user_can_manage( $taxonomy ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$values = self::request_values_json();
		$filled = Model_Data::apply_samples( $taxonomy, $structure_id, $values );

		wp_send_json_success(
			array(
				'values' => $filled,
			)
		);
	}

	private static function verify_request(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			self::send_error( new \WP_Error( 'wtt_bad_nonce', __( 'Invalid nonce.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function request_taxonomy() {
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( (string) $_POST['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! Taxonomy::is_scaffold( $taxonomy ) || ! Tree_Model::is_hierarchical_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Not a hierarchical taxonomy.', 'wp-taxonomy-tree' ) );
		}
		return $taxonomy;
	}

	private static function request_structure_id(): int {
		return isset( $_POST['structure_id'] ) ? absint( wp_unslash( $_POST['structure_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * @return array<string, string>
	 */
	private static function request_values_json(): array {
		$raw = isset( $_POST['values'] ) ? wp_unslash( $_POST['values'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_array( $raw ) ) {
			$decoded = $raw;
		} else {
			$decoded = json_decode( (string) $raw, true );
		}
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $k => $v ) {
			$out[ (string) absint( $k ) ] = is_scalar( $v ) ? (string) $v : '';
		}
		return $out;
	}

	private static function send_error( \WP_Error $error ): void {
		$data   = $error->get_error_data();
		$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 400;
		wp_send_json_error(
			array(
				'code'    => $error->get_error_code(),
				'message' => wp_strip_all_tags(
					html_entity_decode( $error->get_error_message(), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
				),
			),
			$status
		);
	}
}
